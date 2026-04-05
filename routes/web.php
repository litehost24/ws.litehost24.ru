<?php

use App\Http\Controllers\AdminSupportChatController;
use App\Http\Controllers\AdminStatisticsController;
use App\Http\Controllers\AdminVpnDomainController;
use App\Http\Controllers\AdminPublicationController;
use App\Http\Controllers\AdminPartnerReferralController;
use App\Http\Controllers\AdminReferralSettingsController;
use App\Http\Controllers\AdminSubscriptionMessageController;
use App\Http\Controllers\AdminUserRoleController;
use App\Http\Controllers\AdminXrayBypassController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MyController;
use App\Http\Controllers\PartnerReferralController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\SiteBannerController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupportChatController;
use App\Http\Controllers\TelegramConfigController;
use App\Http\Controllers\TelegramConnectController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSubscriptionController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\RoutePath;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/**
 * name - нужен для корректной подсветки активного пункта меню
 */
Route::get('/', [Controller::class, 'showMainPage'])->name('home');
Route::get('/about-company', [Controller::class, 'aboutCompany'])->name('about-company');
Route::get('/contacts', [Controller::class, 'contacts'])->name('contacts');
Route::get('/documents', [Controller::class, 'documents'])->name('documents');

Route::post('/contact/email', [\App\Http\Controllers\ContactEmailController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('contact.email.send');

Route::match(['GET', 'POST'], '/mail/unsubscribe/banner/{user}', [SiteBannerController::class, 'unsubscribe'])
    ->middleware('signed')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('mail.unsubscribe.banner');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    Route::get('/my/main', [MyController::class, 'main'])->name('my.main');
    Route::get('/my/operations', [MyController::class, 'operations'])->name('my.operations');
    Route::get('/my/referrals', [MyController::class, 'referrals'])->name('my.referrals');

    Route::get('/telegram/connect', [TelegramConnectController::class, 'connect'])->name('telegram.connect');

    Route::get('/support/chat', [SupportChatController::class, 'chat'])->name('support.chat');
    Route::get('/support/chat/messages', [SupportChatController::class, 'messages'])->name('support.chat.messages');
    Route::post('/support/chat/messages', [SupportChatController::class, 'sendMessage'])->name('support.chat.messages.send');
    Route::post('/support/chat/read', [SupportChatController::class, 'markRead'])->name('support.chat.read');
    Route::get('/support/chat/unread-count', [SupportChatController::class, 'unreadCount'])->name('support.chat.unread-count');

    Route::get('/subscriptions', [SubscriptionController::class, 'show'])->name('subscriptions');

    Route::get('/partner/referrals', [PartnerReferralController::class, 'index'])
        ->name('partner.referrals');
    Route::post('/partner/referrals/default', [PartnerReferralController::class, 'updateDefault'])
        ->name('partner.referrals.default');
    Route::post('/partner/referrals/{referral}/pricing', [PartnerReferralController::class, 'updatePricing'])
        ->name('partner.referrals.pricing');

    Route::resource('servers', ServersController::class)->middleware('admin');
    Route::post('/servers/current-bundles', [ServersController::class, 'updateCurrentBundles'])
        ->name('servers.current-bundles')
        ->middleware('admin');
    Route::post('/servers/{server}/monitor-check', [ServersController::class, 'monitorCheck'])
        ->name('servers.monitor-check')
        ->middleware('admin');

    Route::get('/user-subscription/connect', [UserSubscriptionController::class, 'connect']);
    Route::get('/user-subscription/disconnect', [UserSubscriptionController::class, 'disconnect']);
    Route::get('/user-subscription/toggle-rebill', [UserSubscriptionController::class, 'toggleRebill']);
    Route::get('/user-subscription/switch-vpn-access-mode', [UserSubscriptionController::class, 'switchVpnAccessMode'])
        ->name('user-subscription.switch-vpn-access-mode');
    Route::get('/user-subscription/switch-mts-beta-to-economy', [UserSubscriptionController::class, 'switchMtsBetaToEconomy'])
        ->name('user-subscription.switch-mts-beta-to-economy');
    Route::get('/user-subscription/download', [UserSubscriptionController::class, 'download'])->name('user-subscription.download');
    Route::get('/user-subscription/download-amneziawg', [UserSubscriptionController::class, 'downloadAmneziaWg'])
        ->name('user-subscription.download-amneziawg');
    Route::get('/user-subscription/instruction', [UserSubscriptionController::class, 'instruction'])->name('user-subscription.instruction');
    Route::post('/user-subscription/add-vpn', [UserSubscriptionController::class, 'addVpn'])->name('user-subscription.add-vpn');
    Route::post('/user-subscription/next-vpn-plan', [UserSubscriptionController::class, 'scheduleNextVpnPlan'])->name('user-subscription.next-vpn-plan');
    Route::post('/user-subscription/next-vpn-plan/clear', [UserSubscriptionController::class, 'clearNextVpnPlan'])->name('user-subscription.clear-next-vpn-plan');
    Route::post('/user-subscription/topup', [UserSubscriptionController::class, 'purchaseTopup'])->name('user-subscription.topup');
    Route::post('/user-subscription/update-note', [UserSubscriptionController::class, 'updateNote'])->name('user-subscription.update-note');
    Route::get('/user-subscriptions-manage', [UserSubscriptionController::class, 'manage'])->name('user-subscriptions-manage');
    Route::post('/user-subscription/mark-as-done', [UserSubscriptionController::class, 'markAsDone']);
    Route::post('/user-subscription/mark-as-done-and-load-file', [UserSubscriptionController::class, 'markAsDoneAndLoadFile']);

    // Маршрут для страницы администрирования всех подписок
    Route::get('/admin/subscriptions', [\App\Http\Controllers\AdminSubscriptionController::class, 'index'])->name('admin.subscriptions.index')->middleware('admin');
    Route::post('/admin/subscriptions/migrate', [\App\Http\Controllers\AdminSubscriptionController::class, 'migrate'])->name('admin.subscriptions.migrate')->middleware('admin');
    Route::get('/admin/subscriptions/stop', [\App\Http\Controllers\AdminSubscriptionController::class, 'stop'])->name('admin.subscriptions.stop')->middleware('admin');
    Route::get('/admin/subscriptions/status', [\App\Http\Controllers\AdminSubscriptionController::class, 'status'])->name('admin.subscriptions.status')->middleware('admin');
    Route::post('/admin/subscriptions/{userSubscription}/switch-vpn-access-mode', [\App\Http\Controllers\AdminSubscriptionController::class, 'switchVpnAccessMode'])->name('admin.subscriptions.switch-vpn-access-mode')->middleware('admin');
    Route::post('/admin/subscriptions/{userSubscription}/delete', [\App\Http\Controllers\AdminSubscriptionController::class, 'destroyUserSubscription'])->name('admin.subscriptions.delete')->middleware('admin');
    Route::get('/admin/subscriptions/user/{user}/details', [\App\Http\Controllers\AdminSubscriptionController::class, 'userDetails'])->name('admin.subscriptions.user.details')->middleware('admin');
    Route::post('/admin/subscriptions/user/{user}/message', [AdminSubscriptionMessageController::class, 'send'])->name('admin.subscriptions.user.message')->middleware('admin');
    Route::post('/admin/users/{user}/role', [AdminUserRoleController::class, 'update'])->name('admin.users.role')->middleware('admin');
    Route::get('/admin/partner-referrals', [AdminPartnerReferralController::class, 'index'])->name('admin.partner-referrals')->middleware('admin');
    Route::post('/admin/partner-referrals/{partner}/{referral}/pricing', [AdminPartnerReferralController::class, 'updatePricing'])->name('admin.partner-referrals.pricing')->middleware('admin');
    Route::post('/admin/partner-referrals/{partner}/default', [AdminPartnerReferralController::class, 'updateDefault'])->name('admin.partner-referrals.default')->middleware('admin');
    Route::get('/admin/referral-settings', [AdminReferralSettingsController::class, 'index'])->name('admin.referral-settings')->middleware('admin');
    Route::post('/admin/referral-settings', [AdminReferralSettingsController::class, 'update'])->name('admin.referral-settings.update')->middleware('admin');
    Route::get('/admin/xray-bypass-domains', [AdminXrayBypassController::class, 'index'])->name('admin.xray-bypass-domains')->middleware('admin');
    Route::post('/admin/xray-bypass-domains', [AdminXrayBypassController::class, 'update'])->name('admin.xray-bypass-domains.update')->middleware('admin');
    Route::get('/admin/vpn-domains', [AdminVpnDomainController::class, 'index'])->name('admin.vpn-domains')->middleware('admin');
    Route::post('/admin/vpn-domains', [AdminVpnDomainController::class, 'update'])->name('admin.vpn-domains.update')->middleware('admin');
    Route::post('/admin/vpn-domains/sync', [AdminVpnDomainController::class, 'sync'])->name('admin.vpn-domains.sync')->middleware('admin');
    Route::post('/admin/vpn-domains/mode', [AdminVpnDomainController::class, 'setMode'])->name('admin.vpn-domains.mode')->middleware('admin');
    Route::post('/admin/vpn-domains/collect', [AdminVpnDomainController::class, 'collect'])->name('admin.vpn-domains.collect')->middleware('admin');
    Route::post('/admin/vpn-domains/probe', [AdminVpnDomainController::class, 'probe'])->name('admin.vpn-domains.probe')->middleware('admin');
    Route::post('/admin/site-banner', [SiteBannerController::class, 'update'])->name('admin.site-banner.update')->middleware('admin');
    Route::post('/admin/site-banner/send', [SiteBannerController::class, 'sendToActive'])->name('admin.site-banner.send')->middleware('admin');
    Route::post('/admin/site-banner/send-test', [SiteBannerController::class, 'sendTest'])->name('admin.site-banner.send-test')->middleware('admin');
    Route::get('/admin/site-banner/preview', [SiteBannerController::class, 'preview'])->name('admin.site-banner.preview')->middleware('admin');
    Route::get('/admin/publications', [AdminPublicationController::class, 'index'])->name('admin.publications.index')->middleware('admin');
    Route::post('/admin/publications/active/save', [AdminPublicationController::class, 'saveActive'])->name('admin.publications.active.save')->middleware('admin');
    Route::post('/admin/publications/active/publish', [AdminPublicationController::class, 'publishActive'])->name('admin.publications.active.publish')->middleware('admin');
    Route::post('/admin/publications/active/preview', [AdminPublicationController::class, 'previewActive'])->name('admin.publications.active.preview')->middleware('admin');
    Route::post('/admin/publications/active/test', [AdminPublicationController::class, 'sendTestActive'])->name('admin.publications.active.test')->middleware('admin');
    Route::post('/admin/publications/active/send', [AdminPublicationController::class, 'sendActive'])->name('admin.publications.active.send')->middleware('admin');
    Route::post('/admin/publications/cabinet/toggle', [AdminPublicationController::class, 'toggleCabinetVisibility'])->name('admin.publications.cabinet.toggle')->middleware('admin');
    Route::post('/admin/publications/inactive/save', [AdminPublicationController::class, 'saveInactive'])->name('admin.publications.inactive.save')->middleware('admin');
    Route::post('/admin/publications/inactive/preview', [AdminPublicationController::class, 'previewInactive'])->name('admin.publications.inactive.preview')->middleware('admin');
    Route::post('/admin/publications/inactive/test', [AdminPublicationController::class, 'sendTestInactive'])->name('admin.publications.inactive.test')->middleware('admin');
    Route::post('/admin/publications/inactive/send', [AdminPublicationController::class, 'sendInactive'])->name('admin.publications.inactive.send')->middleware('admin');
    Route::get('/admin/publications/{publication}', [AdminPublicationController::class, 'show'])->name('admin.publications.show')->middleware('admin');

    Route::get('/admin/support/chats', [AdminSupportChatController::class, 'index'])->name('admin.support.chats.index')->middleware('admin');
    Route::get('/admin/support/chats/list', [AdminSupportChatController::class, 'listChats'])->name('admin.support.chats.list')->middleware('admin');
    Route::get('/admin/support/chats/{chat}/messages', [AdminSupportChatController::class, 'messages'])->name('admin.support.chats.messages')->middleware('admin');
    Route::post('/admin/support/chats/{chat}/messages', [AdminSupportChatController::class, 'sendMessage'])->name('admin.support.chats.messages.send')->middleware('admin');
    Route::post('/admin/support/chats/{chat}/read', [AdminSupportChatController::class, 'markRead'])->name('admin.support.chats.read')->middleware('admin');
    Route::post('/admin/support/chats/{chat}/close', [AdminSupportChatController::class, 'close'])->name('admin.support.chats.close')->middleware('admin');
    Route::get('/admin/statistics', [AdminStatisticsController::class, 'index'])->name('admin.statistics.index')->middleware('admin');
});

Route::post(RoutePath::for('register', '/register'), [UserController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard')])
    ->name('register.store');

Route::middleware('guest')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->name('social.callback');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/profile/auth/{provider}/redirect', [SocialAuthController::class, 'linkRedirect'])
        ->name('social.link.redirect');
    Route::get('/profile/auth/{provider}/callback', [SocialAuthController::class, 'linkCallback'])
        ->name('social.link.callback');
});

Route::post('/payment/init', [PaymentController::class, 'init'])->withoutMiddleware([VerifyCsrfToken::class]);
Route::get('/payment/init', [PaymentController::class, 'initGet']);
Route::get('/payment/hook-success', [PaymentController::class, 'hookSuccess'])->withoutMiddleware([VerifyCsrfToken::class]);

Route::get('/telegram/config/amneziawg', [TelegramConfigController::class, 'showAmneziaWg'])
    ->middleware('signed')
    ->name('telegram.awg.open');

Route::get('/telegram/config/instruction', [TelegramConfigController::class, 'showSubscriptionManual'])
    ->middleware('signed')
    ->name('telegram.instruction.open');

Route::get('/telegram/config/amneziawg/download', [TelegramConfigController::class, 'downloadAmneziaWg'])
    ->middleware('signed')
    ->name('telegram.awg.download');

Route::get('/telegram/config/amneziawg-compatible/download', [TelegramConfigController::class, 'downloadAmneziaWgCompatible'])
    ->middleware('signed')
    ->name('telegram.awg.compat.download');
