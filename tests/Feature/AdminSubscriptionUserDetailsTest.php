<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionUserDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_details_returns_vpn_plan_name_for_charge_rows(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addMonth()->toDateString(),
            'vpn_plan_code' => 'restricted_economy',
            'vpn_plan_name' => 'Эконом',
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.subscriptions.user.details', ['user' => $user->id]));

        $response->assertOk();
        $response->assertJsonPath('charges.0.subscription_name', 'VPN');
        $response->assertJsonPath('charges.0.vpn_plan_name', 'Эконом');
        $response->assertJsonPath('rebilling.0.vpn_plan_name', 'Эконом');
    }
}
