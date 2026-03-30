<?php

namespace App\Http\Controllers;

use App\Mail\SiteBannerAnnouncement;
use App\Models\Publication;
use App\Models\PublicationRecipient;
use App\Models\PublicationTestLog;
use App\Models\ProjectSetting;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminPublicationController extends Controller
{
    public function index(): View
    {
        $activeRecipients = $this->buildAudienceUsers('active');
        $inactiveRecipients = $this->buildAudienceUsers('inactive');

        $activeHistory = Publication::query()
            ->where('audience', 'active')
            ->whereIn('status', ['published', 'sending', 'sent', 'partial', 'failed'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $activeDraft = Publication::query()
            ->where('audience', 'active')
            ->where('status', 'draft')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        $inactiveDraft = Publication::query()
            ->where('audience', 'inactive')
            ->where('status', 'draft')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        return view('admin.publications.index', [
            'activeHistory' => $activeHistory,
            'activeCount' => $activeRecipients->count(),
            'inactiveCount' => $inactiveRecipients->count(),
            'defaultSubject' => 'Информация от Litehost24',
            'defaultTestEmail' => (string) (auth()->user()->email ?? ''),
            'activeDraft' => $activeDraft,
            'inactiveDraft' => $inactiveDraft,
            'cabinetPublicationsEnabled' => ProjectSetting::getInt('cabinet_publications_enabled', 1) === 1,
        ]);
    }

    public function toggleCabinetVisibility(Request $request): RedirectResponse
    {
        if (!Schema::hasTable('project_settings')) {
            return back()->with('pub-error', 'Таблица настроек не найдена. Сначала выполните миграции.');
        }

        $enabled = ProjectSetting::getInt('cabinet_publications_enabled', 1) === 1;
        $nextValue = $enabled ? '0' : '1';
        ProjectSetting::setValue('cabinet_publications_enabled', $nextValue, auth()->id());

        return back()->with('pub-success', $enabled
            ? 'Публикации в кабинете скрыты.'
            : 'Публикации в кабинете включены.');
    }

    public function saveActive(Request $request): RedirectResponse
    {
        return $this->saveDraft($request, 'active');
    }

    public function saveInactive(Request $request): RedirectResponse
    {
        return $this->saveDraft($request, 'inactive');
    }

    public function previewActive(Request $request): View|RedirectResponse
    {
        return $this->previewDraft($request, 'active');
    }

    public function publishActive(Request $request): RedirectResponse
    {
        $draft = $this->resolveDraftForAction($request, 'active');
        if (!$draft) {
            return back()->with('pub-error', 'Сначала сохраните запись (черновик), затем используйте публикацию/предпросмотр/тест/рассылку.');
        }

        $publication = Publication::query()->create([
            'audience' => 'active',
            'subject' => $draft->subject,
            'body' => $draft->body,
            'status' => 'published',
            'snapshot_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'created_by' => auth()->id(),
            'started_at' => now(),
            'finished_at' => now(),
            'idempotency_key' => $this->makeIdempotencyKey('active'),
        ]);

        return back()->with('pub-success', "Публикация #{$publication->id} опубликована в кабинете. Черновик сохранен в редакторе.");
    }

    public function previewInactive(Request $request): View|RedirectResponse
    {
        return $this->previewDraft($request, 'inactive');
    }

    public function sendTestActive(Request $request): RedirectResponse
    {
        return $this->sendTestFromDraft($request, 'active');
    }

    public function sendTestInactive(Request $request): RedirectResponse
    {
        return $this->sendTestFromDraft($request, 'inactive');
    }

    public function sendActive(Request $request): RedirectResponse
    {
        return $this->sendAudienceFromDraft($request, 'active');
    }

    public function sendInactive(Request $request): RedirectResponse
    {
        return $this->sendAudienceFromDraft($request, 'inactive');
    }

    public function show(Publication $publication): View
    {
        $publication->load(['creator', 'recipients' => function ($q) {
            $q->orderBy('id', 'desc')->limit(200);
        }]);

        return view('admin.publications.show', [
            'publication' => $publication,
        ]);
    }

    private function saveDraft(Request $request, string $audience): RedirectResponse
    {
        $data = $request->validate([
            'draft_id' => 'nullable|integer',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:20000',
        ]);

        $draftId = isset($data['draft_id']) ? (int) $data['draft_id'] : 0;

        $draft = null;
        if ($draftId > 0) {
            $draft = Publication::query()
                ->where('id', $draftId)
                ->where('audience', $audience)
                ->where('status', 'draft')
                ->where('created_by', auth()->id())
                ->first();
        }

        if (!$draft) {
            $draft = Publication::query()
                ->where('audience', $audience)
                ->where('status', 'draft')
                ->where('created_by', auth()->id())
                ->latest('id')
                ->first();
        }

        if (!$draft) {
            $draft = Publication::query()->create([
                'audience' => $audience,
                'subject' => (string) $data['subject'],
                'body' => (string) $data['body'],
                'status' => 'draft',
                'snapshot_count' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'created_by' => auth()->id(),
                'idempotency_key' => $this->makeIdempotencyKey($audience),
            ]);
        } else {
            $draft->forceFill([
                'subject' => (string) $data['subject'],
                'body' => (string) $data['body'],
            ])->save();
        }

        $label = $audience === 'active' ? 'активных' : 'неактивных';
        return back()->with('pub-success', "Черновик для {$label} сохранен (#{$draft->id}).");
    }

    private function previewDraft(Request $request, string $audience): View|RedirectResponse
    {
        $draft = $this->resolveDraftForAction($request, $audience);
        if (!$draft) {
            return back()->with('pub-error', 'Сначала сохраните запись (черновик), затем используйте предпросмотр/тест/рассылку.');
        }

        $emailHtml = view('emails.site-banner-announcement', [
            'messageText' => $draft->body,
            'user' => auth()->user(),
            'unsubscribeUrl' => '',
        ])->render();

        return view('admin.site-banner-preview', [
            'subject' => $draft->subject,
            'attachArchives' => false,
            'emailHtml' => $emailHtml,
            'audienceLabel' => $audience === 'active' ? 'Активные' : 'Неактивные',
        ]);
    }

    private function sendTestFromDraft(Request $request, string $audience): RedirectResponse
    {
        $draft = $this->resolveDraftForAction($request, $audience);
        if (!$draft) {
            return back()->with('pub-error', 'Сначала сохраните запись (черновик), затем используйте предпросмотр/тест/рассылку.');
        }

        $data = $request->validate([
            'test_email' => 'required|email|max:255',
        ]);

        $toEmail = trim((string) $data['test_email']);
        $user = User::query()->where('email', $toEmail)->first();

        try {
            Mail::to($toEmail)->send(new SiteBannerAnnouncement([
                'message' => $draft->body,
                'subject' => '[TEST] ' . $draft->subject,
                'user' => $user,
                'attachment_path' => null,
            ]));

            PublicationTestLog::query()->create([
                'audience' => $audience,
                'subject' => (string) $draft->subject,
                'to_email' => $toEmail,
                'status' => 'sent',
                'error_text' => null,
                'created_by' => auth()->id(),
            ]);

            return back()->with('pub-success', 'Тестовое письмо отправлено: ' . $toEmail);
        } catch (\Throwable $e) {
            report($e);

            PublicationTestLog::query()->create([
                'audience' => $audience,
                'subject' => (string) $draft->subject,
                'to_email' => $toEmail,
                'status' => 'failed',
                'error_text' => $e->getMessage(),
                'created_by' => auth()->id(),
            ]);

            return back()->with('pub-error', 'Тест не отправлен: ' . $e->getMessage());
        }
    }

    private function sendAudienceFromDraft(Request $request, string $audience): RedirectResponse
    {
        $draft = $this->resolveDraftForAction($request, $audience);
        if (!$draft) {
            return back()->with('pub-error', 'Сначала сохраните запись (черновик), затем используйте предпросмотр/тест/рассылку.');
        }

        $recentSend = Publication::query()
            ->where('audience', $audience)
            ->whereIn('status', ['sending', 'sent', 'partial'])
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->exists();

        if ($recentSend) {
            return back()->with('pub-error', 'Слишком частая массовая рассылка. Подождите 10 минут.');
        }

        $users = $this->buildAudienceUsers($audience);

        if ($users->isEmpty()) {
            return back()->with('pub-error', 'Для выбранной аудитории не найдено получателей.');
        }

        $publication = null;

        DB::transaction(function () use (&$publication, $draft, $audience, $users): void {
            $publication = Publication::query()->create([
                'audience' => $audience,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'status' => 'sending',
                'snapshot_count' => $users->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'created_by' => auth()->id(),
                'started_at' => now(),
                'finished_at' => null,
                'idempotency_key' => $this->makeIdempotencyKey($audience),
            ]);

            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'publication_id' => $publication->id,
                    'user_id' => $user->id,
                    'email' => (string) $user->email,
                    'status' => 'pending',
                    'error_text' => null,
                    'sent_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            PublicationRecipient::query()->insert($rows);
        });

        $sent = 0;
        $failed = 0;

        $recipients = PublicationRecipient::query()
            ->where('publication_id', $publication->id)
            ->orderBy('id', 'asc')
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $user = User::query()->find($recipient->user_id);

                Mail::to($recipient->email)->send(new SiteBannerAnnouncement([
                    'message' => $publication->body,
                    'subject' => $publication->subject,
                    'user' => $user,
                    'attachment_path' => null,
                ]));

                $recipient->forceFill([
                    'status' => 'sent',
                    'error_text' => null,
                    'sent_at' => now(),
                ])->save();

                $sent++;
            } catch (\Throwable $e) {
                report($e);

                $recipient->forceFill([
                    'status' => 'failed',
                    'error_text' => mb_substr((string) $e->getMessage(), 0, 2000),
                    'sent_at' => null,
                ])->save();

                $failed++;
            }
        }

        $status = 'sent';
        if ($sent === 0 && $failed > 0) {
            $status = 'failed';
        } elseif ($sent > 0 && $failed > 0) {
            $status = 'partial';
        }

        $publication->forceFill([
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'finished_at' => now(),
        ])->save();

        $label = $audience === 'active' ? 'активным' : 'неактивным';
        return back()->with('pub-success', "Рассылка {$label} завершена. Отправлено: {$sent}, ошибок: {$failed}.");
    }

    private function resolveDraftForAction(Request $request, string $audience): ?Publication
    {
        $data = $request->validate([
            'draft_id' => 'required|integer',
        ]);

        return Publication::query()
            ->where('id', (int) $data['draft_id'])
            ->where('audience', $audience)
            ->where('status', 'draft')
            ->where('created_by', auth()->id())
            ->first();
    }

    private function buildAudienceUsers(string $audience): Collection
    {
        $latestByPairIds = UserSubscription::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('user_id', 'subscription_id');

        $latestSubs = UserSubscription::query()
            ->whereIn('id', $latestByPairIds)
            ->get(['user_id', 'end_date']);

        $activeUserIds = $latestSubs
            ->filter(function ($row) {
                return !empty($row->end_date)
                    && $row->end_date !== UserSubscription::AWAIT_PAYMENT_DATE
                    && Carbon::parse($row->end_date)->gt(now());
            })
            ->pluck('user_id')
            ->unique()
            ->values();

        $query = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereIn('role', ['user', 'admin', 'partner'])
            ->whereNull('banner_emails_unsubscribed_at');

        if ($audience === 'active') {
            if ($activeUserIds->isEmpty()) {
                return collect();
            }

            $query->whereIn('id', $activeUserIds->all());
        } else {
            if ($activeUserIds->isNotEmpty()) {
                $query->whereNotIn('id', $activeUserIds->all());
            }
        }

        return $query->orderBy('id')->get(['id', 'name', 'email']);
    }

    private function makeIdempotencyKey(string $audience): string
    {
        $userId = (string) (auth()->id() ?? 0);
        return substr(hash('sha256', $audience . '|' . $userId . '|' . microtime(true) . '|' . random_int(1, 999999)), 0, 48);
    }
}
