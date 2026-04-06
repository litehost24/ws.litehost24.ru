<?php

namespace App\Http\Controllers;

use App\Models\components\Balance;
use App\Models\components\FullConnectSubscription;
use App\Models\components\UserSubscriptionInfo;
use App\Models\components\WireguardQrCode;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use App\Services\VpnAgent\SubscriptionArchiveBuilder;
use App\Services\VpnAgent\SubscriptionMtsBetaToEconomySwitcher;
use App\Services\VpnAgent\SubscriptionVpnAccessModeSwitcher;
use App\Services\ReferralPricingService;
use App\Services\VpnPlanCatalog;
use App\Services\VpnTopupCatalog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse as BinaryFileResponseAlias;

class UserSubscriptionController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    public function instruction(Request $request): JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            return response()->json(['message' => 'Доступ запрещен.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
            'protocol' => ['nullable', 'string', 'max:32'],
        ]);

        $userSub = UserSubscription::where('user_id', Auth::user()->id)
            ->where('id', (int) $data['user_subscription_id'])
            ->first();

        if (!$userSub) {
            return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $subInfo = new UserSubscriptionInfo(collect([$userSub]));
        $subInfo->setUserSubscriptionId((int) $userSub->id);
        $wireguardConfig = $subInfo->getWireguardConfig();
        $amneziaWgConfig = $wireguardConfig;
        $protocol = (string) $request->get('protocol', 'amnezia_vpn');

        $manualData = [
            'protocol' => $protocol,
            'id' => (int) ($userSub->subscription_id ?? 0),
            'manualUid' => 'instruction-tabs-' . (int) $userSub->id,
            'wireguardQrDataUri' => $wireguardConfig !== '' ? WireguardQrCode::makeDataUri($wireguardConfig) : null,
            'awgQrDataUri' => $amneziaWgConfig !== '' ? WireguardQrCode::makePlainDataUri($amneziaWgConfig) : null,
            'wireguardConfig' => $wireguardConfig,
            'amneziaWgConfig' => $amneziaWgConfig,
            'awgConfigUrl' => URL::signedRoute('telegram.awg.download', [
                'user_subscription_id' => (int) $userSub->id,
            ]),
            'amneziaWgConfigUrl' => URL::signedRoute('telegram.awg.compat.download', [
                'user_subscription_id' => (int) $userSub->id,
            ]),
            'fileUrl' => $subInfo->getFileUrl(),
            'pendingVpnAccessModeDisconnectAt' => $userSub->pendingVpnAccessModeDisconnectAt(),
        ];

        $view = $protocol === 'tabbed'
            ? 'subscription.manual_tabbed'
            : 'subscription.manual';

        $html = view($view, $manualData)->render();

        return response()->json(['ok' => true, 'html' => $html], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function download(Request $request)
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'subscription_id' => ['required', 'integer'],
            'user_subscription_id' => ['nullable', 'integer'],
        ]);

        $userSub = null;
        if (!empty($data['user_subscription_id'])) {
            $userSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('id', (int) $data['user_subscription_id'])
                ->first();
        }

        if (!$userSub) {
            $userSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('subscription_id', $data['subscription_id'])
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$userSub) {
            abort(404);
        }

        $relativePath = trim((string) ($userSub->file_path ?? ''));
        if ($relativePath !== '') {
            $relativePath = ltrim($relativePath, '/');
            if (str_starts_with($relativePath, 'storage/')) {
                $relativePath = substr($relativePath, strlen('storage/'));
            }

            // Basic traversal guard (file_path is expected to be a relative path like files/.../*.zip).
            if (str_contains($relativePath, '..')) {
                abort(400);
            }
        }

        $downloadName = basename($relativePath) ?: ('subscription_' . (int) $userSub->id . '.zip');

        try {
            $liveArchivePath = app(SubscriptionArchiveBuilder::class)->buildTemporaryArchive($userSub, $downloadName);
            if (is_string($liveArchivePath) && is_file($liveArchivePath)) {
                return response()->download($liveArchivePath, $downloadName, [
                    'Content-Type' => 'application/zip',
                ])->deleteFileAfterSend(true);
            }
        } catch (\Throwable $e) {
            Log::warning('Live subscription archive build failed, falling back to stored zip', [
                'user_subscription_id' => (int) $userSub->id,
                'file_path' => (string) $userSub->file_path,
                'error' => $e->getMessage(),
            ]);
        }

        if ($relativePath === '' || !Storage::disk('public')->exists($relativePath)) {
            abort(404);
        }

        return Storage::disk('public')->download($relativePath, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function downloadAmneziaWg(Request $request): Response
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = UserSubscription::query()
            ->where('user_id', (int) Auth::id())
            ->where('id', (int) $data['user_subscription_id'])
            ->first();

        if (!$userSub) {
            abort(404);
        }

        $config = app(\App\Services\VpnAgent\SubscriptionWireguardConfigResolver::class)->resolve($userSub);
        if (trim($config) === '') {
            abort(404);
        }

        return response($config, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="peer-1-amneziawg.conf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function connect(): RedirectResponse|JsonResponse
    {
        // Referral gate: subscriptions can be purchased only by "user"/"admin" roles.
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Доступно только по реферальной ссылке.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Доступно только по реферальной ссылке.');
        }

        $sub = Subscription::where('id', request()->get('id'))->first();
        if (!$sub) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        $userSubscriptionId = (int) request()->get('user_subscription_id');
        $currentUserSub = $this->findUserSubscriptionForCard((int) $sub->id, $userSubscriptionId > 0 ? $userSubscriptionId : null);

        if ($currentUserSub && $this->isLegacyVpnSubscription($currentUserSub, $sub)) {
            $message = trim((string) ($currentUserSub->next_vpn_plan_code ?? '')) !== ''
                ? 'Для старого тарифа ручное продление отключено. Новый тариф уже выбран и активируется автоматически после продления.'
                : 'Старый тариф больше не продлевается. Выберите новый тариф со следующего периода.';

            if (request()->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        $fullConnectSubscription = new FullConnectSubscription($sub);

        $pricing = app(ReferralPricingService::class);
        $referral = Auth::user();
        $referrer = $referral?->referrer;
        $finalPrice = $pricing->getFinalPriceCents($sub, $referrer, $referral);

        if ((new Balance)->getBalance() < $finalPrice) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Недостаточно средств'], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Недостаточно средств');
        }

        $userSubInfo = $this->getSubInfo();
        if ($userSubscriptionId > 0) {
            $userSubInfo->setUserSubscriptionId($userSubscriptionId);
        } else {
            $userSubInfo->setSubId($sub->id);
        }

        try {
            if (!$userSubInfo->isWasConnected()) {
                $fullConnectSubscription->create();

                if (request()->expectsJson()) {
                    return $this->subscriptionCardJson($sub, 'Подписка успешно подключена!!!');
                }
                return redirect()->back()->with('subscription-success', 'Подписка успешно подключена!!!');
            }

            if (!$userSubInfo->isRebillActive()) {
                $fullConnectSubscription->activate();

                if (request()->expectsJson()) {
                    return $this->subscriptionCardJson($sub, 'Автопродление включено', $userSubscriptionId > 0 ? $userSubscriptionId : null);
                }
                return redirect()->back()->with('subscription-success', 'Автопродление включено');
            }
        } catch (\Exception $e) {
            \Log::error('Subscription connect error: ' . $e->getMessage());
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Ошибка при подключении подписки'], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Ошибка при подключении подписки');
        }

        if (request()->expectsJson()) {
            return $this->subscriptionCardJson($sub, 'Готово');
        }

        return redirect()->back();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function disconnect(): RedirectResponse
    {
        $sub = Subscription::where('id', request()->get('id'))->first();
        $fullConnectSubscription = new FullConnectSubscription($sub);
        $userSubInfo = $this->getSubInfo();

        $userSubscriptionId = (int) request()->get('user_subscription_id');
        if ($userSubscriptionId > 0) {
            $userSubInfo->setUserSubscriptionId($userSubscriptionId);
        } else {
            $userSubInfo->setSubId($sub->id);
        }

        if ($userSubInfo->isConnected()) {
            $fullConnectSubscription->deactivate();

            return redirect()->back()->with('subscription-success', 'Автопродление успешно отключено');
        }

        return redirect()->back();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function markAsDone(): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            return redirect()->route('home');
        }

        (new FullConnectSubscription)->markAsDone();

        return redirect()->back();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function markAsDoneAndLoadFile(): RedirectResponse|BinaryFileResponseAlias
    {
        if (!Auth::user()->isAdmin()) {
            return redirect()->route('home');
        }

        (new FullConnectSubscription)->markAsDoneAndLoadFile();

        return redirect()->back();
    }

    public function manage(): View | RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            return redirect()->route('home');
        }

        $today = Carbon::today()->toDateString();

        $latestIds = UserSubscription::select(DB::raw('MAX(id)'))
            ->groupBy('user_id', 'subscription_id');

        $userSubs = UserSubscription::whereIn('id', $latestIds)
            ->where('is_processed', 0);

        $createUserSubs = (clone $userSubs)
            ->where('action', 'create')
            ->get();

        // Exclude auto "await payment" records from manual activation queue.
        $activateUserSubs = (clone $userSubs)
            ->where('action', 'activate')
            ->where(function ($query) use ($today) {
                $query->where('is_rebilling', false)
                    ->orWhere('end_date', '>', $today)
                    ->orWhere('end_date', UserSubscription::AWAIT_PAYMENT_DATE);
            })
            ->get();

        $deactivateUserSubs = (clone $userSubs)
            ->where('action', 'deactivate')
            ->get();

        return view('user-subscriptions.manage', [
            'createUserSubs' => $createUserSubs,
            'activateUserSubs' => $activateUserSubs,
            'deactivateUserSubs' => $deactivateUserSubs,
        ]);
    }

    public function addVpn(Request $request): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Добавление VPN доступно только пользователям.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Добавление VPN доступно только пользователям.');
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'need_white_ip' => ['nullable', 'boolean'],
            'vpn_plan_code' => ['nullable', 'string', 'max:64'],
        ]);

        $userId = (int) Auth::id();
        $sub = Subscription::nextAvailableVpnForUser($userId);
        if (!$sub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'VPN-подписки не найдены.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'VPN-подписки не найдены.');
        }

        $pricing = app(ReferralPricingService::class);
        $catalog = app(VpnPlanCatalog::class);
        $referral = Auth::user();
        $referrer = $referral?->referrer;
        $planCode = $this->resolveRequestedVpnPlanCode($request, $sub);
        if (trim((string) $request->input('vpn_plan_code', '')) !== '' && $planCode === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Тариф недоступен для новых подключений.'], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Тариф недоступен для новых подключений.');
        }
        $basePrice = $catalog->resolveBasePriceCents($sub, $planCode);
        $finalPrice = $pricing->getFinalPriceCents($sub, $referrer, $referral, $basePrice);

        if ((new Balance)->getBalance() < $finalPrice) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Недостаточно средств'], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Недостаточно средств');
        }

        try {
            $fullConnectSubscription = new FullConnectSubscription($sub, $data['note'] ?? null, null, $planCode);
            $fullConnectSubscription->create();
        } catch (\Exception $e) {
            \Log::error('Subscription add VPN error: ' . $e->getMessage());
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Ошибка при подключении VPN'], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Ошибка при подключении VPN');
        }

        if ($request->expectsJson()) {
            $userSub = $this->findUserSubscriptionForCard((int) $sub->id);
            $cardHtml = $userSub ? $this->renderSubscriptionCard($userSub) : null;

            $payload = [
                'message' => 'VPN подключен',
                'card_html' => $cardHtml,
                'cards_html' => $this->renderSubscriptionRows(),
                'balance_rub' => (new Balance)->getBalanceRub(),
                'next_vpn_price_rub' => $this->getNextVpnPriceRub(),
            ];

            return response()->json($payload, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return redirect()->back()->with('subscription-success', 'VPN подключен');
    }

    public function updateNote(Request $request): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Редактирование доступно только пользователям.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Редактирование доступно только пользователям.');
        }

        $data = $request->validate([
            'subscription_id' => ['nullable', 'integer', 'required_without:user_subscription_id'],
            'user_subscription_id' => ['nullable', 'integer', 'required_without:subscription_id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $userSub = null;
        if (!empty($data['user_subscription_id'])) {
            $userSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('id', (int) $data['user_subscription_id'])
                ->first();
        }

        if (!$userSub && !empty($data['subscription_id'])) {
            $userSub = UserSubscription::where('user_id', Auth::user()->id)
                ->where('subscription_id', $data['subscription_id'])
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        $userSub->update([
            'note' => $data['note'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Пометка обновлена.', 'note' => $data['note']], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return redirect()->back()->with('subscription-success', 'Пометка обновлена.');
    }

    public function purchaseTopup(Request $request): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Покупка пакета трафика недоступна.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Покупка пакета трафика недоступна.');
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('user_subscription_topups')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Покупка пакета трафика временно недоступна.'], 503, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Покупка пакета трафика временно недоступна.');
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
            'topup_code' => ['required', 'string', 'max:64'],
        ]);

        $userSub = $this->findVisibleUserSubscription((int) $data['user_subscription_id']);
        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        if (!$userSub->isLocallyActive()) {
            $message = 'Пакет трафика можно добавить только к активной подписке.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        if ($userSub->vpnTrafficLimitBytes() === null) {
            $message = 'Для обычного подключения докупка трафика не требуется.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        $package = app(VpnTopupCatalog::class)->find((string) $data['topup_code']);
        if ($package === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Пакет трафика не найден.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Пакет трафика не найден.');
        }

        $expiresOn = trim((string) ($userSub->end_date ?? ''));
        if ($expiresOn === '' || $expiresOn === UserSubscription::AWAIT_PAYMENT_DATE) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Не удалось определить срок действия пакета.'], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Не удалось определить срок действия пакета.');
        }

        $userId = (int) Auth::id();
        $topup = DB::transaction(function () use ($userId, $userSub, $package, $expiresOn) {
            DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if ((new Balance)->getBalance($userId) < (int) $package['price_cents']) {
                return null;
            }

            return UserSubscriptionTopup::query()->create([
                'user_subscription_id' => (int) $userSub->id,
                'user_id' => $userId,
                'topup_code' => (string) $package['code'],
                'name' => (string) $package['label'],
                'price' => (int) $package['price_cents'],
                'traffic_bytes' => (int) $package['traffic_bytes'],
                'expires_on' => Carbon::parse($expiresOn)->toDateString(),
            ]);
        });

        if (!$topup) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Недостаточно средств'], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Недостаточно средств');
        }

        $message = sprintf(
            'Пакет %s добавлен до %s. Неиспользованный остаток на следующий период не переносится.',
            (string) $package['label'],
            Carbon::parse($expiresOn)->format('d.m.Y')
        );

        if ($request->expectsJson()) {
            return $this->subscriptionCardJson($userSub->subscription, $message, (int) $userSub->id);
        }

        return redirect()->back()->with('subscription-success', $message);
    }

    public function scheduleNextVpnPlan(Request $request): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Выбор тарифа недоступен.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Выбор тарифа недоступен.');
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
            'vpn_plan_code' => ['required', 'string', 'max:64'],
        ]);

        $userSub = $this->findVisibleUserSubscription((int) $data['user_subscription_id']);
        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        $subscription = $userSub->subscription;
        if (!$subscription || trim((string) $subscription->name) !== 'VPN') {
            $message = 'Выбор следующего тарифа доступен только для VPN-подписки.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        if (!$userSub->isLegacyVpnPlan()) {
            $message = 'Для нового тарифа выбор на следующий период не требуется.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        $catalog = app(VpnPlanCatalog::class);
        $planCode = $catalog->normalizePlanCode((string) $data['vpn_plan_code']);
        $plan = $catalog->find($planCode);

        if ($plan === null || !$catalog->isPurchasable($planCode)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Тариф не найден.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Тариф не найден.');
        }

        $userSub->update([
            'next_vpn_plan_code' => $planCode,
            'is_rebilling' => true,
        ]);

        $message = $this->scheduledNextPlanMessage($userSub, $planCode, $plan);

        if ($request->expectsJson()) {
            return $this->subscriptionCardJson($subscription, $message, (int) $userSub->id);
        }

        return redirect()->back()->with('subscription-success', $message);
    }

    public function clearNextVpnPlan(Request $request): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Выбор тарифа недоступен.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Выбор тарифа недоступен.');
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = $this->findVisibleUserSubscription((int) $data['user_subscription_id']);
        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        $subscription = $userSub->subscription;
        if (!$this->isLegacyVpnSubscription($userSub, $subscription)) {
            $message = 'Отмена следующего тарифа доступна только для старой VPN-подписки.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        $userSub->update([
            'next_vpn_plan_code' => null,
            'is_rebilling' => false,
        ]);

        $message = 'Выбор следующего тарифа отменён. Подписка остановится в дату окончания.';

        if ($request->expectsJson()) {
            return $this->subscriptionCardJson($subscription, $message, (int) $userSub->id);
        }

        return redirect()->back()->with('subscription-success', $message);
    }

    public function toggleRebill(): RedirectResponse|JsonResponse
    {
        $subId = request()->get('id');
        $userSubscriptionId = (int) request()->get('user_subscription_id');
        $action = request()->get('action'); // 'enable' or 'disable'

        $userSub = $this->findUserSubscriptionForCard((int) $subId, $userSubscriptionId > 0 ? $userSubscriptionId : null);

        if ($userSub) {
            $sub = $userSub->subscription ?: Subscription::where('id', $subId)->first();

            if ($userSub->isLegacyVpnPlan() && $sub && trim((string) $sub->name) === 'VPN') {
                $message = 'Для старого тарифа автопродление недоступно. Выберите новый тариф со следующего периода.';

                if (request()->expectsJson()) {
                    return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
                }

                return redirect()->back()->with('subscription-error', $message);
            }

            if ($action === 'disable') {
                $userSub->update(['is_rebilling' => false]);

                if (request()->expectsJson()) {
                    return $this->subscriptionCardJson($sub, 'Автопродление отключено', (int) $userSub->id);
                }
                return redirect()->back()->with('subscription-success', 'Автопродление отключено');
            } elseif ($action === 'enable') {
                $userSub->update(['is_rebilling' => true]);

                if (request()->expectsJson()) {
                    return $this->subscriptionCardJson($sub, 'Автопродление включено', (int) $userSub->id);
                }
                return redirect()->back()->with('subscription-success', 'Автопродление включено');
            }
        }

        if (request()->expectsJson()) {
            return response()->json(['message' => 'Ошибка при изменении автопродления'], 400, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return redirect()->back()->with('subscription-error', 'Ошибка при изменении автопродления');
    }

    public function switchVpnAccessMode(Request $request, SubscriptionVpnAccessModeSwitcher $switcher): RedirectResponse|JsonResponse
    {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Смена типа подключения недоступна.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Смена типа подключения недоступна.');
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
            'vpn_access_mode' => ['required', 'string', 'max:32'],
        ]);

        $userSub = $this->findVisibleUserSubscription((int) $data['user_subscription_id']);
        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        if ($userSub->hasPendingVpnAccessModeSwitch()) {
            $message = $this->vpnAccessModePendingMessage($userSub);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 409, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        $targetMode = Server::normalizeVpnAccessMode((string) $data['vpn_access_mode']);
        if (!$userSub->canSwitchToVpnAccessMode($targetMode)) {
            $message = $targetMode === Server::VPN_ACCESS_WHITE_IP
                ? 'Для текущего тарифа подключение при ограничениях недоступно.'
                : 'Смена типа подключения недоступна.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        if ($userSub->resolveVpnAccessMode() === $targetMode) {
            if ($request->expectsJson()) {
                return $this->subscriptionCardJson($userSub->subscription, 'Этот тип уже выбран.', (int) $userSub->id);
            }

            return redirect()->back()->with('subscription-success', 'Этот тип уже выбран.');
        }

        try {
            $updated = $switcher->switchWithGracePeriod($userSub, $targetMode);
        } catch (\Throwable $e) {
            \Log::error('Subscription vpn access mode switch error: ' . $e->getMessage(), [
                'user_id' => (int) Auth::id(),
                'user_subscription_id' => (int) $userSub->id,
                'target_mode' => $targetMode,
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Не удалось переключить тип подключения'], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Не удалось переключить тип подключения');
        }

        $message = $this->vpnAccessModePreparedMessage($updated);

        if ($request->expectsJson()) {
            return $this->subscriptionCardJson($updated->subscription, $message, (int) $updated->id);
        }

        return redirect()->back()->with('subscription-success', $message);
    }

    public function switchMtsBetaToEconomy(
        Request $request,
        SubscriptionMtsBetaToEconomySwitcher $switcher
    ): RedirectResponse|JsonResponse {
        if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Смена тарифа недоступна.'], 403, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Смена тарифа недоступна.');
        }

        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = $this->findVisibleUserSubscription((int) $data['user_subscription_id']);
        if (!$userSub) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Подписка не найдена.'], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Подписка не найдена.');
        }

        if (!$userSub->isLocallyActive()) {
            $message = 'Перейти на Эконом можно только для активной подписки.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        if (trim((string) ($userSub->vpn_plan_code ?? '')) !== SubscriptionMtsBetaToEconomySwitcher::SOURCE_PLAN_CODE) {
            $message = 'Переход на Эконом доступен только для тарифа Для сети МТС (бета).';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', $message);
        }

        try {
            $updated = $switcher->switch($userSub);
        } catch (\Throwable $e) {
            \Log::error('Subscription MTS beta -> economy switch error: ' . $e->getMessage(), [
                'user_id' => (int) Auth::id(),
                'user_subscription_id' => (int) $userSub->id,
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Не удалось перевести тариф на Эконом'], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            return redirect()->back()->with('subscription-error', 'Не удалось перевести тариф на Эконом');
        }

        $message = 'Тариф переключён на Эконом. Скачайте новый конфиг: старый конфиг для МТС больше не работает.';

        if ($request->expectsJson()) {
            return $this->subscriptionCardJson($updated->subscription, $message, (int) $updated->id);
        }

        return redirect()->back()->with('subscription-success', $message);
    }

    private function subscriptionCardJson(?Subscription $sub, string $message, ?int $userSubscriptionId = null): JsonResponse
    {
        if (!$sub) {
            return response()->json(['message' => $message], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $userSub = $this->findUserSubscriptionForCard((int) $sub->id, $userSubscriptionId);
        if (!$userSub) {
            return response()->json(['message' => $message], 404, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $cardHtml = $this->renderSubscriptionCard($userSub);

        $payload = [
            'message' => $message,
            'card_html' => $cardHtml,
            'cards_html' => $this->renderSubscriptionRows(),
            'balance_rub' => (new Balance)->getBalanceRub(),
            'next_vpn_price_rub' => $this->getNextVpnPriceRub(),
        ];

        return response()->json($payload, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function getSubInfo(): UserSubscriptionInfo
    {
        $subList = UserSubscription::getCabinetList((int) Auth::id());
        return new UserSubscriptionInfo($subList);
    }

    private function findUserSubscriptionForCard(int $subscriptionId, ?int $userSubscriptionId = null): ?UserSubscription
    {
        if ($userSubscriptionId !== null && $userSubscriptionId > 0) {
            return UserSubscription::query()
                ->where('user_id', (int) Auth::id())
                ->where('id', $userSubscriptionId)
                ->first();
        }

        return UserSubscription::query()
            ->where('user_id', (int) Auth::id())
            ->where('subscription_id', $subscriptionId)
            ->orderBy('id', 'desc')
            ->first();
    }

    private function findVisibleUserSubscription(int $userSubscriptionId): ?UserSubscription
    {
        $subList = UserSubscription::getCabinetList((int) Auth::id());
        $current = $subList->firstWhere('id', $userSubscriptionId);

        return $current instanceof UserSubscription ? $current : null;
    }

    private function renderSubscriptionCard(UserSubscription $userSub): string
    {
        $subListForInfo = UserSubscription::getCabinetList((int) Auth::id());
        UserSubscription::attachTrafficTotals($subListForInfo);
        UserSubscription::attachTrafficPeriodUsage($subListForInfo);
        $displayUserSub = $subListForInfo->firstWhere('id', (int) $userSub->id);
        if (!$displayUserSub) {
            $displayUserSub = $subListForInfo->firstWhere('subscription_id', (int) $userSub->subscription_id);
        }

        if (!$displayUserSub) {
            $displayUserSub = $userSub;
            $displayUserSub->loadMissing('subscription');
            $subListForInfo = $subListForInfo->push($displayUserSub);
        }

        $displayUserSub->traffic_total_bytes = $displayUserSub->traffic_total_bytes ?? null;

        return view('payment.service-block__card', [
            'sub' => $displayUserSub->subscription,
            'subInfo' => new UserSubscriptionInfo($subListForInfo),
            'userSub' => $displayUserSub,
            'balance' => (new Balance)->getBalanceRub(),
        ])->render();
    }

    private function renderSubscriptionRows(): string
    {
        $subListForInfo = UserSubscription::getCabinetList((int) Auth::id());
        UserSubscription::attachTrafficTotals($subListForInfo);
        UserSubscription::attachTrafficPeriodUsage($subListForInfo);

        return view('payment.service-block__rows', [
            'cards' => $subListForInfo,
            'subInfo' => new UserSubscriptionInfo($subListForInfo),
            'balance' => (new Balance)->getBalanceRub(),
        ])->render();
    }

    private function getNextVpnPriceRub(): ?int
    {
        $userId = (int) Auth::id();
        $vpnSub = Subscription::nextAvailableVpnForUser($userId);
        if (!$vpnSub) {
            return null;
        }

        $catalog = app(VpnPlanCatalog::class);
        $pricing = app(ReferralPricingService::class);
        $referral = Auth::user();
        $referrer = $referral?->referrer;
        $basePrice = $catalog->resolveBasePriceCents($vpnSub, $catalog->defaultPurchasePlanCode());
        $finalPrice = $pricing->getFinalPriceCents($vpnSub, $referrer, $referral, $basePrice);

        return (int) ($finalPrice / 100);
    }

    private function resolveRequestedVpnPlanCode(Request $request, Subscription $subscription): ?string
    {
        if (trim((string) $subscription->name) !== 'VPN') {
            return null;
        }

        $catalog = app(VpnPlanCatalog::class);
        $planCode = trim((string) $request->input('vpn_plan_code', ''));
        if ($planCode !== '') {
            $planCode = $catalog->normalizePlanCode($planCode);

            return $catalog->isPurchasable($planCode)
                ? $planCode
                : null;
        }

        if ($request->boolean('need_white_ip')) {
            return $catalog->defaultRestrictedPlanCode();
        }

        return $catalog->defaultRegularPlanCode();
    }

    private function isLegacyVpnSubscription(?UserSubscription $userSub, ?Subscription $subscription): bool
    {
        return $userSub !== null
            && $subscription !== null
            && trim((string) $subscription->name) === 'VPN'
            && $userSub->isLegacyVpnPlan();
    }

    private function scheduledNextPlanMessage(UserSubscription $userSub, string $planCode, array $plan): string
    {
        $message = sprintf(
            'Со следующего периода будет: %s. Текущий тариф продолжит работать до конца оплаченного периода.',
            (string) ($plan['label'] ?? $planCode)
        );

        if ($userSub->vpnPlanNeedsNewConfig($plan)) {
            $message .= ' В дату продления понадобится новая инструкция и новый конфиг. Старая настройка будет работать ещё '
                . UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS
                . ' часа после продления.';
        }

        return $message;
    }

    private function vpnAccessModePreparedMessage(UserSubscription $userSub): string
    {
        $disconnectAt = $userSub->pendingVpnAccessModeDisconnectAt();
        if (!$disconnectAt) {
            return 'Новое подключение готово.';
        }

        return sprintf(
            'Новое подключение готово. Старая настройка отключится автоматически в %s МСК.',
            $disconnectAt->copy()->timezone('Europe/Moscow')->format('H:i')
        );
    }

    private function vpnAccessModePendingMessage(UserSubscription $userSub): string
    {
        $disconnectAt = $userSub->pendingVpnAccessModeDisconnectAt();
        if (!$disconnectAt) {
            return 'Новое подключение уже подготовлено. Дождитесь автоматического отключения старой настройки.';
        }

        return sprintf(
            'Новое подключение уже подготовлено. Старая настройка отключится автоматически в %s МСК.',
            $disconnectAt->copy()->timezone('Europe/Moscow')->format('H:i')
        );
    }
}
