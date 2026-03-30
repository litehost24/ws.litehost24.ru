<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property integer $id
 * @property integer $ref_user_id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $ref_link
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ref_user_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'ref_link',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'banner_emails_unsubscribed_at' => 'datetime',
        'last_expiry_telegram_notified_at' => 'datetime',
        'last_vless_block_email_sent_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function isAdmin() : bool
    {
        if ($this->role === 'admin') {
            return true;
        }
        return false;
    }

    public function isUser() : bool
    {
        if ($this->role === 'user' || $this->role === 'admin' || $this->role === 'partner') {
            return true;
        }
        return false;
    }

    public function socialAccounts()
    {
        return $this->hasMany(UserSocialAccount::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'ref_user_id');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'ref_user_id');
    }

    public function supportChat(): HasOne
    {
        return $this->hasOne(SupportChat::class);
    }

    public function telegramIdentity(): HasOne
    {
        return $this->hasOne(TelegramIdentity::class);
    }
}
