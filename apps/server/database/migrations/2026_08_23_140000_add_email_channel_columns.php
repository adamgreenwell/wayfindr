<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // The address a visitor writes to. A column rather than a key in
            // `settings`, because routing an inbound message means querying BY
            // it -- and JSON-path querying is the driver-specific trap
            // docs/development/testing.md names.
            $table->string('inbound_address')->nullable()->after('domain');
            $table->unique('inbound_address');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            // The Message-ID this message was sent as, or arrived as.
            //
            // Threading reads In-Reply-To and References against it. Subject
            // matching is the alternative and it is wrong in both directions:
            // two unrelated "Re: Order" threads collapse into one, and a
            // customer who edits the subject starts a second conversation
            // about the thing they are already discussing.
            $table->string('email_message_id')->nullable()->after('metadata');
            // Unique, not merely indexed. A provider retries after a timeout or
            // a lost 200, and without this a retry inserts the message again --
            // or, for a first email with no thread to join, opens a SECOND
            // conversation about the same question. A Message-ID is unique by
            // RFC, and the router only ever accepts a message for one site, so
            // uniqueness across the table is the honest constraint.
            $table->unique('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique(['inbound_address']);
            $table->dropColumn('inbound_address');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropUnique(['email_message_id']);
            $table->dropColumn('email_message_id');
        });
    }
};
