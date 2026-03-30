<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiryNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $balance;
    public $subscriptions;

    /**
     * Create a new message instance.
     *
     * @param User $user
     * @param float $balance
     * @param array $subscriptions
     */
    public function __construct(User $user, float $balance, array $subscriptions)
    {
        $this->user = $user;
        $this->balance = $balance;
        $this->subscriptions = $subscriptions;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Уведомление об окончании подписок')
                    ->view('emails.subscription_expiry_notification');
    }
}