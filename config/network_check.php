<?php

return [
    'geo_lookup' => [
        'enabled' => env('NETWORK_CHECK_GEO_LOOKUP_ENABLED', true),
        'provider' => env('NETWORK_CHECK_GEO_LOOKUP_PROVIDER', 'ipwhois'),
        'timeout_seconds' => (float) env('NETWORK_CHECK_GEO_LOOKUP_TIMEOUT', 2.0),
        'cache_ttl_seconds' => (int) env('NETWORK_CHECK_GEO_LOOKUP_CACHE_TTL', 86400),
    ],
];
