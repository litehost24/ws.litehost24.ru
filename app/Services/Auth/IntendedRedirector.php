<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntendedRedirector
{
    public const SESSION_WATCH_KEY = 'auth.intended_watch';

    public function __construct(private Router $router)
    {
    }

    public function resolve(Request $request, string $fallback): string
    {
        $intended = trim((string) $request->session()->pull('url.intended', ''));
        if ($intended === '') {
            $this->clearWatch($request);

            return $fallback;
        }

        $target = $this->normalizeTarget($request, $intended);
        if ($target === null || !$this->matchesGetRoute($request, $target)) {
            $this->clearWatch($request);

            return $fallback;
        }

        $request->session()->put(self::SESSION_WATCH_KEY, $target);

        return $target;
    }

    public function shouldWatch(Request $request): bool
    {
        $watch = (string) $request->session()->get(self::SESSION_WATCH_KEY, '');

        return $watch !== '' && $watch === $this->requestUri($request);
    }

    public function clearWatch(Request $request): void
    {
        $request->session()->forget(self::SESSION_WATCH_KEY);
    }

    private function normalizeTarget(Request $request, string $target): ?string
    {
        if (str_starts_with($target, '/')) {
            return $this->stripFragment($target);
        }

        if (!filter_var($target, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($target);
        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host !== $request->getHost()) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (int) $port !== (int) $request->getPort()) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $path . $query;
    }

    private function matchesGetRoute(Request $request, string $target): bool
    {
        $server = [
            'HTTP_HOST' => $request->getHost(),
            'HTTPS' => $request->isSecure() ? 'on' : 'off',
            'SERVER_PORT' => $request->getPort(),
        ];

        try {
            $route = $this->router->getRoutes()->match(Request::create($target, 'GET', server: $server));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }

        return !$this->hasGuestMiddleware($route);
    }

    private function hasGuestMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $name = is_string($middleware) ? $middleware : '';
            if ($name === 'guest' || $name === \App\Http\Middleware\RedirectIfAuthenticated::class) {
                return true;
            }
        }

        return false;
    }

    private function requestUri(Request $request): string
    {
        return $this->stripFragment($request->getRequestUri());
    }

    private function stripFragment(string $target): string
    {
        $fragmentPos = strpos($target, '#');

        return $fragmentPos === false ? $target : substr($target, 0, $fragmentPos);
    }
}
