<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_connections', function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained()->unique();$t->string('base_url');$t->text('token');$t->unsignedBigInteger('remote_business_id')->nullable();$t->string('remote_business_name')->nullable();$t->boolean('active')->default(false);$t->timestamp('last_checked_at')->nullable();$t->text('last_error')->nullable();$t->timestamps();});
        Schema::create('loyalty_outlet_maps', function(Blueprint $t){$t->id();$t->foreignId('business_id')->constrained();$t->foreignId('outlet_id')->constrained()->unique();$t->unsignedBigInteger('remote_outlet_id');$t->timestamps();});
        Schema::table('sales',function(Blueprint $t){$t->text('loyalty_error')->nullable();$t->unsignedInteger('loyalty_earned_points')->nullable();$t->timestamp('loyalty_synced_at')->nullable();$t->string('loyalty_refund_status')->nullable();});
    }
    public function down(): void {Schema::table('sales',fn(Blueprint $t)=>$t->dropColumn(['loyalty_error','loyalty_earned_points','loyalty_synced_at','loyalty_refund_status']));Schema::dropIfExists('loyalty_outlet_maps');Schema::dropIfExists('loyalty_connections');}
};
