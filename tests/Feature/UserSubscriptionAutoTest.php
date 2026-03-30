<?php

namespace Tests\Feature;

use App\Models\components\AutoUserSubscriptionManage;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSubscriptionAutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_rebilling(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => now()->addDay(),
            'is_processed' => true,
        ]);

        (new AutoUserSubscriptionManage)->start();

        $result = UserSubscription::all();

        $this->assertCount(2, $result);
        $this->assertEquals(now()->addMonth()->toDateString(), $result->last()->end_date);
        $this->assertEquals('activate', $result->last()->action);
        $this->assertEquals(1, $result->last()->is_processed);
        $this->assertStringContainsString($result->first()->file_path, $result->last()->file_path);
    }

    public function test_auto_await_payment(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        $originalSubscription = UserSubscription::factory()->create([
            'price' => 999999,
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => now()->addDay(),
            'is_processed' => true,
        ]);

        (new AutoUserSubscriptionManage)->start();

        $result = UserSubscription::all();

        $this->assertCount(1, $result); // Теперь только одна запись, обновленная
        $this->assertEquals(UserSubscription::AWAIT_PAYMENT_DATE, $result->first()->end_date);
        $this->assertEquals('activate', $result->first()->action);
        $this->assertEquals(0, $result->first()->is_processed);
        $this->assertEquals($originalSubscription->id, $result->first()->id); // Та же запись
    }

    public function test_auto_deactivate(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        $originalSubscription = UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => now()->addDay(),
            'is_processed' => true,
            'is_rebilling' => false,
        ]);

        (new AutoUserSubscriptionManage)->start();

        $result = UserSubscription::all();

        $this->assertCount(1, $result);
        $this->assertEquals('deactivate', $result->first()->action);
        $this->assertEquals(0, $result->first()->is_processed);
        $this->assertEquals($originalSubscription->id, $result->first()->id); // Та же запись
    }

    public function test_auto_await_payment_connect_is_enough_balance(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);
        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'action' => 'activate',
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => UserSubscription::AWAIT_PAYMENT_DATE,
        ]);

        (new AutoUserSubscriptionManage)->start();

        $result = UserSubscription::all();

        $this->assertCount(1, $result);
        $this->assertEquals(now()->addMonth()->toDateString(), $result->last()->end_date);
        $this->assertEquals('activate', $result->last()->action);
        $this->assertEquals(1, $result->last()->is_processed);
        $this->assertIsString($result->last()->file_path);
    }
}
