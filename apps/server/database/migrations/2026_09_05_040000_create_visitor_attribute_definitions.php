<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 80);
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->index(['account_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_attribute_definitions');
    }
};
