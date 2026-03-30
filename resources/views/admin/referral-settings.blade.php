<x-app-layout>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 lg:p-8 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Партнерские настройки</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Комиссия проекта удерживается от маржи (наценки партнёра).
                    </p>
                    @if (session('status') === 'saved')
                        <div class="mt-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Настройки сохранены.
                        </div>
                    @endif
                </div>
                <div class="p-6 lg:p-8">
                    <form method="POST" action="{{ route('admin.referral-settings.update') }}" class="max-w-sm">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700" for="project_cut_pct">Комиссия проекта, %</label>
                        <div class="mt-2 flex items-center gap-2">
                            <input
                                id="project_cut_pct"
                                name="project_cut_pct"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                value="{{ old('project_cut_pct', $projectCutPct) }}"
                                class="w-28 h-10 rounded-md border border-gray-300 px-2 text-sm"
                            >
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Сохранить
                            </button>
                        </div>
                        @error('project_cut_pct')
                            <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
