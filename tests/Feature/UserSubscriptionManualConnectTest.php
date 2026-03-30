<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSubscriptionManualConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_not_connect_subscription_if_not_enough_money()
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->get('/user-subscription/connect?id=' . $subscription->id);

        $response->assertStatus(302);
        $this->assertEquals('Недостаточно средств', session('subscription-error'));
    }

    public function test_user_connect_subscription_first_time()
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create(['role' => 'user']);
        Payment::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/user-subscription/connect?id=' . $subscription->id);

        $response->assertStatus(302);
        $this->assertEquals('Подписка успешно подключена!!!', session('subscription-success'));
    }

    public function test_user_connect_subscription_second_time_like_activate()
    {
        $newPriceSubscription = 10000;
        $subscription = Subscription::factory()->create(['price' => $newPriceSubscription]);
        $user = User::factory()->create(['role' => 'user']);
        Payment::factory()->create(['user_id' => $user->id]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'is_rebilling' => false,
        ]);

        $response = $this->actingAs($user)->get('/user-subscription/connect?id=' . $subscription->id);
        $userSubscriptions = UserSubscription::query()->orderBy('id', 'asc')->get()->toArray();

        $response->assertStatus(302);
        $this->assertEquals('Автопродление включено', session('subscription-success'));
        $this->assertCount(2, $userSubscriptions);
        $this->assertEquals($newPriceSubscription, $userSubscriptions[1]['price']);
    }

    public function test_connect_json_rerenders_all_cards_with_updated_balance_state(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 15000,
        ]);

        $subscriptionA = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $subscriptionB = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $awaitingA = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscriptionA->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => UserSubscription::AWAIT_PAYMENT_DATE,
            'file_path' => 'files/device_a_1_1.zip',
            'connection_config' => 'vless://device-a#device-a',
        ]);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscriptionB->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => UserSubscription::AWAIT_PAYMENT_DATE,
            'file_path' => 'files/device_b_1_2.zip',
            'connection_config' => 'vless://device-b#device-b',
        ]);

        $response = $this->actingAs($user)->getJson('/user-subscription/connect?id=' . $subscriptionA->id . '&user_subscription_id=' . $awaitingA->id);

        $response->assertOk()
            ->assertJson([
                'message' => 'Автопродление включено',
                'balance_rub' => 0,
            ]);

        $cardsHtml = (string) $response->json('cards_html');

        $this->assertNotSame('', $cardsHtml);
        $this->assertStringContainsString('Отключить автопродление', $cardsHtml);
        $this->assertStringNotContainsString('Подключить сейчас', $cardsHtml);
        $this->assertStringContainsString('Отключить автопродление', (string) $response->json('card_html'));
        $this->assertDatabaseHas('user_subscriptions', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionA->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addMonthNoOverflow()->toDateString(),
        ]);
    }
}
