<?php

namespace App\Http\Controllers\Widget;

use App\Broadcasting\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastAuthController extends Controller
{
    public function __invoke(
        Request $request,
        VisitorSessionToken $visitorSessionToken,
        ConversationChannel $conversationChannel
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'socket_id' => ['required', 'string', 'max:255'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        $channelName = $validated['channel_name'];
        $channelPrefix = 'private-conversations.';

        abort_unless(
            str_starts_with($channelName, $channelPrefix),
            403,
            'Conversation channel is not available.'
        );

        $supportCode = substr($channelName, strlen($channelPrefix));

        abort_if($supportCode === '' || str_contains($supportCode, '.'), 403, 'Conversation channel is not available.');

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        abort_unless(
            $conversationChannel->join($visitor, $supportCode),
            403,
            'Conversation channel is not available.'
        );

        abort_unless(
            config('broadcasting.connections.reverb.key')
                && config('broadcasting.connections.reverb.secret')
                && config('broadcasting.connections.reverb.app_id'),
            503,
            'Reverb broadcasting is not configured.'
        );

        $broadcaster = Broadcast::connection('reverb');

        abort_unless($broadcaster instanceof PusherBroadcaster, 503, 'Reverb broadcasting is not configured.');

        return response()->json($broadcaster->validAuthenticationResponse($request, true));
    }
}
