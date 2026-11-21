<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('movement_watermark')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'outlet_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('counted_quantity')->nullable();
            $table->unique(['stock_count_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_count_id')->nullable()->constrained();
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager', 'cashier'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            $abilities[] = 'stock_counts_manage';
            if (in_array($role->key, ['owner', 'manager'], true)) $abilities[] = 'stock_counts_approve';
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique([...$menus, 'stock_counts']))),
                'abilities' => json_encode(array_values(array_unique($abilities))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_count_id'));
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
