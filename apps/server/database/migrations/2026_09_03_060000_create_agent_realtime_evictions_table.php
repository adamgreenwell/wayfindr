<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_realtime_evictions', function (Blueprint $table): void {
            $table->id();
            // Deliberately not a foreign key: deleting a user must not erase the
            // instruction to close a socket that still identifies that user id.
            $table->unsignedBigInteger('agent_id')->unique();
            $table->uuid('request_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_realtime_evictions');
    }
};
