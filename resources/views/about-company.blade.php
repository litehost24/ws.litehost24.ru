<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="max-w-7xl mx-auto p-6 lg:p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
                        @foreach($list as $item)
                            <div
                                class="scale-100 p-6 bg-white from-gray-700/50 via-transparent rounded-lg shadow-2xl shadow-gray-500/20 flex transition-all duration-250">
                                <div>
                                    <div class="h-16 w-16 bg-indigo-50 flex items-center justify-center rounded-full">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                                             viewBox="0 0 24 24" fill="none" stroke="#0055ff"
                                             stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round">
                                            <path
                                                d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/>
                                            <path d="M7 8h10"/>
                                            <path d="M7 12h10"/>
                                            <path d="M7 16h10"/>
                                        </svg>
                                    </div>

                                    <h2 class="mt-6 text-xl font-semibold text-gray-900">{{ $item['title'] }}</h2>

                                    <p class="mt-4 text-gray-500 text-sm leading-relaxed">
                                        {!! $item['description'] !!}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @include('layouts.mini-contact')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
