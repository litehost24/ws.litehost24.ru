<x-app-layout>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 lg:p-8 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Обход доменов (напрямую)</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Домены из списка будут выходить напрямую в интернет, минуя VLESS/Xray.
                    </p>
                    <p class="mt-1 text-sm text-gray-600">
                        Один домен в строке. Поддомены включаются автоматически.
                    </p>
                </div>

                <div class="p-6 lg:p-8">
                    @if ($status === 'saved')
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                            Список сохранён и отправлен на серверы.
                        </div>
                    @endif

                    @if (!empty($previewErrors))
                        <div class="mb-4 rounded-md border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                            @foreach ($previewErrors as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                            @foreach ($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.xray-bypass-domains.update') }}">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700" for="domains">
                            Список доменов
                        </label>
                        <textarea
                            id="domains"
                            name="domains"
                            rows="10"
                            class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        >{{ old('domains', $rawDomains) }}</textarea>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="submit" class="inline-flex h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Сохранить и применить
                            </button>
                            <span class="text-xs text-gray-500">Поддерживаются домены .ru/.com и .рф (будут преобразованы в Punycode).</span>
                        </div>
                    </form>

                    <div class="mt-8">
                        <h4 class="text-sm font-semibold text-gray-900">Punycode (что уходит в Xray)</h4>
                        @if (!empty($preview))
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-xs uppercase text-gray-500">
                                        <tr>
                                            <th class="px-2 py-2">Домен</th>
                                            <th class="px-2 py-2">Punycode</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($preview as $item)
                                            <tr>
                                                <td class="px-2 py-2 text-gray-900">{{ $item['display'] }}</td>
                                                <td class="px-2 py-2 text-gray-600">{{ $item['ascii'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="mt-2 text-sm text-gray-500">Список пуст.</div>
                        @endif
                    </div>

                    <div class="mt-8">
                        <h4 class="text-sm font-semibold text-gray-900">Применение на серверах</h4>
                        @if (!empty($applyResults))
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-xs uppercase text-gray-500">
                                        <tr>
                                            <th class="px-2 py-2">Сервер</th>
                                            <th class="px-2 py-2">Статус</th>
                                            <th class="px-2 py-2">Ошибка</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($applyResults as $row)
                                            <tr>
                                                <td class="px-2 py-2 text-gray-900">#{{ $row['server_id'] }} {{ $row['label'] }}</td>
                                                <td class="px-2 py-2">
                                                    @if ($row['ok'])
                                                        <span class="text-green-700">OK</span>
                                                    @else
                                                        <span class="text-red-700">Ошибка</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-2 text-gray-600">{{ $row['error'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="mt-2 text-sm text-gray-500">Ещё не применялось.</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
