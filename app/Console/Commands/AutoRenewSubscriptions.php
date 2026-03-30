<?php

namespace App\Console\Commands;

use App\Models\components\AutoUserSubscriptionManage;
use Illuminate\Console\Command;

class AutoRenewSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:auto-renew';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Автоматическое продление подписок';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Запуск автопродления подписок...');

        try {
            (new AutoUserSubscriptionManage)->start();
            $this->info('Автопродление подписок завершено успешно.');
        } catch (\Exception $e) {
            $this->error('Ошибка при автопродлении подписок: ' . $e->getMessage());
        }
    }
}
