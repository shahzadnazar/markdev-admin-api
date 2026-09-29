<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people MarkDev does client work for.
 *
 * Only super-admin and admin ever see this table. A team lead identifies a
 * project by its name and code, never by who it is for — which is why the code
 * exists at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('company', 160)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();

            /*
             * The login this client record belongs to, once they have one.
             *
             * NOTHING READS THIS YET. Phase 6 builds the client portal, and a
             * client signing in has to resolve to their own record; the column
             * is written now because adding it later means deciding what the
             * rows that already exist were supposed to mean. Nullable because
             * most clients are an address book entry and never sign in, and
             * nulled rather than cascaded on delete: erasing a login must not
             * take the client and their project history with it.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Retiring a client keeps their projects and their history. There
            // is no delete for a client that has any — the controller refuses
            // it and the foreign key refuses it again.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
