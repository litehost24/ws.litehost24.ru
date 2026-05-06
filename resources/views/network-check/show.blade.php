<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Проверка IP | {{ config('app.name', 'Litehost24') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
        @vite(['resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
    @php
        $clientIp = $report['client_ip'] ?? [];
        $requestInfo = $report['request'] ?? [];
        $serverInfo = $report['server'] ?? [];
        $geo = $geo ?? ($report['geo_lookup'] ?? []);
        $geoData = is_array($geo['data'] ?? null) ? $geo['data'] : [];
        $geoNetwork = is_array($geoData['network'] ?? null) ? $geoData['network'] : [];
        $geoTimezone = is_array($geoData['timezone'] ?? null) ? $geoData['timezone'] : [];
        $geoSecurity = is_array($geoData['security'] ?? null) ? $geoData['security'] : [];
        $forwardedHeaders = $report['forwarded_headers'] ?? [];
        $headers = $report['headers'] ?? [];
        $ipChain = $report['ip_chain'] ?? [];
        $serverReportJson = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    @endphp

    <div class="min-h-screen bg-gray-100">
        @include('navigation-menu')

        <div class="py-10">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="border-b border-gray-200 p-6 lg:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">Проверка IP и подключения</h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">
                                Страница показывает данные, которые сайт видит по текущему запросу, и параметры,
                                которые браузер может определить локально.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('ip-check') }}"
                               class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Обновить
                            </a>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,.9fr)] lg:p-8">
                    <section class="rounded-lg border border-gray-200 bg-gray-50 p-5">
                        <div class="text-sm font-semibold uppercase tracking-wide text-gray-500">Основное</div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-md border border-gray-200 bg-white p-4">
                                <div class="text-xs font-semibold uppercase text-gray-500">Ваш IP</div>
                                <div class="mt-2 break-all text-2xl font-semibold text-gray-900">
                                    {{ $clientIp['address'] ?? 'не определён' }}
                                </div>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-white p-4">
                                <div class="text-xs font-semibold uppercase text-gray-500">Тип адреса</div>
                                <div class="mt-2 text-lg font-semibold text-gray-900">
                                    {{ $clientIp['version'] ?? 'не определён' }}
                                    <span class="text-gray-500">·</span>
                                    {{ $clientIp['type'] ?? 'не определён' }}
                                </div>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-white p-4">
                                <div class="text-xs font-semibold uppercase text-gray-500">Соединение</div>
                                <div class="mt-2 text-lg font-semibold text-gray-900">
                                    {{ !empty($requestInfo['secure']) ? 'HTTPS' : 'HTTP' }}
                                    <span class="text-gray-500">·</span>
                                    {{ $requestInfo['protocol'] ?? 'протокол не определён' }}
                                </div>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-white p-4">
                                <div class="text-xs font-semibold uppercase text-gray-500">Проверено</div>
                                <div class="mt-2 text-lg font-semibold text-gray-900">
                                    {{ \Illuminate\Support\Carbon::parse($report['checked_at'] ?? now())->format('d.m.Y H:i:s') }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="text-sm font-semibold uppercase tracking-wide text-gray-500">Внешняя IP-база</div>
                        <div class="mt-4">
                            @if(($geo['status'] ?? '') === 'ok')
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                        <div class="text-xs font-semibold uppercase text-gray-500">Провайдер</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $geoNetwork['isp'] ?: 'не определён' }}</div>
                                    </div>
                                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                        <div class="text-xs font-semibold uppercase text-gray-500">ASN / организация</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">
                                            {{ $geoNetwork['asn'] ? ('AS' . $geoNetwork['asn']) : 'ASN не определён' }}
                                            @if(!empty($geoNetwork['org']))
                                                · {{ $geoNetwork['org'] }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                        <div class="text-xs font-semibold uppercase text-gray-500">Местоположение</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">
                                            {{ collect([$geoData['country'] ?? '', $geoData['region'] ?? '', $geoData['city'] ?? ''])->filter()->implode(', ') ?: 'не определено' }}
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                        <div class="text-xs font-semibold uppercase text-gray-500">Часовой пояс IP</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900">
                                            {{ $geoTimezone['id'] ?: 'не определён' }}
                                            @if(!empty($geoTimezone['utc']))
                                                · {{ $geoTimezone['utc'] }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 rounded-md border border-blue-100 bg-blue-50 p-3 text-sm leading-6 text-blue-900">
                                    Источник: {{ $geo['provider'] ?? 'внешняя база' }}.
                                    Геолокация по IP примерная и может отличаться от фактического города.
                                </div>
                            @else
                                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                                    {{ $geo['message'] ?? 'Внешняя IP-база сейчас недоступна.' }}
                                </div>
                            @endif
                        </div>
                    </section>
                </div>

                @if(($geo['status'] ?? '') === 'ok')
                    <div class="px-6 pb-8 lg:px-8">
                        <section class="rounded-lg border border-gray-200 bg-white p-5">
                            <h2 class="text-lg font-semibold text-gray-900">Дополнительные признаки IP</h2>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach([
                                    'proxy' => 'Прокси',
                                    'vpn' => 'VPN',
                                    'tor' => 'Tor',
                                    'relay' => 'Relay',
                                ] as $key => $label)
                                    @php($value = $geoSecurity[$key] ?? null)
                                    <div class="rounded-md border {{ $value === true ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-gray-200 bg-gray-50 text-gray-700' }} p-3">
                                        <div class="text-xs font-semibold uppercase">{{ $label }}</div>
                                        <div class="mt-1 text-sm font-semibold">
                                            @if($value === true)
                                                похоже, да
                                            @elseif($value === false)
                                                не обнаружено
                                            @else
                                                нет данных
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    </div>
                @endif

                <div class="grid gap-6 px-6 pb-8 lg:grid-cols-2 lg:px-8">
                    <section class="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-gray-900">Цепочка IP и прокси</h2>
                        <div class="mt-4 overflow-hidden rounded-md border border-gray-200">
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-gray-200">
                                @forelse($ipChain as $item)
                                    <tr>
                                        <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">
                                            {{ $loop->first ? 'Основной IP' : 'Прокси #' . $loop->iteration }}
                                        </th>
                                        <td class="px-3 py-2 text-gray-900">
                                            <span class="break-all font-mono">{{ $item['address'] ?? '' }}</span>
                                            <span class="ml-2 text-gray-500">{{ $item['version'] ?? '' }} · {{ $item['type'] ?? '' }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-3 py-2 text-gray-500">Цепочка не определена.</td>
                                    </tr>
                                @endforelse
                                @forelse($forwardedHeaders as $name => $value)
                                    <tr>
                                        <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">{{ $name }}</th>
                                        <td class="break-all px-3 py-2 font-mono text-gray-900">{{ $value }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">Forwarded headers</th>
                                        <td class="px-3 py-2 text-gray-500">Не переданы.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-gray-900">Запрос к сайту</h2>
                        <div class="mt-4 overflow-hidden rounded-md border border-gray-200">
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-gray-200">
                                <tr>
                                    <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">Адрес страницы</th>
                                    <td class="break-all px-3 py-2 text-gray-900">{{ $requestInfo['url'] ?? '' }}</td>
                                </tr>
                                <tr>
                                    <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">Хост</th>
                                    <td class="break-all px-3 py-2 text-gray-900">{{ $requestInfo['host'] ?? '' }}:{{ $requestInfo['port'] ?? '' }}</td>
                                </tr>
                                <tr>
                                    <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">REMOTE_ADDR</th>
                                    <td class="break-all px-3 py-2 font-mono text-gray-900">{{ $serverInfo['remote_addr'] ?? '' }}</td>
                                </tr>
                                <tr>
                                    <th class="w-40 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">REMOTE_PORT</th>
                                    <td class="break-all px-3 py-2 font-mono text-gray-900">{{ $serverInfo['remote_port'] ?? '' }}</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="grid gap-6 px-6 pb-8 lg:grid-cols-2 lg:px-8">
                    <section class="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-gray-900">Данные браузера</h2>
                        <div class="mt-4 overflow-hidden rounded-md border border-gray-200">
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-gray-200">
                                @foreach([
                                    'user_agent' => 'User-Agent',
                                    'languages' => 'Языки',
                                    'timezone' => 'Часовой пояс',
                                    'local_time' => 'Локальное время',
                                    'platform' => 'Платформа',
                                    'screen' => 'Экран',
                                    'viewport' => 'Окно браузера',
                                    'device_pixel_ratio' => 'Плотность пикселей',
                                    'color_scheme' => 'Цветовая схема',
                                    'connection' => 'Network API',
                                    'cookies' => 'Cookies',
                                    'storage' => 'Local storage',
                                    'online' => 'Online',
                                ] as $key => $label)
                                    <tr>
                                        <th class="w-44 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">{{ $label }}</th>
                                        <td class="break-all px-3 py-2 text-gray-900" data-browser-field="{{ $key }}">определяется...</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-gray-900">Заголовки запроса</h2>
                        <div class="mt-4 max-h-96 overflow-auto rounded-md border border-gray-200">
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-gray-200">
                                @foreach($headers as $name => $value)
                                    <tr>
                                        <th class="w-44 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-600">{{ $name }}</th>
                                        <td class="break-all px-3 py-2 font-mono text-gray-900">{{ $value }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="px-6 pb-8 lg:px-8">
                    <details class="rounded-lg border border-gray-200 bg-white">
                        <summary class="cursor-pointer list-none px-5 py-4 text-lg font-semibold text-gray-900">
                            Технический отчёт для поддержки
                            <span class="mt-1 block text-sm font-normal leading-6 text-gray-600">
                                Откройте этот блок только если поддержка попросила прислать подробные данные проверки.
                            </span>
                        </summary>
                        <div class="border-t border-gray-200 p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm leading-6 text-gray-600">
                                    В отчёте нет паролей и cookies: чувствительные заголовки скрываются.
                                </p>
                                <button type="button"
                                        id="ip-check-copy"
                                        class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-semibold"
                                        style="border: 1px solid #1d4ed8; background: #2563eb; color: #ffffff;"
                                        onmouseover="this.style.background='#1d4ed8'"
                                        onmouseout="this.style.background='#2563eb'">
                                    Скопировать отчёт
                                </button>
                            </div>
                            <textarea id="ip-check-report"
                                      class="mt-4 h-72 w-full rounded-md border border-gray-300 bg-gray-950 p-4 font-mono text-xs leading-5 text-gray-100"
                                      readonly>{{ $serverReportJson }}</textarea>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        </div>
    </div>

    @livewireScripts
    <script>
        (function () {
            const serverReport = @json(
                $report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );
            const browserReport = {};
            const reportBox = document.getElementById('ip-check-report');
            const copyButton = document.getElementById('ip-check-copy');

            function storageAvailable(type) {
                try {
                    const storage = window[type];
                    const key = '__ip_check_test__';
                    storage.setItem(key, key);
                    storage.removeItem(key);
                    return true;
                } catch (e) {
                    return false;
                }
            }

            function setField(key, value) {
                const text = value === null || value === undefined || value === '' ? 'недоступно' : String(value);
                browserReport[key] = text;
                document.querySelectorAll('[data-browser-field="' + key + '"]').forEach(function (node) {
                    node.textContent = text;
                });
            }

            function updateReport() {
                if (!reportBox) {
                    return;
                }

                reportBox.value = JSON.stringify({
                    server: serverReport,
                    browser: browserReport
                }, null, 2);
            }

            function connectionText() {
                const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (!connection) {
                    return '';
                }

                const parts = [];
                if (connection.effectiveType) parts.push('тип: ' + connection.effectiveType);
                if (connection.downlink) parts.push('скорость: ' + connection.downlink + ' Мбит/с');
                if (connection.rtt) parts.push('RTT: ' + connection.rtt + ' мс');
                if (connection.saveData) parts.push('экономия трафика включена');

                return parts.join(', ');
            }

            setField('user_agent', navigator.userAgent);
            setField('languages', Array.isArray(navigator.languages) ? navigator.languages.join(', ') : navigator.language);
            setField('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
            setField('local_time', new Date().toString());
            setField('platform', navigator.platform || '');
            setField('screen', window.screen ? window.screen.width + 'x' + window.screen.height : '');
            setField('viewport', window.innerWidth + 'x' + window.innerHeight);
            setField('device_pixel_ratio', window.devicePixelRatio || 1);
            setField('color_scheme', window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            setField('connection', connectionText());
            setField('cookies', navigator.cookieEnabled ? 'включены' : 'выключены');
            setField('storage', storageAvailable('localStorage') ? 'доступен' : 'недоступен');
            setField('online', navigator.onLine ? 'да' : 'нет');
            updateReport();

            window.addEventListener('resize', function () {
                setField('viewport', window.innerWidth + 'x' + window.innerHeight);
                updateReport();
            });

            if (copyButton && reportBox) {
                copyButton.addEventListener('click', async function () {
                    try {
                        await navigator.clipboard.writeText(reportBox.value);
                        copyButton.textContent = 'Отчёт скопирован';
                        setTimeout(function () {
                            copyButton.textContent = 'Скопировать отчёт';
                        }, 1800);
                    } catch (e) {
                        reportBox.focus();
                        reportBox.select();
                    }
                });
            }
        })();
    </script>
    </body>
</html>
