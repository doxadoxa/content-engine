<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\PurchaseRecord;
use App\Models\PurchaseSource;
use App\Models\User;
use App\Purchases\PurchaseIntake;
use App\Purchases\PurchasePayload;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function index(Request $request, PurchaseReport $report): Response
    {
        $sources = PurchaseSource::query()->orderByDesc('is_primary')->orderBy('name')->get();
        $primary = $sources->firstWhere('is_primary', true);
        $to = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $user = $request->user();
        $project = $this->current->get();
        $canManage = $user instanceof User && $project !== null
            && $user->projects()->whereKey($project->id)->first()?->pivot->getAttribute('role') === 'owner';

        return Inertia::render('purchases/index', [
            'sources' => $sources->map(static fn (PurchaseSource $source): array => [
                'id' => $source->id, 'name' => $source->name, 'kind' => $source->kind,
                'is_primary' => $source->is_primary, 'is_enabled' => $source->is_enabled,
                'tracking_started_at' => $source->tracking_started_at?->toIso8601String(),
                'first_received_at' => $source->first_received_at?->toIso8601String(),
                'last_received_at' => $source->last_received_at?->toIso8601String(),
                'verified_at' => $source->verified_at?->toIso8601String(),
                'verification_note' => $source->verification_note,
                'endpoint' => $source->kind === 'webhook' ? route('purchases.receive', ['source' => $source->id]) : null,
            ]),
            'summary' => $report->summarize($primary, $to->subDays(28), $to),
            'records' => PurchaseRecord::query()->orderByDesc('occurred_at')->paginate(25)->through(static fn (PurchaseRecord $record): array => [
                'id' => $record->id, 'source_id' => $record->purchase_source_id, 'transaction_id' => $record->transaction_id,
                'revision' => $record->revision, 'status' => $record->status, 'amount_minor' => $record->amount_minor,
                'refunded_minor' => $record->refunded_minor, 'currency' => $record->currency,
                'purchased_at' => $record->purchased_at?->toIso8601String(), 'occurred_at' => $record->occurred_at->toIso8601String(),
                'attribution_status' => $record->attribution_status, 'landing_url' => $record->landing_url,
                'is_new_customer' => $record->is_new_customer, 'evidence' => $record->evidence, 'items' => $record->items,
            ]),
            'canManage' => $canManage,
        ]);
    }

    public function source(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'kind' => ['required', 'in:webhook,manual']]);
        $secret = $data['kind'] === 'webhook' ? bin2hex(random_bytes(32)) : null;
        $source = DB::transaction(function () use ($data, $secret): PurchaseSource {
            Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            abort_if(PurchaseSource::query()->count() >= 5, 422, 'Use or pause an existing source; at most five sources are supported.');

            return PurchaseSource::query()->create([
                ...$data, 'secret' => $secret, 'is_enabled' => true,
                'is_primary' => ! PurchaseSource::query()->where('is_primary', true)->exists(),
            ]);
        });
        if ($secret !== null) {
            Inertia::flash('purchase_credential', ['source_id' => $source->id, 'secret' => $secret]);
        }

        return to_route('purchases.index');
    }

    public function updateSource(Request $request, PurchaseSource $source): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:primary,pause,resume,rotate,verify'], 'transaction_id' => ['required_if:action,verify', 'string', 'max:200'], 'note' => ['required_if:action,verify', 'string', 'max:1000']]);
        $secret = DB::transaction(function () use ($data, $source, $request): ?string {
            Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            $source = PurchaseSource::query()->lockForUpdate()->findOrFail($source->id);
            switch ($data['action']) {
                case 'primary':
                    PurchaseSource::query()->where('is_primary', true)->update(['is_primary' => false]);
                    $source->update(['is_primary' => true]);
                    break;
                case 'pause':
                case 'resume':
                    $source->update(['is_enabled' => $data['action'] === 'resume']);
                    break;
                case 'rotate':
                    abort_unless($source->kind === 'webhook', 422);
                    $secret = bin2hex(random_bytes(32));
                    $source->update(['secret' => $secret, 'verified_at' => null, 'verification_note' => null]);

                    return $secret;
                case 'verify':
                    abort_unless($source->is_enabled && $source->first_received_at !== null, 422, 'Receive a sale before verifying this source.');
                    $record = PurchaseRecord::query()->where('purchase_source_id', $source->id)->where('transaction_id', $data['transaction_id'])->whereIn('status', ['paid', 'refunded'])->first();
                    abort_unless($record !== null, 422, 'Verify a received completed sale using its transaction ID.');
                    $source->update(['verified_at' => now(), 'verification_note' => 'Owner '.$request->user()?->getAuthIdentifier().' compared '.$record->transaction_id.' revision '.$record->revision.': '.$data['note']]);
                    break;
            }

            return null;
        });
        if ($secret !== null) {
            Inertia::flash('purchase_credential', ['source_id' => $source->id, 'secret' => $secret]);
        }

        return to_route('purchases.index');
    }

    public function record(Request $request, PurchaseIntake $intake): RedirectResponse
    {
        $sourceId = (string) $request->validate(['source_id' => ['required', 'string']])['source_id'];
        $source = PurchaseSource::query()->where('kind', 'manual')->findOrFail($sourceId);
        $data = $request->except(['source_id', '_token']);
        $data['schema_v'] = 1;
        $data['event_id'] ??= (string) Str::uuid();
        $data['occurred_at'] ??= now()->toIso8601String();
        $data['landing_url'] = null;
        $data['attribution_status'] = 'unattributed';
        $intake->receive($source, PurchasePayload::from($data), (int) $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The sale was reconciled. Revisions replace its state; they do not add another purchase.']);

        return to_route('purchases.index');
    }
}
