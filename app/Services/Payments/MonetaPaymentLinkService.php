<?php

namespace App\Services\Payments;

use App\Models\User;
use Moneta\MonetaSdk;

class MonetaPaymentLinkService
{
    public function makeTopupLink(User $user, int $sumRub): string
    {
        $orderId = $user->id . '_' . time() . '_tg';
        $description = 'Оплата заказа: ' . $orderId;

        $mSdk = new MonetaSdk($orderId, $sumRub, $description, $this->getPathConfig());
        return (string) $mSdk->getAssistantPaymentLink();
    }

    private function getPathConfig(): string
    {
        return base_path() . '/config/moneta';
    }
}

