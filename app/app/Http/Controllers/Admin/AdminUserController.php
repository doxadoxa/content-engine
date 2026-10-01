<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Affiliates\Anderro;
use App\Affiliates\Exceptions\AffiliateEventRejected;
use App\Affiliates\Referrals;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has an account, and what they can reach.
 *
 * Nothing here changes an account. Everything an administrator needs to *do*
 * is done to a project — a plan, a trial, a pause — and the one user-shaped
 * action that would be useful, signing in as somebody to reproduce what they
 * are seeing, is the only feature here that can act as a customer. It should
 * arrive with its own audit trail and its own argument rather than as a line
 * item in a billing change.
 *
 * The one thing that does leave this screen is a test signup sent to Anderro,
 * which touches nothing of ours — see {@see self::sendAnderroSignup()}.
 */
class AdminUserController extends Controller
{
    public function index(Request $request, Anderro $anderro): Response
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"),
            ))
            ->with(['projects' => fn ($query) => $query->select('projects.id', 'projects.name', 'projects.slug')])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/users', [
            'q' => $search,
            'anderro_configured' => $anderro->isConfigured(),
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'projects' => $user->projects->map(fn (Project $project): array => [
                    'id' => $project->getKey(),
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'role' => $project->getAttribute('pivot')?->getAttribute('role'),
                ])->all(),
            ]),
        ]);
    }

    /**
     * Send Anderro a signup for this account, now, to see whether it arrives.
     *
     * For checking the integration end to end — keys, network, and what their
     * dashboard shows — without making a new account through a partner link
     * every time. So it deliberately skips what {@see Referrals::signedUp()}
     * insists on: it does not ask whether a partner referred the account or
     * whether marketing consent stands, and it is sent straight away rather
     * than queued, because the administrator pressing the button is waiting for
     * the answer. It records no referral either, so no payment is ever
     * reported on the strength of a test.
     *
     * The visitor id is the one typed in — copied from the `_anderro_vid`
     * cookie of a browser that clicked a partner link, to test the crediting
     * as well — or else the one recorded for this account, or else a new one,
     * which Anderro will accept and credit to nobody.
     */
    public function sendAnderroSignup(Request $request, User $user, Anderro $anderro): RedirectResponse
    {
        $validated = $request->validate([
            'visitor_id' => ['nullable', 'string', 'regex:'.Referrals::VISITOR_PATTERN],
        ], [
            'visitor_id.regex' => 'A visitor id is 8 to 64 letters, digits, dashes or underscores.',
        ]);

        if (! $anderro->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Anderro is not configured here: both keys are needed.']);

            return back();
        }

        $visitor = $validated['visitor_id']
            ?? $user->affiliateReferral->visitor_id
            ?? bin2hex(random_bytes(16));

        try {
            $anderro->signup($user->email, $visitor);
            $outcome = 'accepted';
            $toast = ['type' => 'success', 'message' => "Anderro accepted a signup for {$user->email} (visitor {$visitor})."];
        } catch (AffiliateEventRejected $e) {
            $outcome = 'rejected: '.$e->getMessage();
            $toast = ['type' => 'error', 'message' => $e->getMessage()];
        } catch (ConnectionException) {
            // No answer is not the same as no event: a timeout is raised the
            // same way whether the request never left or Anderro took it and
            // was slow to say so. So say we do not know, and name the visitor,
            // because sending another with a freshly made up one would credit
            // a second visitor for the same address.
            $outcome = 'unknown: no answer from Anderro';
            $toast = ['type' => 'warning', 'message' => "Anderro did not answer, so whether the signup for {$user->email} arrived is unknown. Check Anderro before sending another, and reuse visitor {$visitor} if you do."];
        }

        $actor = $request->user();

        // Not a change to anything of ours, but an address handed to a third
        // party is exactly what somebody will later ask "who did that" about.
        AdminAction::record(
            $actor instanceof User ? $actor : null,
            'affiliate.test_signup',
            null,
            [],
            ['user_id' => $user->getKey(), 'email' => $user->email, 'visitor_id' => $visitor, 'outcome' => $outcome],
        );

        Inertia::flash('toast', $toast);

        return back();
    }
}
