<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has an account, and what they can reach.
 *
 * Read-only, deliberately, in this first version. Everything an administrator
 * needs to *do* is done to a project — a plan, a trial, a pause — and the one
 * user-shaped action that would be useful, signing in as somebody to reproduce
 * what they are seeing, is the only feature here that can act as a customer.
 * It should arrive with its own audit trail and its own argument rather than
 * as a line item in a billing change.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): Response
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
}
