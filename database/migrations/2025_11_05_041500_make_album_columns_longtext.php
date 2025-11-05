<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: Changing column types with Schema::table(...->change()) requires
// the doctrine/dbal package at runtime. If you don't have it installed run:
// composer require doctrine/dbal

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // Convert commonly encrypted columns to longText so encrypted strings fit.
            $table->longText('positive')->nullable()->change();
            $table->longText('negative')->nullable()->change();
            $table->longText('metadata')->nullable()->change();
            $table->longText('comment')->nullable()->change();
            $table->longText('loras')->nullable()->change();

            // images was json; store encrypted JSON as longText
/*             $table->longText('images')->nullable()->change(); */

            // Numeric and short columns that will hold encrypted strings
            $table->longText('seed')->nullable()->change();
            $table->longText('steps')->nullable()->change();
            $table->longText('cfg')->nullable()->change();
            $table->longText('sampler_name')->nullable()->change();
            $table->longText('scheduler')->nullable()->change();
            $table->longText('denoise')->nullable()->change();
            $table->longText('ckpt_name')->nullable()->change();
            $table->longText('width')->nullable()->change();
            $table->longText('height')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->text('positive')->nullable()->change();
            $table->text('negative')->nullable()->change();
            $table->text('metadata')->nullable()->change();
            $table->text('comment')->nullable()->change();
            $table->text('loras')->nullable()->change();

            // images back to JSON
/*             $table->json('images')->nullable()->change(); */

            $table->bigInteger('seed')->nullable()->change();
            $table->integer('steps')->nullable()->change();
            $table->decimal('cfg', 8, 2)->nullable()->change();
            $table->string('sampler_name')->nullable()->change();
            $table->string('scheduler')->nullable()->change();
            $table->decimal('denoise', 3, 2)->nullable()->change();
            $table->string('ckpt_name')->nullable()->change();
            $table->integer('width')->nullable()->change();
            $table->integer('height')->nullable()->change();
        });
    }
};
