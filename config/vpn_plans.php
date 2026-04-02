<?php

use App\Models\Server;

return [
    'default_purchase_plan' => 'restricted_standard',

    'plans' => [
        'regular_basic' => [
            'label' => 'Обычное подключение',
            'short_label' => 'Обычное',
            'description' => 'Для оптики, Wi‑Fi и проводного интернета. Безлимит по гигабайтам.',
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => null,
        ],
        'restricted_economy' => [
            'label' => 'Эконом',
            'short_label' => 'Эконом',
            'description' => 'Подключение при ограничениях, 10 ГБ в месяц.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        ],
        'restricted_standard' => [
            'label' => 'Стандарт',
            'short_label' => 'Стандарт',
            'description' => 'Подключение при ограничениях, 30 ГБ в месяц.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 20000,
            'traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ],
        'restricted_premium' => [
            'label' => 'Премиум',
            'short_label' => 'Премиум',
            'description' => 'Подключение при ограничениях, 50 ГБ в месяц.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 30000,
            'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        ],
    ],
];
