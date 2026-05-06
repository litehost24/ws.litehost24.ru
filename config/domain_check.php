<?php

return [
    'default_tlds' => ['ru', 'xn--p1ai', 'com', 'net', 'org'],

    'timeout_seconds' => (float) env('DOMAIN_CHECK_TIMEOUT', 2.5),
    'cache_ttl_seconds' => (int) env('DOMAIN_CHECK_CACHE_TTL', 3600),

    'rdap' => [
        'com' => 'https://rdap.verisign.com/com/v1/domain/{domain}',
        'net' => 'https://rdap.verisign.com/net/v1/domain/{domain}',
        'org' => 'https://rdap.publicinterestregistry.org/rdap/domain/{domain}',
    ],

    'whois' => [
        'ru' => [
            'host' => 'whois.tcinet.ru',
            'available_patterns' => ['No entries found'],
            'taken_patterns' => ['domain:'],
        ],
        'xn--p1ai' => [
            'host' => 'whois.tcinet.ru',
            'available_patterns' => ['No entries found'],
            'taken_patterns' => ['domain:'],
        ],
        'su' => [
            'host' => 'whois.tcinet.ru',
            'available_patterns' => ['No entries found'],
            'taken_patterns' => ['domain:'],
        ],
    ],

    'suggestions' => [
        'max' => 6,
        'patterns' => [
            '{name}24',
            '{name}-online',
            '{name}-web',
            'my{name}',
            '{name}-pro',
            '{name}-site',
            'go{name}',
            '{name}-ru',
        ],
    ],
];
