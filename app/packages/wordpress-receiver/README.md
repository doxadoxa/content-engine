# Avyo WordPress receiver

An installable WordPress plugin for scheduled new articles and revision-checked changes to **existing public core pages and posts**. This package is the receiver and a real local integration fixture; Avyo's proposal/approval workflow, destination adapter and public-result verifier are separate requirements. Installing the plugin does not schedule or publish anything.

## Scheduled article publishing (plugin 0.2.0)

In Avyo, connect WordPress using the API base (normally `https://site.example/wp-json/avyo/v1`), a publishing account username, and its Application Password. Test the connection, then enable automatic publishing or choose review first on an article's calendar schedule. Existing-page object binding is unnecessary for new articles. Updating an already installed plugin requires deactivation/reactivation so its new receipt tables are installed.

`POST avyo/v1/articles` accepts the Avyo `WebhookPayload` contract version 1 over authenticated HTTPS. A `ping` returns `capabilities.article_publish: true` only when article receipt storage is installed. A `content.published` or `content.updated` envelope carries a UUID `delivery_id`, a project slug and `content` with a stable ULID identity, locale, slug, title, summary, Markdown and HTML. The receiver creates one public core post per content identity. It retains the website's original permalink when later article revisions arrive. WordPress remains responsible for the page template and author display.

An identical delivery returns its original receipt without another write; new delivery IDs for unchanged content reuse the same post. The receipt includes `contract`, `delivery_id`, `content_id`, `content_hash`, `object_id`, `public_url` and `status: published`. `content_hash` is SHA-256 over the JSON `content` object, excluding `published_at`, using unescaped slashes/Unicode and preserved key order. Changed requests reusing an ID, changed site content and ownership conflicts are refused. Retry unknown outcomes with the same immutable request and delivery ID. Avyo checks the receipt identity/hash and the returned site's origin before recording publication, then enrolls that URL for measurement without fabricating a public observation or search result.

Plain WordPress supports its configured site language. A mismatched language requires a compatible multilingual integration and is refused here. The plugin accepts WordPress-safe HTML and never fetches remote media; inline images already present in HTML remain links to their existing storage. It does not install custom JSON-LD, featured images or SEO-plugin metadata. The summary is stored as the excerpt and, only where Avyo owns descriptions, as its meta description. Another SEO plugin retains ownership of its metadata. Core post filters that alter the requested title, body or excerpt cause transaction rollback. Custom plugin side effects outside the transactional database are outside this support boundary.

The plugin retains `{wp_prefix}avyo_articles` identities and `{wp_prefix}avyo_article_receipts` immutable delivery receipts. Installing or activating it alone does not publish content. The Avyo scheduler still requires a due schedule, appropriate approval, a verified connection and an active publishing entitlement. New automatic schedules require a passing fact check; review-first schedules require the manager's approval.

## Supported boundary

Read raw `post_title` and `post_content`, an Avyo-owned description, their immutable source hash, the latest native revision ID, public URL and field capabilities. The post title is not the rendered HTML document title: a theme or SEO plugin may add a template/suffix.

Supported writes are exact title replacements, explicitly owned descriptions and bounded changes inside core content. Body changes preserve HTML tags, attributes, Gutenberg comments, layouts, forms, URLs, shortcodes and unrelated text. A complete plain core paragraph may be inserted after another top-level plain core paragraph. A plain text anchor may become an explicit HTTPS link inside supported text, outside an existing link or form. The adapter must also verify that an internal-link destination is the reviewed tracked page; the receiver cannot know Avyo's tenant/page identities.

Classic paragraph/heading/list text and the documented core blocks are supported. Known builder metadata, custom/dynamic blocks, ambiguous repeated fragments, non-public/password-protected objects, and conflicting page/post permalinks receive an assisted-handoff response. There is no generic page-builder editor, arbitrary existing-page HTML replacement, media upload, slug/status/taxonomy update or SEO-plugin metadata writer.

Description ownership is off by default. A site administrator may assign it under **Settings → Avyo receiver**, after checking the theme and other plugins. The receiver then owns only `_avyo_meta_description` and emits its escaped value in `wp_head`. Known active SEO plugins or their existing description metadata disable that field; their values are never edited. Real Yoast activation is exercised in the fixture. Other themes/plugins may render metadata that cannot be detected from known keys: public verification must require exactly one description with the approved value. Unknown ownership stays assisted.

WordPress filters that transform an approved field or change another protected field cause the database write to roll back. The revision includes content, object identity/status, metadata, taxonomy relationships and resolved public URL. Protected non-editable fields/metadata are checked after writing as well. The posts, metadata, taxonomy-relationship and operation tables must use InnoDB. Core WordPress writes share the post row lock; metadata and relationship rows are locked during the transaction. Arbitrary plugin hooks that send external requests or write outside these transactional tables cannot be undone by a database rollback and are outside this tested support boundary.

## Install and connect

1. Package the `plugin` directory as `avyo-receiver/` using `./package.sh`, then install that ZIP in the target WordPress site and activate it. No production site has been installed by this work.
2. Use a dedicated WordPress account with `edit_post` access to the intended objects and the corresponding publish capability. The fixture uses an Editor. Create a revocable WordPress Application Password for that account; store it in Avyo's encrypted connection configuration, never in a URL or committed file.
3. Connect using HTTPS and WordPress's standard HTTP Basic Application Password authentication. Normal browser cookie authentication still requires WordPress's REST nonce. HTTP is accepted only when WordPress explicitly declares `local` or `development`; the fixture is bound to loopback. Production must use HTTPS.
4. Bind the exact core object ID to the tracked page, read its source/capabilities and inspect the live public URL before preparing a proposal. A source hash from public HTML is not interchangeable with this CMS revision.
5. Assign description ownership only if appropriate. Retain the assisted path for unsupported fields/layouts or disconnected credentials.

The plugin creates `{wp_prefix}avyo_operations`; uninstalling/deactivating it does not delete the retained before/after evidence. No user credentials are stored in that journal. Approved page content is stored there and is accessible only to an authenticated account that can edit and publish the corresponding object.

## Wire contract, schema version 1

All paths below are relative to `/wp-json/`. Standard WordPress JSON errors contain `code`, `message`, and `data.status`. The existing-object endpoints below require authenticated object permission and never create objects. New articles use the separate article contract above.

`GET avyo/v1/objects/{id}` returns:

```json
{
    "schema_v": 1,
    "object_id": "123",
    "object_type": "page",
    "public_url": "https://site.example/service/",
    "revision": "64-character SHA-256 source hash",
    "core_revision_id": "456",
    "fields": {
        "title": "Raw post title",
        "description": "",
        "body_html": "raw post_content",
        "body_text": "read-only extracted text"
    },
    "editable_fields": ["title", "description", "body_html"],
    "metadata": {
        "description_owner": "avyo",
        "description_present": false,
        "preservation_hash": "hash",
        "unsupported_reason": null,
        "body_unsupported_reason": null,
        "title_semantics": "Raw post title; the public document title may use a theme template or suffix."
    },
    "capabilities": {
        "patch_operations": ["replace", "insert_after", "link"],
        "idempotency": true,
        "reconciliation": true,
        "recovery": true
    },
    "verification": {
        "public_fetch_required": true,
        "api_success_is_verification": false,
        "title": "public heading guidance",
        "description": "public metadata guidance",
        "body_html": "public text/link/form guidance"
    }
}
```

`POST avyo/v1/objects/{id}/operations` applies an approved patch only when the current locked source exactly matches `expected_revision`. The approval decision belongs to Avyo; it must never dispatch merely because content was generated. Freeze this exact request for retries; reordering or altering the payload may count as a different request. UUIDs identify deliveries, not page versions.

```json
{
    "operation_id": "063ca611-bcee-47ee-86fa-91558c9368f1",
    "expected_revision": "64-character source hash from the reviewed CMS snapshot",
    "patches": [
        {
            "field": "title",
            "operation": "replace",
            "before": "Old raw post title",
            "after": "Reviewed title"
        },
        {
            "field": "body_html",
            "operation": "replace",
            "before": "one unique exact text fragment",
            "after": "the approved replacement"
        }
    ]
}
```

Each patch has exactly `field`, `operation`, `before`, `after`, all strings. One to twenty patches, at most 1 MiB JSON/source. `title` and `description` require whole-field plain-text `replace`; body replacements require a single exact fragment and preserve all markup. `insert_after` accepts only complete plain `<!-- wp:paragraph -->…<!-- /wp:paragraph -->` blocks. For `link`, `before` is unique plain anchor text and `after` is an explicit HTTPS URL. The adapter translates its reviewed deterministic locator to a unique source fragment; ambiguity requires a new review or assistance.

Success returns `status: applied`, `kind: publish`, immutable `before` and `after` source snapshots, `changed_fields`, `operation_id`, `object_id`, `request_hash`, `committed_at`, and `public_verification: required`. Native WordPress revisions are retained; the receiver journal also captures description changes, which core content revisions alone do not cover. No metering or new content is created by retries.

`GET avyo/v1/operations/{uuid}` returns that same committed result. A repeated identical POST returns it without writing again, even if an external edit happened since. Therefore the returned `after` snapshot is historical evidence, not a fresh current read. After a timeout, read the operation first. A found operation is reconciled; a 404 means no committed operation was observed at that moment. Retry only the identical original request/UUID so an in-flight request cannot cause a second change. A changed payload with the same UUID returns 409. Do not mint a new UUID to bypass an unknown outcome.

Recovery is another explicit action:

```json
{
    "operation_id": "fd9de169-e0ee-4a8d-b41e-78841303c54a",
    "expected_revision": "current hash equal to the original operation after.revision",
    "restores_operation_id": "063ca611-bcee-47ee-86fa-91558c9368f1"
}
```

Recovery accepts no patches, restores only fields changed by that publication (including whether the Avyo description previously existed), and requires the current result to remain exactly the original `after.revision`. A subsequent external edit or capability/ownership change prevents restoration. Recovery itself is journaled and idempotent. It also needs public verification.

Relevant failures: `403 avyo_forbidden`/`avyo_https_required`; `409 avyo_revision_conflict`, `avyo_recovery_conflict`, `avyo_operation_conflict`, `avyo_source_transformed`, `avyo_unrelated_source_changed`; `422` for unsupported objects/layouts/fields, ambiguous/unsafe fragments or invalid payloads; `503 avyo_outcome_unknown` requires reconciliation.

## Public verification is separate

A 200 response proves the transaction was applied, not that visitors see the change. Avyo must fetch the public URL, check the approved visible heading/text/link and applicable description, and confirm preserved forms/layout. A changed template, cache, credential expiry or inaccessible public URL must leave verification unavailable/pending. Recovery uses the same public checks. This receiver does not bypass caches, promise indexing, measure outcomes, or make an approval decision.

## Representative local installation and checks

From this package directory:

```sh
./fixture/start.sh
python3 tests/integration.py
python3 tests/articles.py ArticleIntegration
```

The isolated Compose project is `avyo-wordpress-fixture`, served at `http://localhost:8093`. It uses official WordPress **7.1 / PHP 8.3**, MariaDB **11.4**, the bundled **Twenty Twenty-Five** theme, and **Yoast SEO 28.5** for the ownership test. The seeder creates real core service pages/posts, native form HTML, classic and Gutenberg text, repeated fragments, builder metadata and a dynamic block. The suite also creates new synthetic objects per run and retains them; it never deletes volumes or touches Avyo's database or CleaningPoint. Outbound WordPress mail and automatic cron/updates are disabled in a fixture-only MU plugin, which is not included in the receiver ZIP.

The local administrator is `fixture-admin` / `local-fixture-admin-only`. These published fixture credentials are deliberately disposable and must never be used elsewhere. Test Application Passwords are generated inside the fixture and read only by its test runner. Rerunning the seeder creates new fixture identities/passwords, preserving prior evidence. Stop without deleting data using `docker compose -f fixture/compose.yaml stop`.

The suite exercises authenticated source reads, revoked/insufficient credentials, core field publication and public HTML verification, native revisions, retry identity, deliberately discarded responses, concurrent requests, external edits and metadata, recovery and its conflicts, unknown layouts, explicit and real SEO-plugin ownership, protected forms/attributes/markup, insertion/internal links, filter transformations, malformed payloads and permalink collisions. Its runtime results are recorded separately from the main Avyo adapter/workflow tests. This package alone does not complete F3/A11 or certify production compatibility.

References: WordPress [Application Password authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [custom endpoint permissions](https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/), [post revisions](https://developer.wordpress.org/rest-api/reference/post-revisions/) and [release archive](https://wordpress.org/download/releases/).
