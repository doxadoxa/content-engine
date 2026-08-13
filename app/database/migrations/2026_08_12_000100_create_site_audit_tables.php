<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the project's own site looks like to a crawler.
 *
 * Three tables rather than one, because the section asks three questions at
 * three different grains and a single row could only answer the first: how
 * healthy is the site, which pages are the bad ones, and what exactly is wrong
 * with each.
 *
 * **Deliberately not `site_pages`.** That table is the planner's memory of what
 * the site has already covered — one row per URL, overwritten on every refresh,
 * no history, and `is_article` is the only judgement it carries. An audit is a
 * measurement taken at an instant, and the whole value of the second one is
 * being comparable to the first. Folding them together would mean either losing
 * the history or giving the planner a table that grows a copy of the site every
 * week.
 *
 * `facts` on a page row holds the *extracted* snapshot — title, canonical,
 * headings, JSON-LD types, images, links, byte count — and never the HTML. The
 * page is fetched once, and every check afterwards reads structured data with
 * no further network. That is what makes `inspect_pages` cheap enough to be a
 * separate step from `crawl_pages`, and what keeps a hundred pages of somebody
 * else's markup out of our database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // The run that produced it. Nullable because the row is created by
            // the first step and outlives the run's retention, and because an
            // audit written by a future maintenance command would have none.
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained()->nullOnDelete();

            $table->timestampTz('started_at')->nullable();

            // Null until `score_audit` finishes. This is what "a sweep is in
            // flight" means to the screen, and it is a fact rather than a
            // second copy of the run's status.
            $table->timestampTz('finished_at')->nullable();

            // Every score is nullable, and that is load-bearing rather than
            // permissive. Null means "not measured" and 0 means "measured, and
            // it is nothing" — opposite facts that look identical if the column
            // defaults to zero. `speed_score` is null on every installation
            // with no PageSpeed key, which is most of them, and reporting those
            // sites as scoring zero for performance would be a lie the whole
            // headline inherits.
            $table->unsignedSmallInteger('health_score')->nullable();
            $table->unsignedSmallInteger('seo_score')->nullable();
            $table->unsignedSmallInteger('geo_score')->nullable();
            $table->unsignedSmallInteger('speed_score')->nullable();

            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('issues_found')->default(0);

            // The site-level checks as the reader found them: robots.txt,
            // sitemap.xml, llms.txt, TLS. Kept whole rather than normalised
            // into the issues table, because the screen shows all four whether
            // or not they are problems — "Ok" is a result worth rendering.
            $table->jsonb('site_checks')->default('{}');

            // The model's answer to "what should I fix first", written on
            // request rather than on every sweep. Null is the ordinary state.
            $table->text('fix_plan')->nullable();
            $table->timestampTz('fix_plan_written_at')->nullable();

            $table->timestampsTz();

            // "The newest audit for this project", which is every read the
            // screen and the dashboard make.
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('site_audit_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_audit_id')->constrained()->cascadeOnDelete();

            $table->text('url');

            // Nullable: a page that could not be reached at all has no status,
            // and that is itself the finding.
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('issues_count')->default(0);

            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('html_bytes')->nullable();

            $table->jsonb('facts')->default('{}');

            // The PageSpeed reading, for the handful of pages a sweep can
            // afford to send. Null on the rest, and on every page when no key
            // is configured.
            $table->jsonb('speed')->nullable();

            $table->timestampsTz();

            // One row per URL per sweep, so a re-run of a step cannot double
            // the table and `updateOrCreate` has something to match on.
            $table->unique(['site_audit_id', 'url']);
            $table->index(['site_audit_id', 'issues_count']);
        });

        Schema::create('site_audit_issues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_audit_id')->constrained()->cascadeOnDelete();

            // Null means the finding is about the site rather than about a
            // page — a missing robots.txt belongs to nobody's URL.
            $table->foreignUlid('site_audit_page_id')->nullable()->constrained()->cascadeOnDelete();

            // The check's own key, not a class name: this is a database value
            // and it has to survive the class being renamed.
            $table->string('check_key');
            $table->string('severity');
            $table->text('summary');
            $table->jsonb('detail')->default('{}');

            $table->timestampsTz();

            $table->index(['site_audit_id', 'severity']);
            $table->index(['site_audit_id', 'check_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audit_issues');
        Schema::dropIfExists('site_audit_pages');
        Schema::dropIfExists('site_audits');
    }
};
