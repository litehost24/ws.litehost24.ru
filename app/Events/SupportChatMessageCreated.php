<?php

namespace App\Events;

use App\Models\SupportChatMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportChatMessageCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public SupportChatMessage $message)
    {
    }
}
