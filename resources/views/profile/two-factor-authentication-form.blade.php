<div class="profile-card">
    <div class="profile-card__header">
        <div>
            <div class="profile-card__title">{{ __('Двухфакторная аутентификация') }}</div>
            <div class="profile-card__desc">{{ __('Добавьте дополнительную защиту вашему аккаунту с помощью двухфакторной аутентификации.') }}</div>
        </div>
    </div>

    <div class="profile-card__body">
        <h3 class="text-lg font-semibold text-gray-900">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Завершите включение двухфакторной аутентификации.') }}
                @else
                    {{ __('Вы включили двухфакторную аутентификацию.') }}
                @endif
            @else
                {{ __('Вы не включили двухфакторную аутентификацию.') }}
            @endif
        </h3>

        <div class="mt-3 text-sm text-gray-600">
            <p>
                {{ __('Когда двухфакторная аутентификация включена, при входе в систему будет запрошен безопасный случайный токен. Вы можете получить этот токен в приложении Google Authenticator на вашем телефоне.') }}
            </p>
        </div>

        @if ($this->enabled)
            @if ($showingQrCode)
                <div class="mt-4 text-sm text-gray-600">
                    <p class="font-semibold">
                        @if ($showingConfirmation)
                            {{ __('Чтобы завершить включение двухфакторной аутентификации, отсканируйте следующий QR-код с помощью приложения-аутентификатора на вашем телефоне или введите ключ настройки и предоставьте сгенерированный OTP-код.') }}
                        @else
                            {{ __('Двухфакторная аутентификация включена. Отсканируйте следующий QR-код с помощью приложения-аутентификатора на вашем телефоне или введите ключ настройки.') }}
                        @endif
                    </p>
                </div>

                <div class="mt-3 p-2 inline-block bg-white border rounded">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <div class="mt-3 text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Ключ настройки') }}: {{ decrypt($this->user->two_factor_secret) }}
                    </p>
                </div>

                @if ($showingConfirmation)
                    <div class="mt-4">
                        <label for="code" class="form-label">{{ __('Код') }}</label>
                        <input id="code" type="text" name="code" class="form-control" inputmode="numeric"
                               autofocus autocomplete="one-time-code"
                               wire:model="code"
                               wire:keydown.enter="confirmTwoFactorAuthentication" />
                        <x-input-error for="code" class="profile-error mt-2" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <div class="mt-4 text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Сохраните эти коды восстановления в надежном менеджере паролей. Они могут быть использованы для восстановления доступа к вашей учетной записи, если ваше устройство для двухфакторной аутентификации будет утеряно.') }}
                    </p>
                </div>

                <div class="mt-3 grid gap-1 px-4 py-4 font-mono text-sm bg-gray-100 rounded-lg">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="mt-4 d-flex flex-wrap gap-2">
            @if (! $this->enabled)
                <x-confirms-password wire:then="enableTwoFactorAuthentication">
                    <button type="button" class="btn btn-primary" wire:loading.attr="disabled">
                        {{ __('Включить') }}
                    </button>
                </x-confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-confirms-password wire:then="regenerateRecoveryCodes">
                        <button type="button" class="btn btn-outline-secondary">
                            {{ __('Сгенерировать коды восстановления') }}
                        </button>
                    </x-confirms-password>
                @elseif ($showingConfirmation)
                    <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                        <button type="button" class="btn btn-primary" wire:loading.attr="disabled">
                            {{ __('Подтвердить') }}
                        </button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="showRecoveryCodes">
                        <button type="button" class="btn btn-outline-secondary">
                            {{ __('Показать коды восстановления') }}
                        </button>
                    </x-confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <button type="button" class="btn btn-outline-secondary" wire:loading.attr="disabled">
                            {{ __('Отмена') }}
                        </button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <button type="button" class="btn btn-outline-danger" wire:loading.attr="disabled">
                            {{ __('Отключить') }}
                        </button>
                    </x-confirms-password>
                @endif
            @endif
        </div>
    </div>
</div>
