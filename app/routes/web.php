<?php

declare(strict_types=1);

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Http\Controllers\Api\PullContentController;
use App\Http\Controllers\Api\PurchaseWebhookController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandBriefController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentItemDetailController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryEconomicsController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MeteringController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanSelectionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SiteAuditController;
use App\Http\Controllers\VisibilityController;
use App\Http\Middleware\AuthenticatePullApi;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * The landing page, and the prices on it.
 *
 * Read from `config/billing.php` rather than written into the page, because
 * there is one price list and a second copy of it in a marketing component is
 * a second copy to forget. Only the self-serve plans: a "Choose" button under
 * the preview or the trial would promise a checkout that does not exist.
 */
Route::get('/', fn (PlanCatalog $plans) => Inertia::render('marketing', [
    'pricing' => [
        'currency' => (string) config('billing.currency', 'eur'),
        'trial_days' => $plans->trialDays(),
        'plans' => array_map(static fn (Plan $plan): array => [
            'key' => $plan->key,
            'name' => $plan->name,
            'price_cents' => $plan->priceCents,
            'currency' => $plan->currency,
            'limits' => [
                'improvements' => $plan->limit('page_improvements'),
                'tracked_pages' => $plan->limit('tracked_pages'),
                'articles' => $plan->limit('articles'),
                'ai_answers' => $plan->limit('ai_answers'),
                'ai_questions' => $plan->limit('ai_questions'),
                'ai_frequency_days' => $plan->limit('ai_frequency_days'),
                'locales' => $plan->limit('locales'),
                'seats' => $plan->limit('seats'),
            ],
        ], $plans->selfServe()),
    ],
]))->name('home');

Route::get('start', PlanSelectionController::class)->name('plans.start');

/*
 * The three public documents (see `LegalController`). Outside the auth group
 * deliberately: a privacy policy you can only read once you have an account is
 * not one, and the cookie banner links here before anybody has signed in.
 *
 * `/cookies` rather than `/cookie-policy` because the banner, the footer and
 * the policy itself all name it, and the shortest honest path is the one people
 * can retype.
 */
Route::get('terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('cookies', [LegalController::class, 'cookies'])->name('legal.cookies');

/*
 * Signing in with an outside account (see `SocialLoginController`).
 *
 * `{provider}` is bound to the `SocialLoginProvider` enum, so an unknown
 * provider is a 404 from the router rather than a branch in the controller —
 * and adding the second one is a case on the enum and a button, not a pair of
 * routes.
 *
 * Behind `guest`, because both halves of this are a way *in*: somebody already
 * signed in who lands here has followed a stale link, and the right answer is
 * the screen they were going to anyway.
 *
 * Throttled by IP, for the reason registration is: the callback exchanges a
 * code at Google on every hit and can end in a new account, and an account is
 * the first step towards a trial that spends real money at a provider. Ten a
 * minute rather than registration's five an hour, because this path is also
 * where people who *already* have an account come back through, and a limit
 * tight enough to stop signup abuse here would lock a shared office out of
 * signing in.
 */
Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('oauth.callback');
});

Route::middleware(['auth'])->group(function (): void {
    // Where somebody starts, and now the only place they can: a box to type
    // into, what needs a person, and what the engine is doing. It absorbed
    // Today and the dashboard, which is why `dashboard` below is a redirect.
    Route::get('home', HomeController::class)->name('home.index');

    // Conversations with the engine (see `AssistantController`). Each one has
    // a URL, because a chat you cannot get back to is a chat you only have
    // once. The two that spend money are throttled; a turn can run several tool
    // calls and each one is a bill.
    Route::get('chat', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('chat', [AssistantController::class, 'store'])
        ->middleware(['throttle:30,1', 'project.entitled:assistant_turns'])->name('assistant.store');
    Route::get('chat/{thread}', [AssistantController::class, 'show'])->name('assistant.show');
    Route::post('chat/{thread}', [AssistantController::class, 'reply'])
        ->middleware(['throttle:30,1', 'project.entitled:assistant_turns'])->name('assistant.reply');
    Route::patch('chat/{thread}', [AssistantController::class, 'rename'])->name('assistant.rename');
    Route::delete('chat/{thread}', [AssistantController::class, 'destroy'])->name('assistant.destroy');

    // Kept as a redirect rather than deleted. The name is linked from
    // `OnboardingController`, from bookmarks, and from anything that ever sent
    // somebody a `/dashboard` URL; a 404 for those is a worse answer than the
    // screen that replaced it.
    Route::redirect('dashboard', '/home')->name('dashboard');

    // Creating a project is a wizard, not a form (§3.1): URL in, a reading of
    // the site, then the operator correcting it — and the engine starts itself.
    //
    // The whole wizard is behind `verified`, and that is the earliest honest
    // place for it. Every step of it spends something — the first one fetches
    // somebody's site and asks a model about it — and the last one starts a
    // free trial that costs us real provider calls. Putting the check only on
    // the final step would let somebody fill in six screens before being told
    // to go and read their email; putting it on the whole application would
    // lock a signed-in operator out of work they already paid for.
    Route::middleware('verified')->group(function (): void {
        Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        // Throttled: each call makes an outbound fetch and a model call on
        // nothing more than a string somebody typed, which is a bill and a
        // request-forgery surface an unbounded loop would make worse.
        Route::post('onboarding/analyse', [OnboardingController::class, 'analyse'])
            ->middleware('throttle:20,1')
            ->name('onboarding.analyse');
        Route::post('onboarding/{project}/save', [OnboardingController::class, 'save'])
            ->middleware('project.owner')->name('onboarding.save');
        Route::post('onboarding/{project}/launch', [OnboardingController::class, 'launch'])
            ->middleware('project.owner')->name('onboarding.launch');
    });

    // What the project is on, and what else there is. Not behind
    // `project.owner`: an operator who has run out of articles should be able
    // to find out why without asking the account holder, and everything here is
    // a quota rather than a figure about our money.
    //
    // Never behind `project.entitled`, which would be the funniest bug in this
    // subsystem — the page you go to *because* you are not entitled.
    Route::get('billing', BillingController::class)->name('billing.index');

    // Out to Stripe. Owner-only, unlike the screen above: reading which quotas
    // are left is an operator's business, committing the account holder's card
    // is not. Throttled because each press opens a session at a provider.
    Route::post('billing/checkout', [BillingCheckoutController::class, 'checkout'])
        ->middleware(['project.owner', 'throttle:10,1'])->name('billing.checkout');
    Route::post('billing/cancel-change', [BillingCheckoutController::class, 'cancelChange'])
        ->middleware(['project.owner', 'throttle:10,1'])->name('billing.cancel-change');
    Route::post('billing/portal', [BillingCheckoutController::class, 'portal'])
        ->middleware(['project.owner', 'throttle:10,1'])->name('billing.portal');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])
        ->middleware('project.owner')->name('projects.edit');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])
        ->middleware('project.owner')->name('projects.update');
    // Owner-only: it ends the subscription as well as the work. Throttled
    // because each press can reach Stripe.
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])
        ->middleware(['project.owner', 'throttle:10,1'])->name('projects.archive');

    Route::post('projects/{project}/switch', [ProjectController::class, 'switch'])->name('projects.switch');

    // Search Console and GA4, granted per project. The callback carries no
    // project in its path because Google matches redirect URIs as exact
    // strings — one registered URI, and the session says which project asked.
    Route::get('projects/{project}/google/connect', [GoogleConnectionController::class, 'connect'])
        ->middleware('project.owner')->name('google.connect');
    Route::patch('projects/{project}/google', [GoogleConnectionController::class, 'choose'])
        ->middleware('project.owner')->name('google.choose');
    Route::delete('projects/{project}/google', [GoogleConnectionController::class, 'disconnect'])
        ->middleware('project.owner')->name('google.disconnect');
    Route::get('integrations/google/callback', [GoogleConnectionController::class, 'callback'])
        ->name('google.callback');

    // The strategy layer (§3.1). One screen: the live brief, and every version
    // behind it. Saving is a PUT because it replaces what the project writes
    // from — that it does so by appending a version rather than overwriting is
    // the model's business, not the caller's.
    Route::get('brief', [BrandBriefController::class, 'edit'])->name('brief.edit');
    Route::put('brief', [BrandBriefController::class, 'update'])
        ->middleware('project.owner')->name('brief.update');
    // Owner-only and rate limited, because each press drives a real browser at
    // somebody else's website. Same permission as editing the brief: it changes
    // what the brief offers.
    Route::post('brief/palette', [BrandBriefController::class, 'palette'])
        ->middleware(['project.owner', 'throttle:10,1', 'project.entitled'])->name('brief.palette');

    // The operator's day (§7): the queue, the calendar, the card. No
    // `project.owner`: approving what the brand says in public is the
    // operator's job rather than an ownership privilege, and everything here is
    // tenant-scoped already.
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('content/{item}/approve', [ApprovalController::class, 'approve'])->name('content.approve');
    Route::post('content/{item}/publish', [ApprovalController::class, 'publish'])->name('content.publish');
    Route::post('content/{item}/reject', [ApprovalController::class, 'reject'])->name('content.reject');

    Route::get('calendar', CalendarController::class)->name('calendar.index');

    Route::get('content', [ContentItemController::class, 'index'])->name('content.index');

    // The article half's two operator verbs, which it did not have — see
    // `ArticleController`. Throttled, because both put a model call behind one
    // press.
    Route::post('content/articles', [ArticleController::class, 'store'])
        ->middleware(['throttle:10,1', 'project.entitled:articles'])->name('content.articles.store');
    Route::post('content/plan', [ArticleController::class, 'plan'])
        ->middleware(['throttle:5,1', 'project.entitled'])->name('content.plan');

    Route::get('content/{item}', ContentItemDetailController::class)->name('content.show');

    Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::post('channels', [ChannelController::class, 'store'])
        ->middleware('project.owner')->name('channels.store');
    Route::patch('channels/{channel}', [ChannelController::class, 'update'])
        ->middleware('project.owner')->name('channels.update');
    // A real signed ping, which is what turns "configured" into "connected".
    Route::post('channels/{channel}/ping', [ChannelController::class, 'ping'])
        ->middleware('project.owner')->name('channels.ping');
    Route::patch('channels/{channel}/autopublish', [ChannelController::class, 'autopublish'])
        ->middleware('project.owner')->name('channels.autopublish');

    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::post('deliveries/{delivery}/replay', [DeliveryController::class, 'replay'])->name('deliveries.replay');

    Route::get('metering', MeteringController::class)
        ->middleware('project.owner')->name('metering.index');

    Route::get('delivery-economics', [DeliveryEconomicsController::class, 'index'])
        ->middleware('project.owner')->name('delivery-economics.index');
    Route::post('delivery-economics', [DeliveryEconomicsController::class, 'store'])
        ->middleware(['project.owner', 'throttle:20,1'])->name('delivery-economics.store');
    Route::get('feedback', FeedbackController::class)->name('feedback.index');
    Route::get('visibility', VisibilityController::class)->name('visibility.index');

    // The site the engine writes for, read as a crawler sees it. No
    // `project.owner` on any of the three: an audit is about the work rather
    // than about the account, and everything here is tenant-scoped already.
    //
    // Both POSTs are throttled, and for different reasons. A recheck crawls a
    // hundred pages of somebody else's server, so an accidental double press
    // must cost them nothing — the starter refuses a second sweep outright and
    // this is the belt to that brace. A fix plan is a model call, and a stuck
    // button should cost a few cents rather than a few dollars.
    Route::get('audit', [SiteAuditController::class, 'index'])->name('audit.index');
    Route::post('audit/recheck', [SiteAuditController::class, 'recheck'])
        ->middleware(['throttle:10,1', 'project.entitled:site_audits'])->name('audit.recheck');
    Route::post('audit/fix-plan', [SiteAuditController::class, 'fixPlan'])
        ->middleware(['throttle:10,1', 'project.entitled'])->name('audit.fix-plan');

    require __DIR__.'/article-schedules.php';
    require __DIR__.'/pages.php';
    require __DIR__.'/purchases.php';
    require __DIR__.'/measurement.php';
    require __DIR__.'/opportunities.php';
    require __DIR__.'/proposals.php';
    require __DIR__.'/native-pages.php';
    require __DIR__.'/ai-sampling.php';
    require __DIR__.'/ai-accuracy.php';
    require __DIR__.'/fact-maintenance.php';
});

Route::post('api/purchases/{source}', PurchaseWebhookController::class)
    ->middleware('throttle:120,1')->name('purchases.receive');

// The pull API (§9.5). Outside the auth group: it is authenticated by a
// channel token rather than by a session, and the token also chooses the tenant.
Route::middleware([AuthenticatePullApi::class])
    ->get('api/content', PullContentController::class)
    ->name('api.content');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
