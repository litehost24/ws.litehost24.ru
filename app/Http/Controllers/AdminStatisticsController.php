<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ReferralEarning;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminStatisticsController extends Controller
{
    /**
     * @var int[]
     */
    private const ALLOWED_DAY_RANGES = [30, 90, 180];
    private const ALL_TIME_RANGE = 'all';

    public function index(Request $request): View
    {
        $selectedRange = (string) $request->query('days', (string) self::ALLOWED_DAY_RANGES[0]);

        $end = Carbon::today();
        if ($selectedRange === self::ALL_TIME_RANGE) {
            $start = $this->resolveAllTimeStart($end);
            $rangeDescription = 'за весь период';
        } else {
            $days = (int) $selectedRange;
            if (!in_array($days, self::ALLOWED_DAY_RANGES, true)) {
                $days = self::ALLOWED_DAY_RANGES[0];
            }

            $selectedRange = (string) $days;
            $start = $end->copy()->subDays($days - 1)->startOfDay();
            $rangeDescription = 'за последние ' . $days . ' дней';
        }

        $endAt = $end->copy()->endOfDay();

        $usersDaily = $this->aggregateDaily(
            User::query(),
            $start,
            $endAt,
            'COUNT(*)'
        );

        $subscriptionsDaily = $this->aggregateDaily(
            UserSubscription::query()->whereIn('action', ['create', 'activate']),
            $start,
            $endAt,
            'COUNT(*)'
        );

        $paymentsCountDaily = $this->aggregateDaily(
            Payment::query()->where('type', 'topup'),
            $start,
            $endAt,
            'COUNT(*)'
        );

        $revenueDailyCents = $this->aggregateDaily(
            Payment::query()->where('type', 'topup'),
            $start,
            $endAt,
            'SUM(amount)'
        );

        $estimatedDailyIncomeRubByDate = $this->buildEstimatedDailyIncomeRub($start, $end);

        $labels = [];
        $labelDates = [];
        $usersTotalDaily = [];
        $subscriptionsSeries = [];
        $paymentsCountSeries = [];
        $revenueDailyRub = [];
        $estimatedDailyIncomeRub = [];

        $runningUsersTotal = User::query()
            ->where('created_at', '<', $start)
            ->count();

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();

            $newUsers = (int) ($usersDaily[$key] ?? 0);
            $runningUsersTotal += $newUsers;

            $labels[] = $cursor->format('d.m');
            $labelDates[] = $cursor->format('Y-m-d');
            $usersTotalDaily[] = $runningUsersTotal;
            $subscriptionsSeries[] = (int) ($subscriptionsDaily[$key] ?? 0);
            $paymentsCountSeries[] = (int) ($paymentsCountDaily[$key] ?? 0);
            $revenueDailyRub[] = round(((int) ($revenueDailyCents[$key] ?? 0)) / 100, 2);
            $estimatedDailyIncomeRub[] = (float) ($estimatedDailyIncomeRubByDate[$key] ?? 0.0);

            $cursor->addDay();
        }

        $totalRevenueCents = (int) Payment::query()
            ->where('type', 'topup')
            ->sum('amount');

        $periodRevenueRub = round(array_sum($revenueDailyRub), 2);
        $periodDays = max(1, count($labelDates));
        $currentEstimatedDailyIncomeRub = count($estimatedDailyIncomeRub) > 0
            ? (float) $estimatedDailyIncomeRub[count($estimatedDailyIncomeRub) - 1]
            : 0.0;

        return view('admin.statistics.index', [
            'selectedRange' => $selectedRange,
            'allowedDayRanges' => self::ALLOWED_DAY_RANGES,
            'allTimeRangeValue' => self::ALL_TIME_RANGE,
            'rangeDescription' => $rangeDescription,
            'summary' => [
                'total_users' => User::query()->count(),
                'total_payments' => Payment::query()->where('type', 'topup')->count(),
                'total_revenue_rub' => round($totalRevenueCents / 100, 2),
                'period_subscriptions' => array_sum($subscriptionsSeries),
                'period_payment_count' => array_sum($paymentsCountSeries),
                'period_revenue_rub' => $periodRevenueRub,
                'avg_daily_revenue_rub' => round($periodRevenueRub / $periodDays, 2),
                'current_estimated_daily_income_rub' => round($currentEstimatedDailyIncomeRub, 2),
            ],
            'chartData' => [
                'labels' => $labels,
                'dates' => $labelDates,
                'usersTotalDaily' => $usersTotalDaily,
                'subscriptionsDaily' => $subscriptionsSeries,
                'paymentsCountDaily' => $paymentsCountSeries,
                'revenueDailyRub' => $revenueDailyRub,
                'estimatedDailyIncomeRub' => $estimatedDailyIncomeRub,
            ],
        ]);
    }

    private function resolveAllTimeStart(Carbon $fallback): Carbon
    {
        $candidates = array_filter([
            User::query()->min('created_at'),
            UserSubscription::query()
                ->whereIn('action', ['create', 'activate'])
                ->min('created_at'),
            Payment::query()
                ->where('type', 'topup')
                ->min('created_at'),
        ]);

        if ($candidates === []) {
            return $fallback->copy()->startOfDay();
        }

        $earliest = collect($candidates)
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->first();

        return $earliest->copy()->startOfDay();
    }

    /**
     * @return array<string, float>
     */
    private function buildEstimatedDailyIncomeRub(Carbon $start, Carbon $end): array
    {
        $columns = [
            'id',
            'user_id',
            'subscription_id',
            'price',
            'action',
            'is_processed',
            'end_date',
            'created_at',
        ];

        $stateByPair = [];

        $initialRows = UserSubscription::query()
            ->where('created_at', '<', $start)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get($columns);

        foreach ($initialRows as $row) {
            $stateByPair[$this->subscriptionStateKey($row)] = $row;
        }

        $eventRows = UserSubscription::query()
            ->whereBetween('created_at', [$start, $end->copy()->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get($columns)
            ->values();

        $partnerEarnBySubscriptionId = ReferralEarning::query()
            ->whereIn('user_subscription_id', $initialRows->pluck('id')->merge($eventRows->pluck('id'))->all())
            ->pluck('partner_earn_cents', 'user_subscription_id');

        $series = [];
        $eventIndex = 0;
        $eventCount = $eventRows->count();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dayEnd = $cursor->copy()->endOfDay();

            while ($eventIndex < $eventCount && $eventRows[$eventIndex]->created_at->lte($dayEnd)) {
                $row = $eventRows[$eventIndex];
                $stateByPair[$this->subscriptionStateKey($row)] = $row;
                $eventIndex++;
            }

            $activeMonthlyCents = 0;
            foreach ($stateByPair as $row) {
                if (!$this->isRevenueActiveOnDate($row, $cursor)) {
                    continue;
                }

                $activeMonthlyCents += $this->resolveNetMonthlyCents($row, $partnerEarnBySubscriptionId);
            }

            $series[$cursor->toDateString()] = round(($activeMonthlyCents / 100) / 30, 2);
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $partnerEarnBySubscriptionId
     */
    private function resolveNetMonthlyCents(UserSubscription $row, $partnerEarnBySubscriptionId): int
    {
        $grossCents = (int) $row->price;
        $partnerEarnCents = (int) ($partnerEarnBySubscriptionId[$row->id] ?? 0);

        return max(0, $grossCents - $partnerEarnCents);
    }

    private function subscriptionStateKey(UserSubscription $row): string
    {
        return (int) $row->user_id . ':' . (int) $row->subscription_id;
    }

    private function isRevenueActiveOnDate(UserSubscription $row, Carbon $date): bool
    {
        if (!in_array((string) $row->action, ['create', 'activate'], true)) {
            return false;
        }

        if (!(bool) $row->is_processed) {
            return false;
        }

        return Carbon::parse($row->end_date)->gt($date->copy()->startOfDay());
    }

    /**
     * @return array<string, int>
     */
    private function aggregateDaily(Builder $query, Carbon $start, Carbon $end, string $aggregateExpression): array
    {
        return (clone $query)
            ->selectRaw('DATE(created_at) as day, ' . $aggregateExpression . ' as value')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->pluck('value', 'day')
            ->map(fn ($value) => (int) $value)
            ->all();
    }
}
