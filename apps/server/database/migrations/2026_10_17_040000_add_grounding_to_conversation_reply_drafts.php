<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_copilot_reply_drafts', function (Blueprint $table): void {
            // Existing suggestions remain nullable; no historical text is promoted to grounded output.
            $table->json('grounding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_copilot_reply_drafts', fn (Blueprint $table) => $table->dropColumn('grounding'));
    }
};
