<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />

        @php
            $refLink = request()->query('ref_link');
        @endphp

        <form method="POST" action="{{ route('register') }}" data-auth-session-guard>
            @csrf

            <input type="hidden" id="ref_link" name="ref_link" value="{{ app('request')->input('ref_link') }}">

            <div>
                <x-label for="name" value="{{ __('Полностью: Фамилия Имя Отчество') }}" />
                <x-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            </div>

            <div class="mt-4">
                <x-label for="email" value="{{ __('Электронная почта') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            </div>

            <div class="mt-4">
                <x-label for="phone" value="{{ __('Телефон') }}" />
                <x-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" required autocomplete="tel" />
            </div>

            <div class="mt-4">
                <x-label for="password" value="{{ __('Пароль') }}" />
                <div class="relative mt-1">
                    <x-input id="password" class="block w-full pr-12" type="password" name="password" required autocomplete="new-password" />
                    <button
                        type="button"
                        class="absolute text-gray-500 hover:text-gray-700"
                        style="right: 12px; top: 50%; transform: translateY(-50%);"
                        data-toggle-password="password"
                        aria-label="Показать пароль"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 3c-4.5 0-8.1 2.9-9.5 7 1.4 4.1 5 7 9.5 7s8.1-2.9 9.5-7c-1.4-4.1-5-7-9.5-7zm0 12a5 5 0 110-10 5 5 0 010 10z" />
                            <path d="M10 7a3 3 0 100 6 3 3 0 000-6z" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="mt-4">
                <x-label for="password_confirmation" value="{{ __('Подтвердите пароль') }}" />
                <div class="relative mt-1">
                    <x-input id="password_confirmation" class="block w-full pr-12" type="password" name="password_confirmation" required autocomplete="new-password" />
                    <button
                        type="button"
                        class="absolute text-gray-500 hover:text-gray-700"
                        style="right: 12px; top: 50%; transform: translateY(-50%);"
                        data-toggle-password="password_confirmation"
                        aria-label="Показать пароль"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 3c-4.5 0-8.1 2.9-9.5 7 1.4 4.1 5 7 9.5 7s8.1-2.9 9.5-7c-1.4-4.1-5-7-9.5-7zm0 12a5 5 0 110-10 5 5 0 010 10z" />
                            <path d="M10 7a3 3 0 100 6 3 3 0 000-6z" />
                        </svg>
                    </button>
                </div>
            </div>

            @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                <div class="mt-4">
                    <x-label for="terms">
                        <div class="flex items-center">
                            <x-checkbox name="terms" id="terms" required />

                            <div class="ms-2">
                                {!! __('Я соглашаюсь с :terms_of_service и :privacy_policy', [
                                        'terms_of_service' => '<a target="_blank" href="'.route('terms.show').'" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">'.__('Условиями обслуживания').'</a>',
                                        'privacy_policy' => '<a target="_blank" href="'.route('policy.show').'" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">'.__('Политикой конфиденциальности').'</a>',
                                ]) !!}
                            </div>
                        </div>
                    </x-label>
                </div>
            @endif

            <div class="flex items-center justify-end mt-4">
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                    {{ __('Уже зарегистрированы?') }}
                </a>

                <x-button class="ms-4">
                    {{ __('Зарегистрироваться') }}
                </x-button>
            </div>
        </form>

        <div class="mt-6">
            <div class="flex items-center">
                <div class="flex-grow border-t border-gray-200"></div>
                <span class="mx-2 text-sm text-gray-500">{{ __('Или') }}</span>
                <div class="flex-grow border-t border-gray-200"></div>
            </div>

            <div class="mt-4 grid gap-3">
                <a
                    href="{{ route('social.redirect', array_filter(['provider' => 'google', 'ref_link' => $refLink])) }}"
                    class="inline-flex items-center justify-start gap-3 rounded-md border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-900 shadow-sm transition hover:-translate-y-0.5 hover:shadow focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white border border-gray-200">
                        <svg viewBox="0 0 48 48" class="h-7 w-7" aria-hidden="true">
                            <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.9 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8.1 3.1l5.7-5.7C34.2 6 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c11 0 20-8.9 20-20 0-1.3-.1-2.6-.4-3.9z"/>
                            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 16.2 19 12 24 12c3.1 0 5.9 1.2 8.1 3.1l5.7-5.7C34.2 6 29.3 4 24 4 16.3 4 9.5 8.4 6.3 14.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.3l-6.3-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.7-3.3-11.3-8l-6.6 5.1C9.4 39.6 16.2 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-1.1 3-3.4 5.3-6.3 6.5l.1.1 6.3 5.2C34.9 41.7 44 36 44 24c0-1.3-.1-2.6-.4-3.9z"/>
                        </svg>
                    </span>
                    Зарегистрироваться через Google
                </a>
                <a
                    href="{{ route('social.redirect', array_filter(['provider' => 'yandex', 'ref_link' => $refLink])) }}"
                    class="inline-flex items-center justify-start gap-3 rounded-md border border-red-600 bg-white px-4 py-3 text-sm font-semibold text-gray-900 shadow-sm transition hover:-translate-y-0.5 hover:shadow focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    <span class="inline-flex h-9 w-9 items-center justify-center">
                        <svg viewBox="0 0 40 40" class="h-9 w-9" aria-hidden="true">
                            <circle cx="20" cy="20" r="20" fill="#FC3F1D"/>
                            <text x="20" y="20" text-anchor="middle" dominant-baseline="central" font-size="22" font-weight="700" font-family="Arial, 'Segoe UI', sans-serif" fill="#FFFFFF">Я</text>
                        </svg>
                    </span>
                    Зарегистрироваться через Yandex
                </a>
                <a
                    href="{{ route('social.redirect', array_filter(['provider' => 'mailru', 'ref_link' => $refLink])) }}"
                    class="inline-flex items-center justify-start gap-3 rounded-md border border-[#005FF9] bg-white px-4 py-3 text-sm font-semibold text-gray-900 shadow-sm transition hover:-translate-y-0.5 hover:shadow focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    <x-mailru-icon class="h-9 w-9" />
                    Зарегистрироваться через Mail.ru
                </a>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                        if (!input) {
                            return;
                        }
                        var show = input.type === 'password';
                        input.type = show ? 'text' : 'password';
                        btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
                    });
                });
            });
        </script>
    </x-authentication-card>
</x-guest-layout>
