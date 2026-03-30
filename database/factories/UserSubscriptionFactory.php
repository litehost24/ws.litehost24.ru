<?php

namespace Database\Factories;

use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class UserSubscriptionFactory extends Factory
{
    protected $model = UserSubscription::class;

    public function definition(): array
    {
        return [
            'subscription_id' => \App\Models\Subscription::factory(),
            'user_id' => \App\Models\User::factory(),
            'price' => 10000, // Price in kopecks (100 rubles)
            'action' => 'create',
            'end_date' => Carbon::now()->addMonths(),
            'is_processed' => false,
            'is_rebilling' => true,
            'file_path' => $this->faker->word(),
        ];
    }
}
