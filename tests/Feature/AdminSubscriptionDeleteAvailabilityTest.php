<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionDeleteAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_button_is_visible_only_for_single_create_only_subscription(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        Server::query()->create([
            'name' => 'Server 1',
            'url1' => 'https://example.com/1',
            'url2' => 'https://example.com/2',
            'username1' => 'u1',
            'password1' => 'p1',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $singleSubscription = Subscription::factory()->create(['name' => 'VPN']);
        $historySubscription = Subscription::factory()->create(['name' => 'VPN']);

        $deletable = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $singleSubscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/10_peerone_1_01_01_2026.zip',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $historySubscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(5)->toDateString(),
            'file_path' => 'files/10_peertwo_1_01_01_2026.zip',
        ]);

        $notDeletable = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $historySubscription->id,
            'action' => 'activate',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(35)->toDateString(),
            'file_path' => 'files/10_peertwo_1_01_02_2026.zip',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee(route('admin.subscriptions.delete', ['userSubscription' => $deletable->id], false), false);
        $response->assertDontSee(route('admin.subscriptions.delete', ['userSubscription' => $notDeletable->id], false), false);
    }

    public function test_admin_cannot_delete_subscription_with_history(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        Server::query()->create([
            'name' => 'Server 1',
            'url1' => 'https://example.com/1',
            'url2' => 'https://example.com/2',
            'username1' => 'u1',
            'password1' => 'p1',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(5)->toDateString(),
            'file_path' => 'files/10_peerx_1_01_01_2026.zip',
        ]);

        $latest = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'activate',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(35)->toDateString(),
            'file_path' => 'files/10_peerx_1_01_02_2026.zip',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.subscriptions.delete', ['userSubscription' => $latest->id]));

        $response->assertRedirect();
        $response->assertSessionHas('subscription-error', 'Удаление доступно только для ошибочно заведённой подписки без истории списаний и операций.');
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $latest->id,
        ]);
    }
}
