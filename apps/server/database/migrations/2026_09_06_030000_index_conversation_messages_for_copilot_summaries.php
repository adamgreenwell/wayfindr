<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'conversation_messages_conversation_id_id_index';

    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
