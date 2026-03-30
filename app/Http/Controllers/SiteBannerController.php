<?php

namespace App\Http\Controllers;

use App\Mail\SiteBannerAnnouncement;
use App\Models\SiteBanner;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SiteBannerController extends Controller
{
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:5000',
            'is_active' => 'nullable|boolean',
        ]);

        $banner = SiteBanner::first();
        $payload = [
            'message' => $data['message'] ?? null,
            'subject' => $data['subject'] ?? null,
            'is_active' => $request->boolean('is_active', false),
        ];

        if ($banner) {
            $banner->update($payload);
        } else {
            SiteBanner::create($payload);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Баннер обновлен.',
            ]);
        }

        return back()->with('banner-success', 'Баннер обновлен.');
    }

    public function sendToActive(Request $request): RedirectResponse|JsonResponse
    {
        $banner = SiteBanner::first();
        if (!$banner || empty($banner->message)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Нет текста баннера для рассылки.',
                ], 422);
            }

            return back()->with('banner-error', 'Нет текста баннера для рассылки.');
        }

        $latestActiveSubIds = UserSubscription::where('end_date', '>', Carbon::now())
            ->where('end_date', '!=', UserSubscription::AWAIT_PAYMENT_DATE)
            ->select(DB::raw('MAX(id)'))
            ->groupBy('user_id');

        $latestActiveSubs = UserSubscription::whereIn('id', $latestActiveSubIds)->get();
        $users = User::whereIn('id', $latestActiveSubs->pluck('user_id'))
            ->whereNull('banner_emails_unsubscribed_at')
            ->get()
            ->keyBy('id');

        foreach ($latestActiveSubs as $sub) {
            $user = $users->get($sub->user_id);
            if (!$user) {
                continue;
            }

            Mail::to($user->email)->send(new SiteBannerAnnouncement([
                'message' => $banner->message,
                'subject' => $banner->subject,
                'user' => $user,
                'attachment_path' => null,
            ]));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Рассылка отправлена активным подписчикам.',
            ]);
        }

        return back()->with('banner-success', 'Рассылка отправлена активным подписчикам.');
    }

    public function sendTest(Request $request): RedirectResponse|JsonResponse
    {
        $banner = SiteBanner::first();
        if (!$banner || empty($banner->message)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Нет текста баннера для рассылки.',
                ], 422);
            }

            return back()->with('banner-error', 'Нет текста баннера для рассылки.');
        }

        $data = $request->validate([
            'test_email' => 'required|email',
        ]);

        $user = User::where('email', $data['test_email'])->first();
        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Пользователь с таким email не найден.',
                ], 404);
            }

            return back()->with('banner-error', 'Пользователь с таким email не найден.');
        }

        Mail::to($user->email)->send(new SiteBannerAnnouncement([
            'message' => $banner->message,
            'subject' => $banner->subject,
            'user' => $user,
            'attachment_path' => null,
        ]));

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Тестовое письмо отправлено.',
            ]);
        }

        return back()->with('banner-success', 'Тестовое письмо отправлено.');
    }

    public function preview(): View
    {
        $banner = SiteBanner::first();

        $message = $banner?->message ?? '';
        $subject = $banner?->subject ?? 'Информация от Litehost24';

        $emailHtml = view('emails.site-banner-announcement', [
            'messageText' => $message,
            'user' => auth()->user(),
        ])->render();

        return view('admin.site-banner-preview', [
            'subject' => $subject,
            'attachArchives' => false,
            'emailHtml' => $emailHtml,
        ]);
    }

    public function unsubscribe(Request $request, User $user): View
    {
        if ($user->banner_emails_unsubscribed_at === null) {
            $user->forceFill([
                'banner_emails_unsubscribed_at' => now(),
            ])->save();
        }

        return view('emails.banner-unsubscribed', [
            'user' => $user,
        ]);
    }
}
