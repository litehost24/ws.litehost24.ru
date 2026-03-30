<div class="mini-contact flex justify-center mt-16 px-0 sm:items-center sm:justify-between">
    <div class="text-center text-sm sm:text-left">
        &nbsp;
    </div>

    <div class="text-center text-sm text-gray-500 sm:text-right sm:ml-0">
        На связи:
        <span class="inline-flex items-center gap-[5px]">
            <a class="mini-contact__phone inline-flex items-center pr-1.5 transition-colors"
               style="color:var(--lh-brand-blue, #3088f0);"
               href="{{ $contacts['phone_href'] }}"><span class="underline">{{ $contacts['phone'] }}</span></a>

            @if (!empty($contacts['telegram_href']))
                <a class="mini-contact__icon-link inline-flex items-center justify-center rounded-md p-1.5 text-gray-500 transition-colors hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 focus-visible:ring-offset-2"
                   href="{{ $contacts['telegram_href'] }}" target="_blank" rel="noopener noreferrer"
                   aria-label="Написать в Telegram" title="Telegram {{ $contacts['telegram'] ?? '' }}">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 3 2.6 10.6c-.8.3-.7 1.4.1 1.6l5.2 1.6 1.9 6.2c.2.8 1.2.9 1.6.3l3-4.3 5.2 3.9c.6.4 1.4.1 1.5-.7L23 4.4c.1-1-1-1.8-2-1.4Z"/>
                        <path d="M8 13.8 20.5 5.9"/>
                    </svg>
                </a>
            @endif

            @if (!empty($contacts['whatsapp_href']))
                <a class="mini-contact__icon-link inline-flex items-center justify-center rounded-md p-1.5 text-gray-500 transition-colors hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 focus-visible:ring-offset-2"
                   href="{{ $contacts['whatsapp_href'] }}" target="_blank" rel="noopener noreferrer"
                   aria-label="Написать в WhatsApp" title="WhatsApp">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        {{-- WhatsApp-styled chat bubble + handset, matching the outline look of other icons. --}}
                        <path d="M21.5 12a9.5 9.5 0 0 1-14.3 8.1L2.5 21.5l1.4-4.5A9.5 9.5 0 1 1 21.5 12Z"/>
                        <path d="M9 9.2c.8 1.8 2.2 3.2 4 4l1.4-1.4c.3-.3.7-.4 1.1-.3l1.4.5c.5.2.8.7.7 1.2a4.2 4.2 0 0 1-4.2 3.6A9.7 9.7 0 0 1 7.2 11a4.2 4.2 0 0 1 3.6-4.2c.5-.1 1 .2 1.2.7l.5 1.4c.1.4 0 .8-.3 1.1l-1.1 1.2"/>
                    </svg>
                </a>
            @endif

            <button type="button"
                    class="mini-contact__icon-link inline-flex items-center justify-center rounded-md p-1.5 text-gray-500 transition-colors hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 focus-visible:ring-offset-2"
                    onclick="window.openContactEmailModal && window.openContactEmailModal()"
                    aria-label="Написать на email" title="Email">
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m3 7 9 6 9-6"/>
                </svg>
            </button>
        </span>
    </div>
</div>

@php
    $contactEmailShouldOpen =
        old('contact_email_modal') === '1'
        ||
        session('contact_email_open')
        || $errors->has('contact_email_name')
        || $errors->has('contact_email_email')
        || $errors->has('contact_email_message')
        || $errors->has('contact_email_captcha_answer')
        || $errors->has('contact_email_company');

    $contactEmailSent = (bool) session('contact_email_sent', false);
@endphp

<div id="contact-email-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50" onclick="window.closeContactEmailModal && window.closeContactEmailModal()"></div>

    <div class="relative" style="max-width: 700px; margin: 96px auto 40px; padding: 0 16px;">
        <div class="rounded-lg bg-white shadow-xl overflow-y-auto" style="max-height: calc(100vh - 160px);">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="text-sm font-semibold text-gray-900">Написать на email</div>
                <button type="button" class="text-gray-500 hover:text-gray-800" aria-label="Закрыть"
                        onclick="window.closeContactEmailModal && window.closeContactEmailModal()">
                    x
                </button>
            </div>

            <form class="px-4 py-4" method="post" action="{{ route('contact.email.send') }}"
                  onsubmit="var b=this.querySelector('button[type=submit]'); if(b){b.disabled=true; b.textContent='Отправляем...';}">
                @csrf
                <input type="hidden" name="contact_email_modal" value="1">

                <div class="hidden">
                    <label for="contact_email_company">Company</label>
                    <input id="contact_email_company" name="contact_email_company" type="text" value="">
                </div>

                <div>
                    <label class="block text-sm text-gray-700" for="contact_email_name">Имя</label>
                    <input id="contact_email_name" name="contact_email_name" type="text"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                           value="{{ old('contact_email_name') }}" maxlength="80" autocomplete="name" required>
                    @error('contact_email_name')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mt-3">
                    <label class="block text-sm text-gray-700" for="contact_email_email">Email</label>
                    <input id="contact_email_email" name="contact_email_email" type="email"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                           value="{{ old('contact_email_email') }}" maxlength="120" autocomplete="email" required>
                    @error('contact_email_email')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mt-3">
                    <label class="block text-sm text-gray-700" for="contact_email_message">Сообщение</label>
                    <textarea id="contact_email_message" name="contact_email_message" rows="5"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                              maxlength="2000" required>{{ old('contact_email_message') }}</textarea>
                    @error('contact_email_message')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                @guest
                    <div class="mt-3">
                        <label class="block text-sm text-gray-700" for="contact_email_captcha_answer">
                            Проверка: {{ $contactEmailCaptchaQuestion ?? '' }}
                        </label>
                        <input id="contact_email_captcha_answer" name="contact_email_captcha_answer" type="text"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                               value="{{ old('contact_email_captcha_answer') }}" inputmode="numeric" autocomplete="off" required>
                        @error('contact_email_captcha_answer')
                            <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                        @enderror
                        @error('contact_email_company')
                            <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                        @enderror
                    </div>
                @endguest

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button type="button"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:border-gray-400 hover:text-gray-900"
                            onclick="window.closeContactEmailModal && window.closeContactEmailModal()">
                        Отмена
                    </button>
                    <button type="submit"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Отправить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="contact-email-success-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50" onclick="window.closeContactEmailSuccessModal && window.closeContactEmailSuccessModal()"></div>

    <div class="relative" style="max-width: 520px; margin: 96px auto 40px; padding: 0 16px;">
        <div class="rounded-lg bg-white shadow-xl overflow-y-auto" style="max-height: calc(100vh - 160px);">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="text-sm font-semibold text-gray-900">Сообщение отправлено</div>
                <button type="button" class="text-gray-500 hover:text-gray-800" aria-label="Закрыть"
                        onclick="window.closeContactEmailSuccessModal && window.closeContactEmailSuccessModal()">
                    x
                </button>
            </div>

            <div class="px-4 py-4">
                <div class="text-sm text-gray-700">
                    Спасибо. Мы ответим на email, который вы указали.
                </div>

                <div class="mt-4 flex items-center justify-end">
                    <button type="button"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            onclick="window.closeContactEmailSuccessModal && window.closeContactEmailSuccessModal()">
                        Ок
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const root = document.getElementById('contact-email-modal');
        if (!root) return;

        function setOpen(isOpen) {
            if (isOpen) {
                root.classList.remove('hidden');
                root.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            } else {
                root.classList.add('hidden');
                root.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }
        }

        window.openContactEmailModal = function () { setOpen(true); };
        window.closeContactEmailModal = function () { setOpen(false); };

        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });

        const shouldOpen = {{ $contactEmailShouldOpen ? 'true' : 'false' }};
        if (shouldOpen) {
            setOpen(true);
        }
    })();
</script>

<script>
    (function () {
        const root = document.getElementById('contact-email-success-modal');
        if (!root) return;

        function setOpen(isOpen) {
            if (isOpen) {
                root.classList.remove('hidden');
                root.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            } else {
                root.classList.add('hidden');
                root.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }
        }

        window.openContactEmailSuccessModal = function () { setOpen(true); };
        window.closeContactEmailSuccessModal = function () { setOpen(false); };

        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });

        const shouldOpen = {{ $contactEmailSent ? 'true' : 'false' }};
        if (shouldOpen) {
            setOpen(true);
        }
    })();
</script>
