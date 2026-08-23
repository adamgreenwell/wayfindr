<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            // Account-scoped, like reply templates: an answer about refunds is
            // the desk's answer, not one site's, and an account whose sites are
            // one product would otherwise write it twice.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 180);
            $table->text('body');
            // Draft is the default. An article half-written is the normal state
            // of an article, and a knowledge base that publishes on save is one
            // nobody drafts in.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Unique per account, not globally: two accounts may both have a
            // "refunds" article and neither should learn about the other.
            $table->unique(['account_id', 'slug']);
            // The widget's query: this account's published articles, newest
            // first. The visitor-facing read is the one that must not scan.
            $table->index(['account_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
