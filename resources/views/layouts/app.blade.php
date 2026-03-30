<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen bg-gray-100">
            @livewire('navigation-menu')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                @isset($slot)
                    {{ $slot }}
                @else
                    @yield('content')
                @endisset
            </main>
        </div>

        @stack('modals')

        @livewireScripts

                        <script>
            function showActivationLoader(element) {
                const href = element.getAttribute('href') || '';
                let subId = null;
                try {
                    const url = new URL(href, window.location.origin);
                    subId = url.searchParams.get('id');
                } catch (e) {
                    subId = null;
                }

                const action = element.getAttribute('data-action');

                if (action === 'activate') {
                    const activationSpinner = document.getElementById('activation-spinner-' + subId);
                    const activationIcon = document.getElementById('activation-icon-' + subId);

                    if (activationSpinner && activationIcon) {
                        activationSpinner.classList.remove('hidden');
                        activationIcon.classList.add('hidden');
                    }
                } else if (action === 'connect') {
                    const connectSpinner = document.getElementById('connect-spinner-' + subId);
                    const connectIcon = document.getElementById('connect-icon-' + subId);

                    if (connectSpinner && connectIcon) {
                        connectSpinner.classList.remove('hidden');
                        connectIcon.classList.add('hidden');
                    }
                }

                element.classList.add('pointer-events-none', 'opacity-60');

                const overlay = document.createElement('div');
                overlay.id = 'activation-overlay';
                overlay.style.position = 'fixed';
                overlay.style.top = '0';
                overlay.style.left = '0';
                overlay.style.width = '100%';
                overlay.style.height = '100%';
                overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                overlay.style.zIndex = '9999';
                overlay.style.display = 'flex';
                overlay.style.flexDirection = 'column';
                overlay.style.justifyContent = 'center';
                overlay.style.alignItems = 'center';
                overlay.style.gap = '12px';

                const loader = document.createElement('div');
                loader.className = 'spinner-border text-light';
                loader.setAttribute('role', 'status');
                loader.style.width = '3rem';
                loader.style.height = '3rem';

                const label = document.createElement('div');
                label.style.color = '#fff';
                label.style.fontSize = '16px';
                label.textContent = 'Подключаем подписку…';

                overlay.appendChild(loader);
                overlay.appendChild(label);
                document.body.appendChild(overlay);
            }
            async function openInstructionModal(id, urlOverride) {
                const modal = document.getElementById('instruction-modal-' + id);
                if (!modal) {
                    return;
                }

                modal.classList.remove('hidden');

                const body = modal.querySelector('[data-instruction-body]');
                if (!body) {
                    return;
                }

                const url = urlOverride || modal.dataset.instructionUrl || '';
                if (!url) {
                    body.innerHTML = '<div class="text-center text-red-600">Инструкция недоступна.</div>';
                    return;
                }

                if (modal.dataset.instructionLoaded === '1' && modal.dataset.instructionLoadedUrl === url) {
                    return;
                }

                body.innerHTML = '<div class="text-center text-gray-500">Загрузка инструкции…</div>';

                try {
                    const response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const payload = await response.json();
                    if (payload && payload.html) {
                        body.innerHTML = payload.html;
                        modal.dataset.instructionLoaded = '1';
                        modal.dataset.instructionLoadedUrl = url;
                        return;
                    }
                } catch (e) {
                    // fall through to error message
                }

                body.innerHTML = '<div class="text-center text-red-600">Не удалось загрузить инструкцию.</div>';
            }

            function closeInstructionModal(id) {
                const modal = document.getElementById('instruction-modal-' + id);
                if (modal) {
                    modal.classList.add('hidden');
                }
            }

            function copyInstructionConfig(id) {
                const input = document.getElementById('instruction-config-' + id);
                copyInstructionTextareaElement(input);
            }

            function copyInstructionAwgConfig(id) {
                const input = document.getElementById('instruction-awg-config-' + id);
                copyInstructionTextareaElement(input);
            }

            function copyInstructionTextarea(id) {
                const input = document.getElementById(id);
                copyInstructionTextareaElement(input);
            }

            function copyInstructionTextareaElement(input) {
                if (!input) {
                    return;
                }

                input.select();
                input.setSelectionRange(0, 999999);

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).catch(function () {
                        document.execCommand('copy');
                    });
                } else {
                    document.execCommand('copy');
                }
            }

            function downloadInstructionAwgConfig(id) {
                const input = document.getElementById('instruction-awg-config-' + id);
                downloadInstructionTextareaElement(input, 'peer-1.conf');
            }

            function downloadInstructionTextarea(id, filename) {
                const input = document.getElementById(id);
                downloadInstructionTextareaElement(input, filename);
            }

            function downloadInstructionTextareaElement(input, filename) {
                if (!input) {
                    return;
                }

                const blob = new Blob([input.value || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename || 'config.txt';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () {
                    URL.revokeObjectURL(url);
                }, 0);
            }

            document.addEventListener('hide.bs.modal', function (event) {
                const modal = event.target;
                const activeElement = document.activeElement;

                if (modal && activeElement && modal.contains(activeElement) && typeof activeElement.blur === 'function') {
                    activeElement.blur();
                }
            });

            // Prevent accidental page jump to top on empty/hash-only links.
            document.addEventListener('click', function (event) {
                const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                if (!link) {
                    return;
                }

                const href = (link.getAttribute('href') || '').trim();
                if (href !== '#' && href !== '') {
                    return;
                }

                if (link.hasAttribute('data-bs-toggle') || link.hasAttribute('x-on:click.prevent') || link.hasAttribute('@click.prevent')) {
                    return;
                }

                event.preventDefault();
            }, true);
        </script>
    </body>
</html>
