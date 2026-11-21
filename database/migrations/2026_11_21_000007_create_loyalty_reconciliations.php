<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('checked_by')->constrained('users');
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('checked_count');
            $table->unsignedInteger('ok_count');
            $table->unsignedInteger('retry_count');
            $table->unsignedInteger('review_count');
            $table->timestamps();
        });
        Schema::create('loyalty_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('result', 16);
            $table->string('reason', 120);
            $table->json('remote_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['loyalty_reconciliation_id', 'sale_id'], 'loyalty_reconciliation_sale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reconciliation_items');
        Schema::dropIfExists('loyalty_reconciliations');
    }
};
