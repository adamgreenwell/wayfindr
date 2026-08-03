<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TEMPORARY SCRATCH MIGRATION - delete before committing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zz_guard_probe', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zz_guard_probe');
    }
};
