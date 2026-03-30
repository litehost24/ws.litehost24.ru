<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg text-gray-800 leading-tight">
            {{ __('Администрирование подписок') }}
        </h2>
    </x-slot>

    <div class="py-4" style="margin-bottom:-16px;">
        <div class="w-full px-2 sm:px-3 lg:px-4">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-4 bg-white border-b border-gray-200">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="text-lg font-medium">Все подписки пользователей</h3>

                        <div class="flex space-x-2">
                            <a href="{{ route('admin.subscriptions.index', ['status' => 'all']) }}"
                               class="px-4 py-2 text-sm font-medium rounded-md {{ $statusFilter !== 'active' && $statusFilter !== 'inactive' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' }}">
                                Все
                            </a>
                            <a href="{{ route('admin.subscriptions.index', ['status' => 'active']) }}"
                               class="px-4 py-2 text-sm font-medium rounded-md {{ $statusFilter === 'active' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' }}">
                                Активные
                            </a>
                            <a href="{{ route('admin.subscriptions.index', ['status' => 'inactive']) }}"
                               class="px-4 py-2 text-sm font-medium rounded-md {{ $statusFilter === 'inactive' ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700' }}">
                                Неактивные
                            </a>
                        </div>
                    </div>

                    @if (session('subscription-success'))
                        <div class="mb-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            {{ session('subscription-success') }}
                        </div>
                    @endif

                    @if (session('subscription-error'))
                        <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                            {{ session('subscription-error') }}
                        </div>
                    @endif

                    @php
                        $networkSummary = $userEndpointNetworkSummary ?? [];
                        $formatTopOperators = function ($items) {
                            if (!is_array($items) || count($items) === 0) {
                                return 'нет данных';
                            }

                            return collect($items)->map(function ($item) {
                                $label = trim((string) ($item['label'] ?? ''));
                                $count = (int) ($item['count'] ?? 0);

                                return $label !== '' ? ($label . ': ' . $count) : null;
                            })->filter()->implode(' · ');
                        };
                    @endphp

                    @if(($networkSummary['fresh_users_total'] ?? 0) > 0)
                        <div class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="font-semibold text-slate-900">Сети по endpoint за 24ч</span>
                                <span>Пользователей: <span class="font-semibold">{{ $networkSummary['fresh_users_total'] ?? 0 }}</span></span>
                                <span>Мобильные: <span class="font-semibold text-sky-700">{{ $networkSummary['mobile_count'] ?? 0 }}</span> ({{ number_format((float) ($networkSummary['mobile_percent'] ?? 0), 1, '.', '') }}%)</span>
                                <span>Проводные: <span class="font-semibold text-emerald-700">{{ $networkSummary['fixed_count'] ?? 0 }}</span> ({{ number_format((float) ($networkSummary['fixed_percent'] ?? 0), 1, '.', '') }}%)</span>
                                <span>Хостинг/прокси: <span class="font-semibold text-amber-700">{{ $networkSummary['hosting_count'] ?? 0 }}</span></span>
                                @if(($networkSummary['unknown_count'] ?? 0) > 0)
                                    <span>Неопределено: <span class="font-semibold text-slate-600">{{ $networkSummary['unknown_count'] ?? 0 }}</span></span>
                                @endif
                            </div>
                            <div class="mt-2 text-xs text-slate-600">
                                <span class="font-semibold text-slate-800">Крупнейшие мобильные:</span>
                                {{ $formatTopOperators($networkSummary['top_mobile'] ?? []) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                <span class="font-semibold text-slate-800">Крупнейшие fixed:</span>
                                {{ $formatTopOperators($networkSummary['top_fixed'] ?? []) }}
                            </div>
                        </div>
                    @endif

                    <div class="mb-2 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('admin.subscriptions.migrate') }}" class="flex flex-wrap items-center gap-3">
                            @csrf
                            <label class="text-sm text-gray-600">Обновить подписки</label>
                            <select name="server_id" class="rounded-md border border-gray-300 p-1 text-sm" {{ ($migration && $migration->status === 'running') ? 'disabled' : '' }}>
                                @foreach($servers as $server)
                                    <option value="{{ $server->id }}" {{ (int)($migration?->server_id ?? $selectedServerId) === (int)$server->id ? 'selected' : '' }}>
                                        Сервер #{{ $server->id }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="number" name="batch_size" min="1" max="1000" class="w-24 rounded-md border border-gray-300 p-1 text-sm" value="{{ $migration?->batch_size ?? 100 }}" {{ ($migration && $migration->status === 'running') ? 'disabled' : '' }}>
                            <button type="submit"
                                    class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 {{ ($migration && $migration->status === 'running') ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ ($migration && $migration->status === 'running') ? 'disabled' : '' }}>
                                Запустить
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.subscriptions.migrate') }}" class="flex flex-wrap items-center gap-3">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $migration?->server_id ?? $selectedServerId }}">
                            <input type="hidden" name="batch_size" value="{{ $migration?->batch_size ?? 100 }}">
                            <input type="hidden" name="resume" value="1">
                            <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" {{ ($migration && $migration->status === 'running') ? '' : 'disabled' }}>
                                Продолжить
                            </button>
                        </form>
                        <form method="GET" action="{{ route('admin.subscriptions.stop') }}" class="flex flex-wrap items-center gap-3">
                            <button type="submit"
                                    class="inline-flex items-center rounded-md border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50 {{ ($migration && $migration->status === 'running') ? '' : 'opacity-50 cursor-not-allowed' }}"
                                    {{ ($migration && $migration->status === 'running') ? '' : 'disabled' }}>
                                Стоп
                            </button>
                        </form>
                        @if($migration)
                            <div class="text-xs text-gray-500" id="migration-status">
                                Статус: <span id="migration-status-text">{{ $migration->status }}</span> · Обработано: <span id="migration-processed">{{ $migration->processed_count }}</span> · Ошибки: <span id="migration-errors">{{ $migration->error_count }}</span>
                            </div>
                            @if($migrationErrors && $migrationErrors->count() > 0)
                                <div class="text-xs text-red-600" id="migration-errors-text">Последние ошибки: {{ $migrationErrors->count() }}</div>
                            @else
                                <div class="text-xs text-red-600 hidden" id="migration-errors-text"></div>
                            @endif
                        @else
                            <div class="text-xs text-gray-500 hidden" id="migration-status"></div>
                            <div class="text-xs text-red-600 hidden" id="migration-errors-text"></div>
                        @endif
                        @if($latestServerId)
                            <div class="text-xs text-gray-500" id="migration-server">
                                Текущий сервер: #<span id="migration-server-id">{{ $migration?->server_id ?? $selectedServerId }}</span>
                            </div>
                        @else
                            <div class="text-xs text-gray-500 hidden" id="migration-server"></div>
                        @endif
                    </div>

                    <div class="admin-subs-scroll overflow-x-auto">
                        @php
                            $currentServerId = $migration?->server_id ?? $selectedServerId ?? $latestServerId;
                            $formatBytes = function ($bytes) {
                                if ($bytes === null) {
                                    return '-';
                                }
                                $bytes = (int) $bytes;
                                if ($bytes <= 0) {
                                    return '0 B';
                                }
                                $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                                $i = 0;
                                $value = (float) $bytes;
                                while ($value >= 1024 && $i < count($units) - 1) {
                                    $value /= 1024;
                                    $i++;
                                }
                                $prec = $i === 0 ? 0 : 2;
                                return number_format($value, $prec, '.', '') . ' ' . $units[$i];
                            };
                            $statusIcon = function ($status) {
                                $status = (string) ($status ?? '');
                                if ($status === 'working') {
                                    return '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-100 text-green-800 font-bold" title="Работает">✓</span>';
                                }
                                if ($status === 'stopped') {
                                    return '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-red-100 text-red-800 font-bold" title="Остановлена">×</span>';
                                }
                                return '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-700 font-bold" title="Неизвестно">?</span>';
                            };
                            $modeBadgeClass = function ($mode) {
                                return $mode === \App\Models\Server::VPN_ACCESS_WHITE_IP
                                    ? 'bg-sky-100 text-sky-800'
                                    : 'bg-slate-100 text-slate-700';
                            };
                            $onlineDot = function ($isOnline, $title = null) {
                                $classes = $isOnline
                                    ? 'bg-green-500 ring-2 ring-green-100'
                                    : 'bg-gray-300 ring-2 ring-gray-100';
                                $title = $title ?: ($isOnline ? 'Онлайн за 5 минут' : 'Оффлайн');

                                return '<span class="inline-block h-2.5 w-2.5 rounded-full ' . $classes . '" title="' . e($title) . '"></span>';
                            };
                            $networkBadgeConfig = function ($type, $isFresh = true) {
                                $config = match ((string) $type) {
                                    'mobile' => ['label' => 'M', 'class' => 'border-sky-200 bg-sky-100 text-sky-800'],
                                    'fixed' => ['label' => 'W', 'class' => 'border-emerald-200 bg-emerald-100 text-emerald-800'],
                                    'hosting' => ['label' => 'H', 'class' => 'border-amber-200 bg-amber-100 text-amber-800'],
                                    default => ['label' => '?', 'class' => 'border-gray-200 bg-gray-100 text-gray-600'],
                                };

                                if (!$isFresh) {
                                    $config['class'] .= ' opacity-60';
                                }

                                return $config;
                            };
                        @endphp
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'user_id', 'sort_order' => $sortBy === 'user_id' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            ID
                                            @if($sortBy === 'user_id')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'user.name', 'sort_order' => $sortBy === 'user.name' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            Имя
                                            @if($sortBy === 'user.name')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'balance', 'sort_order' => $sortBy === 'balance' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            Баланс
                                            @if($sortBy === 'balance')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'subscription_id', 'sort_order' => $sortBy === 'subscription_id' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            ID подп.
                                            @if($sortBy === 'subscription_id')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider" title="Сумма total_bytes_delta по peer_name за все дни (Amnezia)">
                                        Трафик (Amnezia)
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'end_date', 'sort_order' => $sortBy === 'end_date' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            Окончание
                                            @if($sortBy === 'end_date')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider" title="Автопродление">
                                        <a href="{{ route('admin.subscriptions.index', array_merge(request()->query(), ['sort_by' => 'is_rebilling', 'sort_order' => $sortBy === 'is_rebilling' && $sortOrder === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-gray-700">
                                            ↻
                                            @if($sortBy === 'is_rebilling')
                                                @if($sortOrder === 'asc')
                                                    <span>↑</span>
                                                @else
                                                    <span>↓</span>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        Конфиг
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider" title="Обновлено на текущий сервер">
                                        Обн.
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        Локально
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        На сервере
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        Итог
                                    </th>
                                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                        Ошибка
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @php
                                    $grouped = $userSubscriptions->groupBy('user_id');
                                @endphp
                                @foreach($grouped as $userId => $group)
                                        @php
                                            $first = $group->first();
                                            $rest = $group->slice(1);
                                            $groupOnline = $group->contains(function ($sub) {
                                                return (bool) $sub->is_online;
                                            });
                                            $hasInactiveInRest = $rest->contains(function ($sub) {
                                                return !$sub->is_active;
                                            });
                                            $groupOnlineCount = $group->filter(function ($sub) {
                                                return (bool) $sub->is_online;
                                            })->count();
                                            $firstDualProtocolTraffic = (bool) ($first->is_dual_protocol_recent ?? false);
                                            $refUser = $first->user?->referrer;
                                            $referralsCount = (int) ($first->user?->referrals_count ?? 0);
                                            $sharingSource = $group->first(function ($sub) {
                                                return ($sub->sharing_risk_level ?? null) === 'critical';
                                            }) ?? $group->first(function ($sub) {
                                                return ($sub->sharing_risk_level ?? null) === 'warning';
                                            });
                                            $sharingLevel = $sharingSource?->sharing_risk_level;
                                            $sharingTooltip = trim((string) ($sharingSource?->sharing_risk_tooltip ?? ''));
                                            $nameClasses = 'admin-subs-tooltip admin-subs-user-link text-left underline-offset-2 hover:underline';
                                            if ($sharingLevel === 'critical') {
                                                $nameClasses .= ' rounded px-1.5 py-0.5 bg-red-100 text-red-800 font-semibold';
                                            } elseif ($sharingLevel === 'warning') {
                                                $nameClasses .= ' rounded px-1.5 py-0.5 bg-amber-100 text-amber-900 font-semibold';
                                            }
                                            $refLabel = ($refUser
                                                ? ('Реферал от: ' . ($refUser->name ?? 'N/A') . ' (#' . $refUser->id . ')')
                                                : 'Без реферала')
                                                . ' · Рефералов: ' . $referralsCount;
                                            if ($sharingTooltip !== '') {
                                                $refLabel .= ' · ' . $sharingTooltip;
                                            }
                                            $firstModeLabel = $first->vpnAccessModeLabel();
                                            $firstMode = $first->resolveVpnAccessMode();
                                            $firstSwitchTargetMode = $first->switchTargetVpnAccessMode();
                                            $firstSwitchTargetLabel = $firstSwitchTargetMode ? (\App\Models\Server::vpnAccessModeOptions()[$firstSwitchTargetMode] ?? null) : null;
                                            $firstCanSwitchVpnAccessMode = (bool) ($first->canSwitchVpnAccessMode() && $firstSwitchTargetMode && $firstSwitchTargetLabel);
                                            $firstSwitchWarningText = 'После переключения старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг.';
                                            $firstSwitchTooltip = $firstSwitchTargetLabel
                                                ? ('Переключить на ' . $firstSwitchTargetLabel . '. ' . $firstSwitchWarningText)
                                                : $firstSwitchWarningText;
                                            $networkInfo = ($userEndpointNetworksByUserId ?? collect())->get((int) $userId, []);
                                            $networkBadge = $networkBadgeConfig(
                                                (string) ($networkInfo['network_type'] ?? 'unknown'),
                                                (bool) ($networkInfo['is_fresh'] ?? false)
                                            );
                                            $networkTooltipParts = [
                                                $networkInfo['network_type_label'] ?? 'Не определено',
                                                $networkInfo['operator_label'] ?? null,
                                                !empty($networkInfo['as_number']) ? ('AS' . $networkInfo['as_number']) : null,
                                                $networkInfo['endpoint_ip'] ?? null,
                                                !empty($networkInfo['seen_at']) ? ('endpoint: ' . $networkInfo['seen_at']->timezone('Europe/Moscow')->format('d.m H:i')) : null,
                                                !empty($networkInfo) && empty($networkInfo['is_fresh']) ? 'данные старше 24ч' : null,
                                            ];
                                            $networkTooltip = collect($networkTooltipParts)->filter(function ($value) {
                                                return trim((string) $value) !== '';
                                            })->implode(' · ');
                                            if ($networkTooltip === '') {
                                                $networkTooltip = 'Нет данных по endpoint';
                                            }
                                        @endphp
                                    <tr class="{{ $loop->odd ? 'bg-gray-100' : '' }}">
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1" @if($groupOnline) style="color:#15803d;font-weight:600;" @endif>
                                                @if($groupOnline)
                                                    <span aria-hidden="true" style="display:inline-block;width:6px;height:6px;border-radius:9999px;background:#22c55e;"></span>
                                                @endif
                                                {{ $first->user_id }}
                                                @if($groupOnlineCount > 0)
                                                    <span class="inline-flex items-center rounded-full bg-green-100 px-1.5 py-0.5 text-[10px] font-semibold text-green-800" title="Онлайн подписок: {{ $groupOnlineCount }} из {{ $group->count() }}">
                                                        {{ $groupOnlineCount }}/{{ $group->count() }}
                                                    </span>
                                                @endif
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            @php
                                                $instructionUrl = null;
                                                $archiveNote = trim((string) ($first->note ?? ''));
                                                $archiveTooltip = $archiveNote !== '' ? $archiveNote : 'Без пометки';
                                                if (!empty($first->id)) {
                                                    $instructionUrl = URL::signedRoute('telegram.instruction.open', [
                                                        'user_subscription_id' => (int) $first->id,
                                                    ]);
                                                }
                                            @endphp
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="admin-subs-tooltip inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full border text-[10px] font-bold {{ $networkBadge['class'] }}"
                                                        data-tooltip="{{ $networkTooltip }}"
                                                        title="{{ $networkTooltip }}"
                                                    >
                                                        {{ $networkBadge['label'] }}
                                                    </span>
                                                    <div class="flex min-w-0 flex-col">
                                                        <button
                                                            type="button"
                                                            class="{{ $nameClasses }}"
                                                            data-tooltip="{{ $refLabel }}"
                                                            data-user-id="{{ $first->user_id }}"
                                                        >
                                                            @if($first->effective_status === 'working' && $firstDualProtocolTraffic)
                                                                <span class="me-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-yellow-100 text-yellow-800 font-bold" title="Трафик в двух протоколах">!</span>
                                                            @endif
                                                            {{ $first->user->name ?? 'N/A' }}
                                                        </button>
                                                        @if($first->user?->created_at)
                                                            <span class="mt-0.5 text-[11px] leading-4 text-gray-500">
                                                                {{ $first->user->created_at->format('d.m.Y H:i') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </div>
                                                <span class="inline-flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        class="admin-subs-tooltip admin-subs-message-open inline-flex h-7 w-7 items-center justify-center rounded border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
                                                        data-tooltip="Отправить сообщение"
                                                        data-user-id="{{ $first->user_id }}"
                                                        data-user-name="{{ $first->user->name ?? '' }}"
                                                        data-user-email="{{ $first->user->email ?? '' }}"
                                                        data-default-subject="Информация от Litehost24"
                                                        aria-label="Отправить сообщение"
                                                        title="Отправить сообщение"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path d="M2.94 6.34A2 2 0 0 1 4.8 5h10.4a2 2 0 0 1 1.86 1.34L10 10.79 2.94 6.34Z" />
                                                            <path d="M2 8.24v6.26A2.5 2.5 0 0 0 4.5 17h11a2.5 2.5 0 0 0 2.5-2.5V8.24l-7.47 4.7a1 1 0 0 1-1.06 0L2 8.24Z" />
                                                        </svg>
                                                    </button>
                                                @if($instructionUrl)
                                                        <a href="{{ $instructionUrl }}" class="admin-subs-tooltip inline-flex h-7 w-7 items-center justify-center rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100" data-tooltip="{{ $archiveTooltip }}" title="Инструкция" aria-label="Открыть инструкцию" target="_blank" rel="noopener">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                <path fill-rule="evenodd" d="M4 3a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l3.414 3.414A1 1 0 0 1 16 5.414V17a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V3Zm7 0v3h3L11 3Zm-4 7a1 1 0 0 0 0 2h6a1 1 0 1 0 0-2H7Zm0 4a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2H7Z" clip-rule="evenodd" />
                                                            </svg>
                                                        </a>
                                                @endif
                                                @if($firstCanSwitchVpnAccessMode)
                                                        <form method="POST" action="{{ route('admin.subscriptions.switch-vpn-access-mode', ['userSubscription' => $first->id]) }}" onsubmit="return confirm('После переключения старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг. Продолжить?');" class="inline-block">
                                                            @csrf
                                                            <input type="hidden" name="vpn_access_mode" value="{{ $firstSwitchTargetMode }}">
                                                            <button type="submit" class="admin-subs-tooltip inline-flex h-7 items-center rounded border border-amber-200 bg-amber-50 px-2 text-xs font-semibold text-amber-800 hover:bg-amber-100" data-tooltip="{{ $firstSwitchTooltip }}" title="{{ $firstSwitchTooltip }}" aria-label="{{ $firstSwitchTooltip }}">
                                                                IP
                                                            </button>
                                                        </form>
                                                @endif
                                                @if($first->can_admin_delete)
                                                        <form method="POST" action="{{ route('admin.subscriptions.delete', ['userSubscription' => $first->id]) }}" onsubmit="return confirm('Остановить подписку на сервере и удалить запись из базы?');" class="inline-block">
                                                            @csrf
                                                            <button type="submit" class="inline-flex h-7 w-7 items-center justify-center rounded border border-red-200 bg-red-50 text-red-700 hover:bg-red-100" title="Остановить и удалить" aria-label="Остановить и удалить">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M8.5 2a1 1 0 0 0-1 1V4H5a1 1 0 1 0 0 2h.278l.798 9.579A2 2 0 0 0 8.069 17h3.862a2 2 0 0 0 1.993-1.421L14.722 6H15a1 1 0 1 0 0-2h-2.5V3a1 1 0 0 0-1-1h-3Zm2 2V3h-1v1h1Zm-1.41 4.09a1 1 0 0 1 .997.91l.3 4a1 1 0 1 1-1.994.15l-.3-4a1 1 0 0 1 .997-1.06Zm2.82 0a1 1 0 0 1 .997 1.06l-.3 4a1 1 0 1 1-1.994-.15l.3-4a1 1 0 0 1 .997-.91Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                        </form>
                                                @endif
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $first->balance / 100 }} руб.</td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-2">
                                                {!! $onlineDot((bool) $first->is_online) !!}
                                                <span>{{ $first->subscription_id }}</span>
                                            </span>
                                            @if($rest->count() > 0)
                                                <span class="inline-flex items-center ms-2 gap-1">
                                                    <button type="button" class="subs-toggle inline-flex h-5 w-5 items-center justify-center rounded border border-gray-300 bg-white text-xs font-semibold text-gray-700" data-target="subs-{{ $userId }}" title="ещё {{ $rest->count() }}">+</button>
                                                    <span class="text-xs text-gray-600">{{ $group->count() }}</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap" title="{{ $first->traffic_total_bytes === null ? '' : ((int) $first->traffic_total_bytes . ' B') }}">
                                            {{ $formatBytes($first->traffic_total_bytes) }}
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($first->end_date)->format('d.m.Y') }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            @if($first->is_rebilling)
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">✓</span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">—</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                                @if($first->file_path)
                                                    @php
                                                        $fileBase = pathinfo(basename($first->file_path), PATHINFO_FILENAME);
                                                        $parts = explode('_', $fileBase);
                                                        $displayValue = count($parts) >= 3 ? $parts[1].'_'.$parts[2] : $fileBase;
                                                    @endphp
                                                    {{ $displayValue }}
                                                @else
                                                    -
                                                @endif
                                                @if($firstModeLabel)
                                                    <div class="mt-1">
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $modeBadgeClass($firstMode) }}">
                                                            {{ $firstModeLabel }}
                                                        </span>
                                                    </div>
                                                @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            @php
                                                $serverIdFromFile = null;
                                                if (!empty($first->file_path)) {
                                                    $nameParts = explode('_', pathinfo(basename($first->file_path), PATHINFO_FILENAME));
                                                    if (count($nameParts) >= 3) {
                                                        $serverIdFromFile = $nameParts[2];
                                                    }
                                                }
                                            @endphp
                                            @if($currentServerId && $serverIdFromFile == $currentServerId)
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">✓</span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">—</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            @if($first->is_active)
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $hasInactiveInRest ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">Активна</span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Неактивна</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            @if($first->server_status === 'enabled')
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Включена</span>
                                            @elseif($first->server_status === 'disabled')
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Отключена</span>
                                            @elseif($first->server_status === 'missing')
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">Не найдена</span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-700">Неизвестно</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            {!! $statusIcon($first->effective_status) !!}
                                            @if($first->has_server_status_conflict)
                                                <span class="ms-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-yellow-100 text-yellow-800 font-bold" title="Конфликт">!</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            {{ $first->action_error ?: '-' }}
                                        </td>
                                    </tr>
                                    @foreach($rest as $sub)
                                        <tr class="subs-extra hidden {{ $loop->odd ? 'bg-gray-100' : '' }}" data-parent="subs-{{ $userId }}">
                                            <td class="px-2 py-2 whitespace-nowrap"></td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                @php
                                                    $subInstructionUrl = null;
                                                    $subArchiveNote = trim((string) ($sub->note ?? ''));
                                                    $subArchiveTooltip = $subArchiveNote !== '' ? $subArchiveNote : 'Без пометки';
                                                    $subModeLabel = $sub->vpnAccessModeLabel();
                                                    $subMode = $sub->resolveVpnAccessMode();
                                                    $subSwitchTargetMode = $sub->switchTargetVpnAccessMode();
                                                    $subSwitchTargetLabel = $subSwitchTargetMode ? (\App\Models\Server::vpnAccessModeOptions()[$subSwitchTargetMode] ?? null) : null;
                                                    $subCanSwitchVpnAccessMode = (bool) ($sub->canSwitchVpnAccessMode() && $subSwitchTargetMode && $subSwitchTargetLabel);
                                                    $subSwitchWarningText = 'После переключения старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг.';
                                                    $subSwitchTooltip = $subSwitchTargetLabel
                                                        ? ('Переключить на ' . $subSwitchTargetLabel . '. ' . $subSwitchWarningText)
                                                        : $subSwitchWarningText;
                                                    $subNetworkBadge = $networkBadgeConfig(
                                                        (string) ($sub->endpoint_network_type ?? 'unknown'),
                                                        (bool) ($sub->endpoint_network_is_fresh ?? false)
                                                    );
                                                    $subNetworkTooltipParts = [
                                                        $sub->endpoint_network_type_label ?? 'Не определено',
                                                        $sub->endpoint_network_operator_label ?? null,
                                                        !empty($sub->endpoint_network_as_number) ? ('AS' . $sub->endpoint_network_as_number) : null,
                                                        $sub->endpoint_ip ?? null,
                                                        !empty($sub->endpoint_seen_at) ? ('endpoint: ' . $sub->endpoint_seen_at->timezone('Europe/Moscow')->format('d.m H:i')) : null,
                                                        isset($sub->endpoint_network_is_fresh) && !$sub->endpoint_network_is_fresh ? 'данные старше 24ч' : null,
                                                    ];
                                                    $subNetworkTooltip = collect($subNetworkTooltipParts)->filter(function ($value) {
                                                        return trim((string) $value) !== '';
                                                    })->implode(' · ');
                                                    if ($subNetworkTooltip === '') {
                                                        $subNetworkTooltip = 'Нет данных по endpoint';
                                                    }
                                                    if (!empty($sub->id)) {
                                                        $subInstructionUrl = URL::signedRoute('telegram.instruction.open', [
                                                            'user_subscription_id' => (int) $sub->id,
                                                        ]);
                                                    }
                                                @endphp
                                                @if($subInstructionUrl)
                                                    <div class="flex items-center justify-end gap-1">
                                                        <span
                                                            class="admin-subs-tooltip inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full border text-[10px] font-bold {{ $subNetworkBadge['class'] }}"
                                                            data-tooltip="{{ $subNetworkTooltip }}"
                                                            title="{{ $subNetworkTooltip }}"
                                                        >
                                                            {{ $subNetworkBadge['label'] }}
                                                        </span>
                                                        <a href="{{ $subInstructionUrl }}" class="admin-subs-tooltip inline-flex h-7 w-7 items-center justify-center rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100" data-tooltip="{{ $subArchiveTooltip }}" title="Инструкция" aria-label="Открыть инструкцию" target="_blank" rel="noopener">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                <path fill-rule="evenodd" d="M4 3a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l3.414 3.414A1 1 0 0 1 16 5.414V17a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V3Zm7 0v3h3L11 3Zm-4 7a1 1 0 0 0 0 2h6a1 1 0 1 0 0-2H7Zm0 4a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2H7Z" clip-rule="evenodd" />
                                                            </svg>
                                                        </a>
                                                        @if($subCanSwitchVpnAccessMode)
                                                        <form method="POST" action="{{ route('admin.subscriptions.switch-vpn-access-mode', ['userSubscription' => $sub->id]) }}" onsubmit="return confirm('После переключения старый AmneziaWG-конфиг перестанет работать. Пользователю нужно будет скачать новый AmneziaWG-конфиг. Продолжить?');" class="inline-block">
                                                            @csrf
                                                            <input type="hidden" name="vpn_access_mode" value="{{ $subSwitchTargetMode }}">
                                                            <button type="submit" class="admin-subs-tooltip inline-flex h-7 items-center rounded border border-amber-200 bg-amber-50 px-2 text-xs font-semibold text-amber-800 hover:bg-amber-100" data-tooltip="{{ $subSwitchTooltip }}" title="{{ $subSwitchTooltip }}" aria-label="{{ $subSwitchTooltip }}">
                                                                IP
                                                            </button>
                                                        </form>
                                                        @endif
                                                        @if($sub->can_admin_delete)
                                                        <form method="POST" action="{{ route('admin.subscriptions.delete', ['userSubscription' => $sub->id]) }}" onsubmit="return confirm('Остановить подписку на сервере и удалить запись из базы?');" class="inline-block">
                                                            @csrf
                                                            <button type="submit" class="inline-flex h-7 w-7 items-center justify-center rounded border border-red-200 bg-red-50 text-red-700 hover:bg-red-100" title="Остановить и удалить" aria-label="Остановить и удалить">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M8.5 2a1 1 0 0 0-1 1V4H5a1 1 0 1 0 0 2h.278l.798 9.579A2 2 0 0 0 8.069 17h3.862a2 2 0 0 0 1.993-1.421L14.722 6H15a1 1 0 1 0 0-2h-2.5V3a1 1 0 0 0-1-1h-3Zm2 2V3h-1v1h1Zm-1.41 4.09a1 1 0 0 1 .997.91l.3 4a1 1 0 1 1-1.994.15l-.3-4a1 1 0 0 1 .997-1.06Zm2.82 0a1 1 0 0 1 .997 1.06l-.3 4a1 1 0 1 1-1.994-.15l.3-4a1 1 0 0 1 .997-.91Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                        </form>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap"></td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                <span class="inline-flex items-center gap-2">
                                                    {!! $onlineDot((bool) $sub->is_online) !!}
                                                    <span>{{ $sub->subscription_id }}</span>
                                                </span>
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap" title="{{ $sub->traffic_total_bytes === null ? '' : ((int) $sub->traffic_total_bytes . ' B') }}">
                                                {{ $formatBytes($sub->traffic_total_bytes) }}
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($sub->end_date)->format('d.m.Y') }}</td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                @if($sub->is_rebilling)
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">✓</span>
                                                @else
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">—</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                            @if($sub->file_path)
                                                @php
                                                    $fileBase = pathinfo(basename($sub->file_path), PATHINFO_FILENAME);
                                                    $parts = explode('_', $fileBase);
                                                    $displayValue = count($parts) >= 3 ? $parts[1].'_'.$parts[2] : $fileBase;
                                                @endphp
                                                {{ $displayValue }}
                                            @else
                                                -
                                            @endif
                                            @if($subModeLabel)
                                                <div class="mt-1">
                                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $modeBadgeClass($subMode) }}">
                                                        {{ $subModeLabel }}
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                @php
                                                    $serverIdFromFile = null;
                                                    if (!empty($sub->file_path)) {
                                                        $nameParts = explode('_', pathinfo(basename($sub->file_path), PATHINFO_FILENAME));
                                                        if (count($nameParts) >= 3) {
                                                            $serverIdFromFile = $nameParts[2];
                                                        }
                                                    }
                                                @endphp
                                                @if($currentServerId && $serverIdFromFile == $currentServerId)
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">✓</span>
                                                @else
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">—</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                @if($sub->is_active)
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Активна</span>
                                                @else
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Неактивна</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                @if($sub->server_status === 'enabled')
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Включена</span>
                                                @elseif($sub->server_status === 'disabled')
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Отключена</span>
                                                @elseif($sub->server_status === 'missing')
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">Не найдена</span>
                                                @else
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-700">Неизвестно</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                {!! $statusIcon($sub->effective_status) !!}
                                                @if($sub->has_server_status_conflict)
                                                    <span class="ms-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-yellow-100 text-yellow-800 font-bold" title="Конфликт">!</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 whitespace-nowrap">
                                                {{ $sub->action_error ?: '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="2">
                                        Всего пользователей: {{ $totalUsers }}
                                    </td>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="2">
                                        Активных: {{ $activeUsers }}
                                    </td>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="2">
                                        Онлайн: {{ $onlineUsers }}
                                    </td>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="2">
                                        Неактивных: {{ $inactiveUsers }}
                                    </td>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="2">
                                        Активных подписок: {{ $activeSubscriptions }}
                                    </td>
                                    <td class="px-2 py-2 text-xs font-semibold text-gray-700 whitespace-nowrap" colspan="4">
                                        Всего средств на счетах: {{ $totalBalance / 100 }} руб.
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                        @if(count($userSubscriptions) === 0)
                            <div class="text-center py-8">
                                <p class="text-gray-500">Нет данных о подписках пользователей</p>
                            </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="user-details-modal" class="fixed inset-0 z-[9999] hidden overflow-y-auto">
        <div class="absolute inset-0 bg-black/50" data-close-user-details></div>
        <div class="relative admin-user-details-dialog rounded-lg bg-white p-5 shadow-lg">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Детали пользователя</h3>
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xl text-gray-500 hover:bg-gray-100 hover:text-gray-700" data-close-user-details>×</button>
            </div>

            <div id="user-details-loading" class="mt-4 text-sm text-gray-500 hidden">Загрузка...</div>
            <div id="user-details-error" class="mt-4 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>

            <div id="user-details-content" class="mt-4 hidden">
                <div class="grid grid-cols-1 gap-2 text-sm text-gray-700 md:grid-cols-2">
                    <div><span class="font-semibold">ID:</span> <span id="ud-user-id"></span></div>
                    <div><span class="font-semibold">Имя:</span> <span id="ud-user-name"></span></div>
                    <div><span class="font-semibold">Email:</span> <span id="ud-user-email"></span></div>
                    <div><span class="font-semibold">Телефон:</span> <span id="ud-user-phone"></span></div>
                    <div class="md:col-span-2 flex flex-wrap items-center gap-2">
                        <span class="font-semibold">Роль:</span>
                        <span id="ud-user-role"></span>
                        <select id="ud-user-role-select" class="h-8 rounded-md border border-gray-300 px-2 text-xs">
                            <option value="spy">spy</option>
                            <option value="user">user</option>
                            <option value="partner">partner</option>
                            <option value="admin">admin</option>
                        </select>
                        <button type="button" id="ud-user-role-save" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                            Сохранить
                        </button>
                        <span id="ud-user-role-status" class="text-xs text-gray-500"></span>
                    </div>
                    <div><span class="font-semibold">Регистрация:</span> <span id="ud-user-registered"></span></div>
                    <div class="md:col-span-2"><span class="font-semibold">Реферал:</span> <span id="ud-user-referrer"></span></div>
                    <div><span class="font-semibold">Количество рефералов:</span> <span id="ud-user-referrals-count"></span></div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-xs uppercase text-gray-500">Всего поступлений</div>
                        <div id="ud-summary-payments" class="text-base font-semibold text-gray-900"></div>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-xs uppercase text-gray-500">Всего списаний</div>
                        <div id="ud-summary-charges" class="text-base font-semibold text-gray-900"></div>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-xs uppercase text-gray-500">Текущий баланс</div>
                        <div id="ud-summary-balance" class="text-base font-semibold text-gray-900"></div>
                    </div>
                </div>

                <div class="mt-5">
                    <h4 class="text-sm font-semibold text-gray-900">Платежи (успешные)</h4>
                    <div class="mt-2 max-h-52 overflow-auto rounded-md border border-gray-200">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-2 py-2 text-left">Дата</th>
                                <th class="px-2 py-2 text-left">Сумма</th>
                                <th class="px-2 py-2 text-left">Order ID</th>
                            </tr>
                            </thead>
                            <tbody id="ud-payments-body"></tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-5">
                    <h4 class="text-sm font-semibold text-gray-900">Списания и записи подписок</h4>
                    <div class="mt-2 max-h-64 overflow-auto rounded-md border border-gray-200">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-2 py-2 text-left">Дата</th>
                                <th class="px-2 py-2 text-left">Окончание</th>
                                <th class="px-2 py-2 text-left">Подписка</th>
                                <th class="px-2 py-2 text-left">Сумма</th>
                                <th class="px-2 py-2 text-left">Действие</th>
                                <th class="px-2 py-2 text-left">↻</th>
                            </tr>
                            </thead>
                            <tbody id="ud-charges-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="user-message-modal" class="fixed inset-0 z-[9999] hidden overflow-y-auto">
        <div class="absolute inset-0 bg-black/50" data-close-user-message></div>
        <div class="relative admin-user-message-dialog rounded-lg bg-white p-5 shadow-lg">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Отправить сообщение</h3>
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xl text-gray-500 hover:bg-gray-100 hover:text-gray-700" data-close-user-message>×</button>
            </div>

            <div id="user-message-status" class="mt-4 hidden rounded-md border px-3 py-2 text-sm"></div>

            <form id="user-message-form" class="mt-4 space-y-4">
                <input type="hidden" id="um-user-id">

                <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                    <div>
                        <div class="text-xs uppercase text-gray-500">Пользователь</div>
                        <div id="um-user-name" class="mt-1 font-medium text-gray-900">—</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Email</div>
                        <div id="um-user-email" class="mt-1 font-medium text-gray-900">—</div>
                    </div>
                </div>

                <div>
                    <label for="um-template" class="mb-1 block text-sm font-medium text-gray-700">Шаблон</label>
                    <select
                        id="um-template"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    >
                        <option value="">Без шаблона</option>
                    </select>
                </div>

                <div>
                    <label for="um-subject" class="mb-1 block text-sm font-medium text-gray-700">Тема</label>
                    <input
                        id="um-subject"
                        type="text"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    >
                </div>

                <div>
                    <label for="um-body" class="mb-1 block text-sm font-medium text-gray-700">Текст письма</label>
                    <textarea
                        id="um-body"
                        rows="8"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" data-close-user-message>
                        Отмена
                    </button>
                    <button type="submit" id="um-send" class="inline-flex items-center rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                        Отправить
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const statusEl = document.getElementById('migration-status');
            const statusTextEl = document.getElementById('migration-status-text');
            const processedEl = document.getElementById('migration-processed');
            const errorsEl = document.getElementById('migration-errors');
            const errorsTextEl = document.getElementById('migration-errors-text');
            const serverEl = document.getElementById('migration-server');
            const serverIdEl = document.getElementById('migration-server-id');

            async function refreshStatus() {
                try {
                    const res = await fetch('{{ route('admin.subscriptions.status') }}', {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!res.ok) return;
                    const data = await res.json();

                    if (data.migration) {
                        if (statusEl) statusEl.classList.remove('hidden');
                        if (statusTextEl) statusTextEl.textContent = data.migration.status;
                        if (processedEl) processedEl.textContent = data.migration.processed_count;
                        if (errorsEl) errorsEl.textContent = data.migration.error_count;

                        if (errorsTextEl) {
                            if (data.migration.error_count > 0) {
                                errorsTextEl.textContent = 'Последние ошибки: ' + data.migration.error_count;
                                errorsTextEl.classList.remove('hidden');
                            } else {
                                errorsTextEl.textContent = '';
                                errorsTextEl.classList.add('hidden');
                            }
                        }
                    }

                    const serverId = (data.migration && data.migration.server_id) ? data.migration.server_id : data.latest_server_id;
                    if (serverId && serverEl && serverIdEl) {
                        serverEl.classList.remove('hidden');
                        serverIdEl.textContent = serverId;
                    }

                    if (data.migration && data.migration.status === 'running') {
                        setTimeout(refreshStatus, 3000);
                    }
                } catch (e) {
                    // ignore
                }
            }

            if (statusEl && statusTextEl && statusTextEl.textContent === 'running') {
                setTimeout(refreshStatus, 1500);
            }
        })();

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.subs-toggle');
            if (!btn) return;
            const target = btn.getAttribute('data-target');
            const rows = document.querySelectorAll('tr.subs-extra[data-parent="' + target + '"]');
            if (!rows.length) return;
            const isHidden = rows[0].classList.contains('hidden');
            rows.forEach(row => row.classList.toggle('hidden'));
            btn.textContent = isHidden ? '−' : '+';
        });

        (function () {
            const tooltip = document.createElement('div');
            tooltip.style.position = 'fixed';
            tooltip.style.zIndex = '9999';
            tooltip.style.maxWidth = '320px';
            tooltip.style.padding = '8px 10px';
            tooltip.style.borderRadius = '8px';
            tooltip.style.background = '#f3f4f6';
            tooltip.style.color = '#111827';
            tooltip.style.border = '1px solid #e5e7eb';
            tooltip.style.fontSize = '12px';
            tooltip.style.lineHeight = '1.3';
            tooltip.style.boxShadow = '0 6px 18px rgba(0,0,0,.12)';
            tooltip.style.pointerEvents = 'none';
            tooltip.style.opacity = '0';
            tooltip.style.transition = 'opacity .12s ease';
            document.body.appendChild(tooltip);

            function showTip(text, x, y) {
                tooltip.textContent = text;
                const offsetX = 12;
                const offsetY = 12;
                tooltip.style.left = (x + offsetX) + 'px';
                tooltip.style.top = (y + offsetY) + 'px';
                tooltip.style.opacity = '1';
            }

            function hideTip() {
                tooltip.style.opacity = '0';
            }

            document.addEventListener('mousemove', function (e) {
                const target = e.target.closest('.admin-subs-tooltip');
                if (!target) return;
                const text = target.getAttribute('data-tooltip') || '';
                showTip(text, e.clientX, e.clientY);
            });

            document.addEventListener('mouseover', function (e) {
                const target = e.target.closest('.admin-subs-tooltip');
                if (!target) return;
                const text = target.getAttribute('data-tooltip') || '';
                showTip(text, e.clientX, e.clientY);
            });

            document.addEventListener('mouseout', function (e) {
                if (e.target.closest('.admin-subs-tooltip')) {
                    hideTip();
                }
            });
        })();

        (function () {
            const modal = document.getElementById('user-details-modal');
            const content = document.getElementById('user-details-content');
            const loading = document.getElementById('user-details-loading');
            const errorBox = document.getElementById('user-details-error');
            const links = document.querySelectorAll('.admin-subs-user-link');
            const roleSelect = document.getElementById('ud-user-role-select');
            const roleSave = document.getElementById('ud-user-role-save');
            const roleStatus = document.getElementById('ud-user-role-status');

            if (!modal || !links.length) return;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatRub(value) {
                const num = Number(value || 0);
                return num.toFixed(2) + ' руб.';
            }

            function closeModal() {
                modal.classList.add('hidden');
            }

            function openModal() {
                modal.classList.remove('hidden');
            }

            function setRows(targetId, rows, emptyText, colspan) {
                const target = document.getElementById(targetId);
                if (!target) return;
                if (!rows.length) {
                    target.innerHTML = '<tr><td class="px-2 py-2 text-gray-500" colspan="' + (colspan || 1) + '">' + escapeHtml(emptyText) + '</td></tr>';
                    return;
                }
                target.innerHTML = rows.join('');
            }

            function setRoleStatus(message, tone) {
                if (!roleStatus) return;
                roleStatus.textContent = message || '';
                roleStatus.classList.remove('text-red-600', 'text-green-600', 'text-gray-500');
                if (tone === 'error') {
                    roleStatus.classList.add('text-red-600');
                } else if (tone === 'success') {
                    roleStatus.classList.add('text-green-600');
                } else {
                    roleStatus.classList.add('text-gray-500');
                }
            }

            if (roleSave && roleSelect) {
                roleSave.addEventListener('click', async function () {
                    const userId = roleSelect.dataset.userId;
                    if (!userId) return;
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                    roleSave.disabled = true;
                    setRoleStatus('Сохранение...', 'info');

                    try {
                        const response = await fetch('/admin/users/' + encodeURIComponent(userId) + '/role', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ role: roleSelect.value }),
                        });

                        const data = await response.json().catch(function () { return {}; });
                        if (!response.ok) {
                            throw new Error(data.message || 'Ошибка сохранения');
                        }

                        document.getElementById('ud-user-role').textContent = data.role || roleSelect.value;
                        setRoleStatus('Сохранено', 'success');
                    } catch (error) {
                        setRoleStatus(error.message || 'Ошибка сохранения', 'error');
                    } finally {
                        roleSave.disabled = false;
                    }
                });
            }

            document.querySelectorAll('[data-close-user-details]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            links.forEach(function (link) {
                link.addEventListener('click', async function () {
                    const userId = link.getAttribute('data-user-id');
                    if (!userId) return;

                    openModal();
                    if (content) content.classList.add('hidden');
                    if (errorBox) {
                        errorBox.classList.add('hidden');
                        errorBox.textContent = '';
                    }
                    if (loading) loading.classList.remove('hidden');

                    try {
                        const response = await fetch('/admin/subscriptions/user/' + encodeURIComponent(userId) + '/details', {
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await response.json().catch(function () { return {}; });
                        if (!response.ok) {
                            throw new Error(data.message || 'Не удалось загрузить данные пользователя');
                        }

                        document.getElementById('ud-user-id').textContent = data.user?.id ?? '—';
                        document.getElementById('ud-user-name').textContent = data.user?.name ?? '—';
                        document.getElementById('ud-user-email').textContent = data.user?.email ?? '—';
                        document.getElementById('ud-user-phone').textContent = data.user?.phone ?? '—';
                        document.getElementById('ud-user-role').textContent = data.user?.role ?? '—';
                        if (roleSelect) {
                            roleSelect.value = data.user?.role ?? 'spy';
                            roleSelect.dataset.userId = data.user?.id ?? '';
                        }
                        setRoleStatus('', 'info');
                        document.getElementById('ud-user-registered').textContent = data.user?.registered_at ?? '—';
                        document.getElementById('ud-user-referrer').textContent = data.user?.referrer
                            ? ((data.user.referrer.name || 'N/A') + ' (#' + data.user.referrer.id + ')')
                            : 'Без реферала';
                        document.getElementById('ud-user-referrals-count').textContent = data.user?.referrals_count ?? 0;

                        document.getElementById('ud-summary-payments').textContent = formatRub(data.summary?.total_payments_rub);
                        document.getElementById('ud-summary-charges').textContent = formatRub(data.summary?.total_charges_rub);
                        document.getElementById('ud-summary-balance').textContent = formatRub(data.summary?.balance_rub);

                        const paymentRows = (data.payments || []).map(function (item) {
                            return '<tr class=\"border-t border-gray-100\">'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.created_at || '—') + '</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.amount_rub || '0.00') + ' руб.</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.order_name || '—') + '</td>'
                                + '</tr>';
                        });
                        setRows('ud-payments-body', paymentRows, 'Нет успешных платежей', 3);

                        const chargeRows = (data.charges || []).map(function (item, index) {
                            const createdDate = item.created_at ? String(item.created_at).slice(0, 10) : '—';
                            const rowClass = index % 2 === 1 ? ' bg-gray-50' : '';
                            return '<tr class=\"border-t border-gray-100' + rowClass + '\">'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(createdDate) + '</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.end_date || '—') + '</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.subscription_name || 'N/A') + ' (#' + escapeHtml(item.subscription_id) + ')</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.price_rub || '0.00') + ' руб.</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + escapeHtml(item.action || '—') + '</td>'
                                + '<td class=\"px-2 py-2 whitespace-nowrap\">' + (item.is_rebilling ? '✓' : '—') + '</td>'
                                + '</tr>';
                        });
                        setRows('ud-charges-body', chargeRows, 'Нет списаний', 6);

                        if (content) content.classList.remove('hidden');
                    } catch (error) {
                        if (errorBox) {
                            errorBox.textContent = error.message || 'Ошибка загрузки данных';
                            errorBox.classList.remove('hidden');
                        }
                    } finally {
                        if (loading) loading.classList.add('hidden');
                    }
                });
            });
        })();

        (function () {
            const modal = document.getElementById('user-message-modal');
            const form = document.getElementById('user-message-form');
            const userIdInput = document.getElementById('um-user-id');
            const nameEl = document.getElementById('um-user-name');
            const emailEl = document.getElementById('um-user-email');
            const templateSelect = document.getElementById('um-template');
            const subjectInput = document.getElementById('um-subject');
            const bodyInput = document.getElementById('um-body');
            const sendButton = document.getElementById('um-send');
            const statusBox = document.getElementById('user-message-status');
            const openButtons = document.querySelectorAll('.admin-subs-message-open');

            if (!modal || !form || !userIdInput || !templateSelect || !subjectInput || !bodyInput || !sendButton || !statusBox || !openButtons.length) {
                return;
            }

            const messageTemplates = {};

            function closeModal() {
                modal.classList.add('hidden');
            }

            function openModal() {
                modal.classList.remove('hidden');
            }

            function setStatus(message, tone) {
                statusBox.textContent = message || '';
                statusBox.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-800', 'border-green-200', 'bg-green-50', 'text-green-800', 'border-gray-200', 'bg-gray-50', 'text-gray-700');

                if (tone === 'error') {
                    statusBox.classList.add('border-red-200', 'bg-red-50', 'text-red-800');
                } else if (tone === 'success') {
                    statusBox.classList.add('border-green-200', 'bg-green-50', 'text-green-800');
                } else {
                    statusBox.classList.add('border-gray-200', 'bg-gray-50', 'text-gray-700');
                }
            }

            function resetStatus() {
                statusBox.textContent = '';
                statusBox.classList.add('hidden');
                statusBox.classList.remove('border-red-200', 'bg-red-50', 'text-red-800', 'border-green-200', 'bg-green-50', 'text-green-800', 'border-gray-200', 'bg-gray-50', 'text-gray-700');
            }

            function buildGreeting(name) {
                const trimmed = String(name || '').trim();
                if (trimmed === '') {
                    return 'Здравствуйте!';
                }

                return 'Здравствуйте, ' + trimmed + '!';
            }

            function applyTemplate(templateKey, userName, defaultSubject) {
                const greeting = buildGreeting(userName);
                const template = templateKey ? messageTemplates[templateKey] : null;

                subjectInput.value = (template && template.subject) ? template.subject : defaultSubject;
                bodyInput.value = template && template.body
                    ? greeting + '\n\n' + template.body
                    : greeting + '\n';
            }

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const userId = button.getAttribute('data-user-id') || '';
                    const userName = button.getAttribute('data-user-name') || '';
                    const userEmail = button.getAttribute('data-user-email') || '';
                    const defaultSubject = button.getAttribute('data-default-subject') || 'Информация от Litehost24';

                    userIdInput.value = userId;
                    nameEl.textContent = userName || '—';
                    emailEl.textContent = userEmail || '—';
                    templateSelect.value = '';
                    applyTemplate('', userName, defaultSubject);
                    resetStatus();
                    const emailMissing = userEmail.trim() === '';
                    sendButton.disabled = emailMissing;

                    if (emailMissing) {
                        setStatus('У пользователя не указан email.', 'error');
                    }

                    openModal();
                    setTimeout(function () {
                        bodyInput.focus();
                        bodyInput.setSelectionRange(bodyInput.value.length, bodyInput.value.length);
                    }, 10);
                });
            });

            templateSelect.addEventListener('change', function () {
                const activeButton = document.querySelector('.admin-subs-message-open[data-user-id="' + userIdInput.value + '"]');
                const userName = nameEl.textContent === '—' ? '' : nameEl.textContent;
                const defaultSubject = activeButton?.getAttribute('data-default-subject') || 'Информация от Litehost24';
                applyTemplate(templateSelect.value, userName, defaultSubject);
            });

            document.querySelectorAll('[data-close-user-message]').forEach(function (element) {
                element.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                const userId = userIdInput.value;
                const subject = subjectInput.value.trim();
                const body = bodyInput.value.trim();
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                if (!userId) {
                    setStatus('Не удалось определить пользователя.', 'error');
                    return;
                }

                if (!subject) {
                    setStatus('Укажите тему письма.', 'error');
                    subjectInput.focus();
                    return;
                }

                if (!body) {
                    setStatus('Укажите текст письма.', 'error');
                    bodyInput.focus();
                    return;
                }

                sendButton.disabled = true;
                setStatus('Отправка...', 'info');

                try {
                    const response = await fetch('/admin/subscriptions/user/' + encodeURIComponent(userId) + '/message', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            subject: subject,
                            body: body,
                        }),
                    });

                    const data = await response.json().catch(function () { return {}; });
                    if (!response.ok) {
                        throw new Error(data.message || 'Не удалось отправить сообщение.');
                    }

                    setStatus(data.message || 'Сообщение отправлено.', 'success');
                    setTimeout(closeModal, 900);
                } catch (error) {
                    setStatus(error.message || 'Не удалось отправить сообщение.', 'error');
                } finally {
                    sendButton.disabled = false;
                }
            });
        })();
    </script>
    <style>
        .admin-subs-tooltip {
            cursor: help;
        }

        .admin-user-details-dialog {
            width: min(1000px, calc(100% - 2rem));
            margin: 2rem auto;
        }

        .admin-user-message-dialog {
            width: min(720px, calc(100% - 2rem));
            margin: 2rem auto;
        }
    </style>
</x-app-layout>
