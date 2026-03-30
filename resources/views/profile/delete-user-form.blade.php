<div class="profile-card">
    <div class="profile-card__header">
        <div>
            <div class="profile-card__title">{{ __('Удалить аккаунт') }}</div>
            <div class="profile-card__desc">{{ __('Безвозвратно удалить ваш аккаунт.') }}</div>
        </div>
    </div>

    <div class="profile-card__body">
        <div class="text-sm text-gray-600">
            {{ __('После удаления аккаунта все его ресурсы и данные будут безвозвратно удалены. Перед удалением аккаунта, пожалуйста, скачайте все данные, которые хотите сохранить.') }}
        </div>

        <div class="mt-4">
            <button type="button" class="btn btn-outline-danger" wire:click="confirmUserDeletion" wire:loading.attr="disabled">
                {{ __('Удалить аккаунт') }}
            </button>
        </div>

        <x-dialog-modal wire:model.live="confirmingUserDeletion">
            <x-slot name="title">
                {{ __('Удалить аккаунт') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Вы уверены, что хотите удалить свой аккаунт? После удаления аккаунта все его ресурсы и данные будут безвозвратно удалены. Пожалуйста, введите ваш пароль для подтверждения удаления аккаунта.') }}

                <div class="mt-3" x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                    <input type="password" class="form-control" autocomplete="current-password"
                           placeholder="{{ __('Пароль') }}"
                           x-ref="password"
                           wire:model="password"
                           wire:keydown.enter="deleteUser" />

                    <x-input-error for="password" class="profile-error mt-2" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <button type="button" class="btn btn-outline-secondary" wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Отмена') }}
                </button>

                <button type="button" class="btn btn-danger" wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('Удалить аккаунт') }}
                </button>
            </x-slot>
        </x-dialog-modal>
    </div>
</div>
