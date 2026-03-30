<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Подписки') }}
        </h2>
    </x-slot>
    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8 pb-0">
            @livewire('subscriptions.subscription-create-form')

            <x-section-border/>
        </div>

        @foreach($subscriptions as $subscription)
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8 pt-0 pb-0">
                @livewire('subscriptions.subscription-update-form', $subscription->toArray())

                <x-section-border/>
            </div>
        @endforeach
    </div>
</x-app-layout>
