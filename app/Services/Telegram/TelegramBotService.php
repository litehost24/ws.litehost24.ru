<?php

namespace App\Services\Telegram;

use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\TelegramConnectToken;
use App\Models\components\Balance;
use App\Models\components\UserSubscriptionInfo;
use App\Models\UserSubscription;
use App\Mail\TelegramEmailVerifyCodeMail;
use App\Services\Payments\MonetaPaymentLinkService;
use App\Services\QrCodeService;
use App\Services\ReferralPricingService;
use App\Services\Telegram\TelegramSubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class TelegramBotService
{
    public function __construct(
        private readonly TelegramApiClient $api,
        private readonly MonetaPaymentLinkService $payments,
        private readonly TelegramEmailCodeGenerator $codeGen,
        private readonly TelegramSubscriptionService $subs,
    )
    {
    }

    public function handlePrivateUpdate(array $payload): void
    {
        $updateId = (int) ($payload['update_id'] ?? 0);
        $message = $payload['message'] ?? null;

        // Minimal MVP: handle text messages only.
        if (!is_array($message)) {
            return;
        }

        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];

        $telegramUserId = (int) ($from['id'] ?? 0);
        $chatId = (int) ($chat['id'] ?? 0);
        if ($telegramUserId === 0 || $chatId === 0) {
            return;
        }

        $identity = $this->findOrCreateIdentity($telegramUserId, $chatId, $from);

        // Idempotency for Telegram retries.
        if ($updateId > 0 && $identity->last_update_id >= $updateId) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        // Create a placeholder spy-user for this telegram user, so we can later "upgrade" by referral.
        if (!$identity->user_id) {
            $user = $this->createSpyUser($identity);
            $identity->update(['user_id' => $user->id]);
        }

        $identity->loadMissing('user');
        $user = $identity->user;
        if (!$user) {
            return;
        }

        // /start [payload]
        if (str_starts_with($text, '/start')) {
            $payloadArg = trim((string) Str::after($text, '/start'));
            if ($payloadArg !== '') {
                $payloadArg = ltrim($payloadArg);
            }

            if ($payloadArg !== '' && str_starts_with($payloadArg, 'link_')) {
                $token = substr($payloadArg, 5);
                $linkedUser = $this->applyAccountLinkFromStartPayload($identity, $token);
                if ($linkedUser) {
                    $identity->load('user');
                    $user = $linkedUser;
                } else {
                    $this->api->sendMessage($identity->telegram_chat_id, "Ссылка устарела или недействительна. Откройте личный кабинет и нажмите «Подключить Telegram-бота» еще раз.");
                    $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                    return;
                }
            }

            if ($payloadArg !== '' && str_starts_with($payloadArg, 'ref_')) {
                $refLink = substr($payloadArg, 4);
                $this->applyReferralFromStartPayload($identity, $user, $refLink);
            }

            if ($this->canUseBot($user)) {
                $this->sendMenu($identity->telegram_chat_id, 'Доступ активирован.');
            } else {
                $this->sendReferralRequired($identity->telegram_chat_id);
            }

            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Email onboarding
        if ($text === '/email' || mb_strtolower($text) === 'указать email' || mb_strtolower($text) === 'сменить email') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } else {
                $this->identitySetState($identity, ['mode' => 'email_enter'], 600);
                $this->api->sendMessage($identity->telegram_chat_id, "Введите ваш email (мы отправим код подтверждения).");
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Referral link output
        if ($text === '/ref' || $text === '/referral' || mb_strtolower($text) === 'моя реферальная ссылка') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } else {
                $this->sendReferralLinks($identity->telegram_chat_id, $user);
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Balance
        if (mb_strtolower($text) === 'баланс' || $text === '/balance') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } elseif (!$this->hasVerifiedRealEmail($user)) {
                $this->sendEmailRequired($identity->telegram_chat_id);
            } else {
                $balanceRub = (int) (new Balance)->getBalanceRub($user->id);
                $this->api->sendMessage($identity->telegram_chat_id, "Баланс: {$balanceRub} ₽");
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Top-up entry point
        if (mb_strtolower($text) === 'пополнить' || $text === '/topup') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } elseif (!$this->hasVerifiedRealEmail($user)) {
                $this->sendEmailRequired($identity->telegram_chat_id);
            } else {
                $this->identitySetState($identity, ['mode' => 'topup_pick'], 600);
                $this->sendTopupMenu($identity->telegram_chat_id);
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Purchase subscription entry point (currently VPN).
        if (mb_strtolower($text) === 'купить подписку' || $text === '/buy') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } elseif (!$this->hasVerifiedRealEmail($user)) {
                $this->sendEmailRequired($identity->telegram_chat_id);
            } else {
                $this->identitySetState($identity, ['mode' => 'buy_pick'], 600);
                $this->sendBuyMenu($identity->telegram_chat_id, $user);
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Subscriptions status
        if (mb_strtolower($text) === 'мои подписки' || $text === '/subs') {
            if (!$this->canUseBot($user)) {
                $this->sendReferralRequired($identity->telegram_chat_id);
            } elseif (!$this->hasVerifiedRealEmail($user)) {
                $this->sendEmailRequired($identity->telegram_chat_id);
            } else {
                $this->identityClearState($identity);
                $this->sendSubscriptionsCards($identity->telegram_chat_id, $user->id);
            }
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // Default: enforce referral gate.
        if (!$this->canUseBot($user)) {
            $this->sendReferralRequired($identity->telegram_chat_id);
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // State machine: email flows.
        $state = $this->identityGetState($identity);
        if (is_array($state) && ($state['mode'] ?? '') === 'email_enter') {
            $email = trim(mb_strtolower($text));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->api->sendMessage($identity->telegram_chat_id, "Некорректный email. Попробуйте еще раз.");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $existing = User::query()->where('email', $email)->first();

            $code = $this->codeGen->generate();
            $this->identitySetState($identity, [
                'mode' => 'email_code',
                'pending_email' => $email,
                // Store only a hash to avoid leaking code via DB.
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                // If email belongs to another user, we will link telegram to that account on success.
                'existing_user_id' => $existing ? (int) $existing->id : 0,
            ], 600);

            try {
                Mail::to($email)->send(new TelegramEmailVerifyCodeMail($code));
                $extra = '';
                if ($existing && (int) $existing->id !== (int) $user->id) {
                    $extra = "\n\nМы нашли аккаунт с таким email. После ввода кода мы привяжем Telegram к вашему аккаунту.";
                }
                $this->api->sendMessage($identity->telegram_chat_id, "Мы отправили код на {$email}. Введите 6 цифр сюда." . $extra);
            } catch (\Throwable $e) {
                $this->identityClearState($identity);
                $this->api->sendMessage($identity->telegram_chat_id, "Не удалось отправить письмо. Попробуйте позже или укажите другой email.");
            }

            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        if (is_array($state) && ($state['mode'] ?? '') === 'email_code') {
            $codeIn = preg_replace('/\\D+/', '', $text);
            $pendingEmail = (string) ($state['pending_email'] ?? '');
            $hash = (string) ($state['code_hash'] ?? '');
            $attempts = (int) ($state['attempts'] ?? 0);
            $existingUserId = (int) ($state['existing_user_id'] ?? 0);

            if ($pendingEmail === '' || $hash === '') {
                $this->identityClearState($identity);
                $this->api->sendMessage($identity->telegram_chat_id, "Сессия подтверждения истекла. Нажмите «Указать email» снова.");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            if ($codeIn === '' || !Hash::check((string) $codeIn, $hash)) {
                $attempts++;
                if ($attempts >= 5) {
                    $this->identityClearState($identity);
                    $this->api->sendMessage($identity->telegram_chat_id, "Слишком много попыток. Нажмите «Указать email» и начните заново.");
                } else {
                    $state['attempts'] = $attempts;
                    $this->identitySetState($identity, $state, 600);
                    $this->api->sendMessage($identity->telegram_chat_id, "Неверный код. Попробуйте еще раз.");
                }
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            if ($existingUserId > 0 && (int) $existingUserId !== (int) $user->id) {
                // Link telegram to the existing account.
                $existingUser = User::query()->find($existingUserId);
                if (!$existingUser || mb_strtolower((string) $existingUser->email) !== mb_strtolower($pendingEmail)) {
                    $this->identityClearState($identity);
                    $this->api->sendMessage($identity->telegram_chat_id, "Не удалось привязать email. Нажмите «Указать email» и повторите.");
                    $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                    return;
                }

                $identity->update(['user_id' => $existingUser->id]);
                $user = $existingUser;
            } else {
                // Apply email on current user (verification + eligibility handled below).
                $user->forceFill(['email' => $pendingEmail])->save();
            }

            $this->finalizeEmailVerificationAndEligibility($identity, $user, $pendingEmail);

            $this->identityClearState($identity);
            $this->api->sendMessage($identity->telegram_chat_id, "Email подтвержден. Теперь доступны баланс и оплата.");
            $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');

            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // State machine: top-up flows.
        if (is_array($state) && ($state['mode'] ?? '') === 'topup_pick') {
            $choice = mb_strtolower($text);
            $amountMap = [
                '100 ₽' => 100,
                '300 ₽' => 300,
                '500 ₽' => 500,
                '1000 ₽' => 1000,
            ];

            if (isset($amountMap[$text])) {
                $this->identityClearState($identity);
                $this->sendTopupLink($identity->telegram_chat_id, $user, $amountMap[$text]);
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            // Allow entering a custom amount right away (without pressing "Другая сумма").
            $inlineAmount = (int) preg_replace('/[^0-9]/', '', $text);
            if ($inlineAmount > 0) {
                if ($inlineAmount < 10 || $inlineAmount > 50000) {
                    $this->api->sendMessage($identity->telegram_chat_id, "Введите сумму от 10 до 50000 ₽.");
                    $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                    return;
                }
                $this->identityClearState($identity);
                $this->sendTopupLink($identity->telegram_chat_id, $user, $inlineAmount);
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            if ($choice === 'другая сумма') {
                $this->identitySetState($identity, ['mode' => 'topup_custom'], 600);
                $this->api->sendMessage($identity->telegram_chat_id, "Введите сумму пополнения в рублях (например: 500).");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            if ($choice === 'назад') {
                $this->identityClearState($identity);
                $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $this->api->sendMessage($identity->telegram_chat_id, "Выберите сумму кнопкой или напишите число (например: 500).");
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        if (is_array($state) && ($state['mode'] ?? '') === 'topup_custom') {
            if (mb_strtolower($text) === 'назад') {
                $this->identitySetState($identity, ['mode' => 'topup_pick'], 600);
                $this->sendTopupMenu($identity->telegram_chat_id);
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $amount = (int) preg_replace('/[^0-9]/', '', $text);
            if ($amount < 10 || $amount > 50000) {
                $this->api->sendMessage($identity->telegram_chat_id, "Введите сумму от 10 до 50000 ₽.");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }
            $this->identityClearState($identity);
            $this->sendTopupLink($identity->telegram_chat_id, $user, $amount);
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // State machine: buy flows.
        if (is_array($state) && ($state['mode'] ?? '') === 'buy_pick') {
            $choice = mb_strtolower($text);
            if ($choice === 'назад') {
                $this->identityClearState($identity);
                $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            if ($choice === 'vpn' || $choice === 'подписка') {
                $this->identitySetState($identity, ['mode' => 'buy_vpn_note'], 600);
                $this->sendVpnNotePrompt($identity->telegram_chat_id);
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $this->api->sendMessage($identity->telegram_chat_id, "Выберите подписку кнопкой.");
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        if (is_array($state) && ($state['mode'] ?? '') === 'buy_vpn_note') {
            $choice = mb_strtolower($text);
            if ($choice === 'назад') {
                $this->identitySetState($identity, ['mode' => 'buy_pick'], 600);
                $this->sendBuyMenu($identity->telegram_chat_id, $user);
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $note = null;
            if ($choice !== 'без пометки') {
                $note = trim($text);
                if ($note !== '' && mb_strlen($note) > 255) {
                    $this->api->sendMessage($identity->telegram_chat_id, "Пометка слишком длинная. Максимум 255 символов.");
                    $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                    return;
                }
                if ($note === '') {
                    $note = null;
                }
            }

            // Prevent double-charge if Telegram retries this update due to slow upstream (server package build).
            if ($updateId > 0) {
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            }

            $this->identityClearState($identity);

            $result = $this->subs->buyVpn($user, $note);
            if (!$result['ok']) {
                $this->api->sendMessage($identity->telegram_chat_id, (string) $result['message']);
                $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
                return;
            }

            $lines = [
                "VPN подключен.",
            ];
            if (!empty($result['end_date'])) {
                $lines[] = "Оплачен до: " . $result['end_date'];
            }
            if (isset($result['balance_rub'])) {
                $lines[] = "";
                $lines[] = "Баланс: " . (int) $result['balance_rub'] . " ₽";
            }
            $userSubId = (int) ($result['user_subscription_id'] ?? 0);
            $keyboard = $this->buildInstructionKeyboard(
                $userSubId,
                $userSubId > 0
            );
            if ($keyboard !== null) {
                $lines[] = "";
                $lines[] = "Инструкции по подключению:";
            }
            $options = [];
            if ($keyboard !== null) {
                $options['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
            }
            $this->api->sendMessage($identity->telegram_chat_id, implode("\n", $lines), $options);
            $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
            return;
        }

        if (is_array($state) && ($state['mode'] ?? '') === 'subs_pick') {
            $choice = mb_strtolower($text);
            if ($choice === 'назад') {
                $this->identityClearState($identity);
                $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $num = null;
            if (preg_match('/^\\s*\\/?sub\\s*(\\d+)\\s*$/iu', $text, $m)) {
                $num = (int) $m[1];
            } else {
                $num = (int) preg_replace('/\\D+/', '', $text);
            }

            $items = is_array($state['items'] ?? null) ? $state['items'] : [];
            if ($num <= 0 || !isset($items[$num - 1]) || !is_array($items[$num - 1])) {
                $this->api->sendMessage($identity->telegram_chat_id, "Отправьте номер подписки из списка (например: 1) или «Назад».");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $item = $items[$num - 1];
            $userSubId = (int) ($item['user_sub_id'] ?? 0);
            if ($userSubId <= 0) {
                $this->api->sendMessage($identity->telegram_chat_id, "Не удалось открыть подписку. Откройте «Мои подписки» еще раз.");
                $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
                return;
            }

            $this->identityClearState($identity);
            $this->sendSubscriptionDetails($identity->telegram_chat_id, (int) $user->id, $userSubId);
            $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');
            $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
            return;
        }

        // For MVP, only menu + referral links.
        $this->sendMenu($identity->telegram_chat_id, 'Выберите действие:');

        $identity->update(['last_update_id' => max($updateId, $identity->last_update_id)]);
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function findOrCreateIdentity(int $telegramUserId, int $chatId, array $from): TelegramIdentity
    {
        $identity = TelegramIdentity::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if (!$identity) {
            try {
                return TelegramIdentity::create([
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $chatId,
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? null,
                    'last_name' => $from['last_name'] ?? null,
                    'last_update_id' => 0,
                ]);
            } catch (QueryException $e) {
                // A concurrent poll/webhook worker may have inserted the identity first.
                $duplicateKey = (int) ($e->errorInfo[1] ?? 0);
                if ($duplicateKey !== 1062) {
                    throw $e;
                }

                $identity = TelegramIdentity::query()
                    ->where('telegram_user_id', $telegramUserId)
                    ->first();

                if (!$identity) {
                    throw $e;
                }
            }
        }

        // Keep chat_id and profile fields fresh (user may restart bot or rename account).
        $identity->update([
            'telegram_chat_id' => $chatId,
            'username' => $from['username'] ?? $identity->username,
            'first_name' => $from['first_name'] ?? $identity->first_name,
            'last_name' => $from['last_name'] ?? $identity->last_name,
        ]);

        return $identity;
    }

    private function canUseBot(User $user): bool
    {
        return in_array($user->role, ['user', 'admin', 'partner'], true);
    }

    private function hasVerifiedRealEmail(User $user): bool
    {
        if (empty($user->email_verified_at)) {
            return false;
        }
        $email = (string) ($user->email ?? '');
        if ($email === '') {
            return false;
        }
        return !str_ends_with(mb_strtolower($email), '@example.invalid');
    }

    private function sendEmailRequired(int $chatId): void
    {
        $this->api->sendMessage($chatId, implode("\n", [
            "Сначала нужно указать и подтвердить email.",
            "Нажмите «Указать email» или отправьте команду /email.",
        ]));
    }

    private function sendReferralRequired(int $chatId): void
    {
        $botUsername = (string) config('support.telegram.bot_username', 'litehost24bot');
        $this->api->sendMessage($chatId, implode("\n", [
            "Доступ к боту возможен только по реферальной ссылке клиента.",
            "",
            "Попросите у клиента ссылку и перейдите по ней. Обычно это выглядит так:",
            "https://t.me/{$botUsername}?start=ref_<ref_link>",
        ]));
    }

    private function sendMenu(int $chatId, string $text): void
    {
        // If user has verified email, we show "Сменить email" instead of "Указать email".
        $user = User::query()->find(
            TelegramIdentity::query()->where('telegram_chat_id', $chatId)->value('user_id')
        );
        $emailLabel = ($user && $this->hasVerifiedRealEmail($user)) ? 'Сменить email' : 'Указать email';

        $keyboard = [
            'keyboard' => [
                [['text' => 'Баланс'], ['text' => 'Пополнить']],
                [['text' => 'Мои подписки'], ['text' => 'Купить подписку']],
                [['text' => 'Моя реферальная ссылка']],
                [['text' => $emailLabel]],
            ],
            'resize_keyboard' => true,
        ];

        $this->api->sendMessage($chatId, $text, [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendBuyMenu(int $chatId, User $user): void
    {
        $nextVpnPriceRub = $this->subs->getNextVpnPriceRub($user);

        $keyboard = [
            'keyboard' => [
                [['text' => 'Подписка']],
                [['text' => 'Назад']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $suffix = $nextVpnPriceRub !== null ? " · {$nextVpnPriceRub} ₽/мес" : '';
        $this->api->sendMessage($chatId, "Выберите подписку для покупки:\nПодписка{$suffix}", [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendVpnNotePrompt(int $chatId): void
    {
        $keyboard = [
            'keyboard' => [
                [['text' => 'Без пометки'], ['text' => 'Назад']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $this->api->sendMessage($chatId, implode("\n", [
            "Введите пометку (например: ПК, Ноутбук, Телефон).",
            "Или нажмите «Без пометки».",
        ]), [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendReferralLinks(int $chatId, User $user): void
    {
        $ref = (string) ($user->ref_link ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/i', $ref)) {
            $user->forceFill(['ref_link' => sha1($user->id . time())])->save();
        }

        $siteUrl = rtrim((string) config('app.url'), '/');
        $botUsername = (string) config('support.telegram.bot_username', 'litehost24bot');

        $siteLink = $siteUrl . '/register?ref_link=' . $user->ref_link;
        $tgLink = 'https://t.me/' . $botUsername . '?start=ref_' . $user->ref_link;

        $this->api->sendMessage($chatId, implode("\n", [
            "Ваша реферальная ссылка:",
            $siteLink,
            "",
            "Ссылка для запуска бота с рефералкой:",
            $tgLink,
        ]));

        $qr = app(QrCodeService::class);
        $siteQr = $qr->makePng($siteLink, 300);
        if ($siteQr) {
            $this->api->sendMessage($chatId, "QR для сайта");
            $this->api->sendPhoto($chatId, 'ref-site.png', $siteQr);
        }

        $tgQr = $qr->makePng($tgLink, 300);
        if ($tgQr) {
            $this->api->sendMessage($chatId, "QR для Telegram");
            $this->api->sendPhoto($chatId, 'ref-telegram.png', $tgQr);
        }
    }

    private function applyReferralFromStartPayload(TelegramIdentity $identity, User $user, string $refLink): void
    {
        $refLink = trim(urldecode($refLink));
        if ($refLink === '') {
            return;
        }

        $parent = User::query()->where('ref_link', $refLink)->first();
        if (!$parent) {
            return;
        }

        // Keep referrer info on the identity so later email-linking can apply it to the final account.
        if ((int) ($identity->pending_ref_user_id ?? 0) !== (int) $parent->id) {
            $identity->forceFill(['pending_ref_user_id' => (int) $parent->id])->save();
        }

        // If already eligible, we keep current referrer unless empty.
        if (in_array($user->role, ['user', 'admin', 'partner'], true)) {
            if ((int) $user->ref_user_id === 0) {
                $user->forceFill(['ref_user_id' => $parent->id])->save();
                app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
                    $parent,
                    $user,
                    ReferralPricingService::SERVICE_VPN
                );
            }
            if (empty($user->ref_link)) {
                $user->forceFill(['ref_link' => sha1($user->id . time())])->save();
            }
            return;
        }

        $user->forceFill([
            'ref_user_id' => $parent->id,
            'role' => 'user',
            'ref_link' => sha1($user->id . time()),
        ])->save();

        app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
            $parent,
            $user,
            ReferralPricingService::SERVICE_VPN
        );
    }

    private function applyAccountLinkFromStartPayload(TelegramIdentity $identity, string $token): ?User
    {
        $token = trim(urldecode($token));
        if ($token === '' || strlen($token) > 200) {
            return null;
        }

        $hash = hash('sha256', $token);

        $row = TelegramConnectToken::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);
        if (!$user) {
            return null;
        }

        // Link telegram identity to this account.
        $identity->forceFill(['user_id' => $user->id])->save();

        // Invalidate the token.
        $row->forceFill(['used_at' => Carbon::now()])->save();

        return $user;
    }

    private function finalizeEmailVerificationAndEligibility(TelegramIdentity $identity, User $user, string $email): void
    {
        $now = Carbon::now();

        // Mark email as verified for the account that has proven ownership via telegram code.
        if (empty($user->email_verified_at)) {
            $user->forceFill(['email_verified_at' => $now]);
        }

        // Apply referral to the final account if we have it, so bot features unlock even when we linked
        // telegram to a pre-existing (possibly spy) account.
        $pendingRefUserId = (int) ($identity->pending_ref_user_id ?? 0);
        $didSetReferrer = false;
        if ($pendingRefUserId > 0) {
            if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
                $user->forceFill(['role' => 'user']);
            }
            if ((int) $user->ref_user_id === 0) {
                $user->forceFill(['ref_user_id' => $pendingRefUserId]);
                $didSetReferrer = true;
            }
            $ref = (string) ($user->ref_link ?? '');
            if (!preg_match('/^[a-f0-9]{40}$/i', $ref)) {
                $user->forceFill(['ref_link' => sha1($user->id . time())]);
            }
        }

        // Ensure email matches (for safety) and persist.
        if (mb_strtolower((string) $user->email) !== mb_strtolower($email)) {
            $user->forceFill(['email' => $email]);
        }

        $user->save();

        if ($didSetReferrer) {
            $parent = User::query()->find($pendingRefUserId);
            if ($parent) {
                app(ReferralPricingService::class)->lockDefaultMarkupForReferral(
                    $parent,
                    $user,
                    ReferralPricingService::SERVICE_VPN
                );
            }
        }

        // Clear pending ref after successful activation to avoid accidental reuse.
        if (!empty($identity->pending_ref_user_id)) {
            $identity->forceFill(['pending_ref_user_id' => null])->save();
        }
    }

    private function createSpyUser(TelegramIdentity $identity): User
    {
        $telegramUserId = (int) $identity->telegram_user_id;

        $email = 'tg_' . $telegramUserId . '@example.invalid';
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $name = $identity->first_name
            ?: ($identity->username ?: ('User ' . $telegramUserId));

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'role' => 'spy',
            'ref_user_id' => 0,
            'ref_link' => '',
            'email_verified_at' => null,
        ]);

        return $user;
    }

    private function sendTopupMenu(int $chatId): void
    {
        $keyboard = [
            'keyboard' => [
                [['text' => '100 ₽'], ['text' => '300 ₽'], ['text' => '500 ₽'], ['text' => '1000 ₽']],
                [['text' => 'Другая сумма'], ['text' => 'Назад']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $this->api->sendMessage($chatId, "Выберите сумму пополнения:", [
            'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendTopupLink(int $chatId, User $user, int $sumRub): void
    {
        $url = $this->payments->makeTopupLink($user, $sumRub);
        $this->api->sendMessage($chatId, implode("\n", [
            "Пополнение на {$sumRub} ₽:",
            $url,
        ]));
    }

    private function sendSubscriptionsCards(int $chatId, int $userId): void
    {
        $rows = UserSubscription::query()
            ->where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->with('subscription:id,name')
            ->get();

        if ($rows->isEmpty()) {
            $this->api->sendMessage($chatId, "Подписок пока нет.");
            return;
        }

        $latestBySub = [];
        foreach ($rows as $r) {
            $key = (int) $r->subscription_id;
            if (!isset($latestBySub[$key])) {
                $latestBySub[$key] = $r;
            }
        }

        $this->api->sendMessage($chatId, "Ваши подписки:");

        foreach ($latestBySub as $r) {
            $this->sendSubscriptionCard($chatId, $r);
        }
    }

    private function sendSubscriptionDetails(int $chatId, int $userId, int $userSubId): void
    {
        $row = UserSubscription::query()
            ->where('id', $userSubId)
            ->where('user_id', $userId)
            ->with('subscription:id,name')
            ->first();

        if (!$row) {
            $this->api->sendMessage($chatId, "Подписка не найдена. Откройте «Мои подписки» еще раз.");
            return;
        }

        $this->sendSubscriptionCard($chatId, $row);
    }

    private function sendSubscriptionCard(int $chatId, UserSubscription $row): void
    {
        $name = $row->subscription?->name ?? ('#' . $row->subscription_id);
        $end = (string) $row->end_date;
        $rebill = $row->is_rebilling ? 'да' : 'нет';
        $note = trim((string) ($row->note ?? ''));

        $filePath = (string) ($row->file_path ?? '');

        $baseLines = [
            "{$name}",
            "До: {$end}",
            "Автопродление: {$rebill}",
        ];
        if ($note !== '') {
            $baseLines[] = "Пометка: {$note}";
        }

        $wireguardConfig = '';
        if ($filePath !== '') {
            $subInfo = new UserSubscriptionInfo(collect([$row]));
            $subInfo->setUserSubscriptionId((int) $row->id);
            $wireguardConfig = $subInfo->getWireguardConfig();
        }

        $keyboard = $this->buildInstructionKeyboard(
            (int) $row->id,
            $wireguardConfig !== ''
        );
        if ($keyboard !== null) {
            $baseLines[] = "Инструкции по подключению:";
        }

        $options = [];
        if ($keyboard !== null) {
            $options['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        $this->api->sendMessage($chatId, implode("\n", $baseLines), $options);
    }

    private function buildInstructionKeyboard(int $userSubscriptionId, bool $hasAmneziaInstructions): ?array
    {
        if ($userSubscriptionId <= 0) {
            return null;
        }

        $expiresAt = Carbon::now()->addMinutes(30);
        $rows = [];

        if ($hasAmneziaInstructions) {
            $rows[] = [
                [
                    'text' => 'AmneziaVPN (Android)',
                    'url' => URL::temporarySignedRoute('telegram.instruction.open', $expiresAt, [
                        'user_subscription_id' => $userSubscriptionId,
                        'protocol' => 'amnezia_vpn',
                    ]),
                ],
                [
                    'text' => 'AmneziaWG (iPhone)',
                    'url' => URL::temporarySignedRoute('telegram.instruction.open', $expiresAt, [
                        'user_subscription_id' => $userSubscriptionId,
                        'protocol' => 'amneziawg',
                    ]),
                ],
            ];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'inline_keyboard' => $rows,
        ];
    }

    private function identitySetState(TelegramIdentity $identity, array $state, int $ttlSeconds): void
    {
        $identity->forceFill([
            'state' => $state,
            'state_expires_at' => Carbon::now()->addSeconds($ttlSeconds),
        ])->save();
    }

    private function identityGetState(TelegramIdentity $identity): ?array
    {
        if (!$identity->state || !$identity->state_expires_at) {
            return null;
        }
        if (Carbon::now()->greaterThan($identity->state_expires_at)) {
            $this->identityClearState($identity);
            return null;
        }
        return is_array($identity->state) ? $identity->state : null;
    }

    private function identityClearState(TelegramIdentity $identity): void
    {
        $identity->forceFill([
            'state' => null,
            'state_expires_at' => null,
        ])->save();
    }

    public function sendSystemMessage(int $chatId, string $text): void
    {
        $text = trim($text);
        if ($chatId <= 0 || $text === '') {
            return;
        }

        $this->api->sendMessage($chatId, $text);
    }
}
