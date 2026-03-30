<?php

namespace App\Http\Middleware;

use App\Services\Auth\IntendedRedirector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectUnavailableIntendedPage
{
    public function __construct(private IntendedRedirector $redirector)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (
            !$request->isMethod('GET')
            || $request->expectsJson()
            || !Auth::check()
            || !$this->redirector->shouldWatch($request)
        ) {
            return $response;
        }

        $this->redirector->clearWatch($request);

        if (in_array($response->getStatusCode(), [403, 404], true)) {
            return redirect(config('fortify.home'));
        }

        return $response;
    }
}
