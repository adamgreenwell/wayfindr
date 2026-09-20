<?php

use App\Models\Conversation;
use App\Support\Settings\OperatorSettings;
use App\Support\VisitorSessionToken;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

/**
 * Confirm the install's language and clock.
 *
 * Language and region is an essential now, and it appears on BOTH readiness
 * surfaces -- so a fixture that means "nothing needs attention" has to say so
 * about this too, exactly as it already does about mail and backups.
 *
 * Declared here rather than in a test file because Pest helpers are global:
 * two files defining the same name is a fatal that takes down the whole suite
 * before a single test runs.
 */
function readinessLanguageAndRegionConfirmed(): void
{
    $settings = app(OperatorSettings::class);
    $settings->set('localization.language', 'en');
    $settings->set('localization.timezone', 'UTC');
}

/**
 * Make a conversation belong to the visitor session a token names.
 *
 * A conversation records the session that opened it, and only that session may
 * act on it -- a visitor's browser identity is shown to agents, so matching the
 * visitor is not proof of having been part of the conversation.
 *
 * Production always creates conversations through the widget endpoint, which
 * stamps the session itself. A factory does not, so a test that fabricates a
 * conversation and then acts on it as a visitor has to say which session owns it.
 * Doing that explicitly is the point: it is the same statement the endpoint
 * makes, and a test that forgets it fails loudly rather than passing because two
 * timestamps happened to land in the same second.
 */
function conversationOwnedBySession(
    Conversation $conversation,
    string $visitorToken,
): Conversation {
    $sessionId = app(VisitorSessionToken::class)->sessionIdFromToken($visitorToken);

    expect($sessionId)->not->toBe('', 'The token names no session, so nothing can be made to belong to it.');

    $conversation->forceFill(['owner_session_id' => $sessionId])->save();

    return $conversation->refresh();
}
