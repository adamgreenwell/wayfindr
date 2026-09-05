<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Return the current interruption gate before an open dashboard plays sound. */
final class AgentAlertSoundGateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $request->user();

        abort_unless(
            $agent instanceof User
            && $agent->account_id !== null
            && ! $agent->isDeactivated()
            && $agent->hasAccountPermission(AccountPermission::ViewAlerts),
            403,
        );

        return response()->json([
            'data' => [
                'interruptions_paused' => $agent->alertInterruptionsPaused(),
                'quiet_hours' => $agent->alertQuietHours(),
                'sound_enabled' => $agent->alertSoundEnabled(),
            ],
        ]);
    }
}
