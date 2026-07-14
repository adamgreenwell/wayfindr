<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            // Added nullable first so any pre-existing slice-2 rows can be
            // backfilled from their message before the column is made required.
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('conversation_message_id')
                ->constrained()
                ->cascadeOnDelete();

            // Who uploaded the file. The message sender must match this to bind
            // the attachment at send time, and it gates preview of a
            // not-yet-sent upload to its uploader only.
            $table->nullableMorphs('uploaded_by');
        });

        // Backfill conversation_id from each row's message via a correlated
        // subquery (portable across sqlite/mysql/postgres). A no-op on a fresh
        // install, but it keeps an upgrade with existing attachments valid
        // before the NOT NULL constraint is applied below.
        DB::table('conversation_message_attachments')
            ->whereNull('conversation_id')
            ->update([
                'conversation_id' => DB::raw(
                    '(select conversation_id from conversation_messages '.
                    'where conversation_messages.id = conversation_message_attachments.conversation_message_id)'
                ),
            ]);

        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            // Every row now has a conversation, so require it.
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();

            // A two-step upload lands the row before its message exists, so the
            // message binding is nullable; a bound message still cascades its
            // files on delete (the FK keeps cascadeOnDelete).
            $table->unsignedBigInteger('conversation_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropMorphs('uploaded_by');
        });

        // Pending (not-yet-sent) uploads have a null message binding, which the
        // pre-upload schema disallowed. Drop them before restoring the NOT NULL
        // constraint so the rollback cannot fail on their null value.
        DB::table('conversation_message_attachments')
            ->whereNull('conversation_message_id')
            ->delete();

        Schema::table('conversation_message_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_message_id')->nullable(false)->change();
        });
    }
};
