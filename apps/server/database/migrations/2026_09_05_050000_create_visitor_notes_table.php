<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['visitor_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_notes');
    }
};
