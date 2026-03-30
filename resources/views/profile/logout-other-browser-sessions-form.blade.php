<div class="profile-card">
    <div class="profile-card__header">
        <div>
            <div class="profile-card__title">{{ __('Сеансы браузера') }}</div>
            <div class="profile-card__desc">{{ __('Управляйте и выходите из активных сеансов на других браузерах и устройствах.') }}</div>
        </div>
    </div>

    <div class="profile-card__body">
        <div class="text-sm text-gray-600">
            {{ __('При необходимости вы можете выйти из всех других сеансов на всех ваших устройствах. Некоторые из ваших последних сеансов перечислены ниже; однако этот список может быть неполным. Если вы считаете, что ваш аккаунт был скомпрометирован, также обновите свой пароль.') }}
        </div>

        @if (count($this->sessions) > 0)
            <div class="mt-4 space-y-4">
                @foreach ($this->sessions as $session)
                    <div class="d-flex align-items-center gap-3">
                        <div>
                            @if ($session->agent->isDesktop())
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-gray-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-gray-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                                </svg>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm text-gray-600">
                                {{ $session->agent->platform() ? $session->agent->platform() : __('Неизвестно') }} - {{ $session->agent->browser() ? $session->agent->browser() : __('Неизвестно') }}
                            </div>

                            <div class="text-xs text-gray-500">
                                {{ $session->ip_address }},

                                @if ($session->is_current_device)
                                    <span class="text-green-500 font-semibold">{{ __('Это устройство') }}</span>
                                @else
                                    {{ __('Последняя активность') }} {{ $session->last_active }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-4 d-flex align-items-center gap-3 flex-wrap">
            <button type="button" class="btn btn-outline-primary" wire:click="confirmLogout" wire:loading.attr="disabled">
                {{ __('Выйти из других сеансов браузера') }}
            </button>

            <x-action-message class="profile-status" on="loggedOut">
                {{ __('Готово.') }}
            </x-action-message>
        </div>

        <x-dialog-modal wire:model.live="confirmingLogout">
            <x-slot name="title">
                {{ __('Выйти из других сеансов браузера') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Пожалуйста, введите свой пароль, чтобы подтвердить, что вы хотите выйти из других сеансов браузера на всех ваших устройствах.') }}

                <div class="mt-3" x-data="{}" x-on:confirming-logout-other-browser-sessions.window="setTimeout(() => $refs.password.focus(), 250)">
                    <input type="password" class="form-control" autocomplete="current-password"
                           placeholder="{{ __('Пароль') }}"
                           x-ref="password"
                           wire:model="password"
                           wire:keydown.enter="logoutOtherBrowserSessions" />

                    <x-input-error for="password" class="profile-error mt-2" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$toggle('confirmingLogout')" wire:loading.attr="disabled">
                    {{ __('Отмена') }}
                </button>

                <button type="button" class="btn btn-primary" wire:click="logoutOtherBrowserSessions" wire:loading.attr="disabled">
                    {{ __('Выйти из других сеансов браузера') }}
                </button>
            </x-slot>
        </x-dialog-modal>
    </div>
</div>
