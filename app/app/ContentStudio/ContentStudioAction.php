<?php

declare(strict_types=1);

namespace App\ContentStudio;

enum ContentStudioAction: string
{
    case Proposal = 'proposal';
    case Refine = 'refine';
    /**
     * Work out which ideas the next batch holds and start a run for each.
     *
     * Fans out rather than drafting. It is the one operation here that costs
     * nothing and finishes in a moment, and it stays a pipeline action rather
     * than becoming a controller method because both callers — the Studio
     * button and onboarding — need the same answer about which ideas are next,
     * and a run is what the screen already knows how to watch.
     */
    case Generate = 'generate_week';

    /**
     * Draw a draft's picture again, optionally after being told what is wrong.
     *
     * A pipeline action like the other three rather than a synchronous call,
     * for the reason the other three are: it spends money on a provider that
     * takes tens of seconds per image, and an operator asking for three
     * variants must not be holding an HTTP request open while they are drawn.
     * It also puts the spend on a `pipeline_steps` row, which is what keeps
     * §6's per-unit cost a sum of rows rather than an estimate.
     */
    case ReviseImage = 'revise_image';

    /**
     * Draft every channel of one idea.
     *
     * The unit a drafting run is now measured in. `Generate` above used to do a
     * whole week in one job, which meant its duration grew with the plan and
     * the ceiling moved with it: four ideas measured at 499 seconds against a
     * 900-second deadline, and a seven-idea week extrapolated past it. One idea
     * is about 125 seconds whatever the week holds, so the deadline stops being
     * a function of the calendar.
     *
     * It also isolates failure. A provider that refuses on the third idea used
     * to fail the batch; now it fails that idea and the other six stand.
     */
    case GenerateIdea = 'generate_idea';
}
