<?php

namespace App\Contracts;

use App\Models\SupportChatMessage;

interface SupportChatOutboundChannel
{
    public function onMessageCreated(SupportChatMessage $message): void;
}
