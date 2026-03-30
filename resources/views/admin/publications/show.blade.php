<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg text-gray-800 leading-tight">Публикация #{{ $publication->id }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Аудитория:</span> <span class="font-semibold">{{ $publication->audience }}</span></div>
                    <div><span class="text-gray-500">Статус:</span> <span class="font-semibold">{{ $publication->status }}</span></div>
                    <div><span class="text-gray-500">Создано:</span> <span class="font-semibold">{{ optional($publication->created_at)->format('Y-m-d H:i:s') }}</span></div>
                    <div><span class="text-gray-500">Завершено:</span> <span class="font-semibold">{{ optional($publication->finished_at)->format('Y-m-d H:i:s') ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Отправлено:</span> <span class="font-semibold">{{ $publication->sent_count }} / {{ $publication->snapshot_count }}</span></div>
                    <div><span class="text-gray-500">Ошибок:</span> <span class="font-semibold">{{ $publication->failed_count }}</span></div>
                </div>
                <div class="mt-4">
                    <div class="text-sm text-gray-500">Тема</div>
                    <div class="font-semibold text-gray-900">{{ $publication->subject }}</div>
                </div>
                <div class="mt-4">
                    <div class="text-sm text-gray-500">Текст</div>
                    <div class="mt-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm whitespace-pre-wrap">{{ $publication->body }}</div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('admin.publications.index') }}" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Назад к публикациям</a>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-base font-semibold text-gray-900">Последние получатели (до 200)</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm border border-gray-200">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">ID</th>
                                <th class="px-3 py-2 text-left">Email</th>
                                <th class="px-3 py-2 text-left">Статус</th>
                                <th class="px-3 py-2 text-left">Время</th>
                                <th class="px-3 py-2 text-left">Ошибка</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($publication->recipients as $recipient)
                                <tr class="border-t border-gray-200">
                                    <td class="px-3 py-2">{{ $recipient->id }}</td>
                                    <td class="px-3 py-2">{{ $recipient->email }}</td>
                                    <td class="px-3 py-2">{{ $recipient->status }}</td>
                                    <td class="px-3 py-2">{{ optional($recipient->sent_at)->format('Y-m-d H:i:s') ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-red-700">{{ $recipient->error_text }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-4 text-center text-gray-500">Детали получателей отсутствуют.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
