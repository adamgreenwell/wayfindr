<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Release\UpgradeGuard;
use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use App\Support\Updates\InstallationCapabilities;
use App\Support\Updates\OperatorUpdateAudit;
use App\Support\Updates\OperatorUpdateAuthorization;
use App\Support\Updates\OperatorUpdatePlanReview;
use App\Support\Updates\ReleaseMetadataException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Fixed, operator-only requests; the host independently admits every effect. */
final class OperatorUpdateController extends Controller
{
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private HostUpdaterClient $helper,
        private OperatorUpdateAuthorization $authorization,
        private OperatorUpdatePlanReview $reviews,
        private OperatorUpdateAudit $audit,
        private UpgradeGuard $guard,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        return $this->authorization->run($request, function (): JsonResponse {
            try {
                $installation = $this->installation();

                return $this->json([
                    'schema' => 1, 'installation' => $installation->toArray(),
                    'managed_execution_available' => $installation->managedBlockers() === [],
                    'reason' => $installation->managedBlockers() === [] ? null : 'installation_ineligible',
                ]);
            } catch (HostUpdaterException $exception) {
                return $this->json([
                    'schema' => 1, 'installation' => InstallationCapabilities::local($this->guard->installationProfile())->toArray(),
                    'managed_execution_available' => false, 'reason' => $exception->reason,
                ]);
            }
        }, recent: false);
    }

    public function reauthenticate(Request $request): JsonResponse
    {
        $this->keys($request, ['current_password', 'one_time_code']);
        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'one_time_code' => ['nullable', 'string', 'max:32'],
        ]);
        $this->authorization->confirm($request, $input['current_password'], $input['one_time_code'] ?? null);

        return $this->json(['schema' => 1, 'reauthenticated' => true, 'expires_at' => now()->timestamp + OperatorUpdateAuthorization::LIFETIME_SECONDS]);
    }

    /** Recheck creates a fresh preparation; previous review/history is immutable. */
    public function plan(Request $request): JsonResponse
    {
        $this->keys($request, ['release_tag', 'request_id']);
        $input = $request->validate([
            'release_tag' => ['required', 'string', 'max:128', 'regex:/\Av(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/'],
            'request_id' => ['required', 'string', 'regex:'.self::UUID],
        ]);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request, function (User $operator) use ($input): JsonResponse {
            $this->requireEligible();
            $snapshot = $this->helper->prepare($input['release_tag'], $input['request_id'], (int) $operator->id);

            return $this->snapshot($snapshot, 202);
        }));
    }

    public function review(Request $request, string $operation): JsonResponse
    {
        $this->operationId($operation);
        $this->keys($request, []);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request, function () use ($operation): JsonResponse {
            $installation = $this->requireEligible();
            $snapshot = $this->helper->status($operation);
            $review = $this->boundReview($snapshot, $installation);

            return $this->json([
                'schema' => 1, 'snapshot' => $snapshot, 'review' => $review,
                'execution_available' => true, 'audit_mirrored' => $this->audit->mirror($snapshot),
            ]);
        }, recent: false));
    }

    public function start(Request $request, string $operation): JsonResponse
    {
        return $this->mutation($request, $operation, start: true);
    }

    public function cancel(Request $request, string $operation): JsonResponse
    {
        return $this->mutation($request, $operation, start: false);
    }

    public function status(Request $request): JsonResponse
    {
        $this->keys($request, ['operation_id']);
        $input = $request->validate(['operation_id' => ['sometimes', 'required', 'string', 'regex:'.self::UUID]]);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request,
            fn (): JsonResponse => $this->snapshot($this->helper->status($input['operation_id'] ?? null)), recent: false));
    }

    public function history(Request $request): JsonResponse
    {
        $this->keys($request, ['cursor', 'limit']);
        $input = $request->validate(['cursor' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'between:1,50']]);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request,
            fn (): JsonResponse => $this->snapshot($this->helper->history((int) ($input['cursor'] ?? 0), (int) ($input['limit'] ?? 20))), recent: false));
    }

    public function events(Request $request, string $operation): JsonResponse
    {
        $this->operationId($operation);
        $this->keys($request, ['cursor', 'limit']);
        $input = $request->validate(['cursor' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request,
            fn (): JsonResponse => $this->json(['schema' => 1, 'events' => $this->helper->logs($operation, (int) ($input['cursor'] ?? 0), (int) ($input['limit'] ?? 50))]), recent: false));
    }

    private function mutation(Request $request, string $operation, bool $start): JsonResponse
    {
        $this->operationId($operation);
        $this->keys($request, ['request_id', 'plan_id']);
        $input = $request->validate([
            'request_id' => ['required', 'string', 'regex:'.self::UUID],
            'plan_id' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ]);

        return $this->attempt(fn (): JsonResponse => $this->authorization->run($request, function (User $operator) use ($input, $operation, $start): JsonResponse {
            $installation = $this->requireEligible();
            $snapshot = $this->helper->status($operation);
            $record = $snapshot['operation'];
            if (! is_string($record['plan_id'] ?? null) || ! hash_equals($record['plan_id'], $input['plan_id'])) {
                return $this->json(['schema' => 1, 'reason' => 'stale_plan'], 409);
            }
            // Repeated admissions reconcile from the durable receipt. They do
            // not rebuild a source plan after its containers were replaced.
            if ($start && ! isset($record['operator']['start'])) {
                $this->boundReview($snapshot, $installation);
            }
            $result = $start
                ? $this->helper->start($operation, $input['request_id'], $input['plan_id'], (int) $operator->id)
                : $this->helper->cancel($operation, $input['request_id'], $input['plan_id'], (int) $operator->id);

            return $this->snapshot($result, 202);
        }));
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function boundReview(array $snapshot, InstallationCapabilities $installation): array
    {
        $operation = $snapshot['operation'];
        if (($operation['checkpoint'] ?? null) !== 'plan_reported' || ($operation['error'] ?? null) !== 'execution_not_available') {
            abort($this->json(['schema' => 1, 'reason' => 'plan_not_ready'], 409));
        }
        $review = $this->reviews->build($operation['release_tag'], $installation);
        if (! is_string($review['plan_id'] ?? null) || ! is_string($operation['plan_id'] ?? null)
            || ! hash_equals($operation['plan_id'], $review['plan_id'])
            || ($review['status'] ?? null) !== 'update_available'
            || ($review['release_requirements']['migration_blocked'] ?? true) !== false) {
            abort($this->json(['schema' => 1, 'reason' => 'stale_plan'], 409));
        }

        return $review;
    }

    private function installation(): InstallationCapabilities
    {
        $profile = $this->guard->installationProfile();
        $local = InstallationCapabilities::local($profile);
        // An explicit external/hosting owner is never replaced by a helper claim.
        if ($local->ownership !== 'unknown' && $local->ownership !== 'installer-managed') {
            return $local;
        }

        return config('wayfindr.updates.helper_enabled') === true ? $this->helper->capabilities($profile) : $local;
    }

    private function requireEligible(): InstallationCapabilities
    {
        $installation = $this->installation();
        if ($installation->managedBlockers() !== []) {
            abort($this->json(['schema' => 1, 'reason' => 'installation_ineligible', 'installation' => $installation->toArray()], 503));
        }

        return $installation;
    }

    private function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (HostUpdaterException $exception) {
            $refusal = str_starts_with($exception->reason, 'helper_refused:');

            return $this->json(['schema' => 1, 'reason' => $exception->reason], $refusal ? 409 : 503);
        } catch (ReleaseMetadataException $exception) {
            return $this->json(['schema' => 1, 'reason' => $exception->reason], 503);
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshot(array $snapshot, int $status = 200): JsonResponse
    {
        return $this->json(['schema' => 1, 'snapshot' => $snapshot, 'audit_mirrored' => $this->audit->mirror($snapshot)], $status);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    /** @param list<string> $allowed */
    private function keys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), [...$allowed, '_token']) !== []) {
            throw ValidationException::withMessages(['request' => 'The update request contains unsupported fields.']);
        }
    }

    private function operationId(string $operation): void
    {
        if (preg_match(self::UUID, $operation) !== 1) {
            throw ValidationException::withMessages(['operation' => 'The update operation identifier is invalid.']);
        }
    }
}
