<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // good / ok / bad. Three points, not five: the useful signal is
            // "did this go badly", and finer scales add noise and translation
            // problems without adding meaning.
            $table->string('score', 8);
            // Where the actual information usually is. Nullable, because
            // requiring it would cost most of the responses.
            $table->text('comment')->nullable();
            $table->timestamp('rated_at');
            // WHICH close this answers. A conversation closed, reopened and
            // closed again is two questions and two answers; the same close
            // asked twice is one person changing their mind.
            //
            // Stored rather than derived because it has two jobs a derived
            // value cannot do: the unique index below makes "one answer per
            // close" a database rule rather than a read-then-write race, and
            // reporting filters on it so the answers and the closes they refer
            // to are the same cohort -- otherwise a visitor answering just
            // after a window boundary produces "1 of 0 closes answered".
            $table->timestamp('episode_closed_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'episode_closed_at']);

            // A row per rating rather than a column on the conversation: a
            // conversation closed, reopened and closed again can be rated
            // twice, and the second answer does not erase the first. Absence
            // of a row is what "unrated" means, so an average can never be
            // computed over conversations nobody answered about.
            $table->index(['site_id', 'rated_at']);
            $table->index(['conversation_id', 'rated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_ratings');
    }
};
