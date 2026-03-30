<x-app-layout>
    <x-slot name="header">
        <div class="profile__header">
            <div>
                <h2 class="text-2xl font-semibold text-gray-900 leading-tight">
                    {{ __('Профиль') }}
                </h2>
                <div class="text-sm text-gray-500">
                    Управление аккаунтом, безопасностью и реферальными ссылками.
                </div>
            </div>
        </div>
    </x-slot>

    <script>
        (function () {
            function showToast(msg) {
                var el = document.getElementById('lh-copy-toast');
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'lh-copy-toast';
                    el.className = 'lh-copy-toast';
                    document.body.appendChild(el);
                }
                el.textContent = msg;
                el.classList.add('--show');
                clearTimeout(showToast._t);
                showToast._t = setTimeout(function () { el.classList.remove('--show'); }, 1400);
            }

            async function copyText(text) {
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(text);
                        return true;
                    }
                } catch (e) {}

                try {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    return ok;
                } catch (e) {
                    return false;
                }
            }

            function handleCopy(e) {
                var t = e.target;
                var el = t && (t.closest ? t.closest('.lh-copy-btn, .lh-copy-link') : null);
                if (!el) return;

                if (el.classList.contains('lh-copy-link') && (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1)) {
                    return;
                }

                e.preventDefault();

                var text = el.getAttribute('data-copy') || (el.href || '');
                copyText(text).then(function (ok) {
                    showToast(ok ? 'Скопировано в буфер обмена' : 'Не удалось скопировать');
                });
            }

            document.addEventListener('click', handleCopy, true);
        })();
    </script>

    <div class="profile-page py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="profile-stack">
                @php
                    $refLinksVisible = Auth::user()->ref_link && Auth::user()->email_verified_at;
                    $referrer = Auth::user()->referrer;
                    if ($referrer && in_array($referrer->role, ['partner', 'admin'], true)) {
                        $pricing = app(\App\Services\ReferralPricingService::class);
                        $markupCents = $pricing->getMarkupCents(
                            (int) $referrer->id,
                            (int) Auth::user()->id,
                            \App\Services\ReferralPricingService::SERVICE_VPN
                        );
                        $hasPartnerMarkup = $markupCents > 0;
                        if ($hasPartnerMarkup) {
                            $refLinksVisible = false;
                        }
                    }
                @endphp

                @if ($refLinksVisible)
                    @php
                        $siteRefLink = url('/register') . '?ref_link=' . Auth::user()->ref_link;
                        $tgRefLink = 'https://t.me/' . config('support.telegram.bot_username', 'litehost24bot') . '?start=ref_' . Auth::user()->ref_link;
                        $qr = app(\App\Services\QrCodeService::class);
                        $siteQrDataUri = $qr->makeDataUri($siteRefLink, 260);
                        $tgQrDataUri = $qr->makeDataUri($tgRefLink, 260);
                    @endphp
                    <div class="profile-card">
                        <div class="profile-card__header">
                            <div>
                                <div class="profile-card__title">Реферальные ссылки</div>
                                <div class="profile-card__desc">Вы можете помочь проекту, поделившись своей ссылкой с близкими и друзьями. Сейчас за приглашение нет отдельного бонуса, но каждое новое подключение помогает нам развивать проект, поддерживать инфраструктуру и улучшать качество сервиса.</div>
                            </div>
                        </div>
                        <div class="profile-card__body">
                            <div class="profile-links">
                                <div class="profile-link-block">
                                    <div class="profile-link-label">Ваша реферальная ссылка</div>
                                    <div class="profile-link-row">
                                        <a href="{{ $siteRefLink }}"
                                           class="lh-copy-link profile-link-value"
                                           data-copy="{{ $siteRefLink }}">
                                            {{ $siteRefLink }}
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary btn-sm lh-copy-btn"
                                                data-copy="{{ $siteRefLink }}">
                                            Скопировать
                                        </button>
                                        @if ($siteQrDataUri)
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    onclick="openQrModal('site')">
                                                QR
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="profile-link-block">
                                    <div class="profile-link-label">Ссылка для Telegram</div>
                                    <div class="profile-link-row">
                                        <a href="{{ $tgRefLink }}"
                                           class="lh-copy-link profile-link-value"
                                           data-copy="{{ $tgRefLink }}">
                                            {{ $tgRefLink }}
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary btn-sm lh-copy-btn"
                                                data-copy="{{ $tgRefLink }}">
                                            Скопировать
                                        </button>
                                        @if ($tgQrDataUri)
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    onclick="openQrModal('tg')">
                                                QR
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($siteQrDataUri)
                        <div id="qr-modal-site" class="fixed inset-0 z-[9999] hidden overflow-y-auto">
                            <div class="absolute inset-0 bg-black/50" onclick="closeQrModal('site')"></div>
                            <div class="relative instruction-modal-dialog rounded-lg bg-white p-6 shadow-lg instruction-modal-content">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-900">QR для сайта</h3>
                                    <button type="button" class="w-9 h-9 inline-flex items-center justify-center rounded-full text-xl font-semibold text-gray-500 hover:text-gray-700 hover:bg-gray-100" onclick="closeQrModal('site')">×</button>
                                </div>
                                <div class="mt-4 text-center">
                                    <img src="{{ $siteQrDataUri }}" alt="QR для сайта" style="max-width: 280px; width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 8px;">
                                    <div class="mt-3 text-xs text-gray-500 break-all">{{ $siteRefLink }}</div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($tgQrDataUri)
                        <div id="qr-modal-tg" class="fixed inset-0 z-[9999] hidden overflow-y-auto">
                            <div class="absolute inset-0 bg-black/50" onclick="closeQrModal('tg')"></div>
                            <div class="relative instruction-modal-dialog rounded-lg bg-white p-6 shadow-lg instruction-modal-content">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-900">QR для Telegram</h3>
                                    <button type="button" class="w-9 h-9 inline-flex items-center justify-center rounded-full text-xl font-semibold text-gray-500 hover:text-gray-700 hover:bg-gray-100" onclick="closeQrModal('tg')">×</button>
                                </div>
                                <div class="mt-4 text-center">
                                    <img src="{{ $tgQrDataUri }}" alt="QR для Telegram" style="max-width: 280px; width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 8px;">
                                    <div class="mt-3 text-xs text-gray-500 break-all">{{ $tgRefLink }}</div>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif

                <div class="profile-card">
                    <div class="profile-card__header">
                        <div>
                            <div class="profile-card__title">Социальные аккаунты</div>
                            <div class="profile-card__desc">Подключите Google, Yandex или Mail.ru, чтобы входить быстрее.</div>
                        </div>
                        @if (session('status') === 'social-linked')
                            <span class="profile-badge">Подключено</span>
                        @endif
                    </div>

                    @php
                        $connected = Auth::user()
                            ->socialAccounts()
                            ->pluck('provider')
                            ->map(fn ($provider) => strtolower($provider))
                            ->toArray();
                    @endphp

                    <div class="profile-card__body">
                        <div class="profile-social-grid">
                            <div class="profile-social-card">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white border border-gray-200">
                                        <svg viewBox="0 0 48 48" class="h-7 w-7" aria-hidden="true">
                                            <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.9 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8.1 3.1l5.7-5.7C34.2 6 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c11 0 20-8.9 20-20 0-1.3-.1-2.6-.4-3.9z"/>
                                            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 16.2 19 12 24 12c3.1 0 5.9 1.2 8.1 3.1l5.7-5.7C34.2 6 29.3 4 24 4 16.3 4 9.5 8.4 6.3 14.7z"/>
                                            <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.3l-6.3-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.7-3.3-11.3-8l-6.6 5.1C9.4 39.6 16.2 44 24 44z"/>
                                            <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-1.1 3-3.4 5.3-6.3 6.5l.1.1 6.3 5.2C34.9 41.7 44 36 44 24c0-1.3-.1-2.6-.4-3.9z"/>
                                        </svg>
                                    </span>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Google</div>
                                        <div class="profile-social-meta">Почта и профиль</div>
                                    </div>
                                </div>
                                @if (in_array('google', $connected, true))
                                    <span class="text-xs font-semibold text-green-700">Подключен</span>
                                @else
                                    <a class="profile-social-action" href="{{ route('social.link.redirect', ['provider' => 'google']) }}">Привязать</a>
                                @endif
                            </div>

                            <div class="profile-social-card">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="inline-flex h-9 w-9 items-center justify-center">
                                        <svg viewBox="0 0 40 40" class="h-9 w-9" aria-hidden="true">
                                            <circle cx="20" cy="20" r="20" fill="#FC3F1D"/>
                                            <text x="20" y="20" text-anchor="middle" dominant-baseline="central" font-size="22" font-weight="700" font-family="Arial, 'Segoe UI', sans-serif" fill="#FFFFFF">Я</text>
                                        </svg>
                                    </span>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Yandex</div>
                                        <div class="profile-social-meta">ID и профиль</div>
                                    </div>
                                </div>
                                @if (in_array('yandex', $connected, true))
                                    <span class="text-xs font-semibold text-green-700">Подключен</span>
                                @else
                                    <a class="profile-social-action" href="{{ route('social.link.redirect', ['provider' => 'yandex']) }}">Привязать</a>
                                @endif
                            </div>

                            <div class="profile-social-card">
                                <div class="d-flex align-items-center gap-3">
                                    <x-mailru-icon class="h-9 w-9" />
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Mail.ru</div>
                                        <div class="profile-social-meta">Почта и профиль</div>
                                    </div>
                                </div>
                                @if (in_array('mailru', $connected, true))
                                    <span class="text-xs font-semibold text-green-700">Подключен</span>
                                @else
                                    <a class="profile-social-action" href="{{ route('social.link.redirect', ['provider' => 'mailru']) }}">Привязать</a>
                                @endif
                            </div>
                        </div>

                        @if ($errors->has('social'))
                            <div class="mt-3 text-sm text-red-600">{{ $errors->first('social') }}</div>
                        @endif
                    </div>
                </div>

                @if (Laravel\Fortify\Features::canUpdateProfileInformation())
                    @livewire('profile.update-profile-information-form')
                @endif

                @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                    @livewire('profile.update-password-form')
                @endif

                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    @livewire('profile.two-factor-authentication-form')
                @endif

                @livewire('profile.logout-other-browser-sessions-form')

                @if (Laravel\Jetstream\Jetstream::hasAccountDeletionFeatures())
                    @livewire('profile.delete-user-form')
                @endif
            </div>
        </div>
    </div>

    <script>
        (function () {
            function modalId(kind) {
                return kind === 'tg' ? 'qr-modal-tg' : 'qr-modal-site';
            }

            window.openQrModal = function (kind) {
                var modal = document.getElementById(modalId(kind));
                if (modal) {
                    modal.classList.remove('hidden');
                }
            };

            window.closeQrModal = function (kind) {
                var modal = document.getElementById(modalId(kind));
                if (modal) {
                    modal.classList.add('hidden');
                }
            };

            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                var site = document.getElementById('qr-modal-site');
                var tg = document.getElementById('qr-modal-tg');
                if (site && !site.classList.contains('hidden')) {
                    site.classList.add('hidden');
                }
                if (tg && !tg.classList.contains('hidden')) {
                    tg.classList.add('hidden');
                }
            });
        })();
    </script>
</x-app-layout>
