<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent feedback beside the overall score (#1156).
 *
 * Every column is nullable and additive. An existing row keeps its meaning --
 * overall feedback under the single question -- and none of these columns is
 * ever inferred for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_ratings', function (Blueprint $table): void {
            // The built-in template the visitor was SHOWN, so a settings change
            // while the prompt was open cannot reinterpret the answer. Null on
            // rows written before templates existed: the single question.
            $table->string('template_key', 32)->nullable()->after('comment');
            $table->unsignedSmallInteger('template_version')->nullable()->after('template_key');

            // The one agent the question named, snapshotted. Null id with a
            // name means the account was deleted later; null name means the
            // agent question was not asked for this close.
            $table->foreignId('agent_user_id')->nullable()->after('template_version')
                ->constrained('users')->nullOnDelete();
            $table->string('agent_name')->nullable()->after('agent_user_id');

            // good / ok / bad / not_applicable. Null is "skipped", which is
            // absent data -- never a zero and never counted as answered.
            $table->string('agent_score', 16)->nullable()->after('agent_name');

            $table->index(['agent_user_id', 'episode_closed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_ratings', function (Blueprint $table): void {
            $table->dropIndex(['agent_user_id', 'episode_closed_at']);
            $table->dropConstrainedForeignId('agent_user_id');
            $table->dropColumn(['template_key', 'template_version', 'agent_name', 'agent_score']);
        });
    }
};
