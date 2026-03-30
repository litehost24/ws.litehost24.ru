<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReferralPricingService;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

class UserController extends RegisteredUserController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        $result = parent::store($request, $creator);

        $dbUser = User::where('id', auth()->user()->id)->first();
        // Если реферальная ссылка существует, то даем роль user и генерируем новую реферальную ссылку
        if ($parentUser = User::where('ref_link', $request->input('ref_link'))->first()) {
            $authUser = auth()->user();

            $dbUser->update([
                'ref_user_id' => $parentUser->id,
                'role' => 'user',
                'ref_link' => sha1($authUser->id . time()),
            ]);

            app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
                $parentUser,
                $dbUser,
                ReferralPricingService::SERVICE_VPN
            );
        } else {
            $dbUser->update([
                'ref_user_id' => 0,
                'role' => 'spy',
            ]);
        }

        return $result;
    }
}
