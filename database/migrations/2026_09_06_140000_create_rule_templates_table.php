<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sentences on the student Rules & Regulations page.
 *
 * A table rather than a config file or language files: the brief is that an
 * admin can reword a rule without a deploy, and only a row can do that. It
 * also gives the page an honest "last updated" — the newest `updated_at`
 * across the rules — which a file cannot.
 *
 * Each row is a template with {placeholders} that the server fills before the
 * text ever leaves the API, so the portal is handed finished sentences and
 * never assembles one from a number.
 *
 * `key` is what identifies a rule for the seeder and for "reset to default";
 * the body is the only thing an admin edits. Rules cannot be added or removed
 * from the panel on purpose: every sentence here has code behind it, and a
 * free-text rule nothing enforces is exactly what this page must not become.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('section', 40)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('body');
            // What the seeder shipped, kept so an admin can undo a reword
            // without needing the original from a deploy.
            $table->text('default_body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_templates');
    }
};
