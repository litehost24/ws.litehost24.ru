<x-app-layout>
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 lg:p-8 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Домены VPN (аудит и allowlist)</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Здесь собираются уникальные домены из трафика VPN. Отмеченные домены будут идти через VPN,
                        остальные — напрямую через РФ.
                    </p>
                </div>

                <div class="p-6 lg:p-8">
                    <form method="GET" action="{{ route('admin.vpn-domains') }}" class="mb-6 flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600">Сервер</label>
                            <select name="server_id" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm">
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}" @selected($server->id == $activeServerId)>
                                        #{{ $server->id }} {{ $server->ip1 ?: ($server->node1_api_url ?: 'server') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600">Вид</label>
                            <select name="view" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="domains" @selected($viewMode === 'domains')>Все домены</option>
                                <option value="base" @selected($viewMode === 'base')>Сводка по базовым</option>
                            </select>
                        </div>
                        @if ($viewMode === 'domains')
                            <div>
                                <label class="block text-xs font-semibold text-gray-600">Статус</label>
                                <select name="status" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm">
                                    <option value="all" @selected($statusFilter === 'all')>Все</option>
                                    <option value="allow" @selected($statusFilter === 'allow')>Через VPN</option>
                                    <option value="pending" @selected($statusFilter === 'pending')>Через РФ</option>
                                </select>
                            </div>
                        @else
                            <input type="hidden" name="status" value="all" />
                        @endif
                        <input type="hidden" name="sort" value="{{ $sort }}" />
                        <div>
                            <label class="block text-xs font-semibold text-gray-600">Поиск</label>
                            <input
                                type="text"
                                name="q"
                                value="{{ $search }}"
                                class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                                placeholder="example.com"
                            />
                        </div>
                        <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Показать
                        </button>
                    </form>

                    @if ($baseFilter !== '')
                        <div class="mb-4 text-xs text-gray-600">
                            Фильтр по базовому домену: <span class="font-semibold text-gray-800">{{ $baseFilter }}</span>
                            <a href="{{ route('admin.vpn-domains', array_merge(request()->query(), ['base' => null, 'view' => 'domains'])) }}" class="ml-2 text-blue-600 hover:underline">
                                Сбросить
                            </a>
                        </div>
                    @endif

                    @if ($status === 'saved')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Пометки обновлены.
                        </div>
                    @elseif ($status === 'synced')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Allowlist отправлен на серверы.
                        </div>
                    @elseif ($status === 'mode-updated')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Режим маршрутизации обновлён.
                        </div>
                    @elseif ($status === 'collected')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Сбор доменов выполнен. {{ session('collectOutput') }}
                        </div>
                    @elseif ($status === 'probed')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Проверка доменов выполнена. {{ session('probeOutput') }}
                        </div>
                    @elseif ($status === 'probe-queued')
                        <div class="mb-4 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                            {{ session('probeOutput') }}
                        </div>
                    @elseif ($status === 'empty')
                        <div class="mb-4 rounded-md border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                            Нет доменов для обновления на текущей странице.
                        </div>
                    @endif

                    <div class="mb-6 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('admin.vpn-domains.collect') }}">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Собрать сейчас
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.vpn-domains.probe') }}">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Проверить доступность (RU)
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.vpn-domains.sync') }}">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Синхронизировать allowlist
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.vpn-domains.mode') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <input type="hidden" name="mode" value="full" />
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold {{ $mode === 'full' ? 'text-green-700 border-green-300' : 'text-gray-700 hover:bg-gray-50' }}">
                                Режим: всё через VPN
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.vpn-domains.mode') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <input type="hidden" name="mode" value="allow" />
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold {{ $mode === 'allow' ? 'text-green-700 border-green-300' : 'text-gray-700 hover:bg-gray-50' }}">
                                Режим: только allowlist
                            </button>
                        </form>
                    </div>

                    @if ($viewMode === 'base')
                        <form method="POST" action="{{ route('admin.vpn-domains.update') }}">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-xs uppercase text-gray-500">
                                        <tr>
                                            <th class="px-2 py-2">VPN</th>
                                            <th class="px-2 py-2">Базовый домен</th>
                                            <th class="px-2 py-2">Поддоменов</th>
                                            <th class="px-2 py-2">Через VPN</th>
                                            <th class="px-2 py-2">Запросов</th>
                                            <th class="px-2 py-2">
                                                <a href="{{ route('admin.vpn-domains', array_merge(request()->query(), ['sort' => $sort === 'probe' ? 'default' : 'probe'])) }}" class="text-gray-600 hover:text-gray-900">
                                                    Проверка (RU)
                                                    @if ($sort === 'probe')
                                                        <span class="text-xs text-gray-400">▲</span>
                                                    @endif
                                                </a>
                                            </th>
                                            <th class="px-2 py-2">Проверено</th>
                                            <th class="px-2 py-2">Последний раз</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($domains as $domain)
                                            @php
                                                $allowCount = (int) $domain->allow_count;
                                                $domainsCount = (int) $domain->domains_count;
                                                $isAllAllowed = $domainsCount > 0 && $allowCount === $domainsCount;
                                            @endphp
                                            <tr>
                                                <td class="px-2 py-2">
                                                    <input type="hidden" name="base_domains[]" value="{{ $domain->base_domain }}" />
                                                    <input
                                                        type="checkbox"
                                                        name="allow_base[]"
                                                        value="{{ $domain->base_domain }}"
                                                        class="h-4 w-4 rounded border-gray-300"
                                                        @checked($isAllAllowed)
                                                    />
                                                </td>
                                                <td class="px-2 py-2 text-gray-900">
                                                    <a href="{{ route('admin.vpn-domains', array_merge(request()->query(), ['view' => 'domains', 'base' => $domain->base_domain, 'status' => 'all'])) }}" class="text-blue-600 hover:underline">
                                                        {{ $domain->base_domain }}
                                                    </a>
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">{{ number_format($domainsCount) }}</td>
                                                <td class="px-2 py-2 text-gray-600">
                                                    {{ number_format($allowCount) }}
                                                    @if ($allowCount > 0 && $allowCount < $domainsCount)
                                                        <span class="ml-1 text-xs text-gray-400">частично</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">{{ number_format((int) $domain->total_count) }}</td>
                                                <td class="px-2 py-2 text-gray-600">
                                                    @if ($domain->probe_status)
                                                        @if ($domain->probe_status === 'reachable_fast')
                                                            <span class="text-green-700">Доступен</span>
                                                        @elseif ($domain->probe_status === 'reachable_slow')
                                                            <span class="text-yellow-700">Медленно</span>
                                                        @elseif ($domain->probe_status === 'unreachable')
                                                            @php $failStreak = (int) ($domain->probe_fail_streak ?? 0); @endphp
                                                            @if ($failStreak >= 3)
                                                                <span class="text-red-700">Недоступен</span>
                                                            @else
                                                                <span class="text-yellow-700">Под вопросом</span>
                                                            @endif
                                                        @else
                                                            <span class="text-gray-600">Неизвестно</span>
                                                        @endif
                                                        @if ($domain->probe_latency_ms)
                                                            <span class="ml-1 text-xs text-gray-400">{{ (int) $domain->probe_latency_ms }} ms</span>
                                                        @endif
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">
                                                    @if ($domain->probe_checked_at)
                                                        {{ \Carbon\Carbon::parse($domain->probe_checked_at)->format('Y-m-d H:i') }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">
                                                    {{ $domain->last_seen_at ? \Carbon\Carbon::parse($domain->last_seen_at)->format('Y-m-d H:i') : '—' }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="8" class="px-2 py-4 text-sm text-gray-500">Список пуст.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex items-center gap-3">
                                <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    Сохранить пометки
                                </button>
                                <span class="text-xs text-gray-500">Применяется ко всем поддоменам базового домена на текущей странице.</span>
                            </div>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.vpn-domains.update') }}">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $activeServerId }}" />
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-xs uppercase text-gray-500">
                                        <tr>
                                            <th class="px-2 py-2">VPN</th>
                                            <th class="px-2 py-2">Домен</th>
                                            <th class="px-2 py-2">Запросов</th>
                                            <th class="px-2 py-2">Последний раз</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($domains as $domain)
                                            <tr>
                                                <td class="px-2 py-2">
                                                    <input type="hidden" name="domain_ids[]" value="{{ $domain->id }}" />
                                                    <input
                                                        type="checkbox"
                                                        name="allow[]"
                                                        value="{{ $domain->id }}"
                                                        class="h-4 w-4 rounded border-gray-300"
                                                        @checked($domain->allow_vpn)
                                                    />
                                                </td>
                                                <td class="px-2 py-2 text-gray-900">{{ $domain->domain }}</td>
                                                <td class="px-2 py-2 text-gray-600">{{ number_format($domain->count) }}</td>
                                                <td class="px-2 py-2 text-gray-600">
                                                    {{ $domain->last_seen_at ? $domain->last_seen_at->format('Y-m-d H:i') : '—' }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-2 py-4 text-sm text-gray-500">Список пуст.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex items-center gap-3">
                                <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    Сохранить пометки
                                </button>
                                <span class="text-xs text-gray-500">Изменения применяются только для доменов на текущей странице.</span>
                            </div>
                        </form>
                    @endif

                    <div class="mt-6">
                        {{ $domains->links() }}
                    </div>

                    <div class="mt-8">
                        <h4 class="text-sm font-semibold text-gray-900">Применение на серверах</h4>
                        @if (!empty($applyResults))
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-xs uppercase text-gray-500">
                                        <tr>
                                            <th class="px-2 py-2">Сервер</th>
                                            <th class="px-2 py-2">Статус</th>
                                            <th class="px-2 py-2">Ошибка</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($applyResults as $row)
                                            <tr>
                                                <td class="px-2 py-2 text-gray-900">#{{ $row['server_id'] }} {{ $row['label'] }}</td>
                                                <td class="px-2 py-2">
                                                    @if ($row['ok'])
                                                        <span class="text-green-700">OK</span>
                                                    @else
                                                        <span class="text-red-700">Ошибка</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">{{ $row['error'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="mt-2 text-sm text-gray-500">Ещё не применялось.</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
