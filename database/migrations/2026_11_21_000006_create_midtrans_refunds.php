<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('midtrans_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->unique();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('refund_key', 80)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('reason', 255);
            $table->string('status', 20)->default('preparing');
            $table->text('error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('midtrans_refunds');
    }
};
