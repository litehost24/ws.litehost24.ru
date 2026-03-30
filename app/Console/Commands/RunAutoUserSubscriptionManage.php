<?php

namespace App\Console\Commands;

use App\Models\components\AutoUserSubscriptionManage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunAutoUserSubscriptionManage extends Command
{
    protected $signature = 'subscriptions:auto-manage';
    protected $description = 'Run AutoUserSubscriptionManage without the scheduler';

    public function handle(): int
    {
        try {
            (new AutoUserSubscriptionManage)->start();
        } catch (\Exception $e) {
            Log::error('Error in AutoUserSubscriptionManage: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
