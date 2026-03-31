<?php

namespace Tests\Unit;

use App\Models\UserSubscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionIsLocallyActiveTest extends TestCase
{
    public function test_expired_processed_subscription_is_not_locally_active(): void
    {
        $subscription = new UserSubscription([
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->subDay()->toDateString(),
        ]);

        $this->assertFalse($subscription->isLocallyActive());
    }

    public function test_future_processed_subscription_is_locally_active(): void
    {
        $subscription = new UserSubscription([
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->addDay()->toDateString(),
        ]);

        $this->assertTrue($subscription->isLocallyActive());
    }
}
