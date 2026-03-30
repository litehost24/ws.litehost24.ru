<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\IntendedRedirector;
use App\Services\ReferralPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'yandex', 'mailru'];
    private const SESSION_ACTION_KEY = 'social_auth_action';
    private const ACTION_LOGIN = 'login';
    private const ACTION_LINK = 'link';

    public function __construct(private IntendedRedirector $intendedRedirector)
    {
    }

    public function redirect(Request $request, string $provider)
    {
        $this->ensureProvider($provider);
        $request->session()->put(self::SESSION_ACTION_KEY, self::ACTION_LOGIN);

        if ($request->filled('ref_link')) {
            $request->session()->put('ref_link', $request->input('ref_link'));
        }

        return $this->socialiteDriver($provider, 'social.callback')->redirect();
    }

    public function linkRedirect(Request $request, string $provider)
    {
        $this->ensureProvider($provider);
        $request->session()->put(self::SESSION_ACTION_KEY, self::ACTION_LINK);

        return $this->socialiteDriver($provider, 'social.callback')->redirect();
    }

    public function callback(Request $request, string $provider)
    {
        $this->ensureProvider($provider);
        $action = $request->session()->pull(self::SESSION_ACTION_KEY, self::ACTION_LOGIN);

        $socialUser = $this->resolveSocialUser($provider, 'social.callback');
        if (!$socialUser) {
            return redirect()
                ->route('login')
                ->withErrors(['social' => 'Не удалось войти через ' . $this->providerLabel($provider) . '. Попробуйте еще раз.']);
        }

        if ($action === self::ACTION_LINK && $request->user()) {
            return $this->attachSocialAccount($request->user(), $socialUser, $provider);
        }

        $account = UserSocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($account) {
            $user = $account->user;
        } else {
            $email = $socialUser->getEmail();
            $user = $email ? User::where('email', $email)->first() : null;

            if (!$user) {
                $name = $socialUser->getName()
                    ?: $socialUser->getNickname()
                    ?: ($email ? Str::before($email, '@') : 'User');

                $user = User::create([
                    'name' => $name,
                    'email' => $email ?? (Str::uuid() . '@example.invalid'),
                    'password' => Hash::make(Str::random(40)),
                    'role' => '',
                    'ref_user_id' => 0,
                    'ref_link' => '',
                    'email_verified_at' => now(),
                ]);

                $this->applyReferral($user, $request->session()->pull('ref_link'));
            } elseif (empty($user->email_verified_at)) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_email' => $email,
                'provider_name' => $socialUser->getName(),
                'provider_avatar' => $socialUser->getAvatar(),
            ]);
        }

        // Social login is considered trusted for email verification in this project.
        // Ensure legacy linked accounts are also marked as verified.
        if (empty($user->email_verified_at)) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        auth()->login($user, true);

        return redirect()->to($this->intendedRedirector->resolve($request, config('fortify.home')));
    }

    public function linkCallback(Request $request, string $provider)
    {
        $this->ensureProvider($provider);

        $socialUser = $this->resolveSocialUser($provider, 'social.link.callback');
        if (!$socialUser) {
            return redirect()
                ->route('profile.show')
                ->withErrors(['social' => 'Не удалось подключить ' . $this->providerLabel($provider) . '. Попробуйте еще раз.']);
        }

        if (!$request->user()) {
            return redirect()
                ->route('profile.show')
                ->withErrors(['social' => 'Сначала войдите в аккаунт, чтобы подключить ' . $this->providerLabel($provider) . '.']);
        }

        return $this->attachSocialAccount($request->user(), $socialUser, $provider);
    }

    private function ensureProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            abort(404);
        }
    }

    private function resolveSocialUser(string $provider, string $routeName)
    {
        try {
            return $this->socialiteDriver($provider, $routeName)->user();
        } catch (\Throwable) {
            return null;
        }
    }

    private function socialiteDriver(string $provider, string $routeName)
    {
        return Socialite::driver($provider)->redirectUrl(
            route($routeName, ['provider' => $provider])
        );
    }

    private function attachSocialAccount(User $user, $socialUser, string $provider)
    {
        $account = UserSocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($account && $account->user_id !== $user->id) {
            return redirect()
                ->route('profile.show')
                ->withErrors(['social' => $this->providerLabel($provider) . ' уже привязан к другому аккаунту.']);
        }

        if (!$account) {
            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_email' => $socialUser->getEmail(),
                'provider_name' => $socialUser->getName(),
                'provider_avatar' => $socialUser->getAvatar(),
            ]);
        }

        return redirect()
            ->route('profile.show')
            ->with('status', 'social-linked');
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'yandex' => 'Yandex',
            'mailru' => 'Mail.ru',
            default => ucfirst($provider),
        };
    }

    private function applyReferral(User $user, ?string $refLink): void
    {
        if (!empty($user->role)) {
            return;
        }

        if ($refLink) {
            $parentUser = User::where('ref_link', $refLink)->first();
            if ($parentUser) {
                $user->update([
                    'ref_user_id' => $parentUser->id,
                    'role' => 'user',
                    'ref_link' => sha1($user->id . time()),
                ]);

                app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
                    $parentUser,
                    $user,
                    ReferralPricingService::SERVICE_VPN
                );
                return;
            }
        }

        $user->update([
            'ref_user_id' => 0,
            'role' => 'spy',
        ]);
    }
}
