<x-app-layout>
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 lg:p-8 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Мои рефералы</h3>
                    <div class="mt-2 text-sm text-gray-600">
                        Всего рефералов: {{ $referrals->count() }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        Трафик — суммарный по подпискам за все время. Баланс — платежи минус списания.
                    </div>
                </div>
                <div class="p-6 lg:p-8">
                    <div class="overflow-auto rounded-md border border-gray-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">ID</th>
                                    <th class="px-3 py-2 text-left">Имя</th>
                                    <th class="px-3 py-2 text-left">Email</th>
                                    <th class="px-3 py-2 text-left">Дата</th>
                                    <th class="px-3 py-2 text-left">Активные подписки</th>
                                    <th class="px-3 py-2 text-left">Трафик</th>
                                    <th class="px-3 py-2 text-left">Баланс, ₽</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($referrals as $ref)
                                    @php
                                        $active = (int) ($activeCounts[$ref->id] ?? 0);
                                        $trafficBytes = (int) ($trafficByUser->get($ref->id, 0) ?? 0);
                                        $trafficGb = number_format($trafficBytes / 1073741824, 2, '.', ' ');
                                        $balanceCents = (int) ($balanceByUser[$ref->id] ?? 0);
                                    @endphp
                                    <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $ref->id }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $ref->name }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $ref->email }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ optional($ref->created_at)->format('Y-m-d') }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $active }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $trafficGb }} ГБ</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ number_format($balanceCents / 100, 2, '.', ' ') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-3 py-3 text-gray-500" colspan="7">Рефералов пока нет.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
