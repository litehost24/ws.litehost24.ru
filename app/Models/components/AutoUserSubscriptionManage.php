<?php

namespace App\Models\components;

use App\Mail\ForAdminMail;
use App\Mail\VpnRenewalConfigChangeMail;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\ReferralPricingService;
use App\Services\VpnPlanCatalog;
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

            if ($this->shouldStopLegacyVpnWithoutNextPlan($userSub, $subs)) {
                $this->deactivate($userSub, true, 'Для продления выберите новый тариф.');
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

            if ($this->shouldStopLegacyVpnWithoutNextPlan($awaitSub, $subs)) {
                $this->stopAwaitPaymentLegacyWithoutNextPlan($awaitSub);
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
        $renewalContext = $this->prepareRenewalContext($userSub, $subscription);
        $basePrice = $subscription
            ? $this->resolveBasePriceCentsFromPlanSnapshot($subscription, $renewalContext['plan_snapshot'])
            : (int) $userSub->price;
        $subscriptionPrice = $basePrice;

        $pricing = app(ReferralPricingService::class);
        $referral = User::query()->find((int) $userSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $subscriptionPrice = $pricing->getFinalPriceCents($subscription, $referrer, $referral, $basePrice);
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
            'file_path' => $renewalContext['file_path'],
            'connection_config' => $userSub->connection_config ?? null,
            'server_id' => $renewalContext['server_id'],
            'vpn_access_mode' => $renewalContext['vpn_access_mode'],
            'vpn_plan_code' => $renewalContext['plan_snapshot']['vpn_plan_code'] ?? null,
            'vpn_plan_name' => $renewalContext['plan_snapshot']['vpn_plan_name'] ?? null,
            'vpn_traffic_limit_bytes' => $renewalContext['plan_snapshot']['vpn_traffic_limit_bytes'] ?? null,
            'next_vpn_plan_code' => $renewalContext['carry_next_vpn_plan_code'],
            'pending_vpn_access_mode_source_server_id' => $renewalContext['pending_source_server_id'],
            'pending_vpn_access_mode_source_peer_name' => $renewalContext['pending_source_peer_name'],
            'pending_vpn_access_mode_disconnect_at' => $renewalContext['pending_disconnect_at'],
            'pending_vpn_access_mode_error' => null,
            'note' => $userSub->note ?? null,
        ]);

        if (($renewalContext['new_server'] ?? null) instanceof Server && trim((string) ($renewalContext['new_peer_name'] ?? '')) !== '') {
            $this->peerOperator()->syncServerState(
                $renewalContext['new_server'],
                (string) $renewalContext['new_peer_name'],
                'enabled',
                (int) $userSub->user_id
            );
        }

        if ((bool) ($renewalContext['disable_previous'] ?? false)) {
            $this->disableExistingBundlePeer($userSub);
        }

        if ((bool) ($renewalContext['grace_previous_config'] ?? false)) {
            $this->notifyRenewedVpnConfigChange($newSubscription, $renewalContext);
        }

        if ($subscription && $newSubscription && $referral) {
            $pricing->applyEarning($newSubscription, $subscription, $referrer, $referral, $basePrice);
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
        $renewalContext = $this->prepareRenewalContext($awaitSub, $subscription, false);
        $basePrice = $subscription
            ? $this->resolveBasePriceCentsFromPlanSnapshot($subscription, $renewalContext['plan_snapshot'])
            : (int) $awaitSub->price;
        $subscriptionPrice = $basePrice;
        $subscriptionName = $subscription ? $subscription->name : $awaitSub->name;

        $pricing = app(ReferralPricingService::class);
        $referral = User::query()->find((int) $awaitSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $subscriptionPrice = $pricing->getFinalPriceCents($subscription, $referrer, $referral, $basePrice);
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
            'file_path' => $renewalContext['file_path'],
            'connection_config' => $awaitSub->connection_config ?? null,
            'server_id' => $renewalContext['server_id'],
            'vpn_access_mode' => $renewalContext['vpn_access_mode'],
            'vpn_plan_code' => $renewalContext['plan_snapshot']['vpn_plan_code'] ?? null,
            'vpn_plan_name' => $renewalContext['plan_snapshot']['vpn_plan_name'] ?? null,
            'vpn_traffic_limit_bytes' => $renewalContext['plan_snapshot']['vpn_traffic_limit_bytes'] ?? null,
            'next_vpn_plan_code' => $renewalContext['carry_next_vpn_plan_code'],
            'pending_vpn_access_mode_source_server_id' => $renewalContext['pending_source_server_id'],
            'pending_vpn_access_mode_source_peer_name' => $renewalContext['pending_source_peer_name'],
            'pending_vpn_access_mode_disconnect_at' => $renewalContext['pending_disconnect_at'],
            'pending_vpn_access_mode_error' => null,
            'note' => $awaitSub->note ?? null,
        ]);

        if ($subscription && $created && $referral) {
            $pricing->applyEarning($created, $subscription, $referrer, $referral, $basePrice);
        }

        if (($renewalContext['new_server'] ?? null) instanceof Server && trim((string) ($renewalContext['new_peer_name'] ?? '')) !== '') {
            $this->peerOperator()->syncServerState(
                $renewalContext['new_server'],
                (string) $renewalContext['new_peer_name'],
                'enabled',
                (int) $awaitSub->user_id
            );
            if ((bool) ($renewalContext['disable_previous'] ?? false)) {
                $this->disableExistingBundlePeer($awaitSub);
            }

            Log::info("Activated subscription ID: {$awaitSub->id} for user_id: {$awaitSub->user_id}");
            return;
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
            $price = $pricing->getFinalPriceCents(
                $subscription,
                $referrer,
                $referral,
                $this->resolveBasePriceCentsFromPlanSnapshot(
                    $subscription,
                    $this->resolveIntendedRenewalPlanSnapshot($subscription, $userSub)
                )
            );
        }

        \Log::info("Checking balance: user_id={$userSub->user_id}, balance=$balance, price=$price");

        return $price <= $balance;
    }

    private function deactivate(object $userSub, bool $stopRebilling = false, ?string $successMessage = null): void
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
            if ($stopRebilling) {
                $updateData['is_rebilling'] = false;
            }
            if ($successMessage !== null && trim($successMessage) !== '') {
                $updateData['action_error'] = $successMessage;
            }
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

    private function shouldStopLegacyVpnWithoutNextPlan(object $userSub, Collection $subs): bool
    {
        $subscription = $subs->firstWhere('id', $userSub->subscription_id);
        if (!$subscription || trim((string) $subscription->name) !== 'VPN') {
            return false;
        }

        return trim((string) ($userSub->vpn_plan_code ?? '')) === ''
            && trim((string) ($userSub->next_vpn_plan_code ?? '')) === '';
    }

    private function stopAwaitPaymentLegacyWithoutNextPlan(object $userSub): void
    {
        UserSubscription::where('id', $userSub->id)->update([
            'action' => 'deactivate',
            'is_processed' => false,
            'is_rebilling' => false,
            'action_status' => 'success',
            'action_error' => 'Для продления выберите новый тариф.',
            'action_attempts' => 0,
        ]);

        Log::info("Stopped await payment legacy VPN without next plan - User_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
    }

    private function currentPlanSnapshot(object $userSub): array
    {
        return [
            'vpn_plan_code' => trim((string) ($userSub->vpn_plan_code ?? '')) !== '' ? (string) $userSub->vpn_plan_code : null,
            'vpn_plan_name' => trim((string) ($userSub->vpn_plan_name ?? '')) !== '' ? (string) $userSub->vpn_plan_name : null,
            'vpn_traffic_limit_bytes' => $userSub->vpn_traffic_limit_bytes !== null ? (int) $userSub->vpn_traffic_limit_bytes : null,
            'vpn_access_mode' => trim((string) ($userSub->vpn_access_mode ?? '')) !== '' ? (string) $userSub->vpn_access_mode : null,
        ];
    }

    private function resolveIntendedRenewalPlanSnapshot(?Subscription $subscription, object $userSub): array
    {
        $currentSnapshot = $this->currentPlanSnapshot($userSub);
        if (!$subscription || trim((string) $subscription->name) !== 'VPN') {
            return $currentSnapshot;
        }

        $nextPlanCode = trim((string) ($userSub->next_vpn_plan_code ?? ''));
        if ($nextPlanCode === '') {
            return $currentSnapshot;
        }

        return app(VpnPlanCatalog::class)->snapshot($nextPlanCode) ?? $currentSnapshot;
    }

    private function prepareRenewalContext(object $userSub, ?Subscription $subscription, bool $allowGracePeriod = true): array
    {
        $currentSnapshot = $this->currentPlanSnapshot($userSub);
        $planSnapshot = $this->resolveIntendedRenewalPlanSnapshot($subscription, $userSub);
        $currentServerId = (int) ($userSub->server_id ?? 0);

        if ($currentServerId <= 0) {
            $meta = SubscriptionBundleMeta::fromFilePath((string) ($userSub->file_path ?? ''));
            $currentServerId = $meta?->serverId() ?? 0;
        }

        $context = [
            'plan_snapshot' => $planSnapshot,
            'file_path' => $userSub->file_path ?? null,
            'server_id' => $currentServerId > 0 ? $currentServerId : null,
            'vpn_access_mode' => trim((string) ($userSub->vpn_access_mode ?? '')) !== ''
                ? (string) $userSub->vpn_access_mode
                : ($planSnapshot['vpn_access_mode'] ?? null),
            'new_server' => null,
            'new_peer_name' => null,
            'disable_previous' => false,
            'grace_previous_config' => false,
            'pending_source_server_id' => null,
            'pending_source_peer_name' => null,
            'pending_disconnect_at' => null,
            'carry_next_vpn_plan_code' => null,
        ];

        if (!$this->shouldReprovisionForRenewal($subscription, $userSub, $planSnapshot, $currentServerId)) {
            return $context;
        }

        try {
            $targetMode = trim((string) ($planSnapshot['vpn_access_mode'] ?? ''));
            $targetPlanCode = trim((string) ($planSnapshot['vpn_plan_code'] ?? ''));
            $server = $targetMode !== '' ? Server::resolvePurchaseServer($targetMode, $targetPlanCode) : null;
            $user = User::query()->find((int) $userSub->user_id);

            if (!$server || !$user) {
                throw new \RuntimeException('renewal.target.not_resolved');
            }

            $package = (new SubscriptionPackageBuilder($server, $user))->build();

            $context['file_path'] = $package['file_path'] ?? $context['file_path'];
            $context['server_id'] = (int) $server->id;
            $context['vpn_access_mode'] = $server->getVpnAccessMode();
            $context['new_server'] = $server;
            $context['new_peer_name'] = (string) ($package['email'] ?? '');
            $context['disable_previous'] = true;

            if ($allowGracePeriod) {
                [$sourceMeta, $sourceServer] = $this->resolveBundleServerTarget($userSub->file_path ?? null);
                if ($sourceMeta && $sourceServer) {
                    $context['disable_previous'] = false;
                    $context['grace_previous_config'] = true;
                    $context['pending_source_server_id'] = (int) $sourceServer->id;
                    $context['pending_source_peer_name'] = $sourceMeta->peerName();
                    $context['pending_disconnect_at'] = Carbon::now()->addHours(UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS);
                }
            }

            return $context;
        } catch (\Throwable $e) {
            $nextPlanCode = trim((string) ($userSub->next_vpn_plan_code ?? ''));

            $context['plan_snapshot'] = $currentSnapshot;
            $context['vpn_access_mode'] = trim((string) ($userSub->vpn_access_mode ?? '')) !== ''
                ? (string) $userSub->vpn_access_mode
                : ($currentSnapshot['vpn_access_mode'] ?? null);
            $context['carry_next_vpn_plan_code'] = $nextPlanCode !== '' ? $nextPlanCode : null;

            Log::error('Scheduled next VPN plan reprovision failed', [
                'user_id' => (int) ($userSub->user_id ?? 0),
                'subscription_id' => (int) ($userSub->subscription_id ?? 0),
                'next_vpn_plan_code' => $nextPlanCode,
                'error' => $e->getMessage(),
            ]);

            $this->notifyAdmin(
                'Не удалось применить выбранный тариф на следующий период. '
                . 'user_id=' . (int) ($userSub->user_id ?? 0)
                . ', subscription_id=' . (int) ($userSub->subscription_id ?? 0)
                . ', next_vpn_plan_code=' . $nextPlanCode
                . '. ' . $e->getMessage()
            );

            return $context;
        }
    }

    private function shouldReprovisionForRenewal(?Subscription $subscription, object $userSub, array $planSnapshot, int $currentServerId): bool
    {
        if (!$subscription || trim((string) $subscription->name) !== 'VPN') {
            return false;
        }

        if (trim((string) ($userSub->next_vpn_plan_code ?? '')) === '') {
            return false;
        }

        $targetMode = trim((string) ($planSnapshot['vpn_access_mode'] ?? ''));
        if ($targetMode === '') {
            return false;
        }

        $currentMode = trim((string) ($userSub->vpn_access_mode ?? ''));
        if ($currentMode === '' && $currentServerId > 0) {
            $currentMode = optional(Server::query()->find($currentServerId))->getVpnAccessMode() ?? '';
        }

        $targetServer = Server::resolvePurchaseServer($targetMode, trim((string) ($planSnapshot['vpn_plan_code'] ?? '')));
        if (!$targetServer) {
            return false;
        }

        return $currentMode !== $targetMode || $currentServerId !== (int) $targetServer->id;
    }

    private function disableExistingBundlePeer(object $userSub): void
    {
        [$meta, $server, $resolveError] = $this->resolveBundleServerTarget($userSub->file_path ?? null);
        if (!$meta || !$server) {
            if ($resolveError) {
                Log::warning('Unable to resolve previous bundle peer for renewal cleanup', [
                    'user_id' => (int) ($userSub->user_id ?? 0),
                    'subscription_id' => (int) ($userSub->subscription_id ?? 0),
                    'error' => $resolveError,
                ]);
            }

            return;
        }

        try {
            if ($server->usesNode1Api()) {
                $this->peerOperator()->disableNodePeer($server, $meta->peerName(), true);
            } else {
                $this->peerOperator()->disableInboundPeer($server, $meta->peerName());
            }

            $this->peerOperator()->syncServerState($server, $meta->peerName(), 'disabled', (int) ($userSub->user_id ?? 0));
        } catch (\Throwable $e) {
            Log::error('Unable to disable previous bundle peer after renewal reprovision', [
                'user_id' => (int) ($userSub->user_id ?? 0),
                'subscription_id' => (int) ($userSub->subscription_id ?? 0),
                'peer_name' => $meta->peerName(),
                'server_id' => (int) $server->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyAdmin(
                'Не удалось отключить старый peer после продления с новым тарифом. '
                . 'user_id=' . (int) ($userSub->user_id ?? 0)
                . ', subscription_id=' . (int) ($userSub->subscription_id ?? 0)
                . ', peer_name=' . $meta->peerName()
                . ', server_id=' . (int) $server->id
                . '. ' . $e->getMessage()
            );
        }
    }

    private function notifyRenewedVpnConfigChange(UserSubscription $newSubscription, array $renewalContext): void
    {
        $recipient = trim((string) ($newSubscription->user?->email ?? ''));
        $disconnectAt = $renewalContext['pending_disconnect_at'] ?? null;
        if ($recipient === '' || !$disconnectAt instanceof Carbon) {
            return;
        }

        try {
            $newSubscription->loadMissing('user', 'subscription');

            Mail::to($recipient)->send(new VpnRenewalConfigChangeMail($newSubscription, $disconnectAt));
        } catch (\Throwable $e) {
            Log::warning('VPN renewal config change mail failed', [
                'user_id' => (int) ($newSubscription->user_id ?? 0),
                'user_subscription_id' => (int) ($newSubscription->id ?? 0),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveBasePriceCentsFromPlanSnapshot(Subscription $subscription, array $planSnapshot): int
    {
        return app(VpnPlanCatalog::class)->resolveBasePriceCents(
            $subscription,
            (string) ($planSnapshot['vpn_plan_code'] ?? '')
        );
    }
}
