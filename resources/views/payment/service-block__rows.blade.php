@php
    $cards = $cards ?? collect();
    $useCompactList = $cards->count() > 4;
@endphp

<div class="service-block__rows">
    @if($useCompactList)
        <div class="service-block__compact-list">
            @foreach($cards as $cardItem)
                @php
                    $userSub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true) ? $cardItem : null;
                    $sub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)
                        ? $cardItem->subscription
                        : $cardItem;
                    $cardKey = $userSub ? (int) $userSub->id : (int) $sub->id;
                    $deviceName = trim((string) ($userSub?->note ?? ''));
                    $title = $deviceName !== '' ? $deviceName : ((isset($sub->name) && trim((string) $sub->name) === 'VPN') ? 'Подключение' : (string) ($sub->name ?? 'Подписка'));
                    $planLabel = $userSub?->vpnPlanLabel()
                        ?: $userSub?->vpnAccessModeCabinetLabel()
                        ?: $userSub?->vpnAccessModeLabel();
                    $endDate = $userSub ? (string) $userSub->end_date : '';
                    $isActive = $subInfo->setUserSubscriptionId($userSub ? (int) $userSub->id : 0)->isConnected() && !$subInfo->isExpired();
                    $trafficLimitBytes = $userSub?->vpnTrafficLimitBytes();
                    $trafficPeriodBytes = isset($userSub?->traffic_period_bytes) ? (int) $userSub->traffic_period_bytes : null;
                    $trafficDisplayBytes = isset($userSub?->traffic_display_bytes) ? (int) $userSub->traffic_display_bytes : null;
                    $trafficRemainingBytes = isset($userSub?->traffic_remaining_bytes) ? (int) $userSub->traffic_remaining_bytes : null;
                    $formatTrafficGb = static function (?int $bytes): string {
                        return number_format(max(0, (int) $bytes) / 1073741824, 2, '.', ' ') . ' ГБ';
                    };
                    $trafficSummary = $trafficLimitBytes !== null && $trafficPeriodBytes !== null
                        ? ($formatTrafficGb($trafficPeriodBytes) . ' / ' . $formatTrafficGb($trafficLimitBytes))
                        : ($trafficDisplayBytes !== null ? $formatTrafficGb($trafficDisplayBytes) : '—');
                @endphp
                <button type="button"
                        class="service-block__compact-item"
                        onclick="openSubscriptionDetailsModal({{ $cardKey }})">
                    <span class="service-block__compact-main">
                        <span class="service-block__compact-title">{{ $title }}</span>
                        @if($planLabel)
                            <span class="service-block__compact-plan">{{ $planLabel }}</span>
                        @endif
                    </span>
                    <span class="service-block__compact-meta">
                        @if($endDate !== '')
                            <span class="service-block__compact-date">до {{ $endDate }}</span>
                        @endif
                        <span class="service-block__compact-traffic">{{ $trafficSummary }}</span>
                        @if($isActive)
                            <span class="service-block__compact-status">Активна</span>
                        @else
                            <span class="service-block__compact-status service-block__compact-status--muted">Подробнее</span>
                        @endif
                    </span>
                </button>

                <div id="subscription-details-modal-{{ $cardKey }}" class="fixed inset-0 z-[9998] hidden overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center px-4 py-6">
                        <div class="absolute inset-0 bg-black/50" onclick="closeSubscriptionDetailsModal({{ $cardKey }})"></div>
                        <div class="relative w-full max-w-4xl rounded-lg bg-white p-4 shadow-lg sm:p-6">
                            <div class="mb-4 flex items-center justify-between gap-4">
                                <div class="text-lg font-semibold text-gray-900">{{ $title }}</div>
                                <button type="button"
                                        class="inline-flex h-10 items-center rounded-md border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                                        onclick="closeSubscriptionDetailsModal({{ $cardKey }})">
                                    Закрыть
                                </button>
                            </div>
                            <div class="service-block__modal-card-wrap">
                                @include('payment.service-block__card')
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        @foreach($cards->chunk(2) as $row)
            <div class="service-block__row">
                @foreach($row as $cardItem)
                    <div class="service-block__col">
                        @php
                            $userSub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true) ? $cardItem : null;
                            $sub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)
                                ? $cardItem->subscription
                                : $cardItem;
                        @endphp
                        @include('payment.service-block__card')
                    </div>
                @endforeach
                @if($row->count() === 1)
                    <div class="service-block__col service-block__col--empty"></div>
                @endif
            </div>
        @endforeach
    @endif
</div>
