<?php

namespace App\Http\Responses\Auth;

use App\Services\Auth\IntendedRedirector;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class RegisterResponse implements RegisterResponseContract
{
    public function __construct(private IntendedRedirector $redirector)
    {
    }

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->to($this->redirector->resolve($request, Fortify::redirects('register')));
    }
}
