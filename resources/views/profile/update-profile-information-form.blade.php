<form wire:submit.prevent="updateProfileInformation" class="profile-card">
    <div class="profile-card__header">
        <div>
            <div class="profile-card__title">{{ __('Информация профиля') }}</div>
            <div class="profile-card__desc">{{ __('Обновите информацию профиля и адрес электронной почты вашей учетной записи.') }}</div>
        </div>
    </div>

    <div class="profile-card__body">
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div x-data="{photoName: null, photoPreview: null}" class="mb-4">
                <input type="file" id="photo" class="d-none"
                       wire:model.live="photo"
                       x-ref="photo"
                       x-on:change="
                            photoName = $refs.photo.files[0].name;
                            const reader = new FileReader();
                            reader.onload = (e) => {
                                photoPreview = e.target.result;
                            };
                            reader.readAsDataURL($refs.photo.files[0]);
                       " />

                <label for="photo" class="form-label">{{ __('Фото') }}</label>

                <div class="profile-photo-row">
                    <div class="profile-photo-preview" x-show="photoPreview" x-bind:style="'background-image: url(\'' + photoPreview + '\');'" style="display: none;"></div>
                    <img x-show="! photoPreview" src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}" class="profile-photo-img">

                    <div class="profile-photo-actions">
                        <button type="button" class="btn btn-outline-primary btn-sm" x-on:click.prevent="$refs.photo.click()">
                            {{ __('Выбрать новое фото') }}
                        </button>

                        @if ($this->user->profile_photo_path)
                            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="deleteProfilePhoto">
                                {{ __('Удалить фото') }}
                            </button>
                        @endif
                    </div>
                </div>

                <x-input-error for="photo" class="profile-error mt-2" />
            </div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <label for="name" class="form-label">{{ __('Полностью: Фамилия Имя Отчество') }}</label>
                <input id="name" type="text" class="form-control" wire:model="state.name" required autocomplete="name" />
                <x-input-error for="name" class="profile-error mt-2" />
            </div>

            <div class="col-12 col-lg-6">
                <label for="phone" class="form-label">{{ __('Телефон') }}</label>
                <input id="phone" type="tel" class="form-control" wire:model="state.phone" required autocomplete="tel" />
                <x-input-error for="phone" class="profile-error mt-2" />
            </div>

            <div class="col-12 col-lg-6">
                <label for="email" class="form-label">{{ __('Электронная почта') }}</label>
                <input id="email" type="email" class="form-control" wire:model="state.email" required autocomplete="username" />
                <x-input-error for="email" class="profile-error mt-2" />

                @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                    <div class="profile-inline-alert mt-3">
                        <div>{{ __('Ваш адрес электронной почты не подтвержден.') }}</div>
                        <button type="button" class="btn btn-link p-0 align-baseline" wire:click.prevent="sendEmailVerification">
                            {{ __('Нажмите здесь, чтобы повторно отправить письмо для подтверждения.') }}
                        </button>

                        @if ($this->verificationLinkSent)
                            <div class="mt-2 text-success small">
                                {{ __('Новая ссылка для подтверждения была отправлена на ваш адрес электронной почты.') }}
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="profile-card__footer">
        <x-action-message class="profile-status me-auto" on="saved">
            {{ __('Сохранено.') }}
        </x-action-message>

        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="photo">
            {{ __('Сохранить') }}
        </button>
    </div>
</form>
