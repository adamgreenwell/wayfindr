<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_readiness_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_readiness_confirmations');
    }
};
