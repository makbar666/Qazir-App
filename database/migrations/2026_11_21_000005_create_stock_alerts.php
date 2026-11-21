<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('minimum_quantity');
            $table->unsignedInteger('target_quantity');
            $table->timestamps();
            $table->unique(['outlet_id', 'product_id']);
        });

        Schema::create('stock_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->unique();
            $table->boolean('email_enabled')->default(false);
            $table->text('recipient_emails')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->string('recipient_email');
            $table->date('alert_date');
            $table->unsignedInteger('product_count');
            $table->timestamp('sent_at');
            $table->unique(['outlet_id', 'recipient_email', 'alert_date'], 'stock_alert_delivery_unique');
        });

        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['owner', 'manager'])->get() as $role) {
            $menus = json_decode($role->menus, true) ?: [];
            $abilities = json_decode($role->abilities, true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'menus' => json_encode(array_values(array_unique([...$menus, 'stock_alerts']))),
                'abilities' => json_encode(array_values(array_unique([...$abilities, 'stock_alerts_manage']))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_deliveries');
        Schema::dropIfExists('stock_alert_settings');
        Schema::dropIfExists('stock_alert_rules');
    }
};
