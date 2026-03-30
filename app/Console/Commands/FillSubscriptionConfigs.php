<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\UserSubscription;
use App\Models\components\UserManagerVless;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class FillSubscriptionConfigs extends Command
{
    protected $signature = 'subscriptions:fill-configs {--dry-run : Show what would be updated without saving}';
    protected $description = 'Fill connection_config for active subscriptions by querying VLESS server';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $activeSubs = UserSubscription::whereNotNull('end_date')
            ->where('end_date', '>', $now)
            ->where('end_date', '!=', UserSubscription::AWAIT_PAYMENT_DATE)
            ->orderBy('id', 'desc')
            ->get();

        $unique = collect();
        $seen = [];
        foreach ($activeSubs as $sub) {
            $key = $sub->user_id . '_' . $sub->subscription_id;
            if (!isset($seen[$key])) {
                $unique->push($sub);
                $seen[$key] = true;
            }
        }
        $activeSubs = $unique;

        $updated = 0;
        $skipped = 0;

        foreach ($activeSubs as $sub) {
            if (!empty($sub->connection_config)) {
                $skipped++;
                continue;
            }

            $email = null;
            $server = null;

            if (!empty($sub->file_path)) {
                $filename = pathinfo($sub->file_path, PATHINFO_FILENAME);
                $parts = explode('_', $filename);

                if (isset($parts[1])) {
                    $email = $parts[1];
                }

                if (isset($parts[2])) {
                    $server = Server::where('id', $parts[2])->first();
                }
            }

            if (!$server) {
                $server = Server::orderBy('id', 'asc')->first();
            }

            if (!$server || !$email) {
                Log::warning("Skip config fill: missing server or email. sub_id={$sub->id}");
                $skipped++;
                continue;
            }

            try {
                $userManager = new UserManagerVless($server->url2);
                $url = $userManager->getUserConnectionUrlByEmail($email, $server->username2, $server->password2);

                if (!$url) {
                    Log::warning("Config not found on server. sub_id={$sub->id}, email={$email}, server_id={$server->id}");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->info("[dry-run] would update sub_id={$sub->id}, email={$email}");
                } else {
                    $sub->update(['connection_config' => $url]);
                    $this->info("updated sub_id={$sub->id}, email={$email}");
                }

                $updated++;
            } catch (\Exception $e) {
                Log::error("Config fill failed. sub_id={$sub->id}, email={$email}, server_id={$server->id}. {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("done. updated={$updated}, skipped={$skipped}");

        return Command::SUCCESS;
    }
}
