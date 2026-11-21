<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('qris_reconciliation_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->string('remote_status', 30)->nullable();
            $table->unsignedBigInteger('remote_amount')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });

        Schema::create('daily_reconciliation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->date('business_date');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->string('snapshot_hash', 64);
            $table->json('snapshot');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'outlet_id', 'business_date']);
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            $menus[] = 'reconciliation';
            $abilities[] = 'reconciliation_manage';
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique($menus))),
                'abilities' => json_encode(array_values(array_unique($abilities))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager'])->get() as $role) {
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_diff(json_decode($role->menus, true) ?: [], ['reconciliation']))),
                'abilities' => json_encode(array_values(array_diff(json_decode($role->abilities, true) ?: [], ['reconciliation_manage']))),
                'updated_at' => now(),
            ]);
        }
        Schema::dropIfExists('daily_reconciliation_reviews');
        Schema::dropIfExists('qris_reconciliation_checks');
    }
};
