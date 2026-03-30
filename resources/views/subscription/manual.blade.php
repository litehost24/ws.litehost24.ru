@php
    $protocol = (string) ($protocol ?? 'amnezia_vpn');
    $allowed = ['amnezia_vpn', 'amneziawg'];
    if (!in_array($protocol, $allowed, true)) {
        $protocol = 'amnezia_vpn';
    }
@endphp

@include('subscription.manual_' . $protocol)
