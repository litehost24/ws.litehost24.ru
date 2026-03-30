<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property integer $id
 * @property string $name
 * @property string $description
 * @property integer $price
 * @property boolean $is_hidden
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static create(array $array)
 * @method static where(string $string, mixed $id)
 * @method static orderBy(string $string, string $string1)
 * @method static find($subscription_id)
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'is_hidden',
    ];

    public function priceRub(): int
    {
        return $this->price / 100;
    }

    public static function nextAvailableVpnForUser(int $userId): ?self
    {
        $vpnList = self::query()
            ->where('name', 'VPN')
            ->orderBy('id', 'asc')
            ->get();

        if ($vpnList->isEmpty()) {
            return null;
        }

        $usedVpnIds = UserSubscription::getActiveList($userId)
            ->filter(fn ($sub) => $sub->subscription && $sub->subscription->name === 'VPN')
            ->pluck('subscription_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        foreach ($vpnList as $vpnSub) {
            if (!in_array((int) $vpnSub->id, $usedVpnIds, true)) {
                return $vpnSub;
            }
        }

        $last = $vpnList->last();
        if (!$last) {
            return null;
        }

        return self::create([
            'name' => $last->name,
            'description' => $last->description,
            'price' => $last->price,
            'is_hidden' => 1,
        ]);
    }
}
