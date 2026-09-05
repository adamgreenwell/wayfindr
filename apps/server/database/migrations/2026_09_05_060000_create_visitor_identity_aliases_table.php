<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_identity_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->string('anonymous_id');
            // Signed tokens minted before one or more merges still name the
            // visitor row that existed then. Keep only those internal ids so a
            // token can follow its deliberate merge lineage without becoming
            // valid for an unrelated row that later reuses the browser id.
            $table->json('previous_visitor_ids')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'anonymous_id']);
            $table->index(['visitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_identity_aliases');
    }
};
