<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'active']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->uuid('request_key');
            $table->string('number');
            $table->string('supplier_name');
            $table->string('status', 16)->default('ordered');
            $table->string('supplier_reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'request_key']);
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'outlet_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('sku');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('unit_cost');
            $table->unique(['purchase_order_id', 'product_id']);
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'request_key']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained();
            $table->foreignId('purchase_order_item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_receipt_id')->nullable()->constrained();
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique([...$menus, 'purchases']))),
                'abilities' => json_encode(array_values(array_unique([...$abilities, 'purchases_manage']))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_receipt_id'));
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
