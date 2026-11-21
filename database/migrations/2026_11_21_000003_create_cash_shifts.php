<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('status', 12)->default('open');
            $table->string('open_key')->nullable()->unique();
            $table->unsignedBigInteger('opening_cash');
            $table->bigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'outlet_id', 'status']);
        });

        Schema::create('cash_shift_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained();
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('type', 12);
            $table->bigInteger('delta');
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['sale_id', 'type']);
            $table->index(['cash_shift_id', 'type']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->constrained();
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager', 'cashier'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            $menus[] = 'shifts';
            if (in_array($role->key, ['owner', 'manager'], true)) $abilities[] = 'cash_shifts_manage';
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique($menus))),
                'abilities' => json_encode(array_values(array_unique($abilities))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_shift_id'));
        Schema::dropIfExists('cash_shift_entries');
        Schema::dropIfExists('cash_shifts');
    }
};
