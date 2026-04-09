<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    private function storePasswordHash(Request $request, User $user): void
    {
        $request->session()->put([
            'password_hash_web' => $user->getAuthPassword(),
            'password_hash_sanctum' => $user->getAuthPassword(),
        ]);
    }

    private function bindUserToSession(Request $request, User $user): void
    {
        $guard = Auth::guard('web');

        $guard->login($user);
        $request->session()->put($guard->getName(), $user->getAuthIdentifier());
        $request->setUserResolver(static fn () => $user);
        Auth::shouldUse('web');
        $guard->setUser($user);
        $this->storePasswordHash($request, $user);
    }

    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin && $admin->isAdmin(), 403);

        if ((int) $admin->id === (int) $user->id) {
            return redirect()
                ->route('admin.subscriptions.index')
                ->with('error', 'Нельзя войти в кабинет своего же аккаунта.');
        }

        if ($user->isAdmin()) {
            return redirect()
                ->route('admin.subscriptions.index')
                ->with('error', 'Нельзя входить в кабинет другого администратора.');
        }

        $request->session()->put(self::SESSION_KEY, (int) $admin->id);
        $this->bindUserToSession($request, $user);

        return redirect()
            ->route('my.main')
            ->with('status', 'Вы вошли в кабинет пользователя.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = (int) $request->session()->pull(self::SESSION_KEY, 0);
        abort_unless($impersonatorId > 0, 403);

        $admin = User::find($impersonatorId);
        abort_unless($admin && $admin->isAdmin(), 403);

        $this->bindUserToSession($request, $admin);

        return redirect()
            ->route('admin.subscriptions.index')
            ->with('status', 'Вы вернулись в админский аккаунт.');
    }
}
