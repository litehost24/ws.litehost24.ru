<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralEarning extends Model
{
    protected $fillable = [
        'referrer_id',
        'referral_id',
        'user_subscription_id',
        'service_key',
        'base_price_cents',
        'markup_cents',
        'project_cut_pct',
        'project_cut_cents',
        'partner_earn_cents',
    ];
}
