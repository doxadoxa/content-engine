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
| Why every task below ends in ->sentryMonitor()
|--------------------------------------------------------------------------
|
| Because the failure this file is most exposed to is not a command that
| throws — that one reports itself — but a command that never runs at all.
| The scheduler container exited once and stayed exited for five days (the
| comment on that service in docker-compose.yml is the post-mortem), and
| nothing anywhere noticed: no exception, no failed job, no error line. A
| silent process is indistinguishable from a healthy one that had nothing to
| do, and every task here is `withoutOverlapping` and `runInBackground`, which
| are exactly the flags that make quiet look normal.
|
| A check-in inverts that. Sentry knows each task's schedule and alerts on the
| check-in that did not arrive, so the absence becomes the signal. Left
| unconfigured — no DSN — the call is a no-op, so the schedule runs exactly as
| it did before.
|
*/

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
    ->runInBackground()
    ->sentryMonitor();

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
    ->runInBackground()
    ->sentryMonitor();

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
    ->runInBackground()
    ->sentryMonitor();

// Only explicitly scheduled articles are selected.
Schedule::command('publish:approved')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor();

// The floor under the queue. A delivery job has one try, only a `retrying` row
// is ever re-dispatched, and `dispatch_key` stops a second row being made — so
// a worker killed mid-flight leaves a delivery at `pending` with nothing left
// that would ever touch it again. Every ten minutes rather than hourly: the
// threshold is already half an hour of waiting (§9, and
// App\Publishing\StrandedDeliveries).
Schedule::command('publish:sweep-stranded')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor();

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
    ->runInBackground()
    ->sentryMonitor();

/*
|--------------------------------------------------------------------------
| The site the engine writes for
|--------------------------------------------------------------------------
|
| Its own entry rather than a branch of the tick: `EngineTickCommand` waits
| while the article contour is working, and a site audit feeds none of those
| five pipelines. A project halfway through drafting has more reason to know
| its sitemap is broken than an idle one, not less — the article is about to
| be published into that sitemap.
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
    ->runInBackground()
    ->sentryMonitor();

Schedule::command('pages:measure')
    ->dailyAt('07:20')
    ->withoutOverlapping()
    ->sentryMonitor();

Schedule::command('pages:reconcile-publications')
    ->everyMinute()
    ->withoutOverlapping()
    ->sentryMonitor();

Schedule::command('visibility:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sentryMonitor();

Schedule::command('visibility:scheduled')
    ->hourly()
    ->withoutOverlapping()
    ->sentryMonitor();

Schedule::command('visibility:reconcile-accuracy')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sentryMonitor();

Schedule::command('facts:reconcile-maintenance')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sentryMonitor();
