<?php

use App\Models\Server;

return [
    'default_purchase_plan' => 'restricted_standard',

    'plans' => [
        'regular_basic' => [
            'label' => 'Обычное подключение',
            'short_label' => 'Обычное',
            'description' => 'Для Wi‑Fi и проводного интернета.',
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => null,
            'traffic_label' => 'Без ограничений по трафику',
        ],
        'restricted_mts_beta' => [
            'label' => 'Для сети МТС (бета)',
            'short_label' => 'МТС',
            'description' => 'Для мобильной сети МТС.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => null,
            'traffic_label' => 'Без ограничений по трафику',
            'purchase_server_setting' => 'vpn_bundle_mts_beta_server_id',
            'purchasable' => false,
        ],
        'restricted_mini' => [
            'label' => 'Мини',
            'short_label' => 'Мини',
            'description' => 'Для мобильной связи.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 6000,
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'traffic_label' => '5 ГБ интернета',
        ],
        'restricted_economy' => [
            'label' => 'Эконом',
            'short_label' => 'Эконом',
            'description' => 'Для мобильной связи.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_label' => '10 ГБ интернета',
        ],
        'restricted_standard' => [
            'label' => 'Стандарт',
            'short_label' => 'Стандарт',
            'description' => 'Для мобильной связи.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 20000,
            'traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
            'traffic_label' => '30 ГБ интернета',
        ],
        'restricted_premium' => [
            'label' => 'Премиум',
            'short_label' => 'Премиум',
            'description' => 'Для мобильной связи.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 30000,
            'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
            'traffic_label' => '50 ГБ интернета',
        ],
    ],
];
