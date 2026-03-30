<?php

namespace App\Models\components;

use App\Mail\ForAdminMail;
use App\Mail\ForUserMail;
use App\Models\components\UserManagerVless;
use App\Models\components\SubscriptionPackageBuilder;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Services\ReferralPricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Illuminate\Support\Facades\Storage;
use App\Models\Server;
use App\Services\VpnAgent\Node1Provisioner;
//use App\Models\components\UserManagerVless;
use App\Models\components\InboundManagerVless;
use Exception;

class FullConnectSubscription
{
    private ?Subscription $sub;
    private ?string $note = null;
    private ?string $vpnAccessMode = null;

    public function __construct(?Subscription $sub = null, ?string $note = null, ?string $vpnAccessMode = null)
    {
        $this->sub = $sub;
        $this->note = $note;
        $this->vpnAccessMode = $vpnAccessMode;
    }

    public function create(): void
    {
        $referral = Auth::user();
        $referrer = $referral?->referrer;
        $pricing = app(ReferralPricingService::class);
        $basePrice = $this->sub ? (int) $this->sub->price : 0;
        $finalPrice = ($this->sub && $referral)
            ? $pricing->getFinalPriceCents($this->sub, $referrer, $referral)
            : $basePrice;
        $resolvedMode = $this->vpnAccessMode !== null && trim($this->vpnAccessMode) !== ''
            ? Server::normalizeVpnAccessMode($this->vpnAccessMode)
            : null;
        $resolvedServer = $resolvedMode ? Server::resolvePurchaseServer($resolvedMode) : null;

        // Tests should not depend on external server services.
        if (app()->environment('testing')) {
            $created = UserSubscription::create([
                'subscription_id' => $this->sub->id,
                'user_id' => Auth::user()->id,
                'name' => $this->sub->name,
                'price' => $finalPrice,
                'action' => 'create',
                'is_processed' => 1,
                'file_path' => 'files/test.zip',
                'is_rebilling' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()),
                'connection_config' => 'vless://test#' . (string) Auth::user()->email,
                'server_id' => $resolvedServer?->id,
                'vpn_access_mode' => $resolvedMode,
                'note' => $this->note,
            ]);
            if ($this->sub && $created && $referral) {
                $pricing->applyEarning($created, $this->sub, $referrer, $referral);
            }
            return;
        }

        $server = $resolvedServer ?: Server::resolvePurchaseServer($this->vpnAccessMode);
        if (!$server) {
            if ($this->vpnAccessMode !== null && trim($this->vpnAccessMode) !== '') {
                throw new Exception('Server not configured for mode: ' . $this->vpnAccessMode);
            }

            throw new Exception('Server not configured');
        }

        $builder = new SubscriptionPackageBuilder($server, Auth::user());
        $package = $builder->build();

        $created = UserSubscription::create([
            'subscription_id' => $this->sub->id,
            'user_id' => Auth::user()->id,
            'name' => $this->sub->name,
            'price' => $finalPrice,
            'action' => 'create',
            'is_processed' => 1,
            'file_path' => $package['file_path'],
            'is_rebilling' => true,
            'end_date' => UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()),
            'connection_config' => $package['vless_url'],
            'server_id' => $server->id,
            'vpn_access_mode' => $server->getVpnAccessMode(),
            'note' => $this->note,
        ]);
        if ($this->sub && $created && $referral) {
            $pricing->applyEarning($created, $this->sub, $referrer, $referral);
        }

        try {
            Mail::to('4743383@gmail.com')->send(new ForAdminMail([
                'action' => 'Подключение',
            ]));
        } catch (Exception $e) {
            \Log::warning('Admin mail send failed: ' . $e->getMessage());
        }
    }

    public function activate(): void
    {
        $referral = Auth::user();
        $referrer = $referral?->referrer;
        $pricing = app(ReferralPricingService::class);
        $basePrice = $this->sub ? (int) $this->sub->price : 0;
        $finalPrice = ($this->sub && $referral)
            ? $pricing->getFinalPriceCents($this->sub, $referrer, $referral)
            : $basePrice;

        // Tests should not depend on external server services.
        if (app()->environment('testing')) {
            $prevUserSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('subscription_id', $this->sub->id)
                ->orderBy('id', 'desc')
                ->first();

            $created = UserSubscription::create([
                'subscription_id' => $this->sub->id,
                'user_id' => Auth::user()->id,
                'name' => $this->sub->name,
                'price' => $finalPrice,
                'action' => 'activate',
                'is_processed' => 1,
                'is_rebilling' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate($prevUserSub?->end_date),
                'file_path' => $prevUserSub ? $prevUserSub->file_path : 'files/test.zip',
                'connection_config' => $prevUserSub?->connection_config ?? ('vless://test#' . (string) Auth::user()->email),
                'server_id' => $prevUserSub?->server_id,
                'vpn_access_mode' => $prevUserSub?->vpn_access_mode,
                'note' => $prevUserSub?->note ?? $this->note,
            ]);
            if ($this->sub && $created && $referral) {
                $pricing->applyEarning($created, $this->sub, $referrer, $referral);
            }
            return;
        }


        $userSub = UserSubscription::whereIsConnected()->where('subscription_id', $this->sub->id)->first();

        if ($userSub) {
            // Р вЂќР В°Р В¶Р Вµ Р ВµРЎРѓР В»Р С‘ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…Р В° "Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р Р…Р В°РЎРЏ" Р С—Р С•Р Т‘Р С—Р С‘РЎРѓР С”Р В°, РЎРѓР С•Р В·Р Т‘Р В°Р ВµР С Р Р…Р С•Р Р†РЎС“РЎР‹ Р В·Р В°Р С—Р С‘РЎРѓРЎРЉ
            // Р В­РЎвЂљР С• Р С—Р С•Р В·Р Р†Р С•Р В»РЎРЏР ВµРЎвЂљ Р С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…Р С• РЎС“РЎвЂЎР С‘РЎвЂљРЎвЂ№Р Р†Р В°РЎвЂљРЎРЉ РЎРѓРЎвЂљР С•Р С‘Р СР С•РЎРѓРЎвЂљРЎРЉ Р С—Р С•Р Т‘Р С—Р С‘РЎРѓР С”Р С‘ Р Р† РЎРѓР С‘РЎРѓРЎвЂљР ВµР СР Вµ
            $file_path = $userSub->file_path;
            $note = $userSub->note ?? null;

            $created = UserSubscription::create([
                'subscription_id' => $this->sub->id,
                'user_id' => Auth::user()->id,
                'name' => $this->sub->name,
                'price' => $finalPrice,
                'action' => 'activate',
                'is_processed' => 1, // Р вЂ™РЎРѓР ВµР С–Р Т‘Р В° 1 Р С—РЎР‚Р С‘ Р В°Р С”РЎвЂљР С‘Р Р†Р В°РЎвЂ Р С‘Р С‘ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»Р ВµР С
                'is_rebilling' => true,
                'end_date' => UserSubscription::nextMonthlyEndDate($userSub->end_date),
                'file_path' => $file_path,
                'connection_config' => $userSub->connection_config ?? null,
                'server_id' => $userSub->server_id ?? null,
                'vpn_access_mode' => $userSub->vpn_access_mode ?? null,
                'note' => $note,
            ]);
            if ($this->sub && $created && $referral) {
                $pricing->applyEarning($created, $this->sub, $referrer, $referral);
            }
            ///////////////////////////////////////////////////////////////////////////
            //Р В°Р С”РЎвЂљР С‘Р Р†Р В°РЎвЂ Р С‘РЎРЏ

            // Р СџР С•Р В»РЎС“РЎвЂЎР В°Р ВµР С Р С‘Р СРЎРЏ РЎвЂћР В°Р в„–Р В»Р В° Р С‘Р В· РЎРѓРЎвЂљРЎР‚Р С•Р С”Р С‘ $d
            $filename = basename($file_path);
            // Р В Р В°Р В·Р В±Р С‘Р Р†Р В°Р ВµР С Р С‘Р СРЎРЏ РЎвЂћР В°Р в„–Р В»Р В° Р С—Р С• РЎР‚Р В°Р В·Р Т‘Р ВµР В»Р С‘РЎвЂљР ВµР В»РЎР‹ _
            $parts = explode('_', $filename);

            if(isset($parts[2])) {
                $server = Server::where('id', $parts[2])->first();

                if($server) {


                    if ($server->usesNode1Api()) {
                        try {
                            (new Node1Provisioner())->enableByName($server, $parts[1]);
                        } catch (\Exception $e) {
                            $this->notifyAdmin("Ошибка включения node1 API (manual activation). user_id=" . Auth::user()->id . ", sub_id={$this->sub->id}, server_id={$server->id}, name={$parts[1]}. {$e->getMessage()}");
                        }
                    } else {
                        $inboundManager = new \App\Models\components\InboundManagerVless($server->url1);
                        try {
                            $result = $inboundManager->enableInbound($parts[1], $server->username1, $server->password1);
                            if (!$this->isSuccess($result)) {
                                $this->notifyAdmin("Не удалось включить inbound (manual activation). user_id=" . Auth::user()->id . ", sub_id={$this->sub->id}, server_id={$server->id}, remark={$parts[1]}");
                            }
                        } catch (\Exception $e) {
                            $this->notifyAdmin("Ошибка включения inbound (manual activation). user_id=" . Auth::user()->id . ", sub_id={$this->sub->id}, server_id={$server->id}, remark={$parts[1]}. {$e->getMessage()}");
                        }
                    }

                    $userManager = new UserManagerVless($server->url2);
                    try {
                        $result = $userManager->enableUser($parts[1], $server->username2, $server->password2);
                        if (!$this->isSuccess($result)) {
                            $this->notifyAdmin("Не удалось включить пользователя (manual activation). user_id=" . Auth::user()->id . ", sub_id={$this->sub->id}, server_id={$server->id}, email={$parts[1]}");
                        }
                    } catch (\Exception $e) {
                        $this->notifyAdmin("Ошибка включения пользователя (manual activation). user_id=" . Auth::user()->id . ", sub_id={$this->sub->id}, server_id={$server->id}, email={$parts[1]}. {$e->getMessage()}");
                    }



                }
            }
        } else {
            // Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚РЎРЏР ВµР С, Р ВµРЎРѓРЎвЂљРЎРЉ Р В»Р С‘ Р С—Р С•Р Т‘Р С—Р С‘РЎРѓР С”Р В° Р Р† РЎРѓР С•РЎРѓРЎвЂљР С•РЎРЏР Р…Р С‘Р С‘ Р С•Р В¶Р С‘Р Т‘Р В°Р Р…Р С‘РЎРЏ Р С•Р С—Р В»Р В°РЎвЂљРЎвЂ№
            $awaitPaymentSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('subscription_id', $this->sub->id)
                ->where('end_date', UserSubscription::AWAIT_PAYMENT_DATE)
                ->first();

            if ($awaitPaymentSub) {
                // Р В­РЎвЂљР С• Р С—Р С•Р Т‘Р С—Р С‘РЎРѓР С”Р В°, Р С•Р В¶Р С‘Р Т‘Р В°РЎР‹РЎвЂ°Р В°РЎРЏ Р С•Р С—Р В»Р В°РЎвЂљРЎвЂ№ - РЎРѓР С•Р В·Р Т‘Р В°Р ВµР С Р Р…Р С•Р Р†РЎС“РЎР‹ Р В·Р В°Р С—Р С‘РЎРѓРЎРЉ
                // Р вЂР В°Р В»Р В°Р Р…РЎРѓ РЎС“Р В¶Р Вµ Р С—РЎР‚Р С•Р Р†Р ВµРЎР‚Р ВµР Р… Р Р† Р С”Р С•Р Р…РЎвЂљРЎР‚Р С•Р В»Р В»Р ВµРЎР‚Р Вµ
                $created = UserSubscription::create([
                    'subscription_id' => $this->sub->id,
                    'user_id' => Auth::user()->id,
                    'name' => $this->sub->name,
                    'price' => $finalPrice,
                    'action' => 'activate',
                    'is_processed' => true,
                    'is_rebilling' => true,
                    'end_date' => UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()),
                    'file_path' => $awaitPaymentSub->file_path,
                    'connection_config' => $awaitPaymentSub->connection_config ?? null,
                    'server_id' => $awaitPaymentSub->server_id ?? null,
                    'vpn_access_mode' => $awaitPaymentSub->vpn_access_mode ?? null,
                    'note' => $awaitPaymentSub->note ?? null,
                ]);
                if ($this->sub && $created && $referral) {
                    $pricing->applyEarning($created, $this->sub, $referrer, $referral);
                }

            } else {
                // Р С›Р В±РЎвЂ№РЎвЂЎР Р…Р В°РЎРЏ РЎРѓР С‘РЎвЂљРЎС“Р В°РЎвЂ Р С‘РЎРЏ - РЎРѓР С•Р В·Р Т‘Р В°Р ВµР С Р Р…Р С•Р Р†РЎС“РЎР‹ Р В·Р В°Р С—Р С‘РЎРѓРЎРЉ
                // Р вЂР В°Р В»Р В°Р Р…РЎРѓ РЎС“Р В¶Р Вµ Р С—РЎР‚Р С•Р Р†Р ВµРЎР‚Р ВµР Р… Р Р† Р С”Р С•Р Р…РЎвЂљРЎР‚Р С•Р В»Р В»Р ВµРЎР‚Р Вµ
                $prevUserSub = UserSubscription::where('user_id', Auth::user()->id)
                    ->where('subscription_id', $this->sub->id)
                    ->orderBy('id', 'desc')
                    ->first();

                $created = UserSubscription::create([
                    'subscription_id' => $this->sub->id,
                    'user_id' => Auth::user()->id,
                    'name' => $this->sub->name,
                    'price' => $finalPrice,
                    'action' => 'activate',
                    'is_processed' => 1, // Р вЂ™РЎРѓР ВµР С–Р Т‘Р В° 1 Р С—РЎР‚Р С‘ Р В°Р С”РЎвЂљР С‘Р Р†Р В°РЎвЂ Р С‘Р С‘ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»Р ВµР С
                    'is_rebilling' => true,
                    'end_date' => UserSubscription::nextMonthlyEndDate($prevUserSub?->end_date),
                    'file_path' => $prevUserSub ? $prevUserSub->file_path : null,
                    'connection_config' => $prevUserSub?->connection_config ?? null,
                    'server_id' => $prevUserSub?->server_id ?? null,
                    'vpn_access_mode' => $prevUserSub?->vpn_access_mode ?? null,
                    'note' => $prevUserSub?->note ?? null,
                ]);
                if ($this->sub && $created && $referral) {
                    $pricing->applyEarning($created, $this->sub, $referrer, $referral);
                }

            }
        }
    }

    public function deactivate(): void
    {
        $userSub = UserSubscription::where('user_id', Auth::user()->id)
            ->where('subscription_id', $this->sub->id)
            ->orderBy('id', 'desc')
            ->first();
        if ($userSub) {
            $userSub->update([
                'is_rebilling' => false,
            ]);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function markAsDone(?string $mailAttachment = null): void
    {
        $userSub = UserSubscription::where('id', request()->get('id'))->first();
        if ($userSub->action == 'create') {
            $userSub->update([
                'is_processed' => 1,
                'end_date' => UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()),
            ]);
        } else {
            $userSub->update([
                'is_processed' => 1,
            ]);
        }

        Mail::to(Auth::user()->email)->send(new ForUserMail([
            'subName' => $userSub->name,
            'subStatus' => $userSub->action,
            'attachment' => $mailAttachment,
        ]));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function markAsDoneAndLoadFile(): void
    {
        $file = request()->file('file');
        if ($file->isValid()) {
            $fileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
                . "_" . now()->format('ymdHis') . "." . $file->getClientOriginalExtension();

            $uploadedPath = $file->storeAs('/files', $fileName);

            if ($uploadedPath) {
                UserSubscription::where('id', request()->get('id'))->update([
                    'file_path' => $uploadedPath,
                ]);
                $this->markAsDone("storage/$uploadedPath");
            }
        }
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
            \Log::error('Admin уведомление не отправлено: ' . $e->getMessage());
        }
    }
}



