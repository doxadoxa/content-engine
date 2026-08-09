# Comprehensive code, product, UI/UX, and accessibility review

**Product:** Content Engine  
**Review date:** 2026-08-08  
**Review scope:** Laravel application, React/Inertia interface, publishing and pipeline workflows, tenant isolation, onboarding, Docker/runtime configuration, automated tests, and the rendered desktop/mobile product  
**Overall recommendation:** **Ready for staging/canary validation; do not call the dependency risk closed until the external advisory scans are authorized and pass**

## Executive summary

The original review found release-blocking security, publishing, API, concurrency, and mobile problems. The five remediation phases are now complete. Generated Markdown is sanitized, browser responses carry a nonce-based restrictive CSP, all server-side fetching uses one DNS-pinned public-target policy, Horizon is system-admin-only, publication is destination-aware and idempotent, approvals enforce quality, the pull API uses a composite opaque cursor and HMAC token index, owner/operator roles are enforced, onboarding and pipeline side effects are locked, and the responsive interface has been repaired.

The final aggregate gate passes **686 tests / 2,165 assertions**, Pint across **403 files**, PHPStan, ESLint, Prettier, TypeScript, and a production Vite build. The first full remediation run deliberately was not waved through: it exposed a retry dispatch occurring while the new pipeline step mutex was held, which could strand a retryable step in `running`. Retry intent now leaves the protected side-effect window before it is dispatched, and the visibility plus retry/resume suites and the complete second gate pass.

Rendered checks covered login and the authenticated dashboard, calendar, content, channels, approvals, and feedback screens. Dashboard, calendar, content, channels, and approvals had no document-level overflow at 320, 360, or 390 px; feedback also measured `scrollWidth === clientWidth === 390`, with one `h1`, one main landmark, and the Content Engine title. The skip link and target were present, but browser focus automation was inconclusive, so an explicit click-to-focus handoff was added and the frontend gates/build were rerun.

One operational evidence gap remains. `npm audit --offline` completed from the local cache and reported zero vulnerabilities, but it cannot establish advisory freshness; Composer had no cached advisory data and its offline audit could not run. Live `composer audit` and `npm audit` were not run because the environment refused to send locked dependency metadata to public registries without explicit user authorization. This is neither evidence of a vulnerable dependency nor fresh evidence of a clean dependency set. External providers and a real receiver also still require staging validation.

## Remediation status

The detailed findings below preserve the original before-state and evidence. This table is the authoritative current disposition.

| Finding | Status | Remediation evidence |
|---|---|---|
| SEC-01 | Resolved | `SafeMarkdown`, stored-content migration, hostile HTML regression tests, nonce CSP/security headers. |
| SEC-02/03/04 | Resolved | Shared `PublicHttpTarget`/pinned request path covers webhooks, generated links, site reads, redirects, sitemaps, and corpus pages. |
| SEC-05 | Resolved | Horizon uses an explicit trusted email allow-list; project membership tests deny access. |
| AUTH-01 | Resolved | Owner middleware protects administration/integrations; operator UI is read-only where required; role/action tests pass. |
| PUB-01–06 | Resolved | Async verification state, automatic/manual destination semantics, publishability enforcement, row locks, revision dedupe, delivery mutexes, after-commit dispatch, verification invalidation, and safe public URLs. |
| API-01/02/03 | Resolved | Encrypted timestamp+ULID cursor, bounded validation, keyed-HMAC token index, and exact date parsing. |
| PIPE-01 | Resolved | PostgreSQL advisory mutex fences the handler side-effect window; retry dispatch now happens after release. |
| ONB-01/02 | Resolved | Per-step shape/length/URL validation and row-locked launch transaction with after-commit jobs. |
| OPS-01 | Resolved | Narrow proxy trust plus CSP, HSTS where appropriate, clickjacking, MIME, and referrer headers. |
| OPS-02 | Resolved for local exposure | App/PostgreSQL/Redis ports bind to `127.0.0.1`; README clearly labels the default credentials and Compose file as local-only. |
| UX-01–05 | Resolved | Content Engine identity, locale grouping, phone layouts, mobile agenda calendar, bounded pagination, and owner-aware controls. |
| A11Y-01–03 | Resolved | One meaningful `h1`, main landmark and skip link with explicit focus, accessible progress semantics, and live status. |
| PERF-01/02 | Resolved | Large queues paginate; citation counts stay in SQL; metering uses subqueries; feedback queue is independent of page; polling pauses hidden tabs and uses jittered backoff. |
| PERF-03 | Reclassified | The generated Wayfinder helper is ~4.6 KB; the 306 KB file named `wayfinder` is a Rollup shared-dependency chunk, not 306 KB of generated routes. Bundle optimization remains optional, but the original attribution was incorrect. |
| MAINT-01/03 | Resolved | `bin/test` runs every gate and aggregates failures; README now documents the actual 11-phase product, roles, runtime, publishing, pull cursor, checks, and limits. |
| MAINT-02 | Partially resolved | Extensive PHP feature coverage and manual automated browser checks now cover the corrected flows; a committed Playwright/axe suite is still recommended. |
| MAINT-04 | Operationalized; fresh result pending authorization | `bin/audit` runs both lockfile scans and aggregates failures. Offline npm cache reported zero; Composer had no offline cache. Fresh matching requires permission to disclose locked dependency metadata to Packagist/npm. |

### Additional defects found during critic rounds

- The feedback refresh queue originally came from only the current paginated page; it now has an independent, due-date-ordered bounded query and regression assertion.
- `pipeline:cost --pipeline` filtered totals but not per-unit cost; the filter now applies to every reading and has a regression test.
- A retryable pipeline failure could dispatch its synchronous retry while still holding the step mutex, causing the retry to decline the lock and disappear. `PendingStepRetry` carries dispatch outside the mutex; the complete suite proves the terminal failure path.

## Scope and method

This review combined:

- Static inspection of controllers, requests, models, middleware, queues, pipeline steps, publishing, tenancy, migrations, configuration, and React components.
- Focused review of authentication/authorization, untrusted HTML, outbound HTTP, encryption, replay/idempotency, races, pagination, query shape, and deployment defaults.
- Automated validation inside the shipped Docker environment.
- Rendered inspection of login, dashboard, approvals, calendar, channels, feedback, and content screens at desktop and representative 320/360/390 px mobile widths.
- WCAG 2.1 AA-oriented review of structure, keyboard navigation, status announcements, labels, and responsive behavior.

This was an application review and remediation cycle, not a penetration test. Fresh external package advisories remain unqueried pending explicit authorization; the limited offline result and that limitation are recorded below.

## Validation results

| Check | Result | Notes |
|---|---:|---|
| Docker services | Pass | App, Horizon, scheduler, PostgreSQL, and Redis were healthy. Compose configuration validates. |
| PHP tests | Pass | 686 tests, 2,165 assertions, approximately 21 seconds. |
| PHPStan | Pass | No errors in the app container. |
| Pint | Pass | 403 files. |
| ESLint | Pass | `npm run lint:check`. |
| TypeScript | Pass | `npm run types:check`. |
| Prettier | Pass | `npm run format:check`. |
| Production build | Pass | Vite 8 production build completed after the final accessibility change. |
| Aggregate gate | Pass | `bin/test` runs all stages, continues after a failure, and returns their aggregate status. |
| Mobile browser checks | Pass | No document overflow for the five acceptance screens at 320/360/390 px; feedback also passes at 390 px. |
| NPM/Composer vulnerability audit | Limited offline pass / fresh scan pending | `npm audit --offline --audit-level=high` reported zero from local cache. Composer had no offline advisory cache. `bin/audit` is ready, but a current public-registry result requires authorization; no fresh dependency-risk conclusion is possible. |
| Source-control diff | Not available | The delivered workspace has no `.git` metadata, so changed/untracked files could not be distinguished. |

The built file named `wayfinder` remains the largest shared JavaScript chunk at about **306.2 KB / 96.3 KB gzip**, versus the main application bundle at about **114.4 KB / 32.7 KB gzip**. Inspection corrected the original attribution: the generated helper itself is about 4.6 KB and generated route modules are split; Rollup has named a shared vendor/dependency chunk after one of its importers.

## Severity model

- **High:** plausible compromise, cross-tenant exposure, external side effects, permanent data loss, or a primary workflow that fails under the shipped configuration.
- **Medium:** significant reliability, usability, accessibility, or scaling problem with a contained workaround.
- **Low:** maintainability, documentation, defense-in-depth, or polish issue that should be scheduled.

## Detailed findings

### SEC-01 — Stored XSS through generated Markdown — High

**Evidence**

- `app/Pipelines/Steps/Generation/FinaliseDraft.php:82-84`
- `app/Pipelines/Steps/Generation/IllustrateDraft.php:189-193`
- `resources/js/pages/content/show.tsx:194-202`

Both pipeline steps call `Str::markdown($markdown)` with the CommonMark default that allows inline HTML. The content page then renders `body_html` with `dangerouslySetInnerHTML`. A direct runtime check confirmed that this input:

```html
<img src=x onerror=alert(1)>
```

is returned by `Str::markdown` unchanged. The generated text is not trusted: site content, brief text, citations, or an upstream prompt injection can influence it.

**Impact**

A malicious generated article can execute script in an authenticated operator's origin, read same-origin application data, and submit authenticated requests.

**Recommendation**

1. Render with `html_input => strip` and `allow_unsafe_links => false`.
2. Sanitize the resulting HTML with an allow-list sanitizer before storage or display.
3. Add a CSP using nonces/hashes and `object-src 'none'; base-uri 'self'; frame-ancestors 'none'` as defense in depth.
4. Add a regression test containing event handlers, `<script>`, SVG, `javascript:` links, and malformed HTML.

### SEC-02 — Saved webhook endpoints allow SSRF — High

**Evidence**

- `app/Http/Requests/ChannelRequest.php:18-39`
- `app/Publishing/WebhookPublisher.php:153-199`

The endpoint is validated only with Laravel's general `url` rule. Publishing then sends signed server-side requests to the saved value. There is no HTTPS-only policy, public-address check, port restriction, redirect validation, or DNS-rebinding defense.

**Impact**

An authenticated member can target loopback/private services, container peers, administrative endpoints, or cloud metadata. The response status and body handling can also become a limited internal-network oracle.

**Recommendation**

Create a single `PublicHttpTarget` policy used both when configuration is saved and immediately before each connection. Require HTTPS in production; reject credentials, non-approved ports, loopback, link-local, private, multicast, reserved, and metadata ranges for every A and AAAA result; pin the resolved address for the connection; and validate every redirect target. Prefer an egress proxy/firewall as the final control.

### SEC-03 — Model-generated link verification allows SSRF — High

**Evidence**

- `app/Pipelines/Steps/Generation/VerifyLinks.php:55-68`
- `app/Pipelines/Steps/Generation/VerifyLinks.php:97-111`

The verifier extracts arbitrary HTTP(S) links from generated text and issues HEAD/GET requests without a public-network check. Prompt-influenced output is therefore able to direct the application to internal targets.

**Recommendation**

Use the same outbound-target policy as webhooks, disable automatic redirects or validate each hop, cap response bytes, and consider checking only cited domains established earlier in the research flow.

### SEC-04 — Site analysis and corpus ingestion have redirect/sitemap SSRF gaps — High

**Evidence**

- `app/Onboarding/HttpSiteReader.php:28-32,64-103`
- `app/Support/Corpus/SiteLibrary.php:215-245,286-339`
- `app/Http/Controllers/OnboardingController.php:242-277`

`HttpSiteReader` checks the initial host but follows up to three redirects without rechecking destinations. The check uses a single IPv4-style resolution path and is vulnerable to multi-record/IPv6 omissions and time-of-check/time-of-use changes. `SiteLibrary` fetches a configured sitemap and then trusts up to 500 `<loc>` values, fetching up to 60 pages without enforcing same-origin or public-address rules. Onboarding can persist the sitemap value without step-specific URL validation.

**Impact**

A malicious initial page or sitemap can redirect the crawler or enumerate `<loc>` entries pointing at internal services.

**Recommendation**

Centralize outbound URL validation, validate every redirect hop, require sitemap and page URLs to stay on an explicit set of project-owned origins, resolve all A/AAAA records, cap body sizes and content types, and enforce network-level egress restrictions.

### SEC-05 — Any project member can access the global Horizon dashboard — High

**Evidence**

- `app/Providers/HorizonServiceProvider.php:16-25`
- `app/Models/User.php:24-45`

The `viewHorizon` gate grants access to any user who belongs to at least one project. Horizon is global, not tenant-scoped: job metadata, failures, tags, and payloads can include work from every project.

**Impact**

A member of one tenant can inspect operational data belonging to other tenants and can learn internal class names, identifiers, errors, and potentially payload content.

**Recommendation**

Introduce an explicit system-admin permission/allow-list, deny all normal project roles, add an authorization test, and audit historical failed-job payloads for secrets or customer content. If operators need queue status, expose a tenant-filtered purpose-built screen instead.

### AUTH-01 — Stored membership roles are not enforced — Medium (High if non-owner roles are customer-facing)

**Evidence**

- `database/migrations/2026_08_05_000200_create_projects_table.php:25-36`
- `app/Models/User.php:24-32`
- `app/Http/Middleware/HandleInertiaRequests.php:117-128`

Memberships carry `owner`/`operator`-style role data and the role is sent to the client, but no policies or role checks protect project settings, channels, approvals, rejections, connection changes, or onboarding operations. Membership alone is authorization.

**Recommendation**

Either remove role semantics and clearly document that every member is a full operator, or implement policies for project administration, content approval, publishing, integrations, and operational visibility. Enforce them server-side and test each role/action pair.

### PUB-01 — “Test channel” is broken with the shipped async queue — High

**Evidence**

- `app/Http/Controllers/ChannelController.php:88-110`
- `app/Publishing/WebhookPublisher.php:77-92`
- `docker-compose.yml:61`
- `tests/Feature/Dashboard/OperatorDayTest.php:270-299`

`ping()` dispatches `DeliverWebhookJob`, then the request immediately refreshes the still-pending delivery and reports failure. `verified_at` is only set inside that HTTP request, so a later successful worker never verifies the channel. The test passes because the test queue is synchronous; Docker uses Redis.

**Impact**

The connection wizard gives a false error for healthy endpoints and channels remain permanently “Never” verified.

**Recommendation**

Move verification into the successful ping-delivery transition (`WebhookPublisher::succeed`) and let the UI show a pending state that polls or receives an event. Add a feature test using a fake asynchronous queue followed by explicit job execution.

### PUB-02 — Channel-level auto-publish does not control destination selection — High

**Evidence**

- `app/Http/Controllers/ApprovalController.php:59-74`
- `app/Publishing/WebhookPublisher.php:46-57`
- `app/Console/Commands/PublishApprovedCommand.php:66-90`
- `routes/console.php:35-38`

The approval controller checks whether *any* enabled channel has `autopublish=true`. If one does, `WebhookPublisher::publish()` queues the item to **every** enabled webhook channel because its channel query ignores `autopublish`. The scheduled `publish:approved` command also sends every approved unit to every enabled webhook, regardless of each channel's “Auto”/“Approve” setting.

**Impact**

Content can be sent to destinations an operator intentionally left in manual mode. The UI's per-channel setting does not describe actual behavior.

**Recommendation**

Define the semantics explicitly:

- Project auto-approval decides whether a human is required.
- Channel auto-publish decides whether that channel receives an approved item automatically.
- Manual publish should take an explicit set of destination channel IDs.

Pass the destination set into the publisher and enforce it in one place. Add two-channel tests covering every combination of enabled, verified, and auto-publish flags.

### PUB-03 — Manual approval bypasses the publishability gate — High

**Evidence**

- `app/Http/Controllers/ApprovalController.php:59-74`
- `resources/js/pages/approvals/index.tsx:80-151`
- `app/Console/Commands/EngineTickCommand.php:288-323`

The automated path correctly computes `ArticleScore` and holds unpublishable drafts. The manual endpoint merely checks that the item is a draft; the Approve button is always enabled, including for failed fact checks and other critical blockers displayed in the row.

**Impact**

A click or crafted request can approve and publish content that the application's own quality model marks non-publishable.

**Recommendation**

Enforce `ArticleScore::publishable` in the controller/service. Return a 422/409 response listing blocking checks. If the business requires an override, make it a separate privileged, reasoned, audited action rather than the default Approve button.

### PUB-04 — Approval and publication are race-prone — Medium

**Evidence**

- `app/Http/Controllers/ApprovalController.php:59-72`
- `app/Models/ContentItem.php:184-201`
- `app/Publishing/WebhookPublisher.php:46-57,102-120`

State is checked and later saved without a transaction, row lock, or compare-and-set. Concurrent approval submissions can both observe a draft and queue distinct delivery IDs. Concurrent publisher jobs can also create multiple logical deliveries for the same item/channel/event.

**Recommendation**

Use a conditional state transition or `SELECT ... FOR UPDATE`, then write publication intents through a transactional outbox with a logical uniqueness key such as `(content_item_id, channel_id, event_version)`. Keep the receiver delivery ID for transport replay separately.

### PUB-05 — Editing a channel preserves stale verification — Medium

**Evidence**

- `app/Http/Controllers/ChannelController.php:67-81`

Changing the endpoint, type, or secret does not clear `verified_at`, so “Connected” continues to certify a configuration that was never tested.

**Recommendation**

Clear verification whenever connection-affecting fields change. Gate auto-publish on a successful verification of the current configuration, ideally with a configuration fingerprint stored alongside `verified_at`.

### PUB-06 — Receiver-provided public URLs are trusted — Medium

**Evidence**

- `app/Publishing/WebhookPublisher.php:255-277`
- `resources/js/pages/content/show.tsx:104-116`
- `resources/js/pages/dashboard.tsx:628-639`

A successful receiver can supply any `public_url`, which is stored and shown as an external link without an HTTP(S)/host policy.

**Recommendation**

Accept only HTTPS URLs on configured project origins (or an explicit allow-list), reject credentials and unsafe schemes, and render external links with safe `rel` attributes.

### API-01 — Timestamp-only pull cursor can permanently skip content — High

**Evidence**

- `app/Http/Controllers/Api/PullContentController.php:33-67`

Rows are ordered by `updated_at` and ID, but filtering uses only `updated_at > since` and the returned cursor contains only the last timestamp. If more than `per_page` rows share a timestamp—common after a bulk operation—unreturned rows at the boundary are excluded forever on the next request.

**Recommendation**

Return an opaque composite cursor containing `(updated_at, id)` and query:

```sql
updated_at > :time
OR (updated_at = :time AND id > :id)
```

Add a test with more than one page of rows sharing exactly the same timestamp.

### API-02 — Pull-token authentication decrypts every channel on every request — Medium

**Evidence**

- `app/Http/Middleware/AuthenticatePullApi.php:27-46`

The middleware loads all eligible pull channels, decrypts each secret, and compares until a match is found. This is O(number of channels), cannot use an index, and leaks rough token position through request duration.

**Recommendation**

Store a deterministic keyed token fingerprint (for example, HMAC-SHA-256 with a separate server key) in an indexed column and retrieve one channel by fingerprint. Retain the encrypted secret only if it must be recoverable.

### API-03 — Malformed dates become server errors — Medium

**Evidence**

- `app/Http/Controllers/Api/PullContentController.php:43-45`
- `app/Http/Controllers/CalendarController.php:25-29,101-106`

User-controlled query values are passed directly to `Carbon::parse`. Invalid input can throw and produce a 500 response.

**Recommendation**

Validate an exact documented format and return 400/422 with a useful message. For calendar months, accept only `YYYY-MM`; for cursors, decode a signed/opaque cursor rather than free-form dates.

### PIPE-01 — Pipeline attempt fencing does not fence domain side effects — High

**Evidence**

- `app/Pipelines/Core/PipelineRunner.php:252-260,336-427`
- `app/Pipelines/Steps/Generation/FinaliseDraft.php:76-92`
- `app/Pipelines/Steps/Generation/IllustrateDraft.php:176-205`

The runner's compare-and-set protects writes to the pipeline-step row, but the handler executes before ownership is rechecked. A timed-out worker can be taken over and then continue writing a `ContentItem`, creating assets, or spending provider credits. Its final step result is rejected, but its external/domain side effects have already happened.

**Recommendation**

Propagate the attempt/fencing token into every side-effect boundary. Use conditional domain writes, provider idempotency keys, and transactional outbox records tied to the current attempt. For providers without idempotency/cancellation, avoid automatic takeover until the prior operation is known to be dead or make reconciliation explicit.

### ONB-01 — Onboarding validates only the outer answer object — Medium

**Evidence**

- `app/Http/Controllers/OnboardingController.php:128-145,242-277`

Each step accepts any array with no field-level types, sizes, enum values, locale/timezone rules, or URL restrictions. Later calls such as `array_map` assume arrays and can throw if a crafted client sends a string. The complete arbitrary answer object is persisted into JSON.

**Recommendation**

Use a discriminated FormRequest or per-step validators. Cap array counts/string lengths, validate locales and timezones against supported sets, validate weekly targets with a bounded integer rule, and apply the outbound URL policy to sitemap/channel values.

### ONB-02 — Launch double-submit protection is not atomic — Medium

**Evidence**

- `app/Http/Controllers/OnboardingController.php:153-198`
- `app/Onboarding/ProjectLaunch.php:46-52`

Two concurrent requests can both observe `Draft`, write launch artifacts, and call `begin()` before either status change is visible to the other. The early status check protects sequential repeats, not races.

**Recommendation**

Move the Draft → Launching compare-and-set and creation of the first pipeline run into one transaction, lock the project row, and give the launch run a unique project/launch key.

### OPS-01 — Proxy trust is overly broad and response security headers are absent — Medium

**Evidence**

- `bootstrap/app.php:27`
- `docker/Caddyfile:1-18`

All proxies are trusted. If the app is reachable without the intended edge proxy, a client can spoof forwarded host/scheme information. Caddy adds asset caching but no CSP, frame restriction, MIME sniffing protection, referrer policy, or permissions policy.

**Recommendation**

Trust only known proxy CIDRs. Add CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, and `frame-ancestors`. Enable HSTS at the TLS-terminating edge and set secure-cookie behavior explicitly in production.

### OPS-02 — Local service ports and default credentials are exposed broadly — Medium

**Evidence**

- `docker-compose.yml:142-170`

PostgreSQL and Redis publish host ports without a loopback bind, and the development stack uses documented default credentials/no Redis authentication. This is acceptable only on a trusted isolated workstation.

**Recommendation**

Bind development ports to `127.0.0.1`, generate non-default local credentials, and make it explicit that the Compose file is not a production deployment manifest.

## UI/UX and accessibility findings

### UX-01 — Authentication presents the wrong product — High

**Evidence**

- `resources/js/layouts/auth/auth-split-layout.tsx:14-82`

The signed-out experience is branded “viddy” and promises video planning, voice, editing, captions, and social framing. The authenticated product is Content Engine for research, writing, approval, and publishing.

**Impact**

This creates an immediate trust break at login/password reset and makes the application appear to be a recycled or misrouted product.

**Recommendation**

Replace the brand, promise, icons, and metadata with Content Engine positioning. Review email templates, page titles, favicons, Open Graph metadata, and error pages for the same stale identity.

### UX-02 — Dashboard repeats locale variants as separate upcoming items — Medium

**Evidence**

- `app/Http/Controllers/DashboardController.php:269-297`
- Rendered dashboard: the same “limpeza pós-obra” and “limpeza doméstica lisboa” topics appeared three times, once for each locale.

The calendar explicitly reduces locale groups to one card per topic, while dashboard `upcoming()` does not.

**Recommendation**

Group by `locale_group_id`, choose the default-locale/root representative, and show locale badges/counts inside one row. Apply the same topic-versus-variant model consistently to dashboard, content list, approvals, and recent work.

### UX-03 — Mobile dashboard has severe horizontal overflow — High

**Evidence**

- `resources/js/pages/dashboard.tsx:207-210` and list/card content below it
- Browser measurement at 390 px viewport: `documentElement.scrollWidth = 761`, `clientWidth = 390`.

The “Coming up”/“Latest work” grid children preserve intrinsic width from long content and links. The page can be panned almost another full screen horizontally.

**Recommendation**

Add `min-w-0` at every grid/flex boundary, constrain cards and list rows with `max-w-full`, truncate/wrap long URLs and titles, and make the Google connection card stack at small widths. Add a 320/360/390 px regression test that asserts page scroll width never exceeds client width.

### UX-04 — Calendar is wider than the phone viewport — Medium

**Evidence**

- `resources/js/pages/calendar/index.tsx:139-146`
- Browser measurement at 390 px viewport: page scroll width **453 px**.

The seven-column grid has `min-w-3xl`; although horizontal scrolling is reasonable for a calendar, it is not fully contained and the page itself overflows. Controls also become cramped.

**Recommendation**

Default to a chronological agenda/list on phones, keep the month grid as an explicit alternate view, contain any horizontal scroll inside a labelled region, and stack month/filter controls. Preserve keyboard access to every day and event.

### A11Y-01 — Authenticated pages lack a primary `h1` — Medium

**Evidence**

- `resources/js/components/heading.tsx:1-24`
- Rendered dashboard, approvals, channels, and content screens exposed page titles as level-2 headings.

The reusable page-heading component always emits `<h2>`, so the main content begins without an `h1`. This weakens document structure and screen-reader navigation (WCAG 1.3.1 and 2.4.6).

**Recommendation**

Make the primary page heading an `h1`, permit an explicit level only for nested sections, and add automated heading-order checks.

### A11Y-02 — No skip-to-content mechanism — Medium

No skip link or stable main-content target was found in the application shell. Keyboard and switch users must traverse repeated navigation on every page (WCAG 2.4.1).

**Recommendation**

Add a first-focusable “Skip to main content” link, an `id` on `<main>`, and visible focus styling. Verify that opening/closing the mobile sidebar returns focus appropriately.

### A11Y-03 — Live pipeline progress has no progress/status semantics — Medium

**Evidence**

- `resources/js/pages/dashboard.tsx:145-158,311-315`

Busy dashboards poll every five seconds, but the progress bar is a visual `<div>` without `role="progressbar"`, value attributes, or a polite live summary. Screen-reader users may not learn that work began, advanced, completed, or failed.

**Recommendation**

Use the shared accessible Progress primitive or add `role="progressbar"`, `aria-valuemin/max/now`, a programmatic label, and a concise `aria-live="polite"` status. Do not announce every polling refresh.

### UX-05 — Large data screens are dense and unpaginated — Medium

**Evidence**

- `app/Http/Controllers/ContentItemController.php:30-40`
- `app/Http/Controllers/ApprovalController.php:29-36`
- `app/Http/Controllers/FeedbackController.php:23-31`
- `app/Http/Controllers/DeliveryController.php:24-42`
- Rendered content list loaded **53 table rows** in a single mobile page.

Content, approvals, and feedback load full collections. Deliveries silently stop at 100 with no way to navigate older records. This increases response size and DOM work and makes mobile scanning laborious.

**Recommendation**

Add cursor pagination, search/filtering, result counts, and URL-persisted filters. On phones, use compact cards or a deliberate horizontally scrollable table with sticky primary fields. Deliveries should expose next/previous navigation rather than an invisible hard cap.

## Performance and maintainability

### PERF-01 — Repeated broad loads will degrade with project history — Medium

Examples include:

- Approvals and content lists loading all matching units.
- Feedback summary loading published records into PHP for citation counts.
- Dashboard citation statistics reading JSON-bearing rows into PHP (`DashboardController.php:224-230`).
- Metering loading all run IDs for a period and passing them into `whereIn` (`MeteringController.php:23-62`).

Push counts/aggregations into SQL, paginate result sets, and use joins/subqueries rather than materializing ID lists. Add query-count and representative-volume benchmarks.

### PERF-02 — Five-second polling scales with every active operator — Medium

`resources/js/pages/dashboard.tsx:145-158` reloads several dashboard props every five seconds while work is active. This is acceptable for a few internal users but grows linearly and can create repetitive aggregate queries.

Pause polling in hidden tabs, add exponential backoff/jitter, stop immediately on terminal states, and consider server-sent events/WebSockets if concurrent use grows.

### PERF-03 — Generated route bundle is disproportionately large — Low

The Wayfinder asset is about 305.9 KB (96.2 KB gzip), nearly three times the uncompressed main app bundle. Review whether generated Horizon/Fortify/vendor routes and the full route registry are imported into every page. Split or tree-shake generated helpers and exclude server-only/vendor routes from client generation where supported.

### MAINT-01 — The aggregate quality command is red — Medium

The root `check.php` is an ad hoc production-data diagnostic and is not Pint-compliant, causing `bin/test`/`composer lint:check` to fail before the rest of the checks. Prettier also reports two source files.

Move the diagnostic into a named Artisan command or `scripts/` tool with explicit environment safeguards, format all checked sources, and make the CI entry point run every stage even when one fails so developers see the complete result.

### MAINT-02 — No automated React, browser, or accessibility coverage — Medium

The repository contains 63 PHP test files but no Vitest/Jest/Playwright component or end-to-end tests and no automated accessibility checks. Typechecking and linting do not catch the mobile overflow, stale auth brand, heading hierarchy, or async queue/UI mismatch.

Add focused browser coverage for:

1. Authentication identity and primary navigation.
2. Onboarding resume/validation/launch double-submit.
3. Asynchronous channel test → pending → connected.
4. Two-channel publish routing.
5. Approval blockers and authorized override.
6. 320/360/390 px overflow for dashboard/calendar/content.
7. Keyboard-only dialogs/sidebar and axe-core WCAG checks.

### MAINT-03 — README contradicts the implemented product — Low

`README.md:7` says phase 9 and all nine phases are complete, while the product folder documents phases 10 and 11. `README.md:193+` then says calendar, approvals, channels, deliveries, and image generation are “Not here yet,” although those features exist.

Update the status, remove the stale absence list, and document the actual deployment/runtime assumptions, authorization model, publishing semantics, and known limitations.

### MAINT-04 — Dependency advisories are not currently evidenced — Medium operational gap

`npm audit --offline --audit-level=high` reported zero vulnerabilities using local cached data. Composer had no cached advisory data, so `COMPOSER_DISABLE_NETWORK=1 composer audit --locked` could not complete. The committed `bin/audit` command runs both live lockfile scans and aggregates their status. Current registry matching still requires authorization to disclose locked package names and versions, so freshness remains unknown.

Run both in CI with lockfile-based scanning, Dependabot/Renovate, and a policy for high/critical advisories. Record the last successful scan in build artifacts.

## What is working well

- **Tenant isolation:** `CurrentProject` plus fail-closed global scopes and database project keys form a strong default boundary. Horizon is now restricted separately to system administrators.
- **Test breadth:** 686 passing PHP tests cover pipelines, locales, publishing, onboarding, dashboard flows, scoring, authorization, security boundaries, and failure handling.
- **Static correctness:** PHPStan, TypeScript, and ESLint pass.
- **Secret handling:** channel secrets and Google credentials are encrypted at rest and hidden from model serialization/UI props.
- **OAuth flow:** Google connection code uses state, PKCE, session consumption, and membership checks.
- **Webhook design:** signed payload snapshots, explicit delivery states, replay identifiers, retry schedules, and dead-letter visibility are good operational choices.
- **Domain modeling:** content state enums, locale groups, article scoring, pipeline graphs, run/step records, and tenant-aware models make complex behavior understandable.
- **Operator-facing failure detail:** delivery errors, fact-check findings, pipeline failures, and run status are surfaced rather than buried entirely in logs.
- **Desktop visual system:** spacing, dark theme, cards, badges, and sidebar patterns are cohesive; button labels and most form labels are clear.

## Original prioritized remediation plan

P0 and P1 are complete. P2 is complete except for a committed Playwright/axe suite and the externally authorized dependency scans.

### P0 — Before any untrusted/customer production traffic

1. Fix SEC-01 and add CSP/security headers.
2. Build and apply one SSRF-safe outbound HTTP client to SEC-02/03/04.
3. Restrict Horizon to system administrators.
4. Fix PUB-01/02/03 and add asynchronous/two-channel/publishability tests.
5. Replace the pull cursor (API-01) and publish a versioned cursor contract.
6. Repair dashboard mobile overflow and replace the authentication brand.

### P1 — Next reliability and access-control iteration

1. Make approval, launch, and publication idempotent/concurrency-safe.
2. Fence pipeline side effects, not only step-status writes.
3. Decide and enforce the role/permission model.
4. Clear verification on channel changes and validate returned public URLs.
5. Add per-step onboarding validation and safe date validation.
6. Fix calendar mobile layout, heading hierarchy, skip navigation, and live progress semantics.

### P2 — Scale and engineering quality

1. Paginate large screens and move aggregations to SQL.
2. Reduce polling and generated-route bundle weight.
3. Add Playwright/component/axe coverage at representative mobile widths.
4. Make the aggregate check command green, add dependency auditing, and refresh documentation.

## Acceptance criteria and current result

- Generated content containing raw scriptable HTML is rendered inert in both stored and preview views.
- Every outbound request rejects loopback/private/link-local/metadata IPv4 and IPv6 addresses, including after redirects and DNS changes.
- A Redis-queued channel ping transitions UI state from Pending to Connected only after the worker succeeds.
- With channels A(auto) and B(manual), automatic publication sends only to A; an explicit manual action can target B.
- An unpublishable item cannot pass the normal approval endpoint; overrides require a privileged reason and audit record.
- More than one page of identical-timestamp pull records is returned without omission or duplication.
- A member of project A cannot view Horizon or any project B operational data.
- Dashboard, calendar, content, channels, and approvals have no document-level horizontal overflow at 320, 360, and 390 px.
- Every authenticated screen has one meaningful `h1`, a working skip link, visible keyboard focus, and accessible dynamic status.
- The single documented local gate passes formatting, static analysis, tests, and the front-end build. Browser smoke checks pass as recorded above; the dependency-audit gate is documented and committed, with only a cached npm result until live registry access is authorized.

## Review limitations

- Package-registry disclosure was not authorized. npm's offline cache reported zero vulnerabilities, but freshness was not verified; Composer had no offline advisory cache.
- No `.git` metadata was supplied, so the review covers the workspace as delivered rather than a specific diff/commit.
- External providers and real webhook receivers were not exercised; behavior was assessed from code, tests, and local fake/dev data.
- Accessibility review combined static and browser inspection but did not include assistive-technology user testing.
