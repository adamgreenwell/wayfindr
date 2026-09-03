<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, account-scoped outbound webhooks (ADR 0020).
 *
 * Endpoint secrets and destinations are encrypted by their model casts. A
 * delivery stores the exact thin payload that was signed, so every retry sends
 * identical bytes and the operator can inspect the public contract without
 * exposing a transcript or message body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('url');
            $table->text('secret');
            $table->string('secret_last_four', 4);
            $table->json('events');
            // Kept separately from the pivot. If every named site is purged,
            // the endpoint reaches nothing rather than silently widening.
            $table->boolean('restricts_sites')->default(true);
            // Allocated under a row lock. Retried HTTP attempts keep the same
            // value; new events always move forward for this endpoint.
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'disabled_at']);
        });

        Schema::create('outbound_webhook_endpoint_site', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_webhook_endpoint_id');
            $table->foreign('outbound_webhook_endpoint_id', 'outbound_webhook_endpoint_site_endpoint_fk')
                ->references('id')
                ->on('outbound_webhook_endpoints')
                ->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->unique(['outbound_webhook_endpoint_id', 'site_id'], 'outbound_webhook_endpoint_site_unique');
        });

        Schema::create('outbound_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('outbound_webhook_endpoint_id');
            $table->foreign('outbound_webhook_endpoint_id', 'outbound_webhook_delivery_endpoint_fk')
                ->references('id')
                ->on('outbound_webhook_endpoints')
                ->cascadeOnDelete();
            // Site purge is the deletion boundary for every record beneath a
            // site. Keeping a delivery would retain its payload/response and
            // could let the recovery scheduler send it after the purge.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->unsignedBigInteger('sequence');
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['outbound_webhook_endpoint_id', 'sequence'], 'outbound_webhook_delivery_sequence_unique');
            $table->index(['delivered_at', 'failed_at', 'cancelled_at'], 'outbound_webhook_delivery_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_webhook_deliveries');
        Schema::dropIfExists('outbound_webhook_endpoint_site');
        Schema::dropIfExists('outbound_webhook_endpoints');
    }
};
