<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'message',
        'subject',
        'attach_archives',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'attach_archives' => 'boolean',
    ];
}
