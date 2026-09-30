<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent alert mail on its way to SMTP, by the ticket or conversation it names.
 *
 * The check an alert mail makes just before SMTP releases its lock before the
 * transport runs, so a mail built before an erasure could still leave after
 * it. The check records the send here under that lock, and erasing a contact
 * refuses while a send about their work is fresh (ADR 0026 §1). Identifiers and
 * a time only; a row is removed once its mail is sent, and one that never
 * reports back is stale after the in-flight window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_mail_sends', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('started_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_mail_sends');
    }
};
