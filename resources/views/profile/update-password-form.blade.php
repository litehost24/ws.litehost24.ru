<form wire:submit.prevent="updatePassword" class="profile-card">
    <div class="profile-card__header">
        <div>
            <div class="profile-card__title">{{ __('Обновить пароль') }}</div>
            <div class="profile-card__desc">{{ __('Убедитесь, что ваш аккаунт использует длинный, случайный пароль для безопасности.') }}</div>
        </div>
    </div>

    <div class="profile-card__body">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <label for="current_password" class="form-label">{{ __('Текущий пароль') }}</label>
                <input id="current_password" type="password" class="form-control" wire:model="state.current_password" autocomplete="current-password" />
                <x-input-error for="current_password" class="profile-error mt-2" />
            </div>

            <div class="col-12 col-lg-6">
                <label for="password" class="form-label">{{ __('Новый пароль') }}</label>
                <input id="password" type="password" class="form-control" wire:model="state.password" autocomplete="new-password" />
                <x-input-error for="password" class="profile-error mt-2" />
            </div>

            <div class="col-12 col-lg-6">
                <label for="password_confirmation" class="form-label">{{ __('Подтвердите пароль') }}</label>
                <input id="password_confirmation" type="password" class="form-control" wire:model="state.password_confirmation" autocomplete="new-password" />
                <x-input-error for="password_confirmation" class="profile-error mt-2" />
            </div>
        </div>
    </div>

    <div class="profile-card__footer">
        <x-action-message class="profile-status me-auto" on="saved">
            {{ __('Сохранено.') }}
        </x-action-message>

        <button type="submit" class="btn btn-primary">
            {{ __('Сохранить') }}
        </button>
    </div>
</form>
