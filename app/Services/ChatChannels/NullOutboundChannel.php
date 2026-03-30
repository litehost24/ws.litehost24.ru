<?php

namespace App\Services\ChatChannels;

use App\Contracts\SupportChatOutboundChannel;
use App\Models\SupportChatMessage;

class NullOutboundChannel implements SupportChatOutboundChannel
{
    public function onMessageCreated(SupportChatMessage $message): void
    {
        // Intentionally noop. Telegram / external channels will be connected later.
    }
}
