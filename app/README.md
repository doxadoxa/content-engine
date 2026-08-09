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
| `127.0.0.1:5437` | PostgreSQL for host-side tools |
| `127.0.0.1:6384` | Redis for host-side tools |

All published Compose ports are loopback-only. Override their numbers with
`CE_APP_PORT`, `CE_DB_PORT`, and `CE_REDIS_PORT`. This Compose file is a local
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
- `HORIZON_ALLOWED_EMAILS` for trusted system administrators. Project
  membership alone never grants Horizon access.
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

Useful manual commands:

```sh
php artisan engine:tick --dry
php artisan engine:tick --project=<slug>
php artisan pipeline:run <pipeline> <project>
php artisan publish:approved <project>
php artisan publish:replay <delivery-uuid>
php artisan pipeline:cost <project> --days=30
```

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
- **System administrators** are a separate email allow-list used for Horizon;
  this is not a project role.

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
app/Pipelines/Core/              durable pipeline orchestration
app/Pipelines/Definitions/       pipeline graphs
app/Publishing/                  webhook/pull contracts and delivery
app/Support/Http/                outbound network and URL safety
app/Support/Tenancy/             current-project context and scope
app/Http/Controllers/            operator and pull API endpoints
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
