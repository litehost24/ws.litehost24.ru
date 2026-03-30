<?php

namespace App\Listeners;

use App\Contracts\SupportChatOutboundChannel;
use App\Events\SupportChatMessageCreated;

class SendSupportChatMessageToOutboundChannel
{
    public function __construct(private readonly SupportChatOutboundChannel $channel)
    {
    }

    public function handle(SupportChatMessageCreated $event): void
    {
        $this->channel->onMessageCreated($event->message);
    }
}
