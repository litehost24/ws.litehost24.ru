<?php

namespace App\Http\Controllers;

use App\Models\UserSubscription;
use App\Models\components\WireguardQrCode;
use App\Services\VpnAgent\SubscriptionWireguardConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class TelegramConfigController extends Controller
{
    public function __construct(
        private readonly SubscriptionWireguardConfigResolver $configResolver,
    )
    {
    }

    public function showSubscriptionManual(Request $request): View
    {
        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
            'protocol' => ['nullable', 'string', 'max:32'],
        ]);

        $userSub = UserSubscription::query()->findOrFail((int) $data['user_subscription_id']);
        $config = $this->configResolver->resolve($userSub);
        $amneziaWgConfig = $config;
        $fileUrl = $this->resolveArchiveUrl((string) ($userSub->file_path ?? ''));

        return view('telegram.subscription-manual', [
            'protocol' => array_key_exists('protocol', $data) ? (string) $data['protocol'] : '',
            'id' => (int) $userSub->id,
            'wireguardQrDataUri' => !empty($config) ? WireguardQrCode::makeDataUri($config) : null,
            'awgQrDataUri' => !empty($amneziaWgConfig) ? WireguardQrCode::makePlainDataUri($amneziaWgConfig) : null,
            'wireguardConfig' => $config ?? '',
            'amneziaWgConfig' => $amneziaWgConfig ?? '',
            'awgConfigUrl' => URL::signedRoute('telegram.awg.download', [
                'user_subscription_id' => (int) $userSub->id,
            ]),
            'amneziaWgConfigUrl' => URL::signedRoute('telegram.awg.compat.download', [
                'user_subscription_id' => (int) $userSub->id,
            ]),
            'fileUrl' => $fileUrl,
        ]);
    }

    public function showAmneziaWg(Request $request): View
    {
        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = UserSubscription::query()->findOrFail((int) $data['user_subscription_id']);
        $config = $this->configResolver->resolve($userSub);
        if ($config === null || trim($config) === '') {
            abort(404);
        }

        return view('telegram.awg-config', [
            'configText' => $config,
            'filename' => 'peer-1.conf',
            'wireguardQrDataUri' => WireguardQrCode::makeDataUri($config),
        ]);
    }

    public function downloadAmneziaWg(Request $request): Response
    {
        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = UserSubscription::query()->findOrFail((int) $data['user_subscription_id']);
        $config = $this->configResolver->resolve($userSub);
        if ($config === null || trim($config) === '') {
            abort(404);
        }

        return response($config, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="peer-1.conf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadAmneziaWgCompatible(Request $request): Response
    {
        $data = $request->validate([
            'user_subscription_id' => ['required', 'integer', 'min:1'],
        ]);

        $userSub = UserSubscription::query()->findOrFail((int) $data['user_subscription_id']);
        $config = $this->configResolver->resolve($userSub);
        if (trim($config) === '') {
            abort(404);
        }

        return response($config, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="peer-1-amneziawg.conf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function resolveArchiveUrl(string $filePath): string
    {
        $rel = trim($filePath);
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }

        return Storage::disk('public')->url(ltrim($rel, '/'));
    }
}
