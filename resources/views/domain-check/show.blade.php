<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Проверка домена | {{ config('app.name', 'Litehost24') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
        @vite(['resources/js/app.js'])
        @livewireStyles
        <style>
            .domain-check-form-row {
                display: flex;
                width: 100%;
            }

            .domain-check-input {
                flex: 1 1 auto;
                min-width: 0;
                min-height: 44px;
                border: 1px solid #d1d5db;
                border-right: 0;
                border-radius: 6px 0 0 6px;
                color: #111827;
                font-size: 16px;
                line-height: 24px;
                padding: 9px 12px;
            }

            .domain-check-input:focus {
                border-color: #2563eb;
                box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
                outline: none;
            }

            .domain-check-submit {
                align-items: center;
                background: #1d4ed8;
                border: 1px solid #1d4ed8;
                border-radius: 0 6px 6px 0;
                color: #ffffff;
                display: inline-flex;
                font-size: 14px;
                font-weight: 700;
                justify-content: center;
                min-height: 44px;
                padding: 9px 20px;
                white-space: nowrap;
            }

            .domain-check-submit:hover {
                background: #1e40af;
                border-color: #1e40af;
            }

            @media (max-width: 640px) {
                .domain-check-form-row {
                    flex-direction: column;
                    gap: 12px;
                }

                .domain-check-input {
                    border-right: 1px solid #d1d5db;
                    border-radius: 6px;
                }

                .domain-check-submit {
                    border-radius: 6px;
                    width: 100%;
                }
            }
        </style>
    </head>
    <body class="font-sans antialiased">
    @php
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];

        $statusClasses = [
            'taken' => 'border-rose-200 bg-rose-50 text-rose-800',
            'available' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'unknown' => 'border-amber-200 bg-amber-50 text-amber-800',
        ];
    @endphp

    <div class="min-h-screen bg-gray-100">
        @include('navigation-menu')

        <main class="py-10">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <section class="overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="border-b border-gray-200 p-6 lg:p-8">
                        <div class="max-w-3xl">
                            <h1 class="text-2xl font-semibold text-gray-900">Проверка домена</h1>
                            <p class="mt-2 text-sm leading-6 text-gray-600">
                                Введите домен с зоной или только имя. Если домен занят, мы проверим несколько близких вариантов.
                            </p>
                        </div>

                        <form method="GET" action="{{ route('domain-check') }}" class="mt-6">
                            <label for="domain" class="sr-only">Домен</label>
                            <div class="domain-check-form-row">
                                <input
                                    id="domain"
                                    name="domain"
                                    type="text"
                                    value="{{ $query }}"
                                    placeholder="example.ru или example"
                                    class="domain-check-input"
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                                <button type="submit"
                                        class="domain-check-submit">
                                    Проверить
                                </button>
                            </div>
                        </form>

                        @if($result && ($result['status'] ?? '') === 'invalid')
                            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                {{ $result['message'] ?? 'Не удалось проверить домен.' }}
                            </div>
                        @endif
                    </div>

                    @if($result && ($result['status'] ?? '') === 'ok')
                        <div class="grid gap-6 p-6 lg:p-8">
                            @foreach($checks as $check)
                                @php
                                    $status = $check['status'] ?? 'unknown';
                                    $details = is_array($check['details'] ?? null) ? $check['details'] : [];
                                    $suggestions = is_array($check['suggestions'] ?? null) ? $check['suggestions'] : [];
                                @endphp

                                <section class="rounded-lg border border-gray-200 bg-white p-5">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <div class="text-xs font-semibold uppercase text-gray-500">Домен</div>
                                            <h2 class="mt-1 break-all text-2xl font-semibold text-gray-900">
                                                {{ $check['display_domain'] ?? $check['domain'] ?? '' }}
                                            </h2>
                                            @if(($check['display_domain'] ?? '') !== ($check['domain'] ?? ''))
                                                <div class="mt-1 break-all font-mono text-xs text-gray-500">{{ $check['domain'] }}</div>
                                            @endif
                                        </div>

                                        <div class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $statusClasses[$status] ?? $statusClasses['unknown'] }}">
                                            {{ $check['status_label'] ?? 'нужно уточнить' }}
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                            <div class="text-xs font-semibold uppercase text-gray-500">Источник</div>
                                            <div class="mt-1 text-sm font-semibold text-gray-900">{{ $check['source'] ?? 'не определён' }}</div>
                                        </div>
                                        <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                            <div class="text-xs font-semibold uppercase text-gray-500">Результат</div>
                                            <div class="mt-1 text-sm font-semibold text-gray-900">{{ $check['message'] ?? '' }}</div>
                                        </div>
                                        @if(!empty($details['registrar']))
                                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                                <div class="text-xs font-semibold uppercase text-gray-500">Регистратор</div>
                                                <div class="mt-1 break-words text-sm font-semibold text-gray-900">{{ $details['registrar'] }}</div>
                                            </div>
                                        @endif
                                        @if(!empty($details['expires_at']))
                                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                                <div class="text-xs font-semibold uppercase text-gray-500">Оплачен до</div>
                                                <div class="mt-1 text-sm font-semibold text-gray-900">{{ $details['expires_at'] }}</div>
                                            </div>
                                        @endif
                                    </div>

                                    @if(!empty($details['name_servers']) && is_array($details['name_servers']))
                                        <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3">
                                            <div class="text-xs font-semibold uppercase text-gray-500">NS-серверы</div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @foreach($details['name_servers'] as $ns)
                                                    <span class="rounded-md bg-white px-2 py-1 font-mono text-xs text-gray-700 ring-1 ring-gray-200">{{ $ns }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if($status === 'taken')
                                        <div class="mt-6">
                                            <h3 class="text-base font-semibold text-gray-900">Варианты</h3>
                                            @if(count($suggestions) > 0)
                                                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                    @foreach($suggestions as $suggestion)
                                                        @php($suggestionStatus = $suggestion['status'] ?? 'unknown')
                                                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                                                            <div class="break-all text-lg font-semibold text-gray-900">
                                                                {{ $suggestion['display_domain'] ?? $suggestion['domain'] ?? '' }}
                                                            </div>
                                                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                                                <div class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$suggestionStatus] ?? $statusClasses['unknown'] }}">
                                                                    {{ $suggestion['status_label'] ?? 'нужно уточнить' }}
                                                                </div>
                                                                <a href="{{ route('domain-check', ['domain' => $suggestion['domain'] ?? '']) }}"
                                                                   class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                                                                    Проверить
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                                                    Близкие варианты в этой зоне тоже заняты или не подходят для проверки.
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </section>
                            @endforeach
                        </div>

                        <div class="border-t border-gray-200 px-6 py-4 text-sm leading-6 text-gray-600 lg:px-8">
                            Статус «свободен» означает, что запись не найдена в реестре или WHOIS по этой зоне.
                            Проверка не бронирует домен; premium, reserved и временно заблокированные имена уточняются у регистратора.
                        </div>
                    @else
                        <div class="p-6 lg:p-8">
                            <div class="grid gap-4 md:grid-cols-3">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <div class="text-sm font-semibold text-gray-900">С зоной</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        Проверим конкретный домен: <span class="font-mono">example.ru</span>.
                                    </div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <div class="text-sm font-semibold text-gray-900">Без зоны</div>
                                    <div class="mt-1 text-sm text-gray-600">Проверим несколько популярных зон для одного имени.</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <div class="text-sm font-semibold text-gray-900">Если занят</div>
                                    <div class="mt-1 text-sm text-gray-600">Покажем близкие варианты в той же зоне.</div>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        </main>
    </div>

    @livewireScripts
    </body>
</html>
