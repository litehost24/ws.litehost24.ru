<?php

namespace App\Console;

use App\Models\components\AutoUserSubscriptionManage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            try {
                (new AutoUserSubscriptionManage)->start();
            } catch (\Exception $e) {
                \Log::error('Error in AutoUserSubscriptionManage: ' . $e->getMessage());
                // РќРµ РїРµСЂРµР±СЂР°СЃС‹РІР°РµРј РёСЃРєР»СЋС‡РµРЅРёРµ, С‡С‚РѕР±С‹ РїР»Р°РЅРёСЂРѕРІС‰РёРє РЅРµ РїРѕРјРµС‡Р°Р» Р·Р°РґР°С‡Сѓ РєР°Рє FAIL
            }
        })->everyMinute(); // РњРѕР¶РЅРѕ РёР·РјРµРЅРёС‚СЊ РЅР° РЅСѓР¶РЅС‹Р№ РёРЅС‚РµСЂРІР°Р», РЅР°РїСЂРёРјРµСЂ dailyAt("12:00")

        // Р•Р¶РµРґРЅРµРІРЅР°СЏ РїСЂРѕРІРµСЂРєР° РѕРєРѕРЅС‡Р°РЅРёСЏ РїРѕРґРїРёСЃРѕРє Рё СѓРІРµРґРѕРјР»РµРЅРёРµ РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№
        $schedule->command('subscriptions:check-expiry')->timezone('Europe/Moscow')->dailyAt('19:00');

        // Telegram notification: auto-renew enabled but balance is too low.
        $schedule->command('subscriptions:telegram-warn-low-balance --days=3')->timezone('Europe/Moscow')->dailyAt('19:05');

        // Батч-обновление подписок, если миграция запущена
        $schedule->command('subscriptions:migrate --only-running')->everyMinute();

        // Мониторинг доступности узлов серверов.
        $schedule->command('servers:monitor')->everyFiveMinutes();

        // Collect latest node1 system/interface metrics via VPN agent API.
        $schedule->command('servers:collect-metrics')->everyFiveMinutes();

        // Collect VPN peer traffic deltas for node1 API servers.
        // Endpoint flapping detection needs minute-level granularity.
        $schedule->command('vpn:traffic-collect')->everyMinute();

        // Slowly enrich endpoint IPs with ASN/operator metadata for admin reporting.
        $schedule->command('vpn:endpoint-networks-refresh --limit=12')->everyTenMinutes()->withoutOverlapping();

        // Heal unexpected "active in DB, disabled on node1" drifts after traffic collection.
        $schedule->command('subscriptions:reconcile-server-state')->everyMinute();

        // Finish grace-period VPN mode switches by disabling old peers after the timeout.
        $schedule->command('subscriptions:complete-vpn-access-switches')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

