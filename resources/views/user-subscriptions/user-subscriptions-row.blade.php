<div class="subscription-tickets__row {{ $action == 'create' ? '--create' : '' }}">
    <span>ID: {{ $userSub->id }}</span>
    <span>{{ $userSub->user->name }}</span>
    <span>{{ $userSub->name }}</span>
    <span>{{ $userSub->subscription->priceRub() }} ₽</span>
    <span>{{ $action == 'deactivate' ? $userSub->end_date : $userSub->created_at }}</span>
    <span class="subscription-tickets__action-cell">
        @if($action == 'create')
            <form action="/user-subscription/mark-as-done-and-load-file" method="post" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="id" value="{{ $userSub->id }}">
                <input type="file" name="file" class="subscription-tickets__row__file-input" required>
                <button class="subscription-tickets__row__btn-done" onclick="return confirm('Вы уверены?')">Загрузить и скрыть</button>
            </form>
        @else
            <form action="/user-subscription/mark-as-done" method="post">
                @csrf
                <input type="hidden" name="id" value="{{ $userSub->id }}">
                <button class="subscription-tickets__row__btn-done" onclick="return confirm('Вы уверены?')">ok</button>
            </form>
        @endif
    </span>
</div>
