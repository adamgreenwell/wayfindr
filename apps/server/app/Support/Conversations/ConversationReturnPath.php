<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use Illuminate\Http\Request;

/** Keep detail-page actions inside the exact queue context the agent opened. */
final class ConversationReturnPath
{
    /** @return array<string, string|int> */
    public function query(Request $request): array
    {
        $params = [];

        if ($request->input('from_queue') === '1' || $request->input('from_queue') === 1) {
            $params['from_queue'] = '1';
        }

        $conversationFilter = $request->input('conversation_filter');

        if (is_string($conversationFilter) && in_array($conversationFilter, [
            'new_activity',
            'needs_reply',
            'assigned_to_me',
            'unassigned',
            'cobrowse_attention',
            'closed',
        ], true)) {
            $params['conversation_filter'] = $conversationFilter;
        }

        $conversationSearch = $request->input('conversation_search');
        $conversationSearch = is_string($conversationSearch)
            ? mb_substr(trim($conversationSearch), 0, 120)
            : '';

        if ($conversationSearch !== '') {
            $params['conversation_search'] = $conversationSearch;
        }

        $conversationSite = $request->input('conversation_site');

        if (is_int($conversationSite) && $conversationSite > 0) {
            $params['conversation_site'] = $conversationSite;
        } elseif (is_string($conversationSite) && ctype_digit($conversationSite)) {
            $params['conversation_site'] = (int) $conversationSite;
        }

        $conversationPresence = $request->input('conversation_presence');

        if (is_string($conversationPresence) && in_array($conversationPresence, [
            'active',
            'recent',
            'quiet',
            'not_reported',
        ], true)) {
            $params['conversation_presence'] = $conversationPresence;
        }

        return $params;
    }

    /** @return array<string, string|int> */
    public function routeParameters(Conversation $conversation, Request $request): array
    {
        return ['supportCode' => $conversation->support_code] + $this->query($request);
    }
}
