<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customizable, versioned rating templates (#1157).
 *
 * A site edits one DRAFT and publishes it as an immutable, numbered VERSION.
 * Responses point at the version they were shown, so what an answer means is
 * fixed by the definition the visitor read -- never by a later edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_rating_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            // The work in progress. Editing it never changes a live prompt.
            $table->json('draft');
            $table->timestamps();
        });

        Schema::create('site_rating_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // Never rewritten after insert: a change in meaning is a new row.
            $table->json('definition');
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['site_id', 'version']);
        });

        Schema::table('conversation_ratings', function (Blueprint $table): void {
            // The exact published definition a custom answer was given against.
            // Null for the built-in templates, whose key and version already
            // identify them.
            $table->foreignId('template_version_id')->nullable()->after('template_version')
                ->constrained('site_rating_template_versions')->nullOnDelete();
            // `three` or `five`. Null on built-in and legacy rows, which are all
            // three-choice. Kept on the row so a report can never average a
            // five-star answer together with a good/ok/bad one.
            $table->string('scale', 8)->nullable()->after('template_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_ratings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
            $table->dropColumn('scale');
        });

        Schema::dropIfExists('site_rating_template_versions');
        Schema::dropIfExists('site_rating_templates');
    }
};
