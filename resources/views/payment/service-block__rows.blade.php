@php
    $cards = $cards ?? collect();
@endphp

<div class="service-block__rows">
    @foreach($cards->chunk(2) as $row)
        <div class="service-block__row">
            @foreach($row as $cardItem)
                <div class="service-block__col">
                    @php
                        $userSub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true) ? $cardItem : null;
                        $sub = in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)
                            ? $cardItem->subscription
                            : $cardItem;
                    @endphp
                    @include('payment.service-block__card')
                </div>
            @endforeach
            @if($row->count() === 1)
                <div class="service-block__col service-block__col--empty"></div>
            @endif
        </div>
    @endforeach
</div>
