<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSubscriptionManualDisconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_disconnect_subscription()
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'is_rebilling' => false,
            'is_processed' => true,
        ]);

        $response = $this->actingAs($user)->get('/user-subscription/disconnect?id=' . $subscription->id);

        $response->assertStatus(302);
        $this->assertEquals('Подписка успешно отключена', session('subscription-success'));
    }
}
