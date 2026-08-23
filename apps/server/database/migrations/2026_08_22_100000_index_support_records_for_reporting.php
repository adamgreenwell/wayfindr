<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the reporting queries, chosen against the queries themselves.
 *
 * ADR 0015 deferred these deliberately: every composite index on `conversations`
 * and `tickets` is `(scope, status)`, which is right for the queue and useless
 * for "everything in this account over the last quarter". Guessing at the shapes
 * before the queries existed would have been guessing.
 *
 * Now they exist, and each index below answers one of them:
 *
 * - `conversations (site_id, created_at)` -- volume and first-response both scan
 *   a site allowlist over a date range. `conversations` has no `account_id`
 *   column, so the site is the only way in, and there was no index on
 *   `created_at` at all.
 * - `audit_events (account_id, action, occurred_at)` -- closes and reopens are
 *   read as "this account's `conversation.closed` events in this range". The
 *   existing `(account_id, action)` narrows to the action but then scans every
 *   such event ever recorded; the separate `occurred_at` index cannot be
 *   combined with it.
 * - `conversation_messages (sender_type, created_at)` -- agent activity counts
 *   messages sent by agents in a range. The existing `(conversation_id,
 *   created_at)` is the wrong way round for a query that starts from "who sent
 *   it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['site_id', 'created_at'], 'conversations_site_id_created_at_index');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['account_id', 'action', 'occurred_at'], 'audit_events_account_action_occurred_index');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->index(['sender_type', 'created_at'], 'conversation_messages_sender_type_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_site_id_created_at_index');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_account_action_occurred_index');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_messages_sender_type_created_at_index');
        });
    }
};
