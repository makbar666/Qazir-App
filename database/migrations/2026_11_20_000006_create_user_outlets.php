<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_outlets', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'outlet_id']);
        });

        foreach (DB::table('users')->whereNotNull('outlet_id')->get(['id', 'outlet_id']) as $user) {
            DB::table('user_outlets')->insert(['user_id' => $user->id, 'outlet_id' => $user->outlet_id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_outlets');
    }
};
