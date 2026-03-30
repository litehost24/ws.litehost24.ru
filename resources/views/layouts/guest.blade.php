<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-auth-session-guard-max-age-seconds="{{ max(60, ((int) config('session.lifetime', 120) * 60) - 300) }}"
>
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
    <body>
        <div class="font-sans text-gray-900 antialiased">
            {{ $slot }}
        </div>

        @livewireScripts

        <script>
            (() => {
                const root = document.documentElement;
                const maxAgeSeconds = Number(root.dataset.authSessionGuardMaxAgeSeconds || '0');
                if (!Number.isFinite(maxAgeSeconds) || maxAgeSeconds <= 0) {
                    return;
                }

                const forms = Array.from(document.querySelectorAll('form[data-auth-session-guard]'));
                if (!forms.length) {
                    return;
                }

                const storageKey = 'lh_auth_session_guard';
                const currentPath = window.location.pathname + window.location.search;
                const pageLoadedAt = Date.now();

                restoreFormState();

                forms.forEach((form) => {
                    form.addEventListener('submit', (event) => {
                        if ((Date.now() - pageLoadedAt) < (maxAgeSeconds * 1000)) {
                            return;
                        }

                        event.preventDefault();
                        storeFormState(form);
                        window.location.replace(window.location.href);
                    }, true);
                });

                function storeFormState(form) {
                    const payload = {
                        path: currentPath,
                        action: normalizeAction(form),
                        fields: [],
                    };

                    Array.from(form.elements).forEach((field) => {
                        if (
                            !(field instanceof HTMLInputElement)
                            && !(field instanceof HTMLSelectElement)
                            && !(field instanceof HTMLTextAreaElement)
                        ) {
                            return;
                        }

                        if (!field.name || field.disabled || field.name === '_token') {
                            return;
                        }

                        if (field instanceof HTMLInputElement) {
                            const type = (field.type || 'text').toLowerCase();
                            if (['password', 'file', 'submit', 'button', 'image', 'reset'].includes(type)) {
                                return;
                            }

                            if (type === 'checkbox' || type === 'radio') {
                                payload.fields.push({
                                    name: field.name,
                                    kind: type,
                                    value: field.value,
                                    checked: field.checked,
                                });
                                return;
                            }
                        }

                        if (field instanceof HTMLSelectElement && field.multiple) {
                            payload.fields.push({
                                name: field.name,
                                kind: 'select-multiple',
                                values: Array.from(field.selectedOptions).map((option) => option.value),
                            });
                            return;
                        }

                        payload.fields.push({
                            name: field.name,
                            kind: 'value',
                            value: field.value,
                        });
                    });

                    window.sessionStorage.setItem(storageKey, JSON.stringify(payload));
                }

                function restoreFormState() {
                    const raw = window.sessionStorage.getItem(storageKey);
                    if (!raw) {
                        return;
                    }

                    window.sessionStorage.removeItem(storageKey);

                    let payload = null;
                    try {
                        payload = JSON.parse(raw);
                    } catch (_) {
                        return;
                    }

                    if (!payload || payload.path !== currentPath) {
                        return;
                    }

                    const form = forms.find((item) => normalizeAction(item) === payload.action) || forms[0];
                    if (!form || !Array.isArray(payload.fields)) {
                        return;
                    }

                    payload.fields.forEach((entry) => {
                        const escapedName = cssEscape(entry.name || '');
                        if (!escapedName) {
                            return;
                        }

                        const selector = `[name="${escapedName}"]`;
                        if (entry.kind === 'checkbox' || entry.kind === 'radio') {
                            const candidates = Array.from(form.querySelectorAll(selector));
                            candidates.forEach((candidate) => {
                                if (!(candidate instanceof HTMLInputElement)) {
                                    return;
                                }
                                if ((entry.kind === 'radio' || entry.kind === 'checkbox') && candidate.value === entry.value) {
                                    candidate.checked = Boolean(entry.checked);
                                }
                            });
                            return;
                        }

                        const field = form.querySelector(selector);
                        if (!field) {
                            return;
                        }

                        if (entry.kind === 'select-multiple' && field instanceof HTMLSelectElement) {
                            const values = Array.isArray(entry.values) ? entry.values.map(String) : [];
                            Array.from(field.options).forEach((option) => {
                                option.selected = values.includes(option.value);
                            });
                            return;
                        }

                        if (
                            (field instanceof HTMLInputElement)
                            || (field instanceof HTMLTextAreaElement)
                            || (field instanceof HTMLSelectElement)
                        ) {
                            field.value = String(entry.value ?? '');
                        }
                    });

                    showGuardNotice(form);
                }

                function showGuardNotice(form) {
                    if (form.previousElementSibling?.dataset?.authSessionGuardNotice === '1') {
                        return;
                    }

                    const notice = document.createElement('div');
                    notice.dataset.authSessionGuardNotice = '1';
                    notice.className = 'mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900';
                    notice.textContent = 'Страница была открыта слишком долго, поэтому мы обновили её. Проверьте данные и введите пароль ещё раз.';
                    form.parentNode?.insertBefore(notice, form);
                }

                function normalizeAction(form) {
                    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
                    return url.pathname + url.search;
                }

                function cssEscape(value) {
                    if (!value) {
                        return '';
                    }

                    if (window.CSS && typeof window.CSS.escape === 'function') {
                        return window.CSS.escape(value);
                    }

                    return String(value).replace(/["\\]/g, '\\$&');
                }
            })();
        </script>
    </body>
</html>
