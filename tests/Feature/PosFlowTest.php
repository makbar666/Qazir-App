<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_sale_is_idempotent_and_refund_restores_stock_once(): void
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko Uji','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Utama','code'=>'UTAMA','created_at'=>now(),'updated_at'=>now()]);
        $user=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'A1','name'=>'Sabun','price'=>10000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>5,'created_at'=>now(),'updated_at'=>now()]);
        $payload=['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>2]],'discount'=>1000,'payment_method'=>'cash','amount_received'=>20000];
        $this->actingAs($user)->post('/checkout',$payload)->assertRedirect();
        $this->actingAs($user)->post('/checkout',$payload)->assertRedirect();
        $this->assertDatabaseCount('sales',1);
        $this->assertDatabaseHas('sales',['total'=>19000,'change_amount'=>1000]);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>3]);
        $sale=DB::table('sales')->first();
        $this->actingAs($user)->put('/products/'.$product,['sku'=>'A1','name'=>'Sabun Baru','price'=>15000,'point_eligible'=>1,'active'=>1])->assertRedirect();
        $this->assertDatabaseHas('products',['id'=>$product,'name'=>'Sabun Baru','price'=>15000]);
        $this->assertDatabaseHas('sale_items',['sale_id'=>$sale->id,'name'=>'Sabun','unit_price'=>10000]);
        $this->actingAs($user)->post('/sales/'.$sale->id.'/refund',['reason'=>'Barang dikembalikan'])->assertRedirect();
        $this->actingAs($user)->post('/sales/'.$sale->id.'/refund',['reason'=>'Barang dikembalikan'])->assertRedirect();
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseCount('stock_movements',2);
    }

    public function test_product_can_be_hidden_and_restored_without_losing_sales_or_stock(): void
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko Uji','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Utama','code'=>'UTAMA','created_at'=>now(),'updated_at'=>now()]);
        $owner=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'A1','name'=>'Sabun','price'=>10000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>5,'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($owner)->get('/products')->assertOk()->assertSee('addProductDialog')->assertSee('Hapus');
        $this->withSession(['success'=>'Stok diperbarui.'])->get('/products')->assertOk()->assertSee('flash-toast--success')->assertSee('Stok diperbarui.');
        $this->post('/checkout',['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>1]],'discount'=>0,'payment_method'=>'cash','amount_received'=>10000])->assertRedirect();

        $this->delete('/products/'.$product)->assertRedirect('/products');
        $this->assertNotNull(DB::table('products')->where('id',$product)->value('deleted_at'));
        $this->get('/products')->assertDontSee('Sabun');
        $this->get('/products?archived=1')->assertSee('Sabun')->assertSee('Pulihkan');
        $this->get('/checkout')->assertDontSee('Sabun');
        $this->post('/checkout',['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>1]],'discount'=>0,'payment_method'=>'cash','amount_received'=>10000])->assertSessionHasErrors('items');
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        $this->assertDatabaseHas('sale_items',['product_id'=>$product,'name'=>'Sabun']);

        $this->post('/products/'.$product.'/restore')->assertRedirect('/products?archived=1');
        $this->assertNull(DB::table('products')->where('id',$product)->value('deleted_at'));
        $this->get('/products')->assertSee('Sabun');

        $cashier=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'cashier']);
        $this->actingAs($cashier)->delete('/products/'.$product)->assertForbidden();
        $this->get('/products?archived=1')->assertForbidden();
        $this->assertNull(DB::table('products')->where('id',$product)->value('deleted_at'));

        $this->actingAs($owner)->post('/products/'.$product.'/stock',['delta'=>2])->assertRedirect();
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>6]);
        $this->assertDatabaseHas('stock_movements',['product_id'=>$product,'delta'=>2,'reason'=>'Stok ditambah manual']);
        $this->post('/products/'.$product.'/stock',['delta'=>-7])->assertSessionHasErrors('delta');
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>6]);
    }

    public function test_manual_qris_refund_does_not_create_midtrans_refund_request(): void
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko Uji','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Utama','code'=>'UTAMA','created_at'=>now(),'updated_at'=>now()]);
        $owner=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'A1','name'=>'Sabun','price'=>10000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>2,'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($owner)->post('/checkout',['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>1]],'discount'=>0,'payment_method'=>'qris','amount_received'=>10000])->assertRedirect();
        $sale=DB::table('sales')->first();
        $this->assertNull($sale->midtrans_transaction_id);
        $this->post('/sales/'.$sale->id.'/refund',['reason'=>'Barang dikembalikan'])->assertRedirect();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'refunded']);
        $this->assertDatabaseHas('stocks',['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>2]);
        $this->assertDatabaseCount('midtrans_refunds',0);
    }
}
