<?php

namespace Tests\Feature;

use App\Mail\ContactRequestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_send_contact_email_with_correct_answer(): void
    {
        Mail::fake();
        config()->set('support.contact.email_to', 'admin@example.com');

        $now = time();

        $this->withSession([
            'contact_email_captcha_a' => 3,
            'contact_email_captcha_b' => 7,
            'contact_email_captcha_expires_at' => $now + 300,
            'contact_email_form_started_at' => $now - 10,
        ])->post(route('contact.email.send'), [
            'contact_email_name' => 'Test User',
            'contact_email_email' => 'user@example.com',
            'contact_email_message' => 'Hello',
            'contact_email_captcha_answer' => 10,
        ])->assertRedirect();

        Mail::assertSent(ContactRequestMail::class, function (ContactRequestMail $mail) {
            return $mail->hasTo('admin@example.com');
        });
    }

    public function test_guest_cannot_send_contact_email_with_wrong_answer(): void
    {
        Mail::fake();

        $now = time();

        $this->withSession([
            'contact_email_captcha_a' => 3,
            'contact_email_captcha_b' => 7,
            'contact_email_captcha_expires_at' => $now + 300,
            'contact_email_form_started_at' => $now - 10,
        ])->post(route('contact.email.send'), [
            'contact_email_name' => 'Test User',
            'contact_email_email' => 'user@example.com',
            'contact_email_message' => 'Hello',
            'contact_email_captcha_answer' => 11,
        ])->assertSessionHasErrors(['contact_email_captcha_answer']);

        Mail::assertNothingSent();
    }

    public function test_guest_is_blocked_if_submits_too_fast(): void
    {
        Mail::fake();

        $now = time();

        $this->withSession([
            'contact_email_captcha_a' => 3,
            'contact_email_captcha_b' => 7,
            'contact_email_captcha_expires_at' => $now + 300,
            'contact_email_form_started_at' => $now,
        ])->post(route('contact.email.send'), [
            'contact_email_name' => 'Test User',
            'contact_email_email' => 'user@example.com',
            'contact_email_message' => 'Hello',
            'contact_email_captcha_answer' => 10,
        ])->assertSessionHasErrors(['contact_email_captcha_answer']);

        Mail::assertNothingSent();
    }

    public function test_honeypot_blocks_submission(): void
    {
        Mail::fake();

        $now = time();

        $this->withSession([
            'contact_email_captcha_a' => 3,
            'contact_email_captcha_b' => 7,
            'contact_email_captcha_expires_at' => $now + 300,
            'contact_email_form_started_at' => $now - 10,
        ])->post(route('contact.email.send'), [
            'contact_email_name' => 'Test User',
            'contact_email_email' => 'user@example.com',
            'contact_email_message' => 'Hello',
            'contact_email_company' => 'spam',
            'contact_email_captcha_answer' => 10,
        ])->assertSessionHasErrors(['contact_email_company']);

        Mail::assertNothingSent();
    }

    public function test_authed_user_does_not_need_answer(): void
    {
        Mail::fake();
        config()->set('support.contact.email_to', 'admin@example.com');

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('contact.email.send'), [
            'contact_email_name' => 'Test User',
            'contact_email_email' => 'user@example.com',
            'contact_email_message' => 'Hello',
        ])->assertRedirect();

        Mail::assertSent(ContactRequestMail::class, function (ContactRequestMail $mail) {
            return $mail->hasTo('admin@example.com');
        });
    }
}

