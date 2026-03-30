<?php

namespace App\Console\Commands;

use App\Models\VpnDomainProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunVpnDomainProbeJobs extends Command
{
    protected $signature = 'vpn:domain-probe-runner {--once : Run only one pending job}';

    protected $description = 'Run queued VPN domain probe jobs.';

    public function handle(): int
    {
        if (!config('support.vpn_domains_enabled')) {
            $this->info('VPN domain probe runner is disabled.');
            return self::SUCCESS;
        }

        $lock = Cache::lock('vpn-domain-probe-runner', 900);
        if (!$lock->get()) {
            $this->info('Another probe runner is active, skipping.');
            return self::SUCCESS;
        }

        $processed = 0;

        try {
            while (true) {
                $job = $this->claimJob();
                if (!$job) {
                    break;
                }

                $processed++;
                $this->runJob($job);

                if ($this->option('once')) {
                    break;
                }
            }
        } finally {
            $lock->release();
        }

        $this->info("Processed jobs: {$processed}");

        return self::SUCCESS;
    }

    private function claimJob(): ?VpnDomainProbeJob
    {
        return DB::transaction(function () {
            $job = VpnDomainProbeJob::query()
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$job) {
                return null;
            }

            $job->status = 'running';
            $job->attempts = (int) $job->attempts + 1;
            $job->started_at = Carbon::now();
            $job->save();

            return $job;
        });
    }

    private function runJob(VpnDomainProbeJob $job): void
    {
        try {
            $args = [
                '--once' => true,
                '--server-id' => $job->server_id,
                '--limit' => $job->limit,
                '--days' => $job->days,
                '--fresh-hours' => $job->fresh_hours,
            ];

            $exitCode = Artisan::call('vpn:domain-probe', $args);
            $output = trim((string) Artisan::output());

            $job->status = $exitCode === 0 ? 'done' : 'failed';
            $job->output = $output;
            $job->finished_at = Carbon::now();
            $job->save();
        } catch (\Throwable $e) {
            $job->status = 'failed';
            $job->error = $e->getMessage();
            $job->finished_at = Carbon::now();
            $job->save();
        }
    }
}
