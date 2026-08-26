<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PullContentController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BrandBriefController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentItemDetailController;
use App\Http\Controllers\ContentStudioController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\MeteringController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SiteAuditController;
use App\Http\Controllers\SocialCreateController;
use App\Http\Controllers\SocialOverviewController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\ThreadsConnectionController;
use App\Http\Controllers\ThreadsWebhookController;
use App\Http\Controllers\VisibilityController;
use App\Http\Middleware\AuthenticatePullApi;
use App\Http\Middleware\VerifyThreadsSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('marketing'))->name('home');

/*
 * Whether this deployment has a social presence at all (config/social.php).
 *
 * Read once, here, and consulted at each of the three places below where the
 * feature has a URL. Off, those routes are never registered — not registered
 * and answering 404, rather than registered and answering 503 — and the
 * difference matters for exactly one of them. A 503 on the webhook tells Meta
 * there is an endpoint here that is temporarily unwell, which is an invitation
 * to keep delivering and to keep retrying; a 404 tells it there is nothing at
 * this address, which is true. The other five behave the same way for
 * consistency: a screen that is not in the navigation should not be reachable
 * by typing its path either.
 */
$social = (bool) config('social.enabled');

Route::middleware(['auth'])->group(function () use ($social): void {
    // Where somebody starts, and now the only place they can: a box to type
    // into, what needs a person, the figures, both halves of the engine, and
    // what it refused to do. See HomeController for why this absorbed the other
    // two rather than sitting beside them.
    Route::get('home', HomeController::class)->name('home.index');

    // Conversations with the engine (see `AssistantController`). Each one has
    // a URL, because a chat you cannot get back to is a chat you only have
    // once. The two that spend money are throttled; a turn can run several tool
    // calls and each one is a bill.
    Route::get('chat', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('chat', [AssistantController::class, 'store'])
        ->middleware('throttle:30,1')->name('assistant.store');
    Route::get('chat/{thread}', [AssistantController::class, 'show'])->name('assistant.show');
    Route::post('chat/{thread}', [AssistantController::class, 'reply'])
        ->middleware('throttle:30,1')->name('assistant.reply');
    Route::patch('chat/{thread}', [AssistantController::class, 'rename'])->name('assistant.rename');
    Route::delete('chat/{thread}', [AssistantController::class, 'destroy'])->name('assistant.destroy');

    // Kept as a redirect rather than deleted. The name is linked from
    // `OnboardingController`, from bookmarks, and from anything that ever sent
    // somebody a `/dashboard` URL; a 404 for those is a worse answer than the
    // screen that replaced it.
    Route::redirect('dashboard', '/home')->name('dashboard');

    // Creating a project is a wizard, not a form (§3.1): URL in, a reading of
    // the site, then the operator correcting it — and the engine starts itself.
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    // Throttled: each call makes an outbound fetch and a model call on nothing
    // more than a string somebody typed, which is a bill and a request-forgery
    // surface an unbounded loop would make worse.
    Route::post('onboarding/analyse', [OnboardingController::class, 'analyse'])
        ->middleware('throttle:20,1')
        ->name('onboarding.analyse');
    Route::post('onboarding/{project}/save', [OnboardingController::class, 'save'])
        ->middleware('project.owner')->name('onboarding.save');
    Route::post('onboarding/{project}/launch', [OnboardingController::class, 'launch'])
        ->middleware('project.owner')->name('onboarding.launch');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])
        ->middleware('project.owner')->name('projects.edit');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])
        ->middleware('project.owner')->name('projects.update');

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

    // Threads (§9), granted per project and per account. Shaped exactly like
    // the Google trio above, and the callback carries no project for the same
    // reason: Meta compares redirect URIs as exact strings, so there is one
    // registered URI and the session says which project asked. There is no
    // `choose` step — a Threads grant is one account, not a list to pick from.
    //
    // Gone when the presence is off, together with the panel on the settings
    // screen that links to them. There is nothing to connect to: the flow's
    // first step redirects to Meta with an app id this deployment does not
    // have.
    if ($social) {
        Route::get('projects/{project}/threads/connect', [ThreadsConnectionController::class, 'connect'])
            ->middleware('project.owner')->name('threads.connect');
        Route::delete('projects/{project}/threads', [ThreadsConnectionController::class, 'disconnect'])
            ->middleware('project.owner')->name('threads.disconnect');
        Route::get('integrations/threads/callback', [ThreadsConnectionController::class, 'callback'])
            ->name('threads.callback');
    }

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
        ->middleware(['project.owner', 'throttle:10,1'])->name('brief.palette');

    // §7's own screen: one summary, five minutes, four sections. No
    // `project.owner`, by the same reasoning as `content.approve` and
    // `engage.*` below — this is the operator's morning routine and not an
    // ownership privilege. Everything it reads is tenant-scoped already.
    //
    // §7's summary has no route of its own any more. It asked for *one*
    // summary and five minutes, and it had become the middle of three landing
    // screens that all read as "dashboard" — so the two parts of it that
    // existed nowhere else moved to Home: the refusal ledger, which is now
    // `App\Social\RefusalLedger` and renders on every load, and the §6 trend,
    // which is two of the three figures. The rest of it was the reply queue,
    // re-rendered one click from the reply queue.
    //
    // The operator's day (§7): the queue, the calendar, the card.
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('content/{item}/approve', [ApprovalController::class, 'approve'])->name('content.approve');
    Route::post('content/{item}/publish', [ApprovalController::class, 'publish'])->name('content.publish');
    Route::post('content/{item}/reject', [ApprovalController::class, 'reject'])->name('content.reject');

    // The other queue of §7, and the one measured in minutes rather than in
    // days: incoming conversations waiting for a reply (§4.2).
    //
    // No `project.owner`, deliberately and by the same reasoning as
    // `content.approve` above — approving what the brand says in public is the
    // operator's job, not an ownership privilege, and §4.2's target latency is
    // unreachable if the only person allowed to press Send is the account
    // holder. Every route is tenant-scoped: the queue reads through
    // ProjectScope and the `{interaction}` binding resolves through it too, so
    // a conversation in another project is a 404 rather than a permission
    // error.
    //
    // Nothing here is reachable without a session, and that is not merely
    // sensible — it is the structural half of §4.2's permanent ban on
    // autopublish. See App\Social\InteractionReplySender.
    //
    // An `interactions` row can only arrive through the Threads webhook or the
    // listening contour, and with the presence off neither exists — so this
    // queue is empty by construction rather than by circumstance, and an empty
    // queue that can never fill is a screen, not a state.
    if ($social) {
        Route::get('engage', [InteractionController::class, 'index'])->name('engage.index');
        Route::post('engage/{interaction}/send', [InteractionController::class, 'send'])->name('engage.send');
        // §11.1's human-assisting path: the operator posted it themselves.
        Route::post('engage/{interaction}/sent', [InteractionController::class, 'recordSent'])->name('engage.sent');
        Route::post('engage/{interaction}/skip', [InteractionController::class, 'skip'])->name('engage.skip');
    }

    Route::get('calendar', CalendarController::class)->name('calendar.index');

    // The social surface (§4.2 of `studio-rethink-spec.md`). Not behind
    // `$social`, and the controller says why at length: that flag is about
    // having a Threads presence, and this board reads the ideas and drafts the
    // Studio writes on every installation.
    Route::get('social', [SocialOverviewController::class, 'index'])->name('social.index');
    Route::post('social/goal', [SocialOverviewController::class, 'store'])->name('social.goal.store');

    // Where a post comes from: the month's unwritten ideas, the reasons the
    // world handed us, and a blank sheet. The signals shelf is the only thing
    // in this engine that brings anything in from outside our own site.
    Route::get('social/create', SocialCreateController::class)->name('social.create');

    // The composer. Four steps forward instead of four levels of nesting, and
    // its last step posts to `content.approve` above — there is one approval
    // gate in this engine and this is not a second one.
    Route::get('social/posts/{item}', [SocialPostController::class, 'show'])->name('social.posts.show');
    Route::patch('social/posts/{item}', [SocialPostController::class, 'update'])->name('social.posts.update');
    // Describe what is wrong; the engine works out whether that is the words,
    // the picture, or both. Throttled: every call is a model call, and the
    // picture half puts a paid redraw on the queue.
    Route::post('social/posts/{item}/edit', [SocialPostController::class, 'edit'])
        ->middleware('throttle:20,1')->name('social.posts.edit');

    // The monthly assistant, which is a *tab of the social surface* rather than
    // a place of its own. It was a top-level "Studio" beside "Social" for one
    // release and the pair was indefensible: two entries in the column, no
    // sentence explaining which to open, and the same month's work behind both.
    //
    // The old path still answers, permanently rather than as a courtesy — it is
    // in browser histories and in the docs, and a 404 there teaches an operator
    // that the menu lies.
    Route::get('social/plan', [ContentStudioController::class, 'index'])->name('social.plan');
    Route::get('studio', function (Request $request) {
        // The query string comes too. `Route::redirect()` drops it, and the one
        // parameter this screen takes is the month — so a bookmarked
        // `/studio?month=2026-09` would have silently landed on today.
        return redirect()->route('social.plan', $request->query());
    })->name('studio.index');
    Route::post('studio/propose', [ContentStudioController::class, 'propose'])
        ->middleware('throttle:10,1')->name('studio.propose');
    Route::post('studio/plans/{plan}/refine', [ContentStudioController::class, 'refine'])
        ->middleware('throttle:20,1')->name('studio.refine');
    Route::post('studio/plans/{plan}/accept', [ContentStudioController::class, 'accept'])
        ->name('studio.accept');
    Route::post('studio/plans/{plan}/generate', [ContentStudioController::class, 'generate'])
        ->middleware('throttle:10,1')->name('studio.generate');
    // One idea, on demand. Throttled like the batch above rather than like the
    // text actions: it buys a pool of model calls and a picture per channel,
    // which is the same money the weekly button spends, one idea at a time.
    Route::post('studio/ideas/{idea}/generate', [ContentStudioController::class, 'generateIdea'])
        ->middleware('throttle:10,1')->name('studio.ideas.generate');
    // An idea somebody typed, written on the spot. Throttled like the two
    // above: it writes the row and immediately buys the same drafting run.
    Route::post('studio/ideas', [ContentStudioController::class, 'storeIdea'])
        ->middleware('throttle:10,1')->name('studio.ideas.store');
    // What an idea is called, what its point is, and what it should be made
    // as — the three things somebody adjusts before pressing Create. Not
    // throttled with the two above: it spends nothing.
    Route::patch('studio/ideas/{idea}', [ContentStudioController::class, 'updateIdea'])
        ->name('studio.ideas.update');
    // Throttled harder than the text actions: every call here buys pictures
    // from a provider, and a stuck button should cost a few cents rather than
    // a few dollars.
    Route::post('studio/drafts/{item}/image', [ContentStudioController::class, 'reviseImage'])
        ->middleware('throttle:20,1')->name('studio.image.revise');
    Route::post('studio/drafts/{item}/photo', [ContentStudioController::class, 'uploadImage'])
        ->middleware('throttle:60,1')->name('studio.image.upload');
    Route::post('studio/drafts/{item}/image/{asset}', [ContentStudioController::class, 'chooseImage'])
        ->name('studio.image.choose');

    Route::get('content', [ContentItemController::class, 'index'])->name('content.index');

    // The article half's two operator verbs, which it did not have — see
    // `ArticleController`. Throttled like the studio's, because both put a
    // model call behind one press.
    Route::post('content/articles', [ArticleController::class, 'store'])
        ->middleware('throttle:10,1')->name('content.articles.store');
    Route::post('content/plan', [ArticleController::class, 'plan'])
        ->middleware('throttle:5,1')->name('content.plan');

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
        ->middleware('throttle:10,1')->name('audit.recheck');
    Route::post('audit/fix-plan', [SiteAuditController::class, 'fixPlan'])
        ->middleware('throttle:10,1')->name('audit.fix-plan');
});

// The pull API (§9.5). Outside the auth group: it is authenticated by a
// channel token rather than by a session, and the token also chooses the tenant.
Route::middleware([AuthenticatePullApi::class])
    ->get('api/content', PullContentController::class)
    ->name('api.content');

// The listening contour's inbound edge (§4.1). Outside the auth group for the
// same reason the pull API is: the caller is Meta, not a person with a session.
//
// Both halves disappear when the presence is off, and this is the pair the
// choice of 404 over 503 was made for. A subscription handshake that answers
// anything other than a 404 is an address Meta will remember and keep
// delivering to; an installation with no Meta app has no subscription to
// honour, and the honest answer to a POST it never asked for is that there is
// nothing here.
if ($social) {
    // The GET is the subscription handshake and is deliberately *not* behind
    // VerifyThreadsSignature — it has no body, so there is nothing to sign, and
    // requiring a signature there would make the endpoint impossible to
    // subscribe. It authenticates itself the only way Meta offers: a verify
    // token we chose.
    Route::get('api/threads/webhook', [ThreadsWebhookController::class, 'verify'])
        ->name('threads.webhook.verify');

    // The POST carries a body Meta signed with the app secret, so the signature
    // is the whole of its authentication and it belongs in front of the
    // controller.
    Route::post('api/threads/webhook', [ThreadsWebhookController::class, 'receive'])
        ->middleware(VerifyThreadsSignature::class)
        ->name('threads.webhook.receive');
}

require __DIR__.'/settings.php';
