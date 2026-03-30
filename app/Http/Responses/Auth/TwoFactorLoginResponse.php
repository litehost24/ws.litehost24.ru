<?php

namespace App\Http\Responses\Auth;

use App\Services\Auth\IntendedRedirector;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private IntendedRedirector $redirector)
    {
    }

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->to($this->redirector->resolve($request, Fortify::redirects('login')));
    }
}
