<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATALOGUES = [
        'articles' => ['column' => 'ai_article_catalogue_revision', 'fields' => ['id', 'account_id', 'title', 'slug', 'body', 'search_text', 'published_at']],
        'ticket_labels' => ['column' => 'ai_ticket_label_catalogue_revision', 'fields' => ['id', 'account_id', 'name', 'slug']],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('AI catalogue revisions require PostgreSQL or SQLite.');
        }
        Schema::table('accounts', function (Blueprint $table): void {
            $table->uuid('ai_article_catalogue_revision')->nullable();
            $table->uuid('ai_ticket_label_catalogue_revision')->nullable();
        });
        // Initial NULL is a valid revision-zero snapshot of existing content.
        // No seeding or content scan; triggers join every subsequent write,
        // including bulk SQL, atomically with the source mutation.
        foreach (self::CATALOGUES as $table => $spec) {
            $driver === 'pgsql' ? $this->postgres($table, $spec) : $this->sqlite($table, $spec);
        }
    }

    private function postgres(string $table, array $spec): void
    {
        $column = $spec['column'];
        $changed = implode(' OR ', array_map(fn ($field) => "NEW.$field IS DISTINCT FROM OLD.$field", $spec['fields']));
        $old = $table === 'articles' ? 'OLD.published_at IS NOT NULL' : 'TRUE';
        $new = $table === 'articles' ? 'NEW.published_at IS NOT NULL' : 'TRUE';
        DB::unprepared(<<<SQL
            CREATE FUNCTION wf_ai_{$table}_revision() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    UPDATE accounts SET $column = gen_random_uuid() WHERE id = NEW.account_id AND ($new);
                ELSIF TG_OP = 'DELETE' THEN
                    UPDATE accounts SET $column = gen_random_uuid() WHERE id = OLD.account_id AND ($old);
                ELSIF $changed THEN
                    UPDATE accounts SET $column = gen_random_uuid()
                    WHERE (id = OLD.account_id AND ($old)) OR (id = NEW.account_id AND ($new));
                END IF;
                RETURN NULL;
            END;
            \$\$;
            CREATE TRIGGER wf_ai_{$table}_revision AFTER INSERT OR UPDATE OR DELETE ON $table
            FOR EACH ROW EXECUTE FUNCTION wf_ai_{$table}_revision();
            SQL);
    }

    private function sqlite(string $table, array $spec): void
    {
        $column = $spec['column'];
        $uuid = "lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))), 2) || '-8' || substr(lower(hex(randomblob(2))), 2) || '-' || lower(hex(randomblob(6)))";
        $changed = implode(' OR ', array_map(fn ($field) => "NEW.$field IS NOT OLD.$field", $spec['fields']));
        $old = $table === 'articles' ? 'OLD.published_at IS NOT NULL' : '1';
        $new = $table === 'articles' ? 'NEW.published_at IS NOT NULL' : '1';
        DB::unprepared(<<<SQL
            CREATE TRIGGER wf_ai_{$table}_insert AFTER INSERT ON $table WHEN $new
            BEGIN
                UPDATE accounts SET $column = $uuid WHERE id = NEW.account_id;
            END;
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER wf_ai_{$table}_delete AFTER DELETE ON $table WHEN $old
            BEGIN
                UPDATE accounts SET $column = $uuid WHERE id = OLD.account_id;
            END;
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER wf_ai_{$table}_update AFTER UPDATE ON $table WHEN $changed
            BEGIN
                UPDATE accounts SET $column = $uuid
                WHERE (id = OLD.account_id AND ($old)) OR (id = NEW.account_id AND ($new));
            END;
            SQL);
    }

    public function down(): void
    {
        foreach (self::CATALOGUES as $table => $spec) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS wf_ai_{$table}_revision ON $table");
                DB::unprepared("DROP FUNCTION IF EXISTS wf_ai_{$table}_revision()");
            } else {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::unprepared("DROP TRIGGER IF EXISTS wf_ai_{$table}_$operation");
                }
            }
        }
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['ai_article_catalogue_revision', 'ai_ticket_label_catalogue_revision']);
        });
    }
};
