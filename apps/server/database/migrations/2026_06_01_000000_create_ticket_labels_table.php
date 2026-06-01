<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('slug', 80);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'name']);
        });

        Schema::create('ticket_label_ticket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_label_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'ticket_label_id']);
            $table->index(['ticket_label_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_label_ticket');
        Schema::dropIfExists('ticket_labels');
    }
};
