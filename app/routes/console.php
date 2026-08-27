<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The engine's own clock
|--------------------------------------------------------------------------
|
| Until this existed, every pipeline was startable by hand and started by
| nothing: a project could be onboarded and planned, and then sit there while
| the calendar filled with dates nobody would ever write.
|
| Hourly rather than daily, because the work is a queue rather than a batch —
| a unit due tomorrow should start drafting today whatever hour the operator
| finished setting the project up. `withoutOverlapping` because a tick that
| catches the previous one still running would start the same work twice.
|
*/
Schedule::command('engine:tick')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Trials and dunning graces end because time passed, not because anybody did
| anything, and `billing:sweep` is what makes the record say so.
|
| Before the tick rather than after it, and hourly for the same reason the tick
| is: a trial that ran out at nine should not get a tenth hour of engine. It is
| only a tidier — entitlement is decided by reading dates, so a lapsed project
| is refused live whether or not this has run — but the sweep is what pauses the
| project, and a paused project is one the tick does not consider at all.
*/
Schedule::command('billing:sweep')
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->runInBackground();

/*
| And the repair for the other half, which is not optional.
|
| Entitlement is read from a local projection of what Stripe told us, so a
| webhook lost to a deploy, a timeout or a signature mismatch leaves a project
| silently entitled or silently stopped. Neither raises anything: both look
| exactly like normal operation, which is what makes them worse than an outage.
|
| Daily rather than hourly. It is a net under the webhooks rather than the
| mechanism, every run is an API call per paying project, and the failure it
| catches is measured in hours of wrong rather than seconds.
*/
Schedule::command('billing:reconcile')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();

// Anything approved goes out. Separate from the tick because publishing is the
// one step with an outside effect, and it should be readable on its own line.
Schedule::command('publish:approved')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// The floor under the queue. A delivery job has one try, only a `retrying` row
// is ever re-dispatched, and `dispatch_key` stops a second row being made — so
// a worker killed mid-flight leaves a delivery at `pending` with nothing left
// that would ever touch it again. Every ten minutes rather than hourly: the
// threshold is already half an hour of waiting (§9, and
// App\Publishing\StrandedDeliveries), and §4.3 places a post inside a window
// the operator is present for.
Schedule::command('publish:sweep-stranded')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// The same floor, one layer down: under the queue itself rather than under
// publishing. `PipelineRunner::resume()` was written to pick a stalled run back
// up and had no caller for the engine's whole life, so recovery from a lost
// dispatch depended on the lost dispatch arriving. A `visibility` run wedged on
// 2026-08-07 held the article contour of its project shut for two days, and
// nothing in the installation was capable of noticing.
//
// Every ten minutes, matching its neighbour above and for the same reason: what
// it recovers is a project not writing, and the tick that would use the freed
// contour comes round on the hour. Resuming a run that did not need it costs
// one queue message the claim then discards, so the sweep can afford to be
// blunt and frequent.
Schedule::command('pipeline:reap')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| The site the engine writes for
|--------------------------------------------------------------------------
|
| Its own entry rather than a branch of the tick, by the same rule that gives
| `social:listen` one: `EngineTickCommand` waits while the article contour is
| working, and a site audit feeds none of those six pipelines. A project halfway
| through drafting has more reason to know its sitemap is broken than an idle
| one, not less — the article is about to be published into that sitemap.
|
| Daily, and it starts almost nothing. `audit:sweep` asks per project whether
| the last reading is older than `audit.refresh_after_days`, so this produces a
| weekly crawl per project, spread across the week by when each one last had one.
| A weekly schedule entry would instead point every project's crawl at the same
| minute, which is a thundering herd aimed at customers' servers.
|
| Early, and before the working day in every timezone this runs projects in: a
| crawl is the most visible thing this engine does to somebody else's site, and
| it should happen while their traffic is at its lowest.
|
*/
Schedule::command('audit:sweep')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Everything below this line is the social presence
|--------------------------------------------------------------------------
|
| And an installation that has not connected a Threads account does not run any
| of it. `SOCIAL_PRESENCE_ENABLED` is off by default and this is the surface it
| matters most on: five of the seven entries below fire hourly, and hourly is a
| lot of scheduler wakeups, `withoutOverlapping` locks and background processes
| to reach the same conclusion the previous hour reached — that there is nothing
| connected. Each command skips such a project correctly and silently, which is
| the right behaviour for a project among others that *are* connected, and the
| wrong shape for a deployment where none of them ever will be.
|
| A `return` rather than a wrapping `if`, because these are the last seven
| entries in the file and the alternative is eighty lines of indentation
| carrying the same meaning less legibly. Anything that must run whether or not
| this deployment does social goes above it. `publish:sweep-stranded` in
| particular is not social despite its neighbours: it recovers any stranded
| delivery, and a webhook channel strands exactly the same way.
|
| `project:capture` is the arguable one, and it is gated deliberately. §6 is
| about the project rather than about Threads — brand demand, the channel split,
| the coverage gap — and two thirds of it would keep working with no account
| connected. It is here because one switch has to mean one thing: an operator
| who turns the social presence off and finds a nightly job still running is
| owed an explanation the name of the variable cannot give them. When §6 wants a
| life of its own it should get its own line above this one, not a second
| meaning for this switch.
|
*/
if (! config('social.enabled')) {
    return;
}

/*
|--------------------------------------------------------------------------
| Listening (§4.1, §11.3)
|--------------------------------------------------------------------------
|
| Its own entry, deliberately not a branch of the tick. `EngineTickCommand`
| refuses to start anything while any pipeline is pending or running — right
| for work that feeds itself, and wrong for this: a project drafting a long
| article would stop listening for as many hours as the article takes, and
| §4.1's claim is that this contour pays for itself even when nothing else
| happens.
|
| Hourly, as §4.1 says. §11.3 is what makes it non-negotiable that this line
| exists at all: "Планировщик движка пуст… Статья это терпит, соцкалендарь —
| нет." A project with no Threads connection and no feeds is skipped inside the
| command, silently, because that is three of four projects and not a fault.
|
*/
Schedule::command('social:listen')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Planning the week (§4.3)
|--------------------------------------------------------------------------
|
| Weekly, and its own entry for the same reason listening has one:
| `EngineTickCommand::CONTOUR` is a rule — each contour blocks only itself —
| and the week's slots feed none of the six pipelines the tick runs. A project
| halfway through writing an article has exactly as much reason to plan its
| week as an idle one, and a thirty-second planning run must not stall an
| hour of drafting.
|
| Monday morning, before the week it plans. Early enough that Monday's own
| duty windows are still ahead of it — the planner only ever places a slot in
| the future — and not so early that it reads a reply rate the weekend has not
| finished contributing to.
|
| The result may well be no slots at all. §4.3 calls that a valid outcome and
| §7 makes it a visible one: every run writes a `social_plans` row carrying the
| reason, so a week the engine decided to sit out is distinguishable from a
| scheduler that stopped.
|
*/
Schedule::command('social:plan')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground();

// The other end of the week the planner wrote. A slot is a time to publish at
// and nothing else until this runs, so without it §4.3's second contour stops
// halfway: `social_draft` exists and nothing starts it.
//
// Hourly rather than with the tick, because a slot is an instant inside a duty
// window and `EngineTickCommand::isBusy()` would let a generation run swallow
// the morning the slot was placed in. The command drafts two hours ahead — see
// its docblock for why the lead is hours where an article's is a day.
Schedule::command('social:draft')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// §5's kill: a reactive draft that missed its window is deleted, not published
// late — "автоматический комментарий к позавчерашней новости хуже молчания".
// Hourly, because a TTL is measured in hours and a killed draft sitting on the
// operator's screen for a day is a decision that looks like a bug.
Schedule::command('social:kill-expired')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// The other half of the signal table's life. `Signal::scopeReapable()` spares
// consumed signals and anything a content unit points at, because §3's
// per-source attribution is the whole reason the table exists; what it takes is
// a reactive signal whose window closed with nobody acting on it (§5). Daily
// rather than hourly: nothing downstream reads an expired signal — the
// planner's only entry point is `scopeLive()` — so this is housekeeping, and
// housekeeping that races the intake buys nothing.
Schedule::command('signals:reap')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// Threads tokens, kept alive (§9). Daily is generous for a credential that
// lasts about sixty days and is renewed inside the last week of them — the
// margin is deliberate, because the platform refuses to renew an expired token
// and a missed window costs a human reconnecting rather than a retry.
//
// Its own line rather than a step of the tick, because renewal has to happen
// for projects the tick has no reason to visit: until this existed, a token
// was renewed only as a side effect of publishing, so a project that only
// listens — the contour §4.1 says pays for itself with nothing published —
// reached expiry and broke.
Schedule::command('threads:renew')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| The daily snapshot (§6)
|--------------------------------------------------------------------------
|
| One `project_states` row per project per day: brand demand, the channel
| split, Threads insights and the coverage gap. A stored row rather than a
| computation, because §6 says the trend is the product and "задним числом GSC
| и GA4 пересчитывать дорого и не всегда возможно" — a day not captured is a
| day gone.
|
| Just after 04:00, which is after `signals:reap` has finished with the night
| and well before an operator opens §7's summary. Nothing else reads or writes
| `project_states`, so the hour is chosen for the vendors rather than for us:
| both Google APIs are quieter, and the previous day has had the whole night to
| settle in every timezone this engine runs projects in.
|
| It captures the previous complete day and the two before it, never today —
| see `ProjectStateCapture::TRAILING_DAYS`. The window is what makes a missed
| night self-healing: the run is idempotent on (project_id, captured_on), so
| tomorrow's sweep corrects whatever tonight's could not read.
|
*/
Schedule::command('project:capture')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();
