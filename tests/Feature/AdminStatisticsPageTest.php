<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ReferralEarning;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminStatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_statistics_page_with_aggregated_chart_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-24 09:00:00'));

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $customerA = User::factory()->create([
            'created_at' => Carbon::parse('2026-03-20 10:00:00'),
        ]);
        $customerB = User::factory()->create([
            'created_at' => Carbon::parse('2026-03-22 11:30:00'),
        ]);
        $vpnSubscription = Subscription::factory()->create([
            'name' => 'VPN',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $customerA->id,
            'subscription_id' => $vpnSubscription->id,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::parse('2026-04-20 00:00:00'),
            'created_at' => Carbon::parse('2026-03-20 12:00:00'),
        ]);
        $customerBActivation = UserSubscription::factory()->create([
            'user_id' => $customerB->id,
            'subscription_id' => $vpnSubscription->id,
            'price' => 10000,
            'action' => 'activate',
            'is_processed' => true,
            'end_date' => Carbon::parse('2026-04-22 00:00:00'),
            'created_at' => Carbon::parse('2026-03-22 13:00:00'),
        ]);
        UserSubscription::factory()->create([
            'user_id' => $customerB->id,
            'subscription_id' => $vpnSubscription->id,
            'price' => 10000,
            'action' => 'deactivate',
            'is_processed' => false,
            'end_date' => Carbon::parse('9999-01-01 00:00:00'),
            'created_at' => Carbon::parse('2026-03-23 16:00:00'),
        ]);

        ReferralEarning::query()->create([
            'referrer_id' => $admin->id,
            'referral_id' => $customerB->id,
            'user_subscription_id' => $customerBActivation->id,
            'service_key' => 'vpn',
            'base_price_cents' => 5000,
            'markup_cents' => 5000,
            'project_cut_pct' => 40,
            'project_cut_cents' => 2000,
            'partner_earn_cents' => 3000,
        ]);

        Payment::factory()->create([
            'user_id' => $customerA->id,
            'amount' => 12345,
            'type' => 'topup',
            'created_at' => Carbon::parse('2026-03-20 14:00:00'),
        ]);
        Payment::factory()->create([
            'user_id' => $customerB->id,
            'amount' => 4000,
            'type' => 'topup',
            'created_at' => Carbon::parse('2026-03-22 15:00:00'),
        ]);

        try {
            $response = $this->actingAs($admin)->get(route('admin.statistics.index', ['days' => 30]));

            $response->assertOk();
            $response->assertSee('Статистика');
            $response->assertSee('Подписки в день');
            $response->assertSee('Поступления и выручка');
            $response->assertSee('Пользователи и доходность в день');
            $response->assertSee('Выручка за период');
            $response->assertSee('163.45 ₽');
            $response->assertSee('За весь период');

            $chartData = $this->extractChartData($response->getContent());

            $this->assertCount(30, $chartData['dates']);
            $this->assertSame(30, count($chartData['subscriptionsDaily']));
            $this->assertSame(30, count($chartData['paymentsCountDaily']));
            $this->assertSame(30, count($chartData['revenueDailyRub']));
            $this->assertSame(30, count($chartData['estimatedDailyIncomeRub']));
            $this->assertSame(30, count($chartData['usersTotalDaily']));

            $march20Index = array_search('2026-03-20', $chartData['dates'], true);
            $march22Index = array_search('2026-03-22', $chartData['dates'], true);

            $this->assertNotFalse($march20Index);
            $this->assertNotFalse($march22Index);

            $this->assertSame(1, $chartData['subscriptionsDaily'][$march20Index]);
            $this->assertSame(1, $chartData['subscriptionsDaily'][$march22Index]);
            $this->assertSame(1, $chartData['paymentsCountDaily'][$march20Index]);
            $this->assertSame(1, $chartData['paymentsCountDaily'][$march22Index]);
            $this->assertEquals(123.45, $chartData['revenueDailyRub'][$march20Index]);
            $this->assertEquals(40.0, $chartData['revenueDailyRub'][$march22Index]);
            $this->assertEquals(3.33, $chartData['estimatedDailyIncomeRub'][$march20Index]);
            $this->assertEquals(5.67, $chartData['estimatedDailyIncomeRub'][$march22Index]);
            $this->assertSame(1, $chartData['usersTotalDaily'][$march20Index]);
            $this->assertSame(2, $chartData['usersTotalDaily'][$march22Index]);
            $this->assertSame(3, $chartData['usersTotalDaily'][count($chartData['usersTotalDaily']) - 1]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_admin_cannot_view_statistics_page(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        $this->actingAs($user)
            ->get(route('admin.statistics.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_statistics_for_all_time_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-24 09:00:00'));

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        User::factory()->create([
            'created_at' => Carbon::parse('2026-01-10 08:00:00'),
        ]);

        Payment::factory()->create([
            'user_id' => $admin->id,
            'amount' => 5550,
            'type' => 'topup',
            'created_at' => Carbon::parse('2026-01-11 10:00:00'),
        ]);

        try {
            $response = $this->actingAs($admin)->get(route('admin.statistics.index', ['days' => 'all']));

            $response->assertOk();
            $response->assertSee('За весь период');
            $response->assertSee('Количество пополнений баланса за весь период.');

            $chartData = $this->extractChartData($response->getContent());

            $this->assertSame('2026-01-10', $chartData['dates'][0]);
            $this->assertSame('2026-03-24', $chartData['dates'][count($chartData['dates']) - 1]);
            $this->assertGreaterThan(30, count($chartData['dates']));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function extractChartData(string $content): array
    {
        $matches = [];

        $this->assertSame(
            1,
            preg_match('/const chartData = (\{.*\});/Us', $content, $matches),
            'chartData JSON was not found in the statistics page output.'
        );

        $decoded = json_decode($matches[1], true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
