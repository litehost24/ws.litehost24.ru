<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralPriceRule extends Model
{
    protected $fillable = [
        'referrer_id',
        'referral_id',
        'service_key',
        'markup_cents',
    ];
}
