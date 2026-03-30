<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPriceDefault extends Model
{
    protected $fillable = [
        'referrer_id',
        'service_key',
        'markup_cents',
    ];
}
