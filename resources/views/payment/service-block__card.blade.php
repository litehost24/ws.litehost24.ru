@php
    /** @var \App\Models\UserSubscription|null $userSub */
    $userSub = $userSub ?? null;
    $sub = $sub ?? $userSub?->subscription;
    $subDisplayName = (isset($sub->name) && trim((string) $sub->name) === 'VPN')
        ? 'Доступ'
        : (string) ($sub->name ?? '');

    if ($userSub) {
        $subInfo->setUserSubscriptionId((int) $userSub->id);
    } else {
        $subInfo->setSubId((int) $sub->id);
    }

    $cardKey = $userSub ? (int) $userSub->id : (int) $sub->id;
    $instructionTargetId = $cardKey;
    $userSubscriptionQuery = $userSub ? '&user_subscription_id=' . (int) $userSub->id : '';
    $vpnAccessModeLabel = $userSub?->vpnAccessModeLabel();
    $vpnPlanLabel = $userSub?->vpnPlanLabel();
    $displayPlanLabel = $vpnPlanLabel && $vpnPlanLabel !== $vpnAccessModeLabel ? $vpnPlanLabel : null;
    $switchTargetMode = $userSub?->switchTargetVpnAccessMode();
    $switchTargetLabel = $switchTargetMode ? (\App\Models\Server::vpnAccessModeOptions()[$switchTargetMode] ?? null) : null;
    $canSwitchVpnAccessMode = $userSub?->canSwitchVpnAccessMode() ?? false;
    $isLegacyVpnCard = $userSub
        ? trim((string) ($userSub->vpn_plan_code ?? '')) === ''
        : false;
    $nextVpnPlanCode = trim((string) ($userSub->next_vpn_plan_code ?? ''));
    $nextVpnPlanLabel = $userSub?->nextVpnPlanLabel();
    $pendingVpnAccessModeDisconnectAt = $userSub?->pendingVpnAccessModeDisconnectAt();
    $hasPendingVpnAccessModeSwitch = $userSub?->hasPendingVpnAccessModeSwitch() ?? false;
    $pendingVpnAccessModeText = $pendingVpnAccessModeDisconnectAt
        ? 'Новое подключение готово. Старая настройка отключится автоматически в '
            . $pendingVpnAccessModeDisconnectAt->copy()->timezone('Europe/Moscow')->format('H:i')
            . ' МСК.'
        : null;
    $switchWarningText = 'Сейчас подготовим новое подключение и откроем новую инструкцию. Старая настройка продолжит работать ещё 5 минут, затем отключится автоматически.';
    $instructionUrl = $userSub
        ? route('user-subscription.instruction', [
            'user_subscription_id' => (int) $userSub->id,
            'protocol' => 'tabbed',
        ])
        : '';
    $formatTrafficGb = static function (?int $bytes): ?string {
        if ($bytes === null) {
            return null;
        }

        return number_format(max(0, $bytes) / 1073741824, 2, '.', ' ') . ' ГБ';
    };
    $topupOptions = app(\App\Services\VpnTopupCatalog::class)->all();
    $legacyNextPlanOptions = [];
    $showLegacyNextPlanSection = false;
    if (
        $userSub
        && $isLegacyVpnCard
        && $sub
        && trim((string) ($sub->name ?? '')) === 'VPN'
        && $subInfo->isConnected()
        && !$subInfo->isExpired()
    ) {
        $legacyNextPlanOptions = app(\App\Services\VpnPlanCatalog::class)->purchaseOptions(
            $sub,
            Auth::user()?->referrer,
            Auth::user()
        );
        $showLegacyNextPlanSection = !empty($legacyNextPlanOptions);
    }
@endphp
<div
    class="service-block__card {{ $subInfo->isConnected() ? '--active' : '' }}"
    data-sub-id="{{ $sub->id }}"
    data-card-key="{{ $cardKey }}"
    @if($userSub)
        data-user-sub-id="{{ $userSub->id }}"
    @endif
>
    @php
        // Balance is passed from MyController as RUB; subscription price is stored in cents.
        $balanceCents = (int) (($balance ?? 0) * 100);
        $priceCents = (int) ($userSub?->price ?? ($sub->price ?? 0));
        $hasMoneyNow = $priceCents > 0 && $balanceCents >= $priceCents;
        $isAwaitingPayment = $subInfo->isAwaitingPayment();
        $canConnectNow = $isAwaitingPayment && $hasMoneyNow && !$subInfo->isConnected();

        $isBlocked = $isAwaitingPayment || $subInfo->isExpired();
        $isSoon = $subInfo->isExpiringSoon(7);
        $trafficTotalBytes = isset($userSub?->traffic_total_bytes) ? (int) $userSub->traffic_total_bytes : null;
        $trafficGb = $trafficTotalBytes !== null ? ($trafficTotalBytes / 1073741824) : null;
        $trafficPeriodBytes = isset($userSub?->traffic_period_bytes) ? (int) $userSub->traffic_period_bytes : null;
        $trafficTopupBytes = isset($userSub?->traffic_topup_bytes) ? (int) $userSub->traffic_topup_bytes : 0;
        $trafficAvailableBytes = isset($userSub?->traffic_available_bytes) ? (int) $userSub->traffic_available_bytes : null;
        $trafficRemainingBytes = isset($userSub?->traffic_remaining_bytes) ? (int) $userSub->traffic_remaining_bytes : null;
        $trafficLimitBytes = $userSub?->vpnTrafficLimitBytes();
        $showTopupSection = $userSub && $userSub->isLocallyActive() && $trafficLimitBytes !== null && !empty($topupOptions);
        $topupExpiresAt = null;
        if ($showTopupSection) {
            try {
                $topupExpiresAt = \Illuminate\Support\Carbon::parse((string) $userSub->end_date);
            } catch (\Throwable) {
                $topupExpiresAt = null;
            }
        }

        if ($subInfo->isExpired()) {
            $statusColorClass = 'text-red-600';
        } elseif ($isAwaitingPayment) {
            $statusColorClass = 'text-red-600';
        } elseif ($isSoon) {
            $statusColorClass = $hasMoneyNow ? 'text-green-600' : 'text-yellow-600';
        } else {
            $statusColorClass = 'text-green-600';
        }

        $connectedStatusClass = 'text-gray-600';
        if ($subInfo->isExpired()) {
            $connectedStatusClass = 'text-red-600';
        } elseif ($isSoon) {
            $connectedStatusClass = $hasMoneyNow ? 'text-green-600' : 'text-yellow-600';
        }
    @endphp
    @if (in_array(Auth::user()->role, ['user', 'admin', 'partner'], true))
        @php
            $currentNote = trim((string) $subInfo->getNote());
        @endphp
    @endif
    <div class="service-block__header">
        <div class="service-block__title-row">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5"
                 class="w-6 h-6 stroke-gray-400 service-block__title-icon"
            >
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25
                       2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
            <h2 class="text-lg font-semibold text-gray-900 service-block__title">
                <span>{{ $subDisplayName }}</span>
            </h2>
            @if (in_array(Auth::user()->role, ['user', 'admin', 'partner'], true))
                <div class="service-block__note">
                    <div class="js-note-view service-block__note-view">
                        <span class="service-block__note-paren">(</span>
                        <span class="js-note-value service-block__note-value truncate">{{ $currentNote !== '' ? $currentNote : 'Без пометки' }}</span>
                        <span class="service-block__note-paren">)</span>
                        <button
                            type="button"
                            class="js-note-edit-btn service-block__note-edit-btn"
                            title="Изменить пометку"
                            aria-label="Изменить пометку"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-8.9 8.9a2 2 0 01-.878.51l-2.39.717a.75.75 0 01-.931-.931l.717-2.39a2 2 0 01.51-.878l8.9-8.9z"/>
                            </svg>
                        </button>
                    </div>

                    <form method="POST" action="{{ route('user-subscription.update-note') }}" class="js-note-form service-block__note-form --hidden mt-2">
                        @csrf
                        <input type="hidden" name="subscription_id" value="{{ $sub->id }}">
                        @if ($userSub)
                            <input type="hidden" name="user_subscription_id" value="{{ $userSub->id }}">
                        @endif
                        <div class="service-block__note-input-wrap">
                            <input type="text" name="note" maxlength="255" class="w-full h-10 rounded-md border border-gray-300 px-3 text-sm" value="{{ $currentNote }}" placeholder="Без пометки">
                        </div>
                        <div class="service-block__note-actions inline-flex items-center gap-2">
                            <button type="submit" class="service-block__note-btn inline-flex h-10 items-center rounded-md border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Сохранить
                            </button>
                            <button type="button" class="js-note-cancel-btn inline-flex h-10 items-center rounded-md border border-gray-200 px-3 text-sm font-semibold text-gray-500 hover:bg-gray-50">
                                Отмена
                            </button>
                        </div>
                        <span class="js-note-status service-block__note-status text-xs text-gray-500"></span>
                    </form>
                </div>
            @endif
            @if ($vpnAccessModeLabel)
                <span class="service-block__mode-badge">{{ $vpnAccessModeLabel }}</span>
            @endif
            @if ($displayPlanLabel)
                <span class="service-block__mode-badge service-block__mode-badge--plan">{{ $displayPlanLabel }}</span>
            @endif
        </div>
        <div class="service-block__status-row">
            @if ($subInfo->isProcessing())
                <span class="text-orange service-block__status">
                    Заявка в обработке на подключение
                </span>
            @elseif ($subInfo->isRebillActive())
                @if ($isLegacyVpnCard)
                    <span class="{{ $statusColorClass }} service-block__status">
                        старый тариф действует до {{ $subInfo->getEndDate() }}
                        @if ($nextVpnPlanLabel)
                            , затем — {{ $nextVpnPlanLabel }}
                        @endif
                    </span>
                @else
                    <span class="{{ $statusColorClass }} service-block__status">
                        очередное списание {{ $subInfo->getEndDate() }}
                    </span>
                @endif
            @elseif ($subInfo->isConnected())
                <span class="{{ $connectedStatusClass }} service-block__status">
                    подписка истекает {{ $subInfo->getEndDate() }}
                </span>
            @endif
            @if ($trafficGb !== null)
                <span class="text-gray-600 service-block__status service-block__status--traffic block">
                    трафик Amnezia: {{ number_format($trafficGb, 2, '.', ' ') }} ГБ
                </span>
            @endif
            @if ($trafficLimitBytes !== null && $trafficPeriodBytes !== null)
                <span class="text-gray-600 service-block__status service-block__status--traffic block">
                    пакет периода: {{ $formatTrafficGb($trafficLimitBytes) }}
                    @if ($trafficTopupBytes > 0)
                        · докуплено: {{ $formatTrafficGb($trafficTopupBytes) }}
                        · всего: {{ $formatTrafficGb($trafficAvailableBytes ?? ($trafficLimitBytes + $trafficTopupBytes)) }}
                    @endif
                    · использовано: {{ $formatTrafficGb($trafficPeriodBytes) }}
                    · осталось: {{ $formatTrafficGb($trafficRemainingBytes ?? max(0, ($trafficAvailableBytes ?? $trafficLimitBytes) - $trafficPeriodBytes)) }}
                </span>
            @endif
        </div>
    </div>

    @if ($subInfo->isConnected() && !$subInfo->isExpired())
        <div class="service-block__instruction-cta">
            <div class="service-block__instruction-copy">
                <div class="service-block__instruction-title">Инструкция</div>
                <div class="service-block__instruction-text">Пошаговая настройка для Android, ПК и iPhone.</div>
            </div>
            <button type="button"
                    onclick="openInstructionModal({{ $instructionTargetId }})"
                    class="service-block__action-btn service-block__action-btn--instruction"
                    title="Открыть инструкцию по подключению">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="service-block__instruction-icon" aria-hidden="true">
                    <path d="M5 3.75A2.75 2.75 0 0 0 2.25 6.5v7A2.75 2.75 0 0 0 5 16.25h10A2.75 2.75 0 0 0 17.75 13.5v-7A2.75 2.75 0 0 0 15 3.75H5ZM4.75 6.5c0-.138.112-.25.25-.25h10c.138 0 .25.112.25.25v7a.25.25 0 0 1-.25.25H5a.25.25 0 0 1-.25-.25v-7ZM6.5 8a.75.75 0 0 0 0 1.5h7a.75.75 0 0 0 0-1.5h-7Zm0 2.5a.75.75 0 0 0 0 1.5H11a.75.75 0 0 0 0-1.5H6.5Z"/>
                </svg>
                <span>Открыть инструкцию</span>
            </button>
        </div>
    @endif

    @if ($showLegacyNextPlanSection)
        <div class="service-block__legacy-plan">
            <div class="service-block__legacy-plan-head">
                <span class="service-block__legacy-plan-badge">Старый тариф</span>
                <div class="service-block__legacy-plan-title">Этот тариф больше не оформляется.</div>
                @if ($nextVpnPlanLabel)
                    <div class="service-block__legacy-plan-next">
                        Со следующего периода будет: <strong>{{ $nextVpnPlanLabel }}</strong>
                    </div>
                @endif
            </div>
            <details class="service-block__legacy-plan-details">
                <summary class="service-block__legacy-plan-summary">
                    {{ $nextVpnPlanLabel ? 'Изменить тариф на следующий период' : 'Выбрать новый тариф со следующего периода' }}
                </summary>
                <div class="service-block__legacy-plan-body">
                <div class="service-block__legacy-plan-hint">
                        Текущий тариф продолжит работать до конца оплаченного периода. Без выбора нового тарифа подписка остановится в дату окончания.
                </div>
                    <form method="POST" action="{{ route('user-subscription.next-vpn-plan') }}" class="service-block__legacy-plan-form">
                        @csrf
                        <input type="hidden" name="user_subscription_id" value="{{ (int) $userSub->id }}">
                        <label class="service-block__legacy-plan-field">
                            <span class="service-block__legacy-plan-label">Новый тариф</span>
                            <select name="vpn_plan_code" class="service-block__legacy-plan-select" required>
                                <option value="" disabled {{ $nextVpnPlanCode === '' ? 'selected' : '' }}>Выберите тариф</option>
                                @foreach ($legacyNextPlanOptions as $plan)
                                    @php
                                        $planSuffix = ($plan['traffic_limit_gb'] ?? null) !== null
                                            ? ($plan['traffic_limit_gb'] . ' ГБ')
                                            : 'безлимит';
                                        $planIcon = (($plan['vpn_access_mode'] ?? null) === \App\Models\Server::VPN_ACCESS_REGULAR)
                                            ? '🏠'
                                            : '📶';
                                    @endphp
                                    <option value="{{ $plan['code'] }}" {{ $nextVpnPlanCode === (string) $plan['code'] ? 'selected' : '' }}>
                                        {{ $planIcon }} {{ $plan['label'] }} — {{ (int) ($plan['final_price_rub'] ?? 0) }} ₽/мес · {{ $planSuffix }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="service-block__action-btn service-block__action-btn--secondary service-block__legacy-plan-submit">
                            Сохранить тариф на следующий период
                        </button>
                    </form>
                </div>
            </details>
        </div>
    @endif

    @if ($showTopupSection)
        <div class="service-block__topup">
            <div class="service-block__topup-title">Докупить трафик</div>
            <div class="service-block__topup-meta">
                <span>Включено: {{ $formatTrafficGb($trafficLimitBytes) }}</span>
                <span>Докуплено: {{ $formatTrafficGb($trafficTopupBytes) }}</span>
                <span>Всего доступно: {{ $formatTrafficGb($trafficAvailableBytes ?? ($trafficLimitBytes + $trafficTopupBytes)) }}</span>
                @if ($topupExpiresAt)
                    <span>До {{ $topupExpiresAt->format('d.m.Y') }}</span>
                @endif
            </div>
            <div class="service-block__topup-warning">
                <strong>Важно:</strong> дополнительный трафик действует только до конца текущего периода подписки. Неиспользованный остаток на следующий период не переносится.
            </div>
            <div class="service-block__topup-grid">
                @foreach ($topupOptions as $topup)
                    <form method="POST" action="{{ route('user-subscription.topup') }}" class="service-block__topup-form">
                        @csrf
                        <input type="hidden" name="user_subscription_id" value="{{ (int) $userSub->id }}">
                        <input type="hidden" name="topup_code" value="{{ $topup['code'] }}">
                        <button type="submit" class="service-block__topup-btn">
                            <span class="service-block__topup-btn-label">Докупить {{ $topup['label'] }}</span>
                            <span class="service-block__topup-btn-price">{{ (int) $topup['price_rub'] }} ₽</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    @if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true))
        <p class="mt-4 text-gray-500 text-sm leading-relaxed">
            {{ $sub->description }}
        </p>
    @endif

        <div class="mt-3 text-sm service-block__bottom-side">
        @if (!$isLegacyVpnCard)
            @if (!$subInfo->isRebillActive())
                <a href="{{ $subInfo->isConnected() ? '/user-subscription/toggle-rebill?action=enable&id=' . $sub->id . $userSubscriptionQuery : '/user-subscription/connect?id=' . $sub->id . $userSubscriptionQuery }}"
                   class="js-sub-action service-block__action-btn service-block__action-btn--primary"
                   data-action="connect">
                    @if ($subInfo->isConnected())
                        Включить автопродление <span class="service-block__action-arrow">→</span>
                    @elseif ($canConnectNow)
                        Подключить <span class="service-block__action-arrow">→</span>
                    @else
                        Подключить <span class="service-block__action-arrow">→</span>
                    @endif
                </a>
            @else
                <a href="/user-subscription/toggle-rebill?action=disable&id={{ $sub->id }}{{ $userSubscriptionQuery }}"
                   class="js-sub-action service-block__action-btn service-block__action-btn--danger"
                   data-confirm="Вы уверены, что хотите отключить автопродление?">
                    Отключить автопродление
                </a>
            @endif
        @endif

        @if ($isLegacyVpnCard && $canSwitchVpnAccessMode && $switchTargetMode && $switchTargetLabel && $userSub)
            <a href="{{ route('user-subscription.switch-vpn-access-mode', ['user_subscription_id' => (int) $userSub->id, 'vpn_access_mode' => $switchTargetMode]) }}"
               class="js-sub-action service-block__action-btn service-block__action-btn--secondary"
               data-action="switch-mode"
               data-overlay-message="Готовим новое подключение..."
               data-confirm="{{ $switchWarningText }}"
               title="{{ $switchWarningText }}"
               aria-label="{{ $switchWarningText }}">
                Переключить на {{ $switchTargetMode === \App\Models\Server::VPN_ACCESS_WHITE_IP ? 'подключение при ограничениях' : 'обычное подключение' }}
            </a>
        @endif

        @if ($subInfo->isConnected() && !$subInfo->isExpired())
            <div id="instruction-modal-{{ $instructionTargetId }}" class="fixed inset-0 z-[9999] hidden overflow-y-auto" data-instruction-url="{{ $instructionUrl }}" data-instruction-loaded="0">
                <div class="absolute inset-0 bg-black/50" onclick="closeInstructionModal({{ $instructionTargetId }})"></div>
                <div class="relative instruction-modal-dialog rounded-lg bg-white p-6 shadow-lg instruction-modal-content">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Инструкция подключения</h3>
                        <button type="button" class="w-9 h-9 inline-flex items-center justify-center rounded-full text-xl font-semibold text-gray-500 hover:text-gray-700 hover:bg-gray-100" onclick="closeInstructionModal({{ $instructionTargetId }})">×</button>
                    </div>
                    <div class="mt-4 text-sm text-gray-700 instruction-modal-body" data-instruction-body>
                        <div class="text-center text-gray-500">Загрузка инструкции…</div>
                    </div>
                </div>
            </div>
        @endif

        <div class="service-block__price">
            <span>{{ number_format($priceCents / 100, 2, '.', ' ') }} ₽</span> / месяц
        </div>
        @if ($hasPendingVpnAccessModeSwitch && $pendingVpnAccessModeText)
            <div class="service-block__switch-pending">
                {{ $pendingVpnAccessModeText }}
            </div>
        @elseif ($isLegacyVpnCard && $canSwitchVpnAccessMode && $subInfo->isConnected() && !$subInfo->isExpired())
            <div class="service-block__switch-hint">
                После переключения старая настройка будет работать ещё 5 минут, затем отключится автоматически.
            </div>
        @endif
    </div>
</div>
