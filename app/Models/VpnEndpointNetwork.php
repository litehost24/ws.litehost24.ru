<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VpnEndpointNetwork extends Model
{
    use HasFactory;

    protected $fillable = [
        'endpoint_ip',
        'as_number',
        'as_name',
        'operator_label',
        'network_type',
        'last_checked_at',
        'last_error',
    ];

    protected $casts = [
        'as_number' => 'integer',
        'last_checked_at' => 'datetime',
    ];
}
