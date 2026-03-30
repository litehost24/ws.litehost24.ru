<?php

namespace App\Models\components;
use App\Models\components\InboundManager;
use App\Mail\ForAdminMail;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Log;
use App\Models\Server;
use App\Services\ReferralPricingService;
use App\Services\VpnAgent\Node1Provisioner;

class AutoUserSubscriptionManage
{
    private const MAX_DEACTIVATE_ATTEMPTS = 3;

    public function start(): void
    {
        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $subs = Subscription::all();

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ user_id пїЅ subscription_id (пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ ID)
        $allSubs = UserSubscription::orderBy('id', 'desc')->get();
        $uniqueSubs = collect([]);
        $processedPairs = [];

        foreach ($allSubs as $sub) {
            $pairKey = $sub->user_id . '_' . $sub->subscription_id;
            if (!in_array($pairKey, $processedPairs)) {
                $uniqueSubs->push($sub);
                $processedPairs[] = $pairKey;
            }
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
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

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
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
            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ $userSub пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ
            if (!$userSub || !isset($userSub->user_id) || !isset($userSub->subscription_id) ||
                !isset($userSub->end_date) || !isset($userSub->is_rebilling) || !isset($userSub->price)) {
                Log::error("Invalid userSub object, skipping...");
                continue;
            }

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            if ($this->isEnoughBalance($userSub)) {
                $this->processRebilling($userSub, $subs);
            } else {
                // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
                $this->processAwaitPayment($userSub);


            }
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        foreach ($latestExpiringSubsWithoutRebilling as $userSub) {
            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ $userSub пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ
            if (!$userSub || !isset($userSub->user_id) || !isset($userSub->subscription_id) ||
                !isset($userSub->end_date) || !isset($userSub->is_rebilling) || !isset($userSub->price)) {
                Log::error("Invalid userSub object, skipping...");
                continue;
            }

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $this->deactivate($userSub);
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        // Берем только последнюю запись для пары user_id + subscription_id и уже по ней решаем, что делать
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
            if (!$awaitSub || !isset($awaitSub->id) || !isset($awaitSub->user_id) ||
                !isset($awaitSub->subscription_id) || !isset($awaitSub->price)) {
                Log::error("Invalid awaitSub object, skipping...");
                continue;
            }

            if ($this->isEnoughBalance($awaitSub)) {
                $this->processActivation($awaitSub, $subs);
            }
        }

        Log::info('AutoUserSubscriptionManage ended successfully');
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    private function processRebilling($userSub, $subs): void
    {
        Log::info("Processing rebilling for user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $subscription = $subs->firstWhere('id', $userSub->subscription_id);
        $subscriptionName = $subscription ? $subscription->name : 'Unknown';
        $subscriptionPrice = $subscription ? $subscription->price : $userSub->price;

        $pricing = app(ReferralPricingService::class);
        $referral = User::query()->find((int) $userSub->user_id);
        $referrer = $referral?->referrer;
        if ($subscription && $referral) {
            $subscriptionPrice = $pricing->getFinalPriceCents($subscription, $referrer, $referral);
        }



        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
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





       // Log::info("Successfully created new subscription with ID: {$newSubscription->id} for user_id: {$userSub->user_id}");
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     */
    private function processAwaitPayment($userSub): void
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

        $path = $userSub->file_path ?? null;
        if (!empty($path)) {
            $filename = pathinfo(basename($path), PATHINFO_FILENAME);
            $parts = explode('_', $filename);

            if (isset($parts[2])) {
                $server = Server::where('id', $parts[2])->first();

                if ($server) {
                    if ($server->usesNode1Api()) {
                        try {
                            (new Node1Provisioner())->disableByName($server, $parts[1]);
                            $actionMessages[] = "node1.disable.ok(name={$parts[1]})";
                        } catch (\Exception $e) {
                            $actionOk = false;
                            $actionMessages[] = "node1.disable.error(name={$parts[1]}): {$e->getMessage()}";
                            $this->notifyAdmin("Node1 API disable failed (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, name={$parts[1]}. {$e->getMessage()}");
                        }
                    } else {
                        $inboundManager = new \App\Models\components\InboundManagerVless($server->url1);
                        try {
                            $result = $inboundManager->disableInbound($parts[1], $server->username1, $server->password1);
                            if (!$this->isSuccess($result)) {
                                $actionOk = false;
                                $actionMessages[] = "inbound.disable.error(remark={$parts[1]}): unsuccessful response";
                                $this->notifyAdmin("?? ??????? ????????? inbound (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, remark={$parts[1]}");
                            } else {
                                $actionMessages[] = "inbound.disable.ok(remark={$parts[1]})";
                            }
                        } catch (\Exception $e) {
                            $actionOk = false;
                            $actionMessages[] = "inbound.disable.error(remark={$parts[1]}): {$e->getMessage()}";
                            $this->notifyAdmin("?????? ?????????? inbound (await payment). user_id={$userSub->user_id}, sub_id={$userSub->subscription_id}, server_id={$server->id}, remark={$parts[1]}. {$e->getMessage()}");
                        }
                    }

                } else {
                    $actionOk = false;
                    $actionMessages[] = "server.not_found(id={$parts[2]})";
                }
            } else {
                $actionOk = false;
                $actionMessages[] = "parse.error(server_id missing in file_path)";
            }
        } else {
            $actionOk = false;
            $actionMessages[] = "file_path.empty";
        }

        $finalMessage = "await-payment disable attempt #{$attempt}: " . implode('; ', $actionMessages ?: ['no actions executed']);
        UserSubscription::where('id', $userSub->id)->update([
            'action_status' => $actionOk ? 'success' : 'error',
            'action_error' => $finalMessage,
        ]);

        Log::info("Updated subscription ID: {$userSub->id} to await payment state for user_id: {$userSub->user_id}");
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     */
    private function processActivation($awaitSub, $subs): void
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

        $path = $awaitSub->file_path ?? null;
        if (!empty($path)) {
            $filename = basename($path);
            $parts = explode('_', $filename);

            if (isset($parts[2])) {
                $server = Server::where('id', $parts[2])->first();

                if ($server) {
                    if ($server->usesNode1Api()) {
                        try {
                            (new Node1Provisioner())->enableByName($server, $parts[1]);
                        } catch (\Exception $e) {
                            $this->notifyAdmin("Node1 API enable failed (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, name={$parts[1]}. {$e->getMessage()}");
                        }
                    } else {
                        $inboundManager = new \App\Models\components\InboundManagerVless($server->url1);
                        try {
                            $result = $inboundManager->enableInbound($parts[1], $server->username1, $server->password1);
                            if (!$this->isSuccess($result)) {
                                $this->notifyAdmin("?? ??????? ???????? inbound (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, remark={$parts[1]}");
                            }
                        } catch (\Exception $e) {
                            $this->notifyAdmin("?????? ????????? inbound (activation). user_id={$awaitSub->user_id}, sub_id={$awaitSub->subscription_id}, server_id={$server->id}, remark={$parts[1]}. {$e->getMessage()}");
                        }
                    }
                }
            }
        }

        Log::info("Activated subscription ID: {$awaitSub->id} for user_id: {$awaitSub->user_id}");
    }

    // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ rebilling
    private function rebillingOriginal(object $userSub, \Illuminate\Database\Eloquent\Collection $subs): void
    {
        $actualSub = $subs->where('id', $userSub->subscription_id)->first();

        // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        $subscriptionName = $actualSub ? $actualSub->name : 'Unknown';
        $subscriptionPrice = $actualSub ? $actualSub->price : $userSub->price;

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $connectionConfig = null;
        if (!empty($userSub->file_path) && file_exists($userSub->file_path)) {
            $connectionConfig = file_get_contents($userSub->file_path);
        }

        if ($userSub) {
            $newSubscriptionData = [
                'subscription_id' => $userSub->subscription_id,
                'user_id' => $userSub->user_id,
                'name' => $subscriptionName,
                'price' => $subscriptionPrice,
                'action' => 'activate',
                'is_processed' => true,
                'is_rebilling' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
                'file_path' => $userSub->file_path,
                'connection_config' => $connectionConfig,
                'server_id' => $userSub->server_id ?? null,
                'vpn_access_mode' => $userSub->vpn_access_mode ?? null,
                'note' => $userSub->note ?? null,
            ];

            Log::info("Attempting to create subscription with data: " . json_encode($newSubscriptionData));

            try {
                $result = UserSubscription::create($newSubscriptionData);
                Log::info("Successfully created subscription with ID: {$result->id}");
            } catch (\Exception $e) {
                Log::error("Failed to create subscription: " . $e->getMessage());
                Log::error("Exception trace: " . $e->getTraceAsString());
            }

            Log::info("Auto rebilling - user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
        } else {
            Log::error("User subscription object is null in rebilling method");
        }
    }

    // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ activate
    private function activateOriginal(object $userSub, \Illuminate\Database\Eloquent\Collection $subs): void
    {
        if (!$userSub || !isset($userSub->id) || !isset($userSub->user_id) || !isset($userSub->subscription_id) || !isset($userSub->price)) {
            Log::error("Invalid userSub object in activateOriginal method");
            return;
        }

        $actualSub = $subs->where('id', $userSub->subscription_id)->first();

        // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        $subscriptionPrice = $actualSub ? $actualSub->price : $userSub->price;

        UserSubscription::where('id', $userSub->id)->update([
            'price' => $subscriptionPrice,
            'action' => 'activate',
            'is_processed' => true,
            'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
            ]);

        Log::info("Auto activated - User_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
    }

    private function isEnoughBalance(object $userSub): bool
    {
        if (!$userSub || !isset($userSub->user_id) || !isset($userSub->price)) {
            \Log::error("Invalid userSub object in isEnoughBalance method");
            return false;
        }

        $balance = (new Balance)->getBalance($userSub->user_id);
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

    private function rebilling(object $userSub, Collection $subs): void
    {
        Log::info("Rebilling method called for user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");

        $actualSub = $subs->where('id', $userSub->subscription_id)->first();

        Log::info("Actual subscription found: " . ($actualSub ? 'yes' : 'no'));

        if ($actualSub) {
            Log::info("Actual subscription name: " . (isset($actualSub->name) ? $actualSub->name : 'undefined') . ", price: " . (isset($actualSub->price) ? $actualSub->price : 'undefined'));
        } else {
            Log::warning("Subscription with id {$userSub->subscription_id} not found in collection");
        }

        // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        $subscriptionName = $actualSub ? $actualSub->name : 'Unknown';
        $subscriptionPrice = $actualSub ? $actualSub->price : $userSub->price;

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $connectionConfig = null;
        if (!empty($userSub->file_path) && file_exists($userSub->file_path)) {
            $connectionConfig = file_get_contents($userSub->file_path);
        }

        if ($userSub) {
            $newSubscriptionData = [
                'subscription_id' => $userSub->subscription_id,
                'user_id' => $userSub->user_id,
                'name' => $subscriptionName,
                'price' => $subscriptionPrice,
                'action' => 'activate',
                'is_processed' => true,
                'is_rebilling' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
                'file_path' => $userSub->file_path,
                'connection_config' => $connectionConfig,
                'server_id' => $userSub->server_id ?? null,
                'vpn_access_mode' => $userSub->vpn_access_mode ?? null,
                'note' => $userSub->note ?? null,
            ];

            Log::info("Attempting to create subscription with data: " . json_encode($newSubscriptionData));

            try {
                $result = UserSubscription::create($newSubscriptionData);
                Log::info("Successfully created subscription with ID: {$result->id}");
            } catch (\Exception $e) {
                Log::error("Failed to create subscription: " . $e->getMessage());
                Log::error("Exception trace: " . $e->getTraceAsString());
            }

            Log::info("Auto rebilling - user_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
        } else {
            Log::error("User subscription object is null in rebilling method");
        }
    }

    private function awaitPayment(object $userSub): void
    {
        if (!$userSub || !isset($userSub->id) || !isset($userSub->user_id) || !isset($userSub->subscription_id) || !isset($userSub->file_path)) {
            Log::error("Invalid userSub object in awaitPayment method");
            return;
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ - пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ is_processed пїЅ 0 пїЅ end_date пїЅ пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        UserSubscription::where('id', $userSub->id)->update([
            'action' => 'deactivate',
            'is_processed' => false,
            'end_date' => UserSubscription::AWAIT_PAYMENT_DATE,
        ]);

        Log::info("Auto deactivated and moved to await payment state - User_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
    }

    private function activate(object $userSub, Collection $subs): void
    {
        $actualSub = $subs->where('id', $userSub->subscription_id)->first();

        // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        $subscriptionPrice = $actualSub ? $actualSub->price : $userSub->price;

        if ($userSub) {
            UserSubscription::where('id', $userSub->id)->update([
                'price' => $subscriptionPrice,
                'action' => 'activate',
                'is_processed' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
            ]);

            Log::info("Auto activated - User_id: {$userSub->user_id}, subscription_id: {$userSub->subscription_id}");
        } else {
            Log::error("User subscription object is null in activate method");
        }
    }

    private function deactivate(object $userSub): void
    {
        if (!$userSub || !isset($userSub->id) || !isset($userSub->user_id) || !isset($userSub->subscription_id)) {
            Log::error("Invalid userSub object in deactivate method");
            return;
        }

        $actionErrors = [];
        $actionOk = true;

        $path = $userSub->file_path ?? null;
        if (!empty($path)) {
            $filename = basename($path);
            $parts = explode('_', $filename);

            if (isset($parts[2])) {
                $server = Server::where('id', $parts[2])->first();

                if ($server) {
                    if ($server->usesNode1Api()) {
                        try {
                            (new Node1Provisioner())->disableByName($server, $parts[1]);
                        } catch (\Exception $e) {
                            $actionOk = false;
                            $actionErrors[] = "Node1 API disable failed: {$e->getMessage()}";
                        }
                    } else {
                        $inboundManager = new \App\Models\components\InboundManagerVless($server->url1);
                        try {
                            $result = $inboundManager->disableInbound($parts[1], $server->username1, $server->password1);
                            if (!$this->isSuccess($result)) {
                                $actionOk = false;
                                $actionErrors[] = "?? ??????? ????????? inbound";
                            }
                        } catch (\Exception $e) {
                            $actionOk = false;
                            $actionErrors[] = "?????? ?????????? inbound: {$e->getMessage()}";
                        }
                    }
                } else {
                    $actionOk = false;
                    $actionErrors[] = "?????? ?? ?????? (id={$parts[2]})";
                }
            } else {
                $actionOk = false;
                $actionErrors[] = "?? ??????? ?????????? server_id ?? file_path";
            }
        } else {
            $actionOk = false;
            $actionErrors[] = "file_path ??????";
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

    private function isSuccess($result): bool
    {
        if (is_array($result) && array_key_exists('success', $result)) {
            return (bool) $result['success'];
        }

        if (is_bool($result)) {
            return $result;
        }

        return $result !== null;
    }

    private function notifyAdmin(string $message): void
    {
        try {
            Mail::to('4743383@gmail.com')->send(new ForAdminMail([
                'action' => $message,
            ]));
        } catch (\Exception $e) {
            Log::error('Admin уведомление не отправлено: ' . $e->getMessage());
        }
    }
}




