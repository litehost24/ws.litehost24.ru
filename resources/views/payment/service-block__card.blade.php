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
    $switchTargetMode = $userSub?->switchTargetVpnAccessMode();
    $switchTargetLabel = $switchTargetMode ? (\App\Models\Server::vpnAccessModeOptions()[$switchTargetMode] ?? null) : null;
    $canSwitchVpnAccessMode = $userSub?->canSwitchVpnAccessMode() ?? false;
    $switchWarningText = 'После переключения старый AmneziaWG-конфиг перестанет работать. VLESS не изменится. Нужно будет скачать и загрузить новый AmneziaWG-конфиг.';
    $instructionUrl = $userSub
        ? route('user-subscription.instruction', [
            'user_subscription_id' => (int) $userSub->id,
            'protocol' => 'tabbed',
        ])
        : '';
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
        $trafficTotalBytesVless = isset($userSub?->traffic_total_bytes_vless) ? (int) $userSub->traffic_total_bytes_vless : null;
        $trafficGb = $trafficTotalBytes !== null ? ($trafficTotalBytes / 1073741824) : null;
        $trafficGbVless = $trafficTotalBytesVless !== null ? ($trafficTotalBytesVless / 1073741824) : null;

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
        </div>
        <div class="service-block__header__right-side">
            @if ($subInfo->getFileUrl() && !$subInfo->isExpired() && !$subInfo->isAwaitingPayment())
                <a href="{{ $subInfo->getFileUrl() }}" download class="service-block__download-link" title="Скачать архив с инструкцией и конфигами">
                    Скачать
                </a>
            @endif
        </div>
        <div class="service-block__status-row">
            @if ($subInfo->isProcessing())
                <span class="text-orange service-block__status">
                    Заявка в обработке на подключение
                </span>
            @elseif ($subInfo->isRebillActive())
                <span class="{{ $statusColorClass }} service-block__status">
                    очередное списание {{ $subInfo->getEndDate() }}
                </span>
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
            @if ($trafficGbVless !== null)
                <span class="text-gray-600 service-block__status service-block__status--traffic block">
                    трафик VLESS: {{ number_format($trafficGbVless, 2, '.', ' ') }} ГБ
                </span>
            @endif
        </div>
    </div>

    @if (!in_array(Auth::user()->role, ['user', 'admin', 'partner'], true))
        <p class="mt-4 text-gray-500 text-sm leading-relaxed">
            {{ $sub->description }}
        </p>
    @endif

        <div class="mt-3 text-sm service-block__bottom-side">
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

        @if ($canSwitchVpnAccessMode && $switchTargetMode && $switchTargetLabel && $userSub)
            <a href="{{ route('user-subscription.switch-vpn-access-mode', ['user_subscription_id' => (int) $userSub->id, 'vpn_access_mode' => $switchTargetMode]) }}"
               class="js-sub-action service-block__action-btn service-block__action-btn--secondary"
               data-action="switch-mode"
               data-overlay-message="Переключаем тип подключения..."
               data-confirm="После переключения старый AmneziaWG-конфиг перестанет работать. VLESS останется без изменений. Нужно будет скачать и загрузить новый AmneziaWG-конфиг. Продолжить?"
               title="{{ $switchWarningText }}"
               aria-label="{{ $switchWarningText }}">
                Переключить на {{ $switchTargetMode === \App\Models\Server::VPN_ACCESS_WHITE_IP ? 'белый IP' : 'обычный IP' }}
            </a>
        @endif

        @if ($subInfo->isConnected() && !$subInfo->isExpired())
            <div class="service-block__instruction-wrap">
                <button type="button"
                        onclick="openInstructionModal({{ $instructionTargetId }})"
                        class="service-block__action-btn service-block__action-btn--secondary"
                        title="Открыть инструкцию по подключению">
                    Инструкция
                </button>
            </div>
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
        @if ($canSwitchVpnAccessMode && $subInfo->isConnected() && !$subInfo->isExpired())
            <div class="service-block__switch-hint">
                После переключения старый AmneziaWG-конфиг перестанет работать. VLESS не изменится.
            </div>
        @endif
    </div>
</div>
