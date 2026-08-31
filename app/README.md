# Content Engine

Content Engine is a multi-tenant content operations service: it researches a
market, builds a plan, generates and reviews localized content, publishes it,
measures the result, and feeds those measurements into refresh and planning
work. The operator interface is Laravel 13 + Inertia 3 + React 19; PostgreSQL,
Redis, Horizon, and the scheduler run alongside it.

The product specification is in [`../product/spec.md`](../product/spec.md), the
11 completed delivery phases are indexed in
[`../product/phases/README.md`](../product/phases/README.md), and the signed
webhook contract is documented in
[`../product/webhook-publish-adapter-spec.md`](../product/webhook-publish-adapter-spec.md).

## Quick start

Prerequisites: Docker with Compose and Node.js for host-side frontend checks.

```sh
cp .env.example .env
docker compose up --build
```

Open <http://localhost:8091/login> and use the local seed account:
`admin@content-engine.test` / `password`. Change `SEED_ADMIN_*` in `.env` if
that stack is reachable by anyone else.

| Local endpoint | Purpose |
|---|---|
| <http://localhost:8091/dashboard> | Operator dashboard |
| <http://localhost:8091/horizon> | Global queue dashboard; email allow-list only |
| <http://localhost:8091/up> | Application health check |
| <http://localhost:8026> | Mailpit — every mail the stack sends |
| `127.0.0.1:5437` | PostgreSQL for host-side tools |
| `127.0.0.1:6384` | Redis for host-side tools |

All published Compose ports are loopback-only. Override their numbers with
`CE_APP_PORT`, `CE_DB_PORT`, `CE_REDIS_PORT`, and `CE_MAIL_PORT`. This Compose file is a local
development stack: it contains documented local credentials, a shared local
application key, source mounts, and `APP_DEBUG=true`. It is not a production
deployment manifest.

For frontend work, keep the containers running and start Vite on the host:

```sh
npm run dev
```

## Configuration

Start with [`.env.example`](.env.example); its comments describe every setting.
The important groups are:

- `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` for model roles selected in
  [`config/models.php`](config/models.php).
- `DATAFORSEO_*` or `AHREFS_API_TOKEN` for keyword research.
- `GOOGLE_*` for per-project Search Console and GA4 OAuth.
- `ATLASCLOUD_API_KEY` for optional hero images.
- `PULL_TOKEN_HASH_KEY` for stable HMAC lookup of pull-channel bearer tokens.
- `HORIZON_ALLOWED_EMAILS` to name the first administrator on a fresh
  deployment. After that, administration is `users.is_admin`; project
  membership never grants it.
- `STRIPE_*` and `BILLING_*` for checkout, the billing portal and the trial.
  Entitlement works without them — see
  [Billing and entitlement](#billing-and-entitlement).
- `TRUSTED_PROXIES` and `OUTBOUND_*` for deployment and outbound-network
  policy. Production requires HTTPS outbound targets by default.

Queue workers load configuration once. Restart Horizon after changing provider
keys or queue configuration:

```sh
docker compose restart horizon
```

No live provider credentials are committed. The automated suite uses fakes and
does not call external services. Without provider keys, model-backed pipeline
steps fail authentication; Google-backed feedback steps and optional image
generation skip when they are not configured.

## How the service runs

`app`, `horizon`, and `scheduler` use the same image with different
`CONTAINER_MODE` values. The scheduler runs:

- `engine:tick` hourly to start bounded research, planning, generation,
  repurpose, and feedback work that is due.
- `publish:approved` every 30 minutes to send approved content to verified
  automatic webhook destinations.
- `audit:sweep` daily, which starts a site audit only for projects whose last
  reading has gone stale (`AUDIT_REFRESH_AFTER_DAYS`, a week by default). The
  audit is deliberately outside `engine:tick`'s contour: it feeds none of the
  pipelines the tick runs, so neither waits for the other.

Useful manual commands:

```sh
php artisan engine:tick --dry
php artisan engine:tick --project=<slug>
php artisan pipeline:run <pipeline> <project>
php artisan publish:approved <project>
php artisan publish:replay <delivery-uuid>
php artisan pipeline:cost <project> --days=30
php artisan audit:sweep --project=<slug> --force
```

Horizon runs four worker pools. `pipeline` and `pipeline-expensive` keep quick
steps away from model calls (§3.2); `pipeline-audit` is a third kind of work —
slow at the network and cheap everywhere else — so a hundred sequential requests
to a customer's server never sit in front of an article that is due.

A pipeline is a dependency graph of step classes configured in
[`config/pipeline.php`](config/pipeline.php). Run and step state is persisted;
step attempts use database claims plus a project-scoped PostgreSQL advisory
mutex, so retries resume recorded work and competing workers cannot execute the
same side-effect window concurrently.

## Content, tenancy, and roles

One `content_items` row is one locale of one item. Rows sharing a
`locale_group_id` are localized variants; social derivatives point to their
source through `parent_id`. State changes go through
`ContentItem::transitionTo()`, and Brand Brief revisions are append-only.

Tenant-owned models use `BelongsToProject`. Reads fail closed when no current
project exists; writes are stamped and rejected if they target another project.
Queued work carries project context. Cross-project console code must opt into
`acrossProjects()` and add an explicit project condition.

Membership roles are enforced server-side:

- **Owners** can change project settings and Brand Briefs, resume/launch
  onboarding, manage publishing channels, and connect Google.
- **Operators** can run the daily content workflow: inspect work, approve or
  reject publishable drafts, explicitly publish approved content, and replay
  deliveries. Owner-only controls are not rendered for them.
- **System administrators** are `users.is_admin`, which is not a project role
  and is never derived from one. It opens `/admin` and Horizon. See
  [Running the service](#running-the-service).

## Billing and entitlement

A subscription belongs to a **project**, because a project is the tenant: the
scope every read is filtered by, the thing spend accrues against, and what a
customer calls "my site". The Stripe customer is the *account*, so one saved
card can hold several projects — Cashier names each subscription after the
project's ULID.

Plans live in [`config/billing.php`](config/billing.php), versioned the way
model prices are: re-pricing publishes a new version and never edits a
published one, so a project keeps what it was sold.

A trial is bounded by the *trial's* limits, not by the plan it will convert to.
A public trial is a paid plan with free days on the front — the checkout stamps
`medium` — so limits are read from the plan whose free window is running, and
the plan somebody bought is still what the interface says they are on.

**The card is taken at the end of the wizard.** Somebody registers, watches the
engine read their site, confirms the brief — and only then is asked for a card,
which is the strongest moment to ask and the reason it is not asked at signup.
The checkout creates a Stripe subscription with `trial_period_days`: nothing is
charged that day, and the first invoice falls due when the free days run out. A
customer who stays does nothing to convert; one who leaves cancels before the
date.

Nothing runs until Stripe confirms it. Research is spend, and spend before a
subscription exists is what every gate here refuses — so the wizard's last step
marks the project `Launching` and starts nothing, and
`customer.subscription.created` is what begins the engine. A checkout somebody
abandons leaves a legible state rather than a stuck one: the banner says there
is no card and links to the same checkout.

There are **two layers of limit**, and they fail differently:

- The **unit quota** — articles, social posts, audits, plans, assistant turns —
  is what the customer agreed to, and it is what a refusal names. It is
  *reserved* rather than checked and then counted: the guard lives inside the
  write, because two approvals racing for one remaining unit lock different
  rows and would otherwise both win. It is
  consumed on *approval*, not on generation: the engine writes eight social
  posts to keep one, and charging for the seven it discarded would make the
  number on the screen meaningless.
- The **cost ceiling** is a `cost_micros` fuse at roughly three times measured
  cost of goods. It is invisible to the customer and exists for the retry storm
  and the mispriced model, not for legitimate use. The seven discarded drafts
  land here.

The plan's *shape* limits — languages, publishing channels — are enforced where
the shape changes, since no counter will notice them later.

A plan's article allowance also clamps `weekly_target`, the dial `engine:tick`
already reads. Clamped on read and never written back, so a downgrade does not
overwrite a setting somebody chose — and the engine paces itself rather than
stopping dead on the 22nd.

Where the gates are:

- `engine:tick` filters on entitlement beside `status = active`. Everything
  unattended flows from that one list.
- `project.entitled:<metric>` sits beside the throttles on the routes a person
  can press to spend money.
- **Publishing is never gated on quota** and survives a failed payment to the
  end of the dunning grace. Approved work was already paid for.
- **Reading is never gated at all.** An expired project's articles, briefs,
  metrics and audits stay reachable for ever; the project moves to
  `ProjectStatus::Paused`, which already meant exactly this.

```sh
php artisan billing:assign <project> <plan> [--payer=email] [--resume]
php artisan billing:sweep [--dry]      # ends trials and graces, rolls periods
php artisan billing:reconcile [--dry]  # compares local entitlement to Stripe
```

`billing:reconcile` is not optional. Entitlement is read from a local
projection of what Stripe told us, so a webhook lost to a deploy or a signature
mismatch leaves a project silently entitled or silently stopped — and neither
raises anything, because both look exactly like normal operation.

Mail is the one outbound dependency whose failure is silent from the inside:
an unverified sending domain or a wrong key looks exactly like a healthy
application. After a deploy, or after changing anything under `MAIL_`, ask the
provider rather than assume.

```sh
php artisan mail:probe you@example.com
```

Stripe sits behind `App\Billing\Contracts\BillingProvider`, held to the same
two rules as the model and conversation gateways: it is the only door, and the
suite binds a fake over it so no test reaches the network. Webhooks project
straight from the payload into `project_subscriptions` and are idempotent by
event id.

Without Stripe keys nothing breaks — entitlement is decided from local rows and
`billing:assign` works with no provider at all. What stops working is checkout,
the billing portal and the reconciler.

## Production images

[`.github/workflows/build-image.yml`](../.github/workflows/build-image.yml)
builds and pushes two images to GHCR on every push to `main`:

| Image | Contents |
|---|---|
| `ghcr.io/doxadoxa/content-engine` | The app, in all four roles — `CONTAINER_MODE` picks one of `app`, `horizon`, `queue`, `scheduler` |
| `ghcr.io/doxadoxa/content-engine/renderer` | The Remotion renderer: Node, Chromium and the brand typeface |

Both are tagged `sha-<commit>` and `latest`, where `<commit>` is the full
40-character sha and not the abbreviated one. Deploy the `sha-` tag.

The release the application reports to Sentry is that same sha **without the
`sha-` prefix** — the prefix belongs to the tag, not to the release. So a
release copied out of Sentry needs it prepended to become an image reference:

```sh
docker pull ghcr.io/doxadoxa/content-engine:sha-<release-from-sentry>
```

That one step aside, an event, an image and a commit all name each other, on
the server and in the browser both.

`latest` and `main` are conveniences and are applied only when the commit that
was built is still what `main` points at, so they do not walk backwards when two
builds finish out of order or an old run is re-run by hand. They are still
best-effort: a narrow race remains, and the app and renderer images resolve it
separately. Deploy the `sha-` tag, which cannot be ambiguous.

Enabling Sentry on an already-built commit means re-running the workflow from
the Actions tab rather than pushing an empty commit. A manual run passes a
cache-bust so the frontend is genuinely rebuilt — Docker does not treat a
changed build secret as a cache miss, so without it the source-map upload would
be skipped and the failure would be silent.

`docker-compose.yml` in this directory builds `target: dev` from source instead
and is not a deployment manifest — see the note under [Quick start](#quick-start).

Sentry is optional and off by default; the images build and run with none of it
configured. Because Vite inlines `VITE_*` into the bundle, the browser half has
to be configured on the *build*, not on the running container. Set these on the
repository:

- Variables — `SENTRY_ORG`, `SENTRY_PROJECT`, `VITE_SENTRY_ENVIRONMENT`
  (defaults to `production`), `VITE_SENTRY_TRACES_SAMPLE_RATE`.
- Secrets — `VITE_SENTRY_DSN`, and `SENTRY_AUTH_TOKEN` if the build should
  upload source maps. The token is passed as a build secret rather than a build
  arg, which would leave it readable in the image history.

The server half (`SENTRY_LARAVEL_DSN` and the rest) is ordinary runtime
configuration; set it on the container.

## Running the service

`users.is_admin` is the permission for `/admin` and for Horizon, and the only
one — nothing else grants either. `HORIZON_ALLOWED_EMAILS` bootstraps the
column and is no longer consulted when the question is asked: the migration
grants the flag to the addresses on it, and `DatabaseSeeder` grants it to the
seeded account when no administrator exists at all, so a fresh deployment still
has a way in. Left as a second way in it would have kept every fault the column
was introduced to fix — access surviving a revoked flag, and an account
registered later with a listed address acquiring it unasked.

Five screens: an overview with **margin per project** — the figure no payment
provider can compute, because it needs what a customer pays *and* what they
cost — plus accounts, projects, subscriptions, and one project in detail with
its plan, trial and pause controls. Every mutation writes an `admin_actions`
row with before and after.

Everything there reads across tenants, which is the one thing the rest of this
application is built to prevent, so every query opts into `acrossProjects()` or
names its project explicitly. `ProjectScope` failing closed is what makes that
safe: a forgotten opt-in shows an empty table rather than another tenant's
rows.

Impersonation is deliberately absent. It is the one feature here that can act
*as* a customer, and it should arrive with its own audit trail and its own
argument.

## Publishing contracts

### Webhooks

Webhook channels are the push transport. Saving a channel does not mark it
connected: an owner queues a signed ping, the worker verifies it asynchronously,
and the interface polls while that test is pending. Editing connection details
clears the prior verification.

Automatic publication selects only enabled, verified webhook channels whose
`autopublish` flag is on. Explicit publication selects enabled, verified
webhook channels regardless of that flag. Delivery snapshots, logical content
revision keys, per-channel publish/update events, locks, retries, dead letters,
and replay provide idempotent transport behavior. A content item becomes
published only after a receiver succeeds.

Every outbound request goes through the shared public-target policy. Webhook
endpoints and receiver-provided public URLs must pass scheme, host, address,
port, redirect, and project-origin checks.

### Pull API

Static sites can create a `pull_api` channel and use its secret as a bearer
token:

```http
GET /api/content?per_page=25
Authorization: Bearer <channel-secret>
Accept: application/json
```

The response uses the same content shape as webhook delivery and returns:

```json
{
  "contract": 1,
  "data": [],
  "meta": {
    "per_page": 25,
    "has_more": false,
    "next_cursor": "opaque-encrypted-cursor"
  }
}
```

Pass `meta.next_cursor` back as `cursor` until `has_more` is false. The cursor
is opaque and combines `updated_at` with the item ULID so equal timestamps do
not skip rows. `per_page` is 1–100; the old `since` parameter is rejected.
Tokens are stored encrypted for recovery and separately indexed by a keyed
HMAC; changing `PULL_TOKEN_HASH_KEY` invalidates lookup until tokens are
rotated or reindexed.

## Tests and quality gates

The suite requires PostgreSQL features used in production, including pgvector,
JSON, partial indexes, and advisory locks. Run the complete gate from the app
directory:

```sh
bin/test
bin/test --filter=FeedbackLoopTest
```

The script runs every stage even if an earlier one fails, then returns a failing
status if any stage was red:

1. Pint
2. PHPStan
3. Laravel/PHPUnit in Docker against `content_engine_test`
4. ESLint, Prettier, and TypeScript
5. Production Vite build

Individual checks remain available through `composer lint:check`,
`composer types:check`, `php artisan test`, `npm run check`, and
`npm run build`. Feature tests render the real Vite manifest; run a build after
adding a page.

Dependency advisories use a separate networked gate because the registries
receive the locked package names and versions:

```sh
bin/audit
```

Run it in CI, or locally after approving access to Packagist and npm. An offline
`npm audit` result is only cached evidence and does not establish advisory
freshness; Composer cannot audit offline unless its advisory data is already
cached.

Wayfinder generates typed route helpers under `resources/js/routes` and
`resources/js/actions`. After changing routes, run:

```sh
php artisan wayfinder:generate --with-form
```

## Repository map

```text
app/Billing/                     plans, entitlement, and the one Stripe door
app/Pipelines/Core/              durable pipeline orchestration
app/Pipelines/Definitions/       pipeline graphs
app/Audit/                       site audit checks, crawler, and scoring
app/Publishing/                  webhook/pull contracts and delivery
app/Support/Http/                outbound network and URL safety
app/Support/Tenancy/             current-project context and scope
app/Http/Controllers/            operator and pull API endpoints
app/Http/Controllers/Admin/      cross-tenant administration
resources/js/pages/              Inertia screens
resources/js/components/ui/      shared accessible UI primitives
packages/engine-receiver/        Laravel receiver package
docker/                          local runtime and database bootstrap
tests/                           Postgres-backed unit and feature coverage
```

## Known operational limits

- The provider adapters are fully faked in the suite. Exercise model, keyword,
  image, Google, and receiver integrations in a staging environment before
  production rollout.
- Social channel records drive derivative generation; delivery still goes
  through a verified webhook receiver. There are no direct LinkedIn, X,
  Telegram, or WordPress API clients in this repository.
- Browser smoke checks currently remain a release procedure rather than a
  committed Playwright/axe suite. The PHP suite covers rendered props and
  authorization, while ESLint/TypeScript cannot replace responsive and
  keyboard testing.
- The deterministic local quality gate does not contact package registries.
  Run `bin/audit` in a networked CI job and retain its output as a build
  artifact.
- Cost accuracy depends on maintaining the versioned provider prices in
  `config/models.php`; cached-input discounts and embedding costs are not yet
  metered.
