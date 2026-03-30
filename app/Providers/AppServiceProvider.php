<?php

namespace App\Providers;

use App\Composers\AllViewComposer;
use App\Contracts\SupportChatOutboundChannel;
use App\Services\ChatChannels\NullOutboundChannel;
use App\Services\ChatChannels\TelegramOutboundChannel;
use App\Services\Telegram\TelegramWebhookResponseBuffer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TelegramWebhookResponseBuffer::class, fn () => new TelegramWebhookResponseBuffer());

        $this->app->bind(SupportChatOutboundChannel::class, function () {
            $driver = (string) config('support.outbound_driver', 'null');

            return match ($driver) {
                'telegram' => app(TelegramOutboundChannel::class),
                default => app(NullOutboundChannel::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $appUrl = (string) config('app.url', '');
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        view()->composer('*', AllViewComposer::class);
    }
}
