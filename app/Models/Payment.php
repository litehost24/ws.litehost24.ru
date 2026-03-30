<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property integer $id
 * @property integer $user_id
 * @property string  $amount
 * @property string  $order_name
 * @property Carbon  $created_at
 * @property Carbon  $updated_at
 *
 * @method static where(string $string, mixed $id)
 * @method static create(array $array)
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'order_name',
        'type',
    ];
}
