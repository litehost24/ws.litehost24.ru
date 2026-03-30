<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg text-gray-800 leading-tight">Публикации и рассылки</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('pub-success'))
                <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('pub-success') }}
                </div>
            @endif
            @if (session('pub-error'))
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ session('pub-error') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Публикации в кабинете</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Сейчас: <span class="font-semibold">{{ $cabinetPublicationsEnabled ? 'показываются' : 'скрыты' }}</span>
                    </p>
                </div>
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.publications.cabinet.toggle') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border {{ $cabinetPublicationsEnabled ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' }} px-3 py-2 text-sm font-semibold">
                            {{ $cabinetPublicationsEnabled ? 'Скрыть публикации в кабинете' : 'Показать публикации в кабинете' }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Публикации активным пользователям</h3>
                    <p class="mt-1 text-sm text-gray-600">Сначала сохраните запись, затем при необходимости опубликуйте в кабинете или отправьте рассылку.</p>
                </div>
                <div class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2">
                        <form method="POST" action="{{ route('admin.publications.active.save') }}" class="space-y-4 border border-gray-200 rounded-lg p-4 js-send-form" data-count="{{ $activeCount }}" data-label="активным">
                            @csrf
                            <input type="hidden" name="draft_id" value="{{ (int) ($activeDraft->id ?? 0) }}">

                            <div>
                                <label class="text-sm font-medium text-gray-700">Тема</label>
                                <input type="text" name="subject" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" value="{{ old('subject', $activeDraft->subject ?? $defaultSubject) }}" maxlength="255" required>
                            </div>

                            <div>
                                <label class="text-sm font-medium text-gray-700">Текст</label>
                                <textarea name="body" rows="8" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" maxlength="20000" required>{{ old('body', $activeDraft->body ?? '') }}</textarea>
                            </div>

                            <div>
                                <label class="text-sm font-medium text-gray-700">Тестовый email</label>
                                <input type="email" name="test_email" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" value="{{ old('test_email', $defaultTestEmail) }}" maxlength="255">
                            </div>

                            <div class="text-sm text-gray-600">
                                Получателей сейчас: <span class="font-semibold">{{ $activeCount }}</span>
                                @if(!empty($activeDraft))
                                    · Черновик #{{ $activeDraft->id }}
                                @else
                                    · Черновик не сохранен
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="inline-flex items-center rounded-md border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Сохранить запись</button>
                                <button type="submit" formaction="{{ route('admin.publications.active.publish') }}" class="inline-flex items-center rounded-md border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Опубликовать в кабинете</button>
                                <button type="submit" formaction="{{ route('admin.publications.active.preview') }}" formtarget="_blank" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Предпросмотр</button>
                                <button type="submit" formaction="{{ route('admin.publications.active.test') }}" class="inline-flex items-center rounded-md border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Отправить тест</button>
                                <button type="submit" formaction="{{ route('admin.publications.active.send') }}" class="inline-flex items-center rounded-md border border-green-300 px-3 py-2 text-sm font-semibold text-green-700 hover:bg-green-50 js-send-submit">Разослать всем активным</button>
                            </div>
                        </form>
                    </div>

                    <div class="lg:col-span-1">
                        <div class="border border-gray-200 rounded-lg p-4 h-full">
                            <h4 class="text-sm font-semibold text-gray-900">Прошлые публикации (активные)</h4>
                            <div class="mt-3 max-h-[640px] overflow-y-auto space-y-2 pr-1">
                                @forelse($activeHistory as $item)
                                    <a href="{{ route('admin.publications.show', $item) }}" class="block rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                                        <div class="text-xs text-gray-500">#{{ $item->id }} · {{ optional($item->created_at)->format('Y-m-d H:i') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-gray-900 truncate">{{ $item->subject }}</div>
                                        <div class="mt-1 text-xs text-gray-600">Статус: {{ $item->status }} · {{ $item->sent_count }}/{{ $item->snapshot_count }} · ошибок: {{ $item->failed_count }}</div>
                                    </a>
                                @empty
                                    <div class="text-sm text-gray-500">История пока пуста.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Рассылка неактивным пользователям</h3>
                    <p class="mt-1 text-sm text-gray-600">Сначала сохраните запись, затем используйте предпросмотр, тест и массовую отправку.</p>
                </div>
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.publications.inactive.save') }}" class="space-y-4 border border-gray-200 rounded-lg p-4 js-send-form" data-count="{{ $inactiveCount }}" data-label="неактивным">
                        @csrf
                        <input type="hidden" name="draft_id" value="{{ (int) ($inactiveDraft->id ?? 0) }}">

                        <div>
                            <label class="text-sm font-medium text-gray-700">Тема</label>
                            <input type="text" name="subject" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" value="{{ old('subject', $inactiveDraft->subject ?? $defaultSubject) }}" maxlength="255" required>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">Текст</label>
                            <textarea name="body" rows="8" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" maxlength="20000" required>{{ old('body', $inactiveDraft->body ?? '') }}</textarea>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">Тестовый email</label>
                            <input type="email" name="test_email" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm" value="{{ old('test_email', $defaultTestEmail) }}" maxlength="255">
                        </div>

                        <div class="text-sm text-gray-600">
                            Получателей сейчас: <span class="font-semibold">{{ $inactiveCount }}</span>
                            @if(!empty($inactiveDraft))
                                · Черновик #{{ $inactiveDraft->id }}
                            @else
                                · Черновик не сохранен
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="inline-flex items-center rounded-md border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Сохранить запись</button>
                            <button type="submit" formaction="{{ route('admin.publications.inactive.preview') }}" formtarget="_blank" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Предпросмотр</button>
                            <button type="submit" formaction="{{ route('admin.publications.inactive.test') }}" class="inline-flex items-center rounded-md border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Отправить тест</button>
                            <button type="submit" formaction="{{ route('admin.publications.inactive.send') }}" class="inline-flex items-center rounded-md border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50 js-send-submit">Разослать неактивным</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            if (!submitter || !submitter.classList.contains('js-send-submit')) {
                return;
            }

            const form = event.target.closest('.js-send-form');
            if (!form) {
                return;
            }

            const count = Number(form.dataset.count || 0);
            const label = form.dataset.label || 'пользователям';
            const ok = window.confirm('Будет отправлено ' + count + ' пользователям (' + label + '). Продолжить?');
            if (!ok) {
                event.preventDefault();
                return;
            }

            submitter.disabled = true;
        }, true);
    </script>
</x-app-layout>
