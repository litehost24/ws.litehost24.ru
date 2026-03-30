<?php

namespace App\Http\Responses\Auth;

use App\Services\Auth\IntendedRedirector;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private IntendedRedirector $redirector)
    {
    }

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->to($this->redirector->resolve($request, Fortify::redirects('login')));
    }
}
