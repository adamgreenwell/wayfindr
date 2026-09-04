<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('priority')->default('normal')->after('status');
            $table->timestamp('support_wait_started_at')->nullable()->after('last_message_at');
            $table->unsignedBigInteger('support_wait_elapsed_seconds')->default(0)->after('support_wait_started_at');
            $table->timestamp('support_wait_last_counted_at')->nullable()->after('support_wait_elapsed_seconds');
            $table->index(['site_id', 'status', 'priority']);
        });

        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('priority');
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->unique(['account_id', 'priority']);
        });

        Schema::create('sla_clocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->morphs('subject');
            $table->string('metric');
            $table->string('priority');
            $table->unsignedInteger('target_seconds');
            $table->unsignedInteger('warning_seconds');
            $table->unsignedBigInteger('elapsed_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_counted_at');
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->json('warning_alerted_user_ids')->nullable();
            $table->json('warning_mail_alerted_user_ids')->nullable();
            $table->timestamp('warning_alerted_at')->nullable();
            $table->json('breach_alerted_user_ids')->nullable();
            $table->json('breach_mail_alerted_user_ids')->nullable();
            $table->timestamp('breach_alerted_at')->nullable();
            $table->timestamp('satisfied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'metric', 'breached_at']);
            $table->index(['site_id', 'satisfied_at', 'cancelled_at']);
            $table->index(['satisfied_at', 'cancelled_at', 'id']);
        });

        Schema::create('sla_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sla_clock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->string('channel');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['sla_clock_id', 'stage', 'channel', 'user_id'],
                'sla_alert_delivery_route_unique',
            );
            $table->index(
                ['accepted_at', 'started_at', 'failed_at', 'cancelled_at'],
                'sla_alert_delivery_pending_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_alert_deliveries');
        Schema::dropIfExists('sla_clocks');
        Schema::dropIfExists('sla_policies');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'status', 'priority']);
            $table->dropColumn([
                'priority',
                'support_wait_started_at',
                'support_wait_elapsed_seconds',
                'support_wait_last_counted_at',
            ]);
        });
    }
};
