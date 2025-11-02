<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::create([
            'name' => 'dev',
            'email' => 'dev@dev.com',
            'password' => Hash::make('dev@dev.com'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
