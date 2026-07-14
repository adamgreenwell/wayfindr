<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            // A two-step upload lands the row before its message exists, so the
            // attachment is scoped to its conversation directly (denormalized,
            // like account_id/site_id) rather than only through the message.
            $table->foreignId('conversation_id')
                ->after('conversation_message_id')
                ->constrained()
                ->cascadeOnDelete();

            // Who uploaded the file. The message sender must match this to bind
            // the attachment at send time, and it gates preview of a
            // not-yet-sent upload to its uploader only.
            $table->nullableMorphs('uploaded_by');
        });

        // The message binding is now nullable: a pending upload has no owning
        // message until send binds it. A bound message still cascades its files
        // on delete (the FK keeps cascadeOnDelete).
        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropMorphs('uploaded_by');
        });

        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_message_id')->nullable(false)->change();
        });
    }
};
