<?php

namespace App\Http\Controllers;

use App\Mail\ContactRequestMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ContactEmailController extends Controller
{
    public function send(Request $request)
    {
        $isAuthed = Auth::check();

        $rules = [
            'contact_email_name' => ['required', 'string', 'max:80'],
            'contact_email_email' => ['required', 'string', 'email', 'max:120'],
            'contact_email_message' => ['required', 'string', 'max:2000'],

            // Honeypot: bots tend to fill everything; real users never see this.
            'contact_email_company' => ['nullable', 'string', 'max:0'],
        ];

        if (!$isAuthed) {
            $rules['contact_email_captcha_answer'] = ['required', 'integer'];
        }

        $validated = $request->validate($rules);

        if (!$isAuthed) {
            $now = time();
            $startedAt = (int) session('contact_email_form_started_at', 0);
            if ($startedAt <= 0 || ($now - $startedAt) < 3) {
                throw ValidationException::withMessages([
                    'contact_email_captcha_answer' => 'Слишком быстро. Подождите пару секунд и попробуйте снова.',
                ]);
            }

            $a = (int) session('contact_email_captcha_a', 0);
            $b = (int) session('contact_email_captcha_b', 0);
            $expiresAt = (int) session('contact_email_captcha_expires_at', 0);

            if ($a < 1 || $b < 1 || $expiresAt < $now) {
                throw ValidationException::withMessages([
                    'contact_email_captcha_answer' => 'Проверка устарела. Обновите страницу и попробуйте снова.',
                ]);
            }

            $expected = $a + $b;
            $given = (int) $validated['contact_email_captcha_answer'];
            if ($given !== $expected) {
                throw ValidationException::withMessages([
                    'contact_email_captcha_answer' => 'Неверный ответ на проверку.',
                ]);
            }
        }

        $to = (string) config('support.contact.email_to', '4743383@gmail.com');

        $payload = [
            'from_name' => $validated['contact_email_name'],
            'from_email' => $validated['contact_email_email'],
            // Don't use "message" key: it can collide with mail view variables.
            'body' => $validated['contact_email_message'],
            'meta' => [
                'ip' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'page' => (string) url()->previous(),
                'user_id' => $isAuthed ? (int) Auth::id() : null,
            ],
        ];

        try {
            Mail::to($to)->send(new ContactRequestMail($payload));
        } catch (\Throwable $e) {
            Log::error('Contact email send failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return back()
                ->withInput()
                ->with('contact_email_open', true)
                ->with('flash.bannerStyle', 'danger')
                ->with('flash.banner', 'Не удалось отправить сообщение. Попробуйте позже.');
        }

        session()->forget([
            'contact_email_captcha_a',
            'contact_email_captcha_b',
            'contact_email_captcha_expires_at',
            'contact_email_form_started_at',
        ]);

        return back()
            ->with('flash.bannerStyle', 'success')
            ->with('flash.banner', 'Сообщение отправлено. Ответим на email.')
            // Used by the footer "Email" widget to show a dedicated success modal.
            ->with('contact_email_sent', true);
    }
}
