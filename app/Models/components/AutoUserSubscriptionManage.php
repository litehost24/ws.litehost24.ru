<?php

namespace App\Models\components;

use App\Mail\ForAdminMail;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\ReferralPricingService;
use App\Services\VpnAgent\SubscriptionPeerOperator;
use App\Support\SubscriptionBundleMeta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Log;

class AutoUserSubscriptionManage
{
    private const MAX_DEACTIVATE_ATTEMPTS = 3;

    public function start(): void
    {
        $subs = Subscription::all();

        $allSubs = UserSubscription::orderBy('id', 'desc')->get();
        $uniqueSubs = collect();
        $processedPairs = [];

        foreach ($allSubs as $sub) {
            $pairKey = $sub->user_id . '_' . $sub->subscription_id;
            if (!in_array($pairKey, $processedPairs, true)) {
                $uniqueSubs->push($sub);
                $processedPairs[] = $pairKey;
            }
        }

        $latestExpiringSubsWithRebilling = $uniqueSubs->filter(function ($sub) {
            if (empty($sub->end_date) || $sub->end_date === UserSubscription::AWAIT_PAYMENT_DATE) {
                return false;
            }

            $endDate = Carbon::parse($sub->end_date)->toDateString();
            $today = Carbon::today()->toDateString();

            return $sub->is_processed == true
                && $endDate <= $today
                && $sub->is_rebilling == true;
        });

        $latestExpiringSubsWithoutRebilling = $uniqueSubs->filter(function ($sub) {
            if (empty($sub->end_date) || $sub->end_date === UserSubscription::AWAIT_PAYMENT_DATE) {
                return false;
            }

            $endDate = Carbon::parse($sub->end_date)->toDateString();
            $today = Carbon::today()->toDateString();

            return $sub->is_processed == true
                && $endDate <= $today
                && $sub->is_rebilling == false;
        });

        Log::info('AutoUserSubscriptionManage started for ' . $latestExpiringSubsWithRebilling->count() . ' subscriptions with rebilling and ' . $latestExpiringSubsWithoutRebilling->count() . ' subscriptions without rebilling');

        foreach ($latestExpiringSubsWithRebilling as $userSub) {
            if (
                !$userSub
                || !isset($userSub->user_id)
                || !isset($userSub->subscription_id)
                || !isset($userSub->end_date)
                || !isset($userSub->is_rebilling)
                || !isset($userSub->price)
            ) {
                Log::error('Invalid userSub object, skipping...');
                continue;
            }

            if ($this->isEnoughBalance($userSub)) {
                $this->processRebilling($userSub, $subs);
            } else {
                $this->processAwaitPayment($userSub);
            }
        }

        foreach ($latestExpiringSubsWithoutRebilling as $userSub) {
            if (
                !$userSub
                || !isset($userSub->user_id)
                || !isset($userSub->subscription_id)
                || !isset($userSub->end_date)
                || !isset($userSub->is_rebilling)
                || !isset($userSub->price)
            ) {
                Log::error('Invalid userSub object, skipping...');
                continue;
            }

            $this->deactivate($userSub);
        }

        $awaitPaymentSubs = $uniqueSubs->filter(function ($sub) {
            if ($sub->end_date === UserSubscription::AWAIT_PAYMENT_DATE) {
                return true;
            }

            if (empty($sub->end_date)) {
                return false;
            }

            $endDate = Carbon::parse($sub->end_date)->toDateString();
            $today = Carbon::today()->toDateString();

            return $sub->is_processed == false
                && $sub->is_rebilling == true
                && $endDate < $today;
        });

        foreach ($awaitPaymentSubs as $awaitSub) {
            if (
                !$awaitSub
                || !isset($awaitSub->id)
                || !isset($awaitSub->user_id)
                || !isset($awaitSub->subscription_id)
                || !isset($awaitSub->price)
            ) {
                Log::error('Invalid awaitSub object, skipping...');
                continue;
            }

            if ($this->isEnoughBalance($awaitSub)) {
                $this->processActivation($awaitSub, $subs);
            }
        }

        Log::info('AutoUserSubscriptionManage ended successfully');
    }

    private function processRebilling(object $userSub, Collection $subs): void
    {
        Log::info("Processing rebilling for user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");

        $subscription = $subs->firstWhere('id', $userSub->subscription_id);
        $subscriptionName = $subscription ? $subscription->name : 'Unknown';
        $subscriptionPrice = $subscription ? $subscription->price : $userSub->price;

        $pricing = app(ReferralPricingService::class);
        $referral = User::query()->find((int) $userSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $subscriptionPrice = $pricing->getFinalPriceCents($subscription, $referrer, $referral);
        }

        $newSubscription = UserSubscription::create([
            'subscription_id' => $userSub->subscription_id,
            'user_id' => $userSub->user_id,
            'name' => $subscriptionName,
            'price' => $subscriptionPrice,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
            'file_path' => $userSub->file_path,
            'connection_config' => $userSub->connection_config ?? null,
            'server_id' => $userSub->server_id ?? null,
            'vpn_access_mode' => $userSub->vpn_access_mode ?? null,
            'note' => $userSub->note ?? null,
        ]);

        if ($subscription && $newSubscription && $referral) {
            $pricing->applyEarning($newSubscription, $subscription, $referrer, $referral);
        }
    }

    private function processAwaitPayment(object $userSub): void
    {
        Log::info("Processing await payment for user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");

        $attempt = (int) ($userSub->action_attempts ?? 0) + 1;
        $actionOk = true;
        $actionMessages = [];

        UserSubscription::where('id', $userSub->id)->update([
            'action' => 'activate',
            'is_processed' => false,
            'action_status' => 'in_progress',
            'action_attempts' => $attempt,
            'action_error' => "await-payment disable attempt #{$attempt} started",
        ]);

        [$meta, $server, $resolveError] = $this->resolveBundleServerTarget($userSub->file_path ?? null);
        if ($resolveError !== null) {
            $actionOk = false;
            $actionMessages[] = $resolveError;
        } elseif ($meta && $server) {
            if ($server->usesNode1Api()) {
                try {
                    $this->peerOperator()->disableNodePeer($server, $meta->peerName());
                    $this->peerOperator()->syncServerState($server, $meta->peerName(), 'disabled', (int) $userSub->user_id);
                    $actionMessages[] = "node1.disable.ok(name={$meta->peerName()})";
                } catch (\Exception $e) {
                    $actionOk = false;
                    $actionMessages[] = "node1.disable.error(name={$meta->peerName()}): {$e->getMessage()}";
                    $this->notifyAdmin("Node1 API disable failed (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, name={$meta->peerName()}. {$e->getMessage()}");
                }
            } else {
                try {
                    $this->peerOperator()->disableInboundPeer($server, $meta->peerName());
                    $this->peerOperator()->syncServerState($server, $meta->peerName(), 'disabled', (int) $userSub->user_id);
                    $actionMessages[] = "inbound.disable.ok(remark={$meta->peerName()})";
                } catch (\Exception $e) {
                    $actionOk = false;
                    $actionMessages[] = "inbound.disable.error(remark={$meta->peerName()}): {$e->getMessage()}";

                    if ($e->getMessage() === 'unsuccessful response') {
                        $this->notifyAdmin("?? ??????? ????????? inbound (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, remark={$meta->peerName()}");
                    } else {
                        $this->notifyAdmin("?????? ?????????? inbound (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, remark={$meta->peerName()}. {$e->getMessage()}");
                    }
                }
            }
        }

        $finalMessage = "await-payment disable attempt #{$attempt}: " . implode('; ', $actionMessages ?: ['no actions executed']);
        UserSubscription::where('id', $userSub->id)->update([
            'action_status' => $actionOk ? 'success' : 'error',
            'action_error' => $finalMessage,
        ]);

        Log::info("Updated subscription ID: {$userSub->id} to await payment state for user_id: {$userSub->user_id}");
    }

    private function processActivation(object $awaitSub, Collection $subs): void
    {
        Log::info("Processing activation for await payment subscription ID: {$awaitSub->id}");

        $subscription = $subs->firstWhere('id', $awaitSub->subscription_id);
        $subscriptionPrice = $subscription ? $subscription->price : $awaitSub->price;
        $subscriptionName = $subscription ? $subscription->name : $awaitSub->name;

        $pricing = app(ReferralPricingService::class);
        $referral = User::query()->find((int) $awaitSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $subscriptionPrice = $pricing->getFinalPriceCents($subscription, $referrer, $referral);
        }

        $created = UserSubscription::create([
            'subscription_id' => $awaitSub->subscription_id,
            'user_id' => $awaitSub->user_id,
            'name' => $subscriptionName,
            'price' => $subscriptionPrice,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()),
            'file_path' => $awaitSub->file_path,
            'connection_config' => $awaitSub->connection_config ?? null,
            'server_id' => $awaitSub->server_id ?? null,
            'vpn_access_mode' => $awaitSub->vpn_access_mode ?? null,
            'note' => $awaitSub->note ?? null,
        ]);

        if ($subscription && $created && $referral) {
            $pricing->applyEarning($created, $subscription, $referrer, $referral);
        }

        [$meta, $server] = $this->resolveBundleServerTarget($awaitSub->file_path ?? null);
        if ($meta && $server) {
            if ($server->usesNode1Api()) {
                try {
                    $this->peerOperator()->enableNodePeer($server, $meta->peerName());
                    $this->peerOperator()->syncServerState($server, $meta->peerName(), 'enabled', (int) $awaitSub->user_id);
                } catch (\Exception $e) {
                    $this->notifyAdmin("Node1 API enable failed (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, name={$meta->peerName()}. {$e->getMessage()}");
                }
            } else {
                try {
                    $this->peerOperator()->enableInboundPeer($server, $meta->peerName());
                    $this->peerOperator()->syncServerState($server, $meta->peerName(), 'enabled', (int) $awaitSub->user_id);
                } catch (\Exception $e) {
                    if ($e->getMessage() === 'unsuccessful response') {
                        $this->notifyAdmin("?? ??????? ???????? inbound (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, remark={$meta->peerName()}");
                    } else {
                        $this->notifyAdmin("?????? ????????? inbound (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, remark={$meta->peerName()}. {$e->getMessage()}");
                    }
                }
            }
        }

        Log::info("Activated subscription ID: {$awaitSub->id} for user_id: {$awaitSub->user_id}");
    }

    private function isEnoughBalance(object $userSub): bool
    {
        if (!$userSub || !isset($userSub->user_id) || !isset($userSub->price)) {
            \Log::error('Invalid userSub object in isEnoughBalance method');
            return false;
        }

        $balance = (new Balance())->getBalance($userSub->user_id);
        $price = $userSub->price;

        $subscription = Subscription::query()->find((int) $userSub->subscription_id);
        $referral = User::query()->find((int) $userSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $pricing = app(ReferralPricingService::class);
            $price = $pricing->getFinalPriceCents($subscription, $referrer, $referral);
        }

        \Log::info("Checking balance: user_id={$userSub->user_id}, balance=$balance, price=$price");

        return $price <= $balance;
    }

    private function deactivate(object $userSub): void
    {
        if (!$userSub || !isset($userSub->id) || !isset($userSub->user_id) || !isset($userSub->subscription_id)) {
            Log::error('Invalid userSub object in deactivate method');
            return;
        }

        $actionErrors = [];
        $actionOk = true;

        [$meta, $server] = $this->resolveBundleServerTarget($userSub->file_path ?? null);
        if (!$userSub->file_path) {
            $actionOk = false;
            $actionErrors[] = 'file_path ??????';
        } elseif (!$meta) {
            $actionOk = false;
            $actionErrors[] = '?? ??????? ?????????? server_id ?? file_path';
        } elseif (!$server) {
            $actionOk = false;
            $actionErrors[] = "?????? ?? ?????? (id={$meta->serverId()})";
        } elseif ($server->usesNode1Api()) {
            try {
                $this->peerOperator()->disableNodePeer($server, $meta->peerName());
                $this->peerOperator()->syncServerState($server, $meta->peerName(), 'disabled', (int) $userSub->user_id);
            } catch (\Exception $e) {
                $actionOk = false;
                $actionErrors[] = "Node1 API disable failed: {$e->getMessage()}";
            }
        } else {
            try {
                $this->peerOperator()->disableInboundPeer($server, $meta->peerName());
                $this->peerOperator()->syncServerState($server, $meta->peerName(), 'disabled', (int) $userSub->user_id);
            } catch (\Exception $e) {
                $actionOk = false;
                if ($e->getMessage() === 'unsuccessful response') {
                    $actionErrors[] = '?? ??????? ????????? inbound';
                } else {
                    $actionErrors[] = "?????? ?????????? inbound: {$e->getMessage()}";
                }
            }
        }

        $updateData = [
            'action_status' => $actionOk ? 'success' : 'error',
            'action_error' => $actionOk ? null : implode('; ', $actionErrors),
        ];

        if ($actionOk) {
            $updateData['action'] = 'deactivate';
            $updateData['is_processed'] = false;
            $updateData['action_attempts'] = 0;
        } else {
            $attempts = (int) ($userSub->action_attempts ?? 0) + 1;
            $updateData['action_attempts'] = $attempts;

            if ($attempts >= self::MAX_DEACTIVATE_ATTEMPTS) {
                $updateData['is_processed'] = false;
                $updateData['action_status'] = 'failed';
                $this->notifyAdmin(
                    "????????? ??????? ??????????? ????????: user_id={$userSub->user_id}, " .
                    "subscription_id={$userSub->subscription_id}, attempts={$attempts}. " .
                    "????????? ??????: " . ($updateData['action_error'] ?? 'unknown')
                );
            }
        }

        UserSubscription::where('id', $userSub->id)->update($updateData);

        Log::info("Auto deactivated - User_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
    }

    private function resolveBundleServerTarget(?string $filePath): array
    {
        $meta = SubscriptionBundleMeta::fromFilePath($filePath);
        if ($meta === null) {
            return [null, null, $filePath ? 'parse.error(server_id missing in file_path)' : 'file_path.empty'];
        }

        $server = Server::query()->find($meta->serverId());
        if (!$server) {
            return [$meta, null, "server.not_found(id={$meta->serverId()})"];
        }

        return [$meta, $server, null];
    }

    private function notifyAdmin(string $message): void
    {
        $recipient = trim((string) config('support.admin.email_to', config('support.contact.email_to', '')));
        if ($recipient === '') {
            return;
        }

        try {
            Mail::to($recipient)->send(new ForAdminMail([
                'action' => $message,
            ]));
        } catch (\Exception $e) {
            Log::error('Admin уведомление не отправлено: ' . $e->getMessage());
        }
    }

    private function peerOperator(): SubscriptionPeerOperator
    {
        return app(SubscriptionPeerOperator::class);
    }
}
