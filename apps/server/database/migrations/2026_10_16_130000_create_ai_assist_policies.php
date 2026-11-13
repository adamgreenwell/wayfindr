<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_ai_assist_policies', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained()->cascadeOnDelete();
            $this->columns($table);
        });
        Schema::create('site_ai_assist_policies', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $this->columns($table);
        });
        // Intentionally unseeded: upgrades retain the existing manual path.
    }

    private function columns(Blueprint $table): void
    {
        $table->uuid('revision');
        $table->string('mode', 16);
        $table->boolean('summary');
        $table->boolean('reply_draft');
        $table->boolean('relevant_articles');
        $table->boolean('ticket_details');
        $table->boolean('article_context_consent')->default(false);
        $table->unsignedInteger('max_context_characters')->nullable();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_ai_assist_policies');
        Schema::dropIfExists('account_ai_assist_policies');
    }
};
