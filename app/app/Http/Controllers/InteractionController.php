<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InteractionSkipReason;
use App\Enums\ReplyRoute;
use App\Http\Requests\RecordHandSentReplyRequest;
use App\Http\Requests\SendInteractionReplyRequest;
use App\Http\Requests\SkipInteractionRequest;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\User;
use App\Social\Exceptions\ReplyRefused;
use App\Social\InteractionReplySender;
use App\Social\ReplyClearance;
use App\Social\ReplyGuard;
use App\Social\ReplyGuardFinding;
use App\Social\ReplyGuardVerdict;
use App\Social\ReplyRouting;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The duty screen and the four things an operator does on it (§4.2, §7).
 *
 * §7 asks for one screen and five minutes, on a phone: "подтверждение ответа за
 * минуты с телефона, а не за час с ноутбука". So the queue is one query, every
 * action is one request, and none of them needs a second page — send, record
 * one posted by hand, or skip with a reason, from the row.
 *
 * # Why this class exists at all
 *
 * It is the only caller of {@see InteractionReplySender}, and that is the
 * structural half of §4.2's permanent prohibition rather than an accident of
 * layering. The sender demands a {@see User}; the only place in this
 * application where one exists is a request behind the `auth` middleware, which
 * is this. A queue job, a console command and a scheduled task cannot produce
 * one honestly, so they cannot send — see the sender's own docblock for the
 * other three mechanisms, and `EngageTest` for the assertion that reads the
 * source tree rather than trusting any of these sentences.
 *
 * # Editing before sending, and why there is still no endpoint that saves a draft
 *
 * The operator edits in the textarea and the edited text rides on the send —
 * {@see SendInteractionReplyRequest} requires `text` rather than defaulting to
 * the stored draft, precisely so that what goes out is what the operator was
 * looking at.
 *
 * `interactions.draft_reply` means *what the engine wrote*, and a "save the
 * draft" action that wrote the operator's words into it would end that. §7
 * requires the guard's findings to stay on the row, and a finding is a verdict
 * on a specific sentence; a column that sometimes holds the engine's sentence
 * and sometimes the operator's makes every stored finding ambiguous about which
 * one it judged.
 *
 * The edit is still not lost, though — that conclusion was wrong. The default
 * §11.1 path *requires* leaving the page: edit, open the thread, post, come
 * back. The screen keeps the unsent text in `localStorage` under the
 * conversation's id, which preserves the invariant exactly — nothing reaches
 * the server, `draft_reply` still means one thing — and removes the loss. See
 * `resources/js/pages/engage/index.tsx`.
 *
 * # Acknowledgements
 *
 * §10's fact-check duty and §9's unconfirmed send are cleared by the operator
 * ticking a box, not by the server inferring consent from a diff. The ticked
 * codes ride on the send and are recorded — see {@see ReplyClearance}.
 *
 * # Refusals
 *
 * {@see ReplyRefused} already carries operator-facing wording and a status, so
 * nothing here invents a sentence. It becomes a validation error on the row: an
 * Inertia form shows it next to the button that failed, and the redirect
 * re-renders the queue, which is what an operator who has just been told
 * "somebody else got there first" needs to see. The exception's own 409/422
 * survives on the {@see ValidationException} for any client that asks for JSON.
 */
class InteractionController extends Controller
{
    /**
     * A page of the duty queue.
     *
     * Twenty-five, and the route for all of them resolved in one query by
     * {@see ReplyRouting::forMany()} — §7's screen has to open in seconds on a
     * phone, and twenty-five `exists()` calls is how that stops being true.
     */
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly CurrentProject $current,
        private readonly InteractionReplySender $sender,
        private readonly ReplyRouting $routing,
        private readonly ReplyGuard $guard,
        private readonly ReplyClearance $clearance,
    ) {}

    public function index(): Response
    {
        $project = $this->current->get();

        if ($project === null) {
            // A project still in the onboarding wizard is not "live", so
            // `EnsureCurrentProject` puts nothing on the request — which is the
            // ordinary state of a brand-new installation, not a conflict.
            // /today and /dashboard both render an empty screen in exactly this
            // condition; this one used to answer 409, so the first thing a new
            // operator saw in the sidebar was an error page. There is nothing
            // to reply to before a project exists, and an empty queue says that
            // truthfully.
            return $this->render(Interaction::query()->whereRaw('1 = 0')->paginate(self::PER_PAGE), 0);
        }

        $page = Interaction::query()
            // Oldest first, and that is the whole ordering decision: §4.2 is
            // judged on how long somebody waited, so the longest wait is the
            // top of the screen. See Interaction::scopeOpen().
            ->open()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var array<string, ReplyRoute> $routes */
        $routes = $this->routing->forMany($page->getCollection());

        // One query for the whole page, like the routes above: §9's unconfirmed
        // sends are rare and the screen still has to open in seconds.
        $unconfirmed = $this->clearance->unconfirmedSendsFor($page->getCollection());

        $page->through(fn (Interaction $conversation): array => $this->row(
            $conversation,
            $project,
            $routes[(string) $conversation->getKey()] ?? ReplyRoute::ByHand,
            isset($unconfirmed[(string) $conversation->getKey()]),
        ));

        return $this->render($page, Interaction::query()
            ->whereNotNull('answered_at')
            ->where('answered_at', '>=', now()->startOfDay())
            ->count());
    }

    /**
     * The one tap of §4.2 — with whatever the operator has in the box.
     */
    public function send(SendInteractionReplyRequest $request, Interaction $interaction): RedirectResponse
    {
        try {
            $answered = $this->sender->send(
                $interaction,
                $this->project(),
                $this->operator($request),
                $request->string('text')->value(),
                $this->acknowledged($request),
            );
        } catch (ReplyRefused $e) {
            $this->refuse($e, 'reply');
        }

        return $this->answered($answered, 'Replied to');
    }

    /**
     * §11.1's human-assisting path: the operator posted it, we write it down.
     *
     * Not a fallback and not an error state. With `reply_to_foreign` off — the
     * default, and the honest position until somebody checks it against a live
     * account — this is how every conversation on somebody else's post gets
     * answered, and §4.2's latency is measured on it exactly the same way.
     */
    public function recordSent(RecordHandSentReplyRequest $request, Interaction $interaction): RedirectResponse
    {
        $reference = trim($request->string('reference')->value());

        try {
            $answered = $this->sender->recordSentByHand(
                $interaction,
                $this->project(),
                $this->operator($request),
                $request->string('text')->value(),
                $reference === '' ? null : $reference,
                $this->acknowledged($request),
            );
        } catch (ReplyRefused $e) {
            $this->refuse($e, 'reply');
        }

        return $this->answered($answered, 'Recorded your reply to');
    }

    /**
     * Leave it alone, on the record (§7).
     *
     * The reason is required by {@see SkipInteractionRequest} and comes from a
     * closed set, because §7's rule about the engine — an unexplained silence
     * is indistinguishable from a fault — is just as true of the operator, and
     * a countable reason is the only kind the listening contour can learn from.
     */
    public function skip(SkipInteractionRequest $request, Interaction $interaction): RedirectResponse
    {
        $reason = InteractionSkipReason::from($request->string('reason')->value());

        DB::transaction(function () use ($interaction, $reason): void {
            // Locked and re-read for the same reason the sender does it: two
            // operators on one queue is the normal case on a phone, and the
            // second one has to be told rather than throw an
            // InvalidStateTransition out of a controller.
            /** @var Interaction $locked */
            $locked = Interaction::query()
                ->whereKey($interaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state->isTerminal()) {
                throw ValidationException::withMessages([
                    'reason' => 'This conversation is already closed — somebody else got there first. Reload the queue.',
                ])->status(409);
            }

            $locked->ignore($reason->value);
        });

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Skipped: '.$reason->label().'.',
        ]);

        return back();
    }

    /**
     * The screen, whether or not there is a project behind it.
     *
     * One shape for both branches on purpose: a project-less render that
     * dropped a prop would make the page's contract conditional, and a page
     * whose props depend on a state nobody tests is how a blank screen ships.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    private function render(LengthAwarePaginator $page, int $answeredToday): Response
    {
        return Inertia::render('engage/index', [
            'conversations' => $page,
            'reasons' => array_map(
                static fn (InteractionSkipReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ],
                InteractionSkipReason::cases(),
            ),
            'text_limit' => $this->guard->textLimit(),
            // §11.1 as one boolean, so the screen can explain *why* a
            // conversation is copy-and-paste rather than leaving the operator
            // to guess that the engine is broken.
            'foreign_replies_allowed' => $this->routing->foreignRepliesAllowed(),
            'answered_today' => $answeredToday,
        ]);
    }

    /**
     * One conversation, as the duty screen reads it.
     *
     * `waited_seconds` is the number §4.2 is judged on and the reason this
     * screen exists, so it is computed here rather than derived on the client
     * from a timestamp: the server's clock is the one the latency was measured
     * against everywhere else.
     *
     * The guard's findings travel whole — code, sentence, whether they block
     * and whether one tap can clear them. §7 requires the explanation beside
     * the silence, so a blocked draft is a row with a reason on it and never a
     * row that is missing.
     *
     * The findings the screen shows are the stored verdict *plus* the two that
     * do not come from the text — §10's fact-check duty on a YMYL project and
     * §9's unconfirmed previous send — because the operator has to see the same
     * things the send boundary will ask about. {@see ReplyClearance} composes
     * both, so the screen and the sender cannot drift.
     *
     * @return array<string, mixed>
     */
    private function row(Interaction $conversation, Project $project, ReplyRoute $route, bool $unconfirmed): array
    {
        $stored = ReplyGuardVerdict::fromArray($conversation->draft_guard);

        // The stored fact-check is re-issued by `contextual()` with the weight
        // it actually carries here and now, so the stored one is dropped rather
        // than printed beside it.
        $verdict = new ReplyGuardVerdict(array_values(array_filter(
            $stored->findings,
            static fn (ReplyGuardFinding $finding): bool => $finding->code !== ReplyGuard::FACTCHECK,
        )), $stored->checkedAt);

        foreach ($this->clearance->contextual($project, $stored, $unconfirmed) as $finding) {
            $verdict = $verdict->with($finding);
        }

        $draft = $conversation->draft_reply;

        return [
            'id' => (string) $conversation->getKey(),
            'author' => $conversation->author,
            'handle' => $conversation->author_handle,
            'text' => $conversation->text,
            'permalink' => $conversation->permalink,
            'received_at' => $conversation->received_at->toIso8601String(),
            // The server's clock, so the first render agrees with the toast and
            // with every latency this contour is judged on. The screen ticks it
            // forward from `received_at` afterwards — a phone left open for
            // twenty minutes must not still say 3m.
            'waited_seconds' => (int) $conversation->received_at->diffInSeconds(now()),
            'state' => $conversation->state->value,
            'state_label' => $conversation->state->label(),
            'draft' => $draft,
            'drafted_at' => $conversation->draft_generated_at?->toIso8601String(),
            'findings' => array_map(
                fn (ReplyGuardFinding $finding): array => [
                    ...$finding->toArray(),
                    // Whether §4.2's human can clear this by saying they have
                    // looked, or has to edit the words instead.
                    'acknowledgeable' => $this->clearance->acknowledgeable($finding->code),
                ],
                $verdict->findings,
            ),
            // Whether the *engine's* words may go out as they stand, with
            // nothing acknowledged. An edit or a tick changes the answer, which
            // is why the guard runs again at the send boundary over whatever
            // was actually typed.
            'sendable' => $draft !== null && trim($draft) !== ''
                && $this->clearance->refusal($verdict, []) === null,
            'route' => $route->value,
            'route_label' => $route->label(),
        ];
    }

    /**
     * What the operator ticked, as a list of codes.
     *
     * Sifted again in the sender: a controller is not the place a rule about
     * which acknowledgements exist should live.
     *
     * @return list<string>
     */
    private function acknowledged(Request $request): array
    {
        /** @var list<string> $codes */
        $codes = array_values(array_filter(
            (array) $request->input('acknowledged', []),
            static fn (mixed $code): bool => is_string($code),
        ));

        return $codes;
    }

    /** The same flash for both ways a reply reaches a thread. */
    private function answered(Interaction $conversation, string $verb): RedirectResponse
    {
        $who = $conversation->author_handle ?? $conversation->author;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$verb} {$who} after ".$this->waited($conversation).'.',
        ]);

        return back();
    }

    /**
     * How long that person waited, in the words a person would use.
     *
     * The thresholds are the ones `waited()` in `engage/index.tsx` uses, to the
     * second. They were 90s and 5400s here and 60s and 3600s there, so a
     * conversation answered after seventy seconds was "70s" in the toast and
     * "1m" on the row it had just left — two descriptions of one wait, on one
     * screen, in the number this contour is judged on.
     */
    private function waited(Interaction $conversation): string
    {
        $seconds = (int) $conversation->received_at->diffInSeconds($conversation->answered_at ?? now());

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3_600) {
            return intdiv($seconds, 60).'m';
        }

        return $seconds < 86_400
            ? intdiv($seconds, 3_600).'h'
            : intdiv($seconds, 86_400).'d';
    }

    /**
     * The refusal the domain wrote, on the row the operator pressed.
     *
     * The status the sender chose is kept on the exception — 409 when the world
     * moved, 422 when the text or the connection will not allow it — so a JSON
     * client gets the distinction. A web request gets a redirect with the
     * message in the error bag, which re-renders the queue as a side effect,
     * and that is exactly the right answer to "somebody else got there first".
     */
    private function refuse(ReplyRefused $e, string $field): never
    {
        throw ValidationException::withMessages([$field => $e->getMessage()])->status($e->status);
    }

    /** The signed-in human §4.2 requires. There is no other kind of caller. */
    private function operator(Request $request): User
    {
        $operator = $request->user();

        abort_unless($operator instanceof User, 403, 'Only a signed-in operator can answer a conversation.');

        return $operator;
    }

    private function project(): Project
    {
        $project = $this->current->get();

        abort_if($project === null, 409, 'No project is current, so there is nothing to reply as.');

        return $project;
    }
}
