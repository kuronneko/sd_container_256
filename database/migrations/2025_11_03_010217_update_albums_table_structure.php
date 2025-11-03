<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // Add new ComfyUI fields
            $table->bigInteger('seed')->nullable()->after('id');
            $table->integer('steps')->nullable()->after('seed');
            $table->decimal('cfg', 8, 2)->nullable()->after('steps');
            $table->string('sampler_name')->nullable()->after('cfg');
            $table->string('scheduler')->nullable()->after('sampler_name');
            $table->decimal('denoise', 3, 2)->nullable()->after('scheduler');

            $table->string('ckpt_name')->nullable()->after('negative_prompt');

            $table->integer('width')->nullable()->after('ckpt_name');
            $table->integer('height')->nullable()->after('width');

            // Rename existing fields
            $table->renameColumn('positive_prompt', 'positive');
            $table->renameColumn('negative_prompt', 'negative');
            $table->renameColumn('extra_configuration', 'metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // Remove new fields
            $table->dropColumn([
                'seed',
                'steps',
                'cfg',
                'sampler_name',
                'scheduler',
                'denoise',
                'ckpt_name',
                'width',
                'height'
            ]);

            // Rename fields back
            $table->renameColumn('positive', 'positive_prompt');
            $table->renameColumn('negative', 'negative_prompt');
            $table->renameColumn('metadata', 'extra_configuration');
        });
    }
};
