@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 bg-white border-b border-gray-200">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium">&#1057;&#1077;&#1088;&#1074;&#1077;&#1088;&#1099;</h3>
                    <a
                        href="{{ route('servers.create') }}"
                        class="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                        style="display:inline-flex !important;align-items:center !important;background:#1d4ed8 !important;color:#ffffff !important;border:1px solid #1e40af !important;box-shadow:0 1px 2px rgba(0,0,0,.15) !important;text-decoration:none !important;"
                    >
                        &#1044;&#1086;&#1073;&#1072;&#1074;&#1080;&#1090;&#1100; &#1089;&#1077;&#1088;&#1074;&#1077;&#1088;
                    </a>
                </div>

                @if ($message = Session::get('success'))
                    <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">
                        {{ $message }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div class="mb-3">
                        <h4 class="text-sm font-semibold text-emerald-900">Какие серверы выдаём новым подпискам</h4>
                        <p class="mt-1 text-xs text-emerald-800">
                            Старые подписки это не меняет. Здесь выбирается, на каком сервере создавать новые подписки.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('servers.current-bundles') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        @csrf

                        <div class="rounded-md bg-white p-3 shadow-sm">
                            <label for="white_ip_server_id" class="mb-1 block text-sm font-medium text-gray-700">Подписки с белым IP</label>
                            <select name="white_ip_server_id" id="white_ip_server_id" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="">Не выбран</option>
                                @foreach (($bundleServerOptions[\App\Models\Server::VPN_ACCESS_WHITE_IP] ?? collect()) as $option)
                                    @php
                                        $selectedWhiteId = old('white_ip_server_id', $configuredBundleServerIds[\App\Models\Server::VPN_ACCESS_WHITE_IP] ?: ($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_WHITE_IP]?->id ?? ''));
                                    @endphp
                                    <option value="{{ $option->id }}" {{ (string) $selectedWhiteId === (string) $option->id ? 'selected' : '' }}>
                                        #{{ $option->id }} · {{ $option->ip1 ?: ($option->node1_api_url ?: 'без IP') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="mt-2 text-xs text-gray-500">
                                Если пользователь ставит галочку `Нужен белый IP`, подписка создаётся здесь:
                                @if (!empty($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_WHITE_IP]))
                                    #{{ $effectiveBundleServers[\App\Models\Server::VPN_ACCESS_WHITE_IP]->id }} · {{ $effectiveBundleServers[\App\Models\Server::VPN_ACCESS_WHITE_IP]->ip1 ?: ($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_WHITE_IP]->node1_api_url ?: 'без IP') }}
                                @else
                                    не настроен
                                @endif
                            </div>
                        </div>

                        <div class="rounded-md bg-white p-3 shadow-sm">
                            <label for="regular_server_id" class="mb-1 block text-sm font-medium text-gray-700">Обычные подписки</label>
                            <select name="regular_server_id" id="regular_server_id" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="">Не выбран</option>
                                @foreach (($bundleServerOptions[\App\Models\Server::VPN_ACCESS_REGULAR] ?? collect()) as $option)
                                    @php
                                        $selectedRegularId = old('regular_server_id', $configuredBundleServerIds[\App\Models\Server::VPN_ACCESS_REGULAR] ?: ($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_REGULAR]?->id ?? ''));
                                    @endphp
                                    <option value="{{ $option->id }}" {{ (string) $selectedRegularId === (string) $option->id ? 'selected' : '' }}>
                                        #{{ $option->id }} · {{ $option->ip1 ?: ($option->node1_api_url ?: 'без IP') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="mt-2 text-xs text-gray-500">
                                Если галочку не ставит, подписка создаётся здесь:
                                @if (!empty($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_REGULAR]))
                                    #{{ $effectiveBundleServers[\App\Models\Server::VPN_ACCESS_REGULAR]->id }} · {{ $effectiveBundleServers[\App\Models\Server::VPN_ACCESS_REGULAR]->ip1 ?: ($effectiveBundleServers[\App\Models\Server::VPN_ACCESS_REGULAR]->node1_api_url ?: 'без IP') }}
                                @else
                                    не настроен
                                @endif
                            </div>
                        </div>

                        <div class="lg:col-span-2 flex items-center justify-between gap-3">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                                style="background:#047857 !important;color:#ffffff !important;border:1px solid #065f46 !important;box-shadow:0 1px 2px rgba(0,0,0,.15) !important;"
                            >
                                Сохранить
                            </button>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    @foreach ($servers as $server)
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-server-card="{{ $server->id }}">
                        <div class="server-row flex items-start gap-4">
                            <div class="server-col grid min-w-0 flex-1 gap-2 text-sm">
                                <div class="flex items-center justify-between gap-4 rounded-md border border-blue-100 bg-blue-50 px-2 py-1">
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-blue-700">VPN bundle</span>
                                    <div class="text-right">
                                        <div class="font-semibold text-blue-900">{{ $server->vpnAccessModeLabel() }}</div>
                                        @php
                                            $isCurrentBundle = ($effectiveBundleServers[$server->getVpnAccessMode()]?->id ?? null) === $server->id;
                                        @endphp
                                        @if ($isCurrentBundle)
                                            <div class="mt-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-700">
                                                Выдаётся сейчас
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @if ($server->usesNode1Api())
                                    <div class="rounded-md border border-gray-200 bg-white px-2 py-2">
                                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">AWG / API узел</div>
                                        <div class="flex items-center justify-between gap-4 rounded-md bg-gray-100 px-2 py-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">IP</span>
                                            <span class="text-right text-gray-800">{{ $server->ip1 ?: '-' }}</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between gap-4 rounded-md px-2 py-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">API URL</span>
                                            <span class="text-right text-gray-800">{{ $server->node1_api_url ?: '-' }}</span>
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded-md border border-amber-200 bg-amber-50 px-2 py-2">
                                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-amber-700">Legacy node 1</div>
                                        <div class="flex items-center justify-between gap-4 rounded-md bg-white px-2 py-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">IP</span>
                                            <span class="text-right text-gray-800">{{ $server->ip1 ?: '-' }}</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between gap-4 rounded-md px-2 py-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">URL</span>
                                            <span class="text-right text-gray-800">{{ $server->url1 ?: '-' }}</span>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between gap-4 rounded-md bg-white px-2 py-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Логин</span>
                                            <span class="text-right text-gray-800">{{ $server->username1 ?: '-' }}</span>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="server-col grid min-w-0 flex-1 gap-2 text-sm">
                                <div class="rounded-md border border-gray-200 bg-white px-2 py-2">
                                    <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">VLESS узел</div>
                                    <div class="flex items-center justify-between gap-4 rounded-md bg-gray-100 px-2 py-1">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">IP</span>
                                        <span class="text-right text-gray-800">{{ $server->ip2 ?: '-' }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-4 rounded-md px-2 py-1">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">URL</span>
                                        <span class="text-right text-gray-800">{{ $server->url2 ?: '-' }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-4 rounded-md bg-gray-100 px-2 py-1">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Логин</span>
                                        <span class="text-right text-gray-800">{{ $server->username2 ?: '-' }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="server-status min-w-[220px] rounded-md border border-gray-200 bg-gray-50 p-2 text-xs">
                                <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">&#1057;&#1090;&#1072;&#1090;&#1091;&#1089; &#1084;&#1086;&#1085;&#1080;&#1090;&#1086;&#1088;&#1080;&#1085;&#1075;&#1072;</div>
                                @php
                                    $node1 = $monitorState[$server->id]['node1'] ?? ['status' => 'n/a', 'changed_at' => null];
                                    $node2 = $monitorState[$server->id]['node2'] ?? ['status' => 'n/a', 'changed_at' => null];
                                    $metric = $serverMetrics[$server->id]['node1'] ?? null;
                                    $rates = is_array($metric?->rates) ? $metric->rates : [];
                                    $wanInterface = isset($rates['eth0']) ? 'eth0' : (isset($rates['ens3']) ? 'ens3' : 'WAN');
                                    $wanRate = $rates['eth0'] ?? $rates['ens3'] ?? null;
                                    $awgRate = $rates['awg0'] ?? null;
                                    $ifbRate = $rates['ifb0'] ?? null;
                                    $awgHasTraffic = is_array($awgRate)
                                        && (
                                            ((float) ($awgRate['rx_mbps'] ?? 0)) > 0
                                            || ((float) ($awgRate['tx_mbps'] ?? 0)) > 0
                                        );
                                    $vpnInterface = $awgHasTraffic ? 'awg0' : (isset($rates['ifb0']) ? 'ifb0' : 'awg0');
                                    $vpnRate = $awgHasTraffic ? $awgRate : ($ifbRate ?? $awgRate);
                                    $relayRate = $rates['wg6backhaul'] ?? null;
                                    $nodeIpLabel = $server->ip1 ?: 'node1';
                                    $wanLabel = "Интернет ({$nodeIpLabel})";
                                    $vpnLabel = "VPN трафик ({$nodeIpLabel})";
                                    $relayLabel = 'Relay backhaul';
                                    $awgSummary = $serverAwgSummaries[$server->id] ?? null;
                                    $topPeers = is_array($awgSummary?->top_peers) ? array_slice($awgSummary->top_peers, 0, 3) : [];

                                    $fmtRate = function ($row, $key) {
                                        if (!is_array($row) || !isset($row[$key]) || !is_numeric($row[$key])) {
                                            return '-';
                                        }

                                        return number_format((float) $row[$key], 2, '.', ' ') . ' Mbps';
                                    };

                                    $fmtSummaryRate = function ($value) {
                                        if ($value === null || !is_numeric($value)) {
                                            return '-';
                                        }

                                        return number_format((float) $value, 2, '.', ' ') . ' Mbps';
                                    };
                                @endphp

                                <div class="mb-2 rounded bg-white px-2 py-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500">Node 1</span>
                                        @php
                                            $node1Class = $node1['status'] === 'up' ? 'text-green-600' : ($node1['status'] === 'down' ? 'text-red-600' : 'text-gray-500');
                                            $node1Label = $node1['status'] === 'up' ? 'UP' : ($node1['status'] === 'down' ? 'DOWN' : 'N/A');
                                        @endphp
                                        <span
                                            class="font-semibold"
                                            data-node-status="node1"
                                            style="display:inline-block;min-width:46px;text-align:center;padding:2px 8px;border-radius:9999px;color:#fff;background:{{ $node1['status'] === 'up' ? '#16a34a' : ($node1['status'] === 'down' ? '#dc2626' : '#6b7280') }};"
                                        >{{ $node1Label }}</span>
                                    </div>
                                    <div class="text-[11px] text-gray-500" data-node-time="node1">{{ $node1['changed_at'] ?? '-' }}</div>
                                </div>

                                <div class="rounded bg-white px-2 py-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500">Node 2</span>
                                        @php
                                            $node2Class = $node2['status'] === 'up' ? 'text-green-600' : ($node2['status'] === 'down' ? 'text-red-600' : 'text-gray-500');
                                            $node2Label = $node2['status'] === 'up' ? 'UP' : ($node2['status'] === 'down' ? 'DOWN' : 'N/A');
                                        @endphp
                                        <span
                                            class="font-semibold"
                                            data-node-status="node2"
                                            style="display:inline-block;min-width:46px;text-align:center;padding:2px 8px;border-radius:9999px;color:#fff;background:{{ $node2['status'] === 'up' ? '#16a34a' : ($node2['status'] === 'down' ? '#dc2626' : '#6b7280') }};"
                                        >{{ $node2Label }}</span>
                                    </div>
                                    <div class="text-[11px] text-gray-500" data-node-time="node2">{{ $node2['changed_at'] ?? '-' }}</div>
                                </div>
                                <div class="mt-2 text-[11px] text-gray-500" data-last-check>
                                    &#1055;&#1088;&#1086;&#1074;&#1077;&#1088;&#1077;&#1085;&#1086;: -
                                </div>
                                <div class="mt-1 text-[11px] text-gray-500" data-last-mode>
                                    &#1056;&#1077;&#1078;&#1080;&#1084;: -
                                </div>

                                <div class="mt-3 rounded bg-white px-2 py-2 text-[11px] text-gray-700">
                                    <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Node 1 Metrics</div>
                                    @if ($metric)
                                        @if (!$metric->ok)
                                            <div class="text-amber-600">
                                                Metric collection failed: {{ $metric->error_message ?: 'unknown' }}
                                            </div>
                                            <div class="mt-2 text-[10px] text-gray-400">
                                                {{ $metric->collected_at?->format('Y-m-d H:i') ?? '-' }}
                                            </div>
                                        @else
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="text-gray-500">CPU / IOWait</span>
                                                <span class="text-right">
                                                    {{ $metric->cpu_usage_percent !== null ? number_format((float) $metric->cpu_usage_percent, 1, '.', ' ') . '%' : '-' }}
                                                    /
                                                    {{ $metric->cpu_iowait_percent !== null ? number_format((float) $metric->cpu_iowait_percent, 1, '.', ' ') . '%' : '-' }}
                                                </span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">RAM</span>
                                                <span class="text-right">
                                                    {{ $metric->memory_used_percent !== null ? number_format((float) $metric->memory_used_percent, 1, '.', ' ') . '%' : '-' }}
                                                </span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">Load</span>
                                                <span class="text-right">
                                                    {{ $metric->load1 !== null ? number_format((float) $metric->load1, 2, '.', ' ') : '-' }}
                                                </span>
                                            </div>
                                            <div class="mt-2 border-t border-gray-100 pt-2">
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="text-gray-500">{{ $wanLabel }}</span>
                                                    <span class="text-right">↓ {{ $fmtRate($wanRate, 'rx_mbps') }} / ↑ {{ $fmtRate($wanRate, 'tx_mbps') }}</span>
                                                </div>
                                                <div class="mt-1 flex items-center justify-between gap-3">
                                                    <span class="text-gray-500">{{ $vpnLabel }}</span>
                                                    <span class="text-right">↓ {{ $fmtRate($vpnRate, 'rx_mbps') }} / ↑ {{ $fmtRate($vpnRate, 'tx_mbps') }}</span>
                                                </div>
                                                <div class="mt-1 flex items-center justify-between gap-3">
                                                    <span class="text-gray-500">{{ $relayLabel }}</span>
                                                    <span class="text-right">↓ {{ $fmtRate($relayRate, 'rx_mbps') }} / ↑ {{ $fmtRate($relayRate, 'tx_mbps') }}</span>
                                                </div>
                                            </div>
                                            <div class="mt-2 text-[10px] text-gray-400">
                                                {{ $metric->collected_at?->format('Y-m-d H:i') ?? '-' }}
                                            </div>
                                        @endif
                                    @else
                                        <div class="text-gray-400">No metrics yet</div>
                                    @endif

                                    <div class="mt-2 border-t border-gray-100 pt-2">
                                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">AWG Live</div>
                                        @if ($awgSummary)
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="text-gray-500">Peers / endpoint</span>
                                                <span class="text-right">{{ (int) $awgSummary->peers_total }} / {{ (int) $awgSummary->peers_with_endpoint }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">Active 5m / 60s</span>
                                                <span class="text-right">{{ (int) $awgSummary->peers_active_5m }} / {{ (int) $awgSummary->peers_active_60s }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">Transferring now</span>
                                                <span class="text-right">{{ (int) $awgSummary->peers_transferring }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">AWG rate</span>
                                                <span class="text-right">
                                                    ↓ {{ $fmtSummaryRate($awgSummary->total_rx_mbps) }} / ↑ {{ $fmtSummaryRate($awgSummary->total_tx_mbps) }}
                                                </span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between gap-3">
                                                <span class="text-gray-500">Avg per peer</span>
                                                <span class="text-right">
                                                    {{ $fmtSummaryRate($awgSummary->avg_mbps_per_endpoint) }} endpoint / {{ $fmtSummaryRate($awgSummary->avg_mbps_per_active_5m) }} active
                                                </span>
                                            </div>
                                            @if ($awgSummary->heavy_peers_count)
                                                <div class="mt-1 flex items-center justify-between gap-3 text-amber-700">
                                                    <span>Heavy peers</span>
                                                    <span class="text-right">{{ (int) $awgSummary->heavy_peers_count }}</span>
                                                </div>
                                            @endif
                                            @if (!empty($topPeers))
                                                <div class="mt-2 rounded bg-gray-50 px-2 py-2">
                                                    <div class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-500">Top peers</div>
                                                    @foreach ($topPeers as $topPeer)
                                                        <div class="mt-1 flex items-start justify-between gap-3 first:mt-0">
                                                            <span class="min-w-0 text-gray-500">
                                                                {{ $topPeer['peer_name'] ?? '-' }}
                                                                @if (!empty($topPeer['ip']))
                                                                    <span class="text-[10px] text-gray-400">· {{ $topPeer['ip'] }}</span>
                                                                @endif
                                                            </span>
                                                            <span class="shrink-0 text-right">
                                                                {{ isset($topPeer['mbps']) && is_numeric($topPeer['mbps']) ? number_format((float) $topPeer['mbps'], 2, '.', ' ') . ' Mbps' : '-' }}
                                                                @if (isset($topPeer['share_percent']) && is_numeric($topPeer['share_percent']))
                                                                    <span class="text-[10px] text-gray-400">· {{ number_format((float) $topPeer['share_percent'], 1, '.', ' ') }}%</span>
                                                                @endif
                                                            </span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <div class="mt-2 text-[10px] text-gray-400">
                                                {{ $awgSummary->collected_at?->format('Y-m-d H:i') ?? '-' }} · window {{ (int) ($awgSummary->window_sec ?? 0) }}s
                                            </div>
                                        @else
                                            <div class="text-gray-400">No AWG summary yet</div>
                                        @endif
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wide js-monitor-check"
                                    style="background:#1d4ed8;color:#ffffff;border:1px solid #1e40af;box-shadow:0 1px 2px rgba(0,0,0,.15);"
                                    data-url="{{ route('servers.monitor-check', $server->id) }}"
                                >
                                    <span aria-hidden="true">&#8635;</span>
                                    &#1055;&#1088;&#1086;&#1074;&#1077;&#1088;&#1080;&#1090;&#1100; &#1089;&#1077;&#1081;&#1095;&#1072;&#1089;
                                </button>
                            </div>

                            <div class="server-actions flex shrink-0 flex-col items-start gap-2">
                                <a class="inline-flex h-10 w-10 items-center justify-center rounded-md border border-gray-300 bg-white text-black hover:bg-gray-50" href="{{ route('servers.show', $server->id) }}" title="&#1055;&#1088;&#1086;&#1089;&#1084;&#1086;&#1090;&#1088;" aria-label="&#1055;&#1088;&#1086;&#1089;&#1084;&#1086;&#1090;&#1088;">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6-10-6-10-6z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </a>
                                <a class="inline-flex h-10 w-10 items-center justify-center rounded-md border border-gray-300 bg-white text-black hover:bg-gray-50" href="{{ route('servers.edit', $server->id) }}" title="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100;" aria-label="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100;">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 21h6"></path>
                                        <path d="M14.5 3.5l6 6"></path>
                                        <path d="M4 20l1.5-6.5L17 2.5a2 2 0 0 1 2.8 0l1.7 1.7a2 2 0 0 1 0 2.8L10 18.5 4 20z"></path>
                                    </svg>
                                </a>
                                <form action="{{ route('servers.destroy', $server->id) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-md border border-gray-300 bg-white text-black hover:bg-gray-50" onclick="return confirm('&#1042;&#1099; &#1091;&#1074;&#1077;&#1088;&#1077;&#1085;&#1099;, &#1095;&#1090;&#1086; &#1093;&#1086;&#1090;&#1080;&#1090;&#1077; &#1091;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100; &#1101;&#1090;&#1086;&#1090; &#1089;&#1077;&#1088;&#1074;&#1077;&#1088;?')" title="&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;" aria-label="&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18"></path>
                                            <path d="M8 6V4h8v2"></path>
                                            <path d="M19 6l-1 14H6L5 6"></path>
                                            <path d="M10 11v6"></path>
                                            <path d="M14 11v6"></path>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

<style>
    @media (max-width: 920px) {
        .server-row { flex-direction: column; }
        .server-col { width: 100%; }
        .server-actions { width: 100%; flex-direction: row; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const buttons = document.querySelectorAll('.js-monitor-check');

    function statusBg(status) {
        if (status === 'up') return '#16a34a';
        if (status === 'down') return '#dc2626';
        return '#6b7280';
    }

    function statusLabel(status) {
        if (status === 'up') return 'UP';
        if (status === 'down') return 'DOWN';
        return 'N/A';
    }

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('[data-server-card]');
            if (!card) return;

            const oldText = button.textContent;
            button.disabled = true;
            button.textContent = '...';

            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();
                if (!response.ok || !data?.nodes) {
                    throw new Error('monitor-check-failed');
                }

                ['node1', 'node2'].forEach((node) => {
                    const nodeData = data.nodes[node] || {status: 'n/a', changed_at: '-'};
                    const statusEl = card.querySelector(`[data-node-status="${node}"]`);
                    const timeEl = card.querySelector(`[data-node-time="${node}"]`);

                    if (statusEl) {
                        statusEl.textContent = statusLabel(nodeData.status);
                        statusEl.style.background = statusBg(nodeData.status);
                        statusEl.style.color = '#ffffff';
                    }

                    if (timeEl) {
                        timeEl.textContent = nodeData.changed_at || '-';
                    }
                });

                const checkedEl = card.querySelector('[data-last-check]');
                if (checkedEl) {
                    checkedEl.textContent = `Проверено: ${data.checked_at || '-'}`;
                }

                const modeEl = card.querySelector('[data-last-mode]');
                if (modeEl) {
                    const n1 = data.nodes?.node1 || {};
                    const n2 = data.nodes?.node2 || {};
                    const hasAuth = !!(n1.auth_checked || n2.auth_checked);
                    const authFailed = [n1, n2].some((n) => n.auth_checked && n.auth_ok === false);
                    const authOk = [n1, n2].some((n) => n.auth_checked && n.auth_ok === true);

                    if (hasAuth) {
                        modeEl.textContent = authFailed ? 'Режим: AUTH FAIL' : (authOk ? 'Режим: AUTH OK' : 'Режим: AUTH');
                        modeEl.style.color = authFailed ? '#dc2626' : '#16a34a';
                    } else {
                        modeEl.textContent = 'Режим: FALLBACK (TCP/HTTP)';
                        modeEl.style.color = '#6b7280';
                    }
                }
            } catch (e) {
                alert('Check failed');
            } finally {
                button.disabled = false;
                button.textContent = oldText;
            }
        });
    });
});
</script>
