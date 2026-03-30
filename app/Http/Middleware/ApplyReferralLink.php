<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ReferralPricingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplyReferralLink
{
    public function handle(Request $request, Closure $next)
    {
        $refLink = trim((string) $request->query('ref_link', ''));
        if ($refLink !== '') {
            $request->session()->put('ref_link', $refLink);
        }

        $user = Auth::user();
        if ($user) {
            $pending = (string) $request->session()->get('ref_link', '');
            if ($pending !== '') {
                $this->tryApplyReferral($user, $pending, $request);
            }
        }

        return $next($request);
    }

    private function tryApplyReferral(User $user, string $refLink, Request $request): void
    {
        if ((int) $user->ref_user_id !== 0) {
            $request->session()->forget('ref_link');
            return;
        }

        if (in_array($user->role, ['admin', 'partner'], true)) {
            $request->session()->forget('ref_link');
            return;
        }

        $parent = User::query()->where('ref_link', $refLink)->first();
        if (!$parent || (int) $parent->id === (int) $user->id) {
            $request->session()->forget('ref_link');
            return;
        }

        $ref = (string) ($user->ref_link ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/i', $ref)) {
            $ref = sha1($user->id . time());
        }

        $user->update([
            'ref_user_id' => (int) $parent->id,
            'role' => 'user',
            'ref_link' => $ref,
        ]);

        app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
            $parent,
            $user,
            ReferralPricingService::SERVICE_VPN
        );

        $request->session()->forget('ref_link');
    }
}
