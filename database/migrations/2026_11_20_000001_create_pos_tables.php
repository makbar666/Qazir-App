<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        Schema::create('outlets', function (Blueprint $t) { $t->id(); $t->foreignId('business_id')->constrained(); $t->string('name'); $t->string('code'); $t->timestamps(); $t->unique(['business_id', 'code']); });
        Schema::table('users', function (Blueprint $t) { $t->foreignId('business_id')->nullable()->constrained(); $t->foreignId('outlet_id')->nullable()->constrained(); $t->string('role')->default('cashier'); });
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->foreignId('business_id')->constrained(); $t->string('sku'); $t->string('name'); $t->unsignedBigInteger('price'); $t->boolean('point_eligible')->default(true); $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['business_id', 'sku']); });
        Schema::create('stocks', function (Blueprint $t) { $t->id(); $t->foreignId('outlet_id')->constrained(); $t->foreignId('product_id')->constrained(); $t->unsignedInteger('quantity')->default(0); $t->timestamps(); $t->unique(['outlet_id', 'product_id']); });
        Schema::create('sales', function (Blueprint $t) { $t->id(); $t->foreignId('business_id')->constrained(); $t->foreignId('outlet_id')->constrained(); $t->foreignId('cashier_id')->constrained('users'); $t->uuid('request_key'); $t->string('number'); $t->string('status')->default('paid'); $t->unsignedBigInteger('subtotal'); $t->unsignedBigInteger('discount')->default(0); $t->unsignedBigInteger('total'); $t->string('payment_method'); $t->unsignedBigInteger('amount_received'); $t->unsignedBigInteger('change_amount')->default(0); $t->string('member_external_id')->nullable(); $t->string('loyalty_status')->default('disabled'); $t->string('loyalty_transaction_id')->nullable(); $t->text('refund_reason')->nullable(); $t->foreignId('refunded_by')->nullable()->constrained('users'); $t->timestamp('refunded_at')->nullable(); $t->timestamps(); $t->unique(['business_id', 'request_key']); $t->unique(['business_id', 'number']); });
        Schema::create('sale_items', function (Blueprint $t) { $t->id(); $t->foreignId('sale_id')->constrained(); $t->foreignId('product_id')->constrained(); $t->string('sku'); $t->string('name'); $t->unsignedInteger('quantity'); $t->unsignedBigInteger('unit_price'); $t->unsignedBigInteger('line_total'); $t->boolean('point_eligible'); });
        Schema::create('stock_movements', function (Blueprint $t) { $t->id(); $t->foreignId('business_id')->constrained(); $t->foreignId('outlet_id')->constrained(); $t->foreignId('product_id')->constrained(); $t->foreignId('sale_id')->nullable()->constrained(); $t->foreignId('actor_id')->constrained('users'); $t->integer('delta'); $t->string('reason'); $t->timestamps(); });
    }
    public function down(): void
    {
        foreach (['stock_movements','sale_items','sales','stocks','products'] as $table) Schema::dropIfExists($table);
        Schema::table('users', function (Blueprint $t) { $t->dropConstrainedForeignId('outlet_id'); $t->dropConstrainedForeignId('business_id'); $t->dropColumn('role'); });
        Schema::dropIfExists('outlets'); Schema::dropIfExists('businesses');
    }
};
