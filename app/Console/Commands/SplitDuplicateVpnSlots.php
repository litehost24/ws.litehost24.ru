<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\UserSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SplitDuplicateVpnSlots extends Command
{
    protected $signature = 'subscriptions:split-duplicate-vpn-slots
        {--dry-run : Только показать, какие цепочки будут перенесены}
        {--user-id= : Ограничить обработку одним пользователем}';

    protected $description = 'Разводит активные дубли VPN-слотов по устройствам и свободным VPN-подпискам.';

    public function handle(): int
    {
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $dryRun = (bool) $this->option('dry-run');

        $vpnSubscriptions = Subscription::query()
            ->where('name', 'VPN')
            ->orderBy('id', 'asc')
            ->get();

        if ($vpnSubscriptions->isEmpty()) {
            $this->error('VPN-подписки не найдены.');
            return self::FAILURE;
        }

        $plannedMoves = [];
        $movedChains = 0;
        $passes = 0;

        while (true) {
            $passes++;
            if ($passes > 20) {
                $this->error('Превышено число проходов по дублям, остановка.');
                return self::FAILURE;
            }

            $activeDevices = $this->activeVpnDeviceRows($vpnSubscriptions, $userId);
            $duplicateGroups = $activeDevices
                ->groupBy(fn (UserSubscription $sub) => $sub->user_id . ':' . $sub->subscription_id)
                ->filter(fn (Collection $group) => $group->count() > 1);

            if ($duplicateGroups->isEmpty()) {
                break;
            }

            $usedVpnIdsByUser = $activeDevices
                ->groupBy('user_id')
                ->map(function (Collection $group) {
                    return $group->pluck('subscription_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                });

            foreach ($duplicateGroups as $group) {
                /** @var UserSubscription $first */
                $first = $group->first();
                $groupUserId = (int) $first->user_id;
                $groupSubscriptionId = (int) $first->subscription_id;

                $rowsForSlot = UserSubscription::query()
                    ->where('user_id', $groupUserId)
                    ->where('subscription_id', $groupSubscriptionId)
                    ->orderBy('id', 'asc')
                    ->get();

                $chainsByDevice = $rowsForSlot
                    ->groupBy(fn (UserSubscription $sub) => $sub->cabinetDeviceKey())
                    ->map(fn (Collection $chain) => $chain->sortBy('id')->values());

                $activeDeviceKeys = $group
                    ->map(fn (UserSubscription $sub) => $sub->cabinetDeviceKey())
                    ->unique()
                    ->values();

                $deviceKeysToMove = $activeDeviceKeys
                    ->map(function (string $deviceKey) use ($chainsByDevice) {
                        $chain = $chainsByDevice->get($deviceKey, collect());

                        return [
                            'device_key' => $deviceKey,
                            'first_id' => (int) ($chain->first()->id ?? PHP_INT_MAX),
                        ];
                    })
                    ->sortBy('first_id')
                    ->slice(1)
                    ->pluck('device_key')
                    ->all();

                if (empty($deviceKeysToMove)) {
                    continue;
                }

                $usedVpnIds = $usedVpnIdsByUser->get($groupUserId, []);

                foreach ($deviceKeysToMove as $deviceKey) {
                    $target = $this->allocateVpnSlot($vpnSubscriptions, $usedVpnIds, $dryRun);
                    if ($target === null) {
                        $this->error("Не удалось выделить свободный VPN-слот для user_id={$groupUserId}, subscription_id={$groupSubscriptionId}.");
                        return self::FAILURE;
                    }

                    $chainRows = $chainsByDevice->get($deviceKey, collect())->values();
                    if ($chainRows->isEmpty()) {
                        continue;
                    }

                    $rowIds = $chainRows->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $plannedMoves[] = [
                        'user_id' => $groupUserId,
                        'from_subscription_id' => $groupSubscriptionId,
                        'to_subscription_id' => (int) $target['id'],
                        'device' => $this->deviceLabel($chainRows->first()),
                        'rows' => implode(',', $rowIds),
                        'created_slot' => $target['created'] ? 'yes' : 'no',
                    ];

                    if (!$dryRun) {
                        DB::transaction(function () use ($rowIds, $target) {
                            UserSubscription::query()
                                ->whereIn('id', $rowIds)
                                ->update([
                                    'subscription_id' => (int) $target['id'],
                                    'updated_at' => now(),
                                ]);
                        });
                    }

                    $movedChains++;
                }

                $usedVpnIdsByUser->put($groupUserId, $usedVpnIds);
            }

            if ($dryRun) {
                break;
            }
        }

        if (empty($plannedMoves)) {
            $this->info('Активных дублей VPN-слотов не найдено.');
            return self::SUCCESS;
        }

        $this->table(
            ['user_id', 'from', 'to', 'device', 'rows', 'new_slot'],
            array_map(static function (array $move): array {
                return [
                    $move['user_id'],
                    $move['from_subscription_id'],
                    $move['to_subscription_id'],
                    $move['device'],
                    $move['rows'],
                    $move['created_slot'],
                ];
            }, $plannedMoves)
        );

        if ($dryRun) {
            $this->info('Dry-run завершён, изменения не записаны.');
        } else {
            $this->info("Готово. Перенесено цепочек устройств: {$movedChains}.");
        }

        return self::SUCCESS;
    }

    private function activeVpnDeviceRows(Collection $vpnSubscriptions, ?int $userId = null): Collection
    {
        $seen = [];

        return UserSubscription::connectedQuery($userId)
            ->whereIn('subscription_id', $vpnSubscriptions->pluck('id')->all())
            ->orderBy('id', 'desc')
            ->get()
            ->filter(function (UserSubscription $sub) use (&$seen) {
                $key = $sub->user_id . ':' . $sub->subscription_id . ':' . $sub->cabinetDeviceKey();
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;
                return true;
            })
            ->values();
    }

    /**
     * @param array<int> $usedVpnIds
     * @return array{id:int, created:bool}|null
     */
    private function allocateVpnSlot(Collection $vpnSubscriptions, array &$usedVpnIds, bool $dryRun): ?array
    {
        foreach ($vpnSubscriptions as $vpnSubscription) {
            $vpnId = (int) $vpnSubscription->id;
            if (!in_array($vpnId, $usedVpnIds, true)) {
                $usedVpnIds[] = $vpnId;

                return ['id' => $vpnId, 'created' => false];
            }
        }

        $template = $vpnSubscriptions->last();
        if (!$template) {
            return null;
        }

        if ($dryRun) {
            $nextId = max(array_merge($usedVpnIds, $vpnSubscriptions->pluck('id')->map(fn ($id) => (int) $id)->all(), [0])) + 1;
            $usedVpnIds[] = $nextId;

            return ['id' => $nextId, 'created' => true];
        }

        $created = Subscription::create([
            'name' => $template->name,
            'description' => $template->description,
            'price' => $template->price,
            'is_hidden' => 1,
        ]);

        $vpnSubscriptions->push($created);
        $usedVpnIds[] = (int) $created->id;

        return ['id' => (int) $created->id, 'created' => true];
    }

    private function deviceLabel(UserSubscription $sub): string
    {
        $note = trim((string) ($sub->note ?? ''));
        if ($note !== '') {
            return $note;
        }

        $filePath = trim((string) ($sub->file_path ?? ''));
        if ($filePath !== '') {
            return basename($filePath);
        }

        $config = trim((string) ($sub->connection_config ?? ''));
        if ($config !== '') {
            return mb_strimwidth($config, 0, 40, '...');
        }

        return 'row:' . (int) $sub->id;
    }
}
