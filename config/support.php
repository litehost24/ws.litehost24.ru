<?php

return [
    'outbound_driver' => env('SUPPORT_OUTBOUND_DRIVER', 'null'),
    'vpn_domains_enabled' => (bool) env('VPN_DOMAINS_ENABLED', false),

    'contact' => [
        // Where to send public "contact us" emails (from footer modal).
        'email_to' => env('SUPPORT_CONTACT_EMAIL_TO', '4743383@gmail.com'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'resolve_ip' => env('TELEGRAM_API_RESOLVE_IP', ''),
        // Bot username for deep-links: https://t.me/<bot_username>?start=...
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'litehost24bot'),
        // chat_id группы/канала поддержки (число, иногда отрицательное для супергрупп)
        'support_chat_id' => env('TELEGRAM_SUPPORT_CHAT_ID', ''),
        // куда слать системные уведомления мониторинга (по умолчанию в тот же чат)
        'monitor_chat_id' => env('TELEGRAM_MONITOR_CHAT_ID', env('TELEGRAM_SUPPORT_CHAT_ID', '')) ,
        'monitor_enabled' => (bool) env('TELEGRAM_MONITOR_ENABLED', true),
        // Secret part of webhook URL: /api/telegram/webhook/{secret}
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
        // Отправлять ли в Telegram сообщения админа тоже
        'send_admin_messages' => (bool) env('TELEGRAM_SEND_ADMIN_MESSAGES', false),
    ],
];
