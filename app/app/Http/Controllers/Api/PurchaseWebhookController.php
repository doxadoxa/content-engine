<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseSource;
use App\Purchases\PurchaseIntake;
use App\Purchases\PurchasePayload;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

final class PurchaseWebhookController extends Controller
{
    public function __invoke(Request $request, string $source, CurrentProject $current, PurchaseIntake $intake): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        // Deliberately no tenant route-model binding: possession of this source's secret chooses the tenant.
        $connection = PurchaseSource::acrossProjects()->where('kind', 'webhook')->where('is_enabled', true)->find($source);
        $timestamp = $request->header('X-Avyo-Timestamp', '');
        $signature = $request->header('X-Avyo-Signature', '');
        abort_unless($connection !== null && $connection->secret !== null
            && preg_match('/^\d{10}$/D', $timestamp) === 1
            && abs(now()->getTimestamp() - (int) $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $connection->secret), $signature), 401, 'Invalid purchase signature.');
        abort_unless($request->isJson(), 415, 'Send application/json.');
        try {
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => 'The body must contain a JSON object.']);
        }
        if (! is_array($input) || array_is_list($input)) {
            throw ValidationException::withMessages(['payload' => 'The body must contain a JSON object.']);
        }
        $payload = PurchasePayload::from($input);

        return $current->run($connection->project_id, fn (): JsonResponse => response()->json($intake->receive($connection, $payload)));
    }
}
