<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="max-w-7xl mx-auto p-6 lg:p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
                        @foreach($list as $item)
                            <div class="scale-100 p-6 bg-white from-gray-700/50 via-transparent rounded-lg shadow-2xl
                        shadow-gray-500/20 flex transition-all duration-250">
                                <div>
                                    <div class="h-16 w-16 bg-indigo-50 flex items-center justify-center rounded-full">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                             stroke-width="1.5" stroke="#0055ff"  class="w-7 h-7 ">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621
                                                   0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25
                                                    2.25 0 002.25 2.25M16.5
                                                    7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3
                                                    4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z"></path>
                                        </svg>
                                    </div>

                                    <h2 class="mt-6 text-xl font-semibold text-gray-900">{{ $item['title'] }}</h2>

                                    <p class="mt-4 text-gray-500 text-sm leading-relaxed">
                                        {{ $item['description'] }}
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
