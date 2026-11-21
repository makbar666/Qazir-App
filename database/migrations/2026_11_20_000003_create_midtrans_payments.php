<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('midtrans_settings',function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained()->unique();$t->string('environment')->default('sandbox');$t->text('server_key');$t->boolean('active')->default(true);$t->timestamps();});
        Schema::table('sales',function(Blueprint $t){$t->string('midtrans_transaction_id')->nullable();$t->text('midtrans_qr_url')->nullable();$t->string('midtrans_status')->nullable();$t->text('midtrans_error')->nullable();$t->timestamp('paid_at')->nullable();});
    }
    public function down(): void {Schema::table('sales',fn(Blueprint $t)=>$t->dropColumn(['midtrans_transaction_id','midtrans_qr_url','midtrans_status','midtrans_error','paid_at']));Schema::dropIfExists('midtrans_settings');}
};
