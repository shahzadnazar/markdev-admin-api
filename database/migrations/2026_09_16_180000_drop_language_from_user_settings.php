<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop user_settings.language — nothing has ever read it.
 *
 * The portal's Settings page offered English and Urdu, saved the choice, and
 * then rendered English either way: there is no i18n layer in that app at all,
 * no translation library, no locale switching and no translated strings. A
 * student who picked Urdu saw no change and had every reason to think the app
 * was broken. A control that saves and does nothing is worse than no control.
 *
 * This is NOT Laravel's own localisation. config('app.locale'), the
 * fallback_locale and the `<html lang>` tags that read app()->getLocale() are
 * untouched and stay — they are how the admin panel declares its language to a
 * browser, which is a different thing entirely from a per-student preference
 * nobody implemented.
 *
 * Reversible: down() puts the column back with the same type and default it
 * had, so a rollback lands on a schema identical to the one before this ran.
 * The VALUES are not restored, and could not be — every row held 'en' except
 * any a student had changed, and the change meant nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_settings', 'language')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_settings', 'language')) {
            Schema::table('user_settings', function (Blueprint $table) {
                // Same shape as 2026_07_16_110500 created it.
                $table->string('language', 10)->default('en');
            });
        }
    }
};
