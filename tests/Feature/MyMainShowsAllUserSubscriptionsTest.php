<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MyMainShowsAllUserSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_main_shows_latest_subscriptions_including_expired(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $activeSub = Subscription::factory()->create([
            'name' => 'VPN Active Plan',
        ]);
        $expiredSub = Subscription::factory()->create([
            'name' => 'VPN Expired Plan',
        ]);
        $foreignSub = Subscription::factory()->create([
            'name' => 'Foreign Plan',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $activeSub->id,
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->subDays(10)->toDateString(),
        ]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $activeSub->id,
            'is_processed' => true,
            'action' => 'activate',
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $expiredSub->id,
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->subDays(1)->toDateString(),
        ]);

        UserSubscription::factory()->create([
            'user_id' => $otherUser->id,
            'subscription_id' => $foreignSub->id,
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/my/main');

        $response->assertOk();
        $response->assertSeeText('VPN Active Plan');
        $response->assertSeeText('VPN Expired Plan');
        $response->assertDontSeeText('Foreign Plan');
    }

    public function test_user_main_shows_all_user_subscription_rows(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $vpnA = Subscription::factory()->create(['name' => 'VPN A']);
        $vpnB = Subscription::factory()->create(['name' => 'VPN B']);

        UserSubscription::factory()->count(4)->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnB->id,
            'is_processed' => true,
            'action' => 'create',
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/my/main');

        $response->assertOk();
        $this->assertSame(5, substr_count($response->getContent(), 'name="user_subscription_id"'));
    }
}
