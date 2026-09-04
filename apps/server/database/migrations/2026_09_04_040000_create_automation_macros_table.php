<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_macros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('subject_type');
            $table->json('actions');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'name']);
            $table->index(['account_id', 'is_enabled', 'subject_type', 'position']);
        });

        Schema::table('automation_rule_executions', function (Blueprint $table): void {
            $table->foreignId('automation_macro_id')
                ->nullable()
                ->after('automation_rule_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('triggered_by_user_id')
                ->nullable()
                ->after('automation_macro_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('automation_rule_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triggered_by_user_id');
            $table->dropConstrainedForeignId('automation_macro_id');
        });

        Schema::dropIfExists('automation_macros');
    }
};
