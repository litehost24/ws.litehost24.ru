<x-form-section submit="create">
    <x-slot name="title">
        {{ __('Новая подписка') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Создание новой подписки.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Наименование') }}"/>
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name"
                     autocomplete="name"/>
            <x-input-error for="name" class="mt-2"/>
        </div>

        <!-- Description -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="description" value="{{ __('Описание') }}"/>
            <textarea id="description" type="text"
                      class="subscription-crud__textarea border-gray-300
                      focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm mt-1 block w-full"
                      wire:model="description"
                      autocomplete="description"></textarea>
            <x-input-error for="description" class="mt-2"/>
        </div>

        <!-- Price -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="price" value="{{ __('Цена в рублях') }}"/>
            <x-input id="price" type="number" class="mt-1 block w-full" wire:model="price"
                     autocomplete="price"/>
            <x-input-error for="price" class="mt-2"/>
        </div>

        <!-- Is hidden -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="is_hidden" value="{{ __('Видимость только для проверенных пользователей') }}"/>
            <x-input id="is_hidden" type="checkbox" class="mt-1 block" wire:model="is_hidden"
                     autocomplete="is_hidden" />
            <x-input-error for="is_hidden" class="mt-2"/>
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Сохранено.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled">
            {{ __('Сохранить') }}
        </x-button>
    </x-slot>
</x-form-section>
