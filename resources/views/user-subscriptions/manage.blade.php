<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Заявки') }}

            <?php
        if(Auth::user()->role == 'admin')    echo ' - Готовых конфигов '.count(Storage::files('files/new'));
                ?>
        </h2>
    </x-slot>
    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8 pb-0">
            <div class="subscription-tickets">
                @include('user-subscriptions.user-subscriptions-column', [
                'title' => 'Активировать',
                'action' => 'activate',
                'userSubs' => $activateUserSubs,
                ])
                @include('user-subscriptions.user-subscriptions-column', [
                'title' => 'Деактивировать',
                'action' => 'deactivate',
                'userSubs' => $deactivateUserSubs,
                ])
                @include('user-subscriptions.user-subscriptions-column', [
                'title' => 'Создать',
                'action' => 'create',
                'userSubs' => $createUserSubs,
                ])
            </div>
        </div>
    </div>
</x-app-layout>
