<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day in the life of the whole project (§6).
 *
 * §9 already connected both halves of the data — Search Console and GA4 — but
 * reads them per unit: both gateways take a list of URLs. Presence is not a
 * property of a URL. "More people are searching for us by name" and "people
 * arrive without asking Google" are facts about the project, and there was
 * nowhere in the engine to write one down.
 *
 * A stored snapshot rather than a computation, and that is the whole design
 * decision here. The trend is what carries the meaning — a single reading of
 * brand demand says nothing, and the shape over eight weeks says everything —
 * but neither vendor will hand back an arbitrary past day cheaply, and Search
 * Console restates recent history as it settles, so a figure recomputed in
 * October is not the figure October saw. Every number is nullable because a
 * gateway that was not connected on a given day must read as "not measured"
 * rather than as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // A date, not a timestamp: the unit is a day in the project's own
            // timezone, and the vendors report days rather than moments.
            $table->date('captured_on');

            // Brand demand, from Search Console sliced by query and matched on
            // the brand name. The signal §6 leads with, because people typing
            // our name is what presence means.
            $table->unsignedInteger('brand_impressions')->nullable();
            $table->unsignedInteger('brand_clicks')->nullable();
            // The matched queries themselves, kept so a rise can be read as
            // well as counted. A number that went up without saying which words
            // went up is not actionable.
            $table->jsonb('brand_queries')->nullable();

            // GA4 by channel. Direct and referral are "came without asking
            // Google"; the social pair is "are we bringing people who stay".
            // The denominator. §6 asks for the *share* of social traffic, and
            // direct + referral + social is not the whole of it — organic and
            // paid are missing — so a share computed from those three alone
            // would be wrong in a direction that flatters the channel.
            $table->unsignedInteger('total_sessions')->nullable();

            $table->unsignedInteger('direct_sessions')->nullable();
            $table->unsignedInteger('referral_sessions')->nullable();
            $table->unsignedInteger('social_sessions')->nullable();
            $table->unsignedInteger('social_engaged_sessions')->nullable();
            $table->unsignedInteger('social_engagement_seconds')->nullable();

            $table->unsignedInteger('conversions')->nullable();

            // Threads insights — the health of the channel itself.
            $table->unsignedInteger('followers')->nullable();
            $table->unsignedInteger('post_impressions')->nullable();
            $table->unsignedInteger('post_replies')->nullable();
            $table->unsignedInteger('post_likes')->nullable();
            $table->unsignedInteger('post_reposts')->nullable();
            $table->unsignedInteger('post_quotes')->nullable();

            // What we did, as opposed to what happened to us. Defaulted to zero
            // rather than nullable: unlike the vendor figures these come from
            // our own tables, so "we published none" is always knowable and is
            // exactly what the governor's floor of §4.3 alerts on.
            $table->unsignedInteger('posts_published')->default(0);
            $table->unsignedInteger('replies_sent')->default(0);

            // What the project talks about against what the site covers. §6's
            // first consequence: a subject with conversation and no page is a
            // task for the article planner, not a curiosity.
            $table->jsonb('entity_coverage')->nullable();

            $table->jsonb('raw')->default('{}');

            $table->timestamps();

            // One row a day. Re-capturing a day must correct it rather than
            // double it — the sweep is idempotent by this constraint and by
            // nothing else, and a retried job is the normal case.
            $table->unique(['project_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_states');
    }
};
