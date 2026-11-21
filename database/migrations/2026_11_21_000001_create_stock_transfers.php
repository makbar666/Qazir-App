<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('source_outlet_id')->constrained('outlets');
            $table->foreignId('destination_outlet_id')->constrained('outlets');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('in_transit');
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unique(['stock_transfer_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_transfer_id')->nullable()->constrained();
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique([...$menus, 'transfers']))),
                'abilities' => json_encode(array_values(array_unique([...$abilities, 'transfers_manage']))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_transfer_id'));
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
