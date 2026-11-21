<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('key', 40);
            $table->string('name', 100);
            $table->text('menus');
            $table->text('abilities');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'key']);
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            \App\Support\RoleAccess::seedDefaults((int) $businessId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
