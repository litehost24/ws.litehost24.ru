<?php

namespace App\Services\Telegram;

class TelegramEmailCodeGenerator
{
    public function generate(): string
    {
        return (string) random_int(100000, 999999);
    }
}

