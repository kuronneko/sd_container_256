<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration alters the `images` column on the `albums` table to
     * LONGTEXT so it can store encrypted strings (which are not valid JSON).
     * For MySQL we use a direct ALTER TABLE statement to avoid requiring
     * doctrine/dbal for column changes.
     *
     * IMPORTANT: Backup your DB before running this migration.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->longText('images')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * This attempts to revert the column back to JSON where supported.
     * If your DB doesn't support JSON or you prefer TEXT, edit this migration
     * before rolling back.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->json('images')->nullable()->change();
        });
    }
};
