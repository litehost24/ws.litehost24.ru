<x-form-section submit="update">
    <x-slot name="title">
        {{ __($name) }}
    </x-slot>

    <x-slot name="description">
        {{ __('Редактирование подписки.') }}
    </x-slot>

    <x-slot name="form">
        <input type="hidden" name="id" value="{{ old('id', $id ?? '') }}">

        <!-- ID -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="id_display" value="{{ __('ID подписки') }}"/>
            <x-input id="id_display" type="text" class="mt-1 block w-full" value="{{ $id }}" readonly />
        </div>

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

            @if (!empty($is_hidden))
                <x-input id="is_hidden" type="checkbox" class="mt-1 block" wire:model="is_hidden"
                         autocomplete="is_hidden" checked />
            @else
                <x-input id="is_hidden" type="checkbox" class="mt-1 block" wire:model="is_hidden"
                         autocomplete="is_hidden" />
            @endif

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

        <x-button type="button" class="bg-red-600 hover:bg-red-700 ml-2" wire:click="delete"
                  onclick="return confirm('{{ __('Вы уверены, что хотите удалить подписку?') }}')">
            {{ __('Удалить') }}
        </x-button>
    </x-slot>
</x-form-section>
