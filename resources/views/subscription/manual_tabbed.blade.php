@php
    $manualUid = trim((string) ($manualUid ?? ('instruction-tabs-' . ($id ?? '0'))));
@endphp

<div class="instruction-tabs" data-instruction-tabs>
    <div class="instruction-tabs__bar" role="tablist" aria-label="Варианты подключения">
        <button
            type="button"
            id="{{ $manualUid }}-tab-amnezia-vpn"
            class="instruction-tabs__tab is-active"
            data-instruction-tab="amnezia_vpn"
            data-tabs-target="{{ $manualUid }}"
            role="tab"
            aria-selected="true"
            aria-controls="{{ $manualUid }}-panel-amnezia-vpn"
        >
            AmneziaVPN (Android / ПК)
        </button>
        <button
            type="button"
            id="{{ $manualUid }}-tab-amneziawg"
            class="instruction-tabs__tab"
            data-instruction-tab="amneziawg"
            data-tabs-target="{{ $manualUid }}"
            role="tab"
            aria-selected="false"
            aria-controls="{{ $manualUid }}-panel-amneziawg"
            tabindex="-1"
        >
            AmneziaWG (iPhone)
        </button>
    </div>

    <div
        id="{{ $manualUid }}-panel-amnezia-vpn"
        class="instruction-tabs__panel is-active"
        data-instruction-panel="amnezia_vpn"
        data-tabs-target="{{ $manualUid }}"
        role="tabpanel"
        aria-labelledby="{{ $manualUid }}-tab-amnezia-vpn"
    >
        @include('subscription.manual_amnezia_vpn')
    </div>

    <div
        id="{{ $manualUid }}-panel-amneziawg"
        class="instruction-tabs__panel"
        data-instruction-panel="amneziawg"
        data-tabs-target="{{ $manualUid }}"
        role="tabpanel"
        aria-labelledby="{{ $manualUid }}-tab-amneziawg"
        hidden
    >
        @include('subscription.manual_amneziawg')
    </div>
</div>
