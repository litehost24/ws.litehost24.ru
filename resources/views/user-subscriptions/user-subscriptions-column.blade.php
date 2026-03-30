<div class="subscription-tickets__col shadow bg-white">
    <h3 class="subscription-tickets__col__main-header">{{ $title }}:</h3>
    <div class="subscription-tickets__col__header {{ $action == 'create' ? '--create' : '' }}">
        <span>ID</span>
        <span>Пользователь</span>
        <span>Подписка</span>
        <span>Цена</span>
        <span>
        @if($action == 'deactivate')
            Дата отключения
        @elseif($action == 'activate')
            Дата активации
        @else
            Дата подключения
        @endif
        </span>

        @if($action == 'create')
            <span class="subscription-tickets__col__action">Загрузить</span>
        @else
            <span class="subscription-tickets__col__action">Скрыть</span>
        @endif
    </div>
    @foreach($userSubs as $userSub)
        @include('user-subscriptions.user-subscriptions-row', [
            'userSub' => $userSub,
            'action' => $action,
        ])
    @endforeach
</div>
