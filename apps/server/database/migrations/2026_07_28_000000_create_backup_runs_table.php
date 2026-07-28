<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each operator-triggered backup run (ADR 0011 slice 3) so the operator
 * can see the outcome of the queued "run a backup now" action and a short
 * history, without opening the terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16); // running | succeeded | failed
            $table->text('message')->nullable();
            $table->string('archive_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('offsite_disk')->nullable();
            $table->string('offsite_key')->nullable();
            $table->unsignedInteger('pruned_local')->default(0);
            $table->unsignedInteger('pruned_remote')->default(0);
            // Who triggered it (null = the scheduler / system).
            $table->foreignId('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
