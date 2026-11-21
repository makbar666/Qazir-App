<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TandaClient
{
    public function config(int $businessId): ?object {return DB::table('loyalty_connections')->where('business_id',$businessId)->first();}
    public function request(object $config,string $method,string $path,array $data=[]): array
    {
        $url=rtrim($config->base_url,'/').'/api/v1/pos/'.$path;
        $http=Http::timeout(8)->withToken(decrypt($config->token))->acceptJson();
        $response=$method==='GET'?$http->get($url,$data):$http->post($url,$data);
        if(!$response->successful())throw new \RuntimeException('Tanda HTTP '.$response->status().': '.substr((string)($response->json('message')??$response->body()),0,300));
        return $response->json();
    }
    public function reserve(int $saleId,string $operatorRole): array
    {
        $sale=DB::table('sales')->where('id',$saleId)->first();
        $config=$this->config($sale->business_id);
        $map=DB::table('loyalty_outlet_maps')->where('business_id',$sale->business_id)->where('outlet_id',$sale->outlet_id)->first();
        if(!$config?->active||!$map)throw new \RuntimeException('Integrasi Tanda untuk outlet ini belum siap.');
        $items=DB::table('sale_items')->where('sale_id',$saleId)->get()->map(fn($i)=>['name'=>$i->name,'quantity'=>$i->quantity,'unit_price'=>$i->unit_price,'eligible'=>(bool)$i->point_eligible])->all();
        $eligible=array_sum(array_map(fn($i)=>$i['eligible']?$i['quantity']*$i['unit_price']:0,$items));
        return $this->request($config,'POST','redemptions',[
            'external_key'=>$sale->request_key,'outlet_id'=>$map->remote_outlet_id,
            'member_id'=>(int)$sale->member_external_id,'subtotal'=>$sale->subtotal,'discount'=>$sale->discount,
            'eligible_subtotal'=>$eligible,'points'=>$sale->redeem_points,'operator_role'=>$operatorRole,'items'=>$items,
        ]);
    }
    public function release(int $saleId): bool
    {
        $sale=DB::table('sales')->where('id',$saleId)->first();
        if(!$sale||!$sale->redeem_points)return true;
        $config=$this->config($sale->business_id);
        if(!$config?->active)return false;
        try{
            $this->request($config,'POST','redemptions/'.rawurlencode($sale->request_key).'/release');
            DB::table('sales')->where('id',$saleId)->update(['loyalty_status'=>'released','loyalty_error'=>null,'updated_at'=>now()]);
            return true;
        }catch(Throwable $e){
            Log::warning('Loyalty reservation release failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);
            DB::table('sales')->where('id',$saleId)->update(['loyalty_status'=>'release_pending','loyalty_error'=>$e->getMessage(),'updated_at'=>now()]);
            return false;
        }
    }
    public function abortDraft(int $saleId): void
    {
        $this->release($saleId);
        DB::transaction(function()use($saleId){
            $sale=DB::table('sales')->where('id',$saleId)->lockForUpdate()->first();
            if(!$sale||$sale->status!=='pending_loyalty')return;
            foreach(DB::table('sale_items')->where('sale_id',$saleId)->get() as $item){
                DB::table('stocks')->where('outlet_id',$sale->outlet_id)->where('product_id',$item->product_id)->increment('quantity',$item->quantity);
                DB::table('stock_movements')->insert(['business_id'=>$sale->business_id,'outlet_id'=>$sale->outlet_id,'product_id'=>$item->product_id,'sale_id'=>$saleId,'actor_id'=>$sale->cashier_id,'delta'=>$item->quantity,'reason'=>'loyalty_reserve_failed','created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('sales')->where('id',$saleId)->update(['status'=>'cancelled','updated_at'=>now()]);
        },3);
    }
    public function sync(int $saleId): bool
    {
        $sale=DB::table('sales')->where('id',$saleId)->first(); if(!$sale||!$sale->member_external_id||!in_array($sale->status,['paid','refunded'],true))return false;
        $config=$this->config($sale->business_id); if(!$config||!$config->active)return false;
        $map=DB::table('loyalty_outlet_maps')->where('outlet_id',$sale->outlet_id)->where('business_id',$sale->business_id)->first(); if(!$map)return false;
        try{
            if(!$sale->loyalty_transaction_id){
                $items=DB::table('sale_items')->where('sale_id',$saleId)->get()->map(fn($i)=>['name'=>$i->name,'quantity'=>$i->quantity,'unit_price'=>$i->unit_price,'eligible'=>(bool)$i->point_eligible])->all();
                $payload=['external_sale_id'=>(string)$sale->id,'idempotency_key'=>$sale->request_key,'outlet_id'=>$map->remote_outlet_id,'member_id'=>(int)$sale->member_external_id,'subtotal'=>$sale->subtotal,'discount'=>$sale->discount,'total'=>$sale->total,'payment_method'=>$sale->payment_method,'items'=>$items];
                if($sale->redeem_points){$payload['redeem_points']=$sale->redeem_points;$payload['redeem_rupiah']=$sale->redeem_rupiah;$payload['reservation_key']=$sale->request_key;}
                $result=$this->request($config,'POST','sales',$payload);
                DB::table('sales')->where('id',$saleId)->update(['loyalty_status'=>'synced','loyalty_transaction_id'=>$result['transaction_id'],'loyalty_earned_points'=>$result['earned_points'],'loyalty_error'=>null,'loyalty_synced_at'=>now()]);
            }
            if($sale->status==='refunded'){
                $this->request($config,'POST','sales/'.rawurlencode((string)$sale->id).'/refund',['reason'=>$sale->refund_reason]);
                DB::table('sales')->where('id',$saleId)->update(['loyalty_refund_status'=>'synced','loyalty_error'=>null,'loyalty_synced_at'=>now()]);
            }
            return true;
        }catch(Throwable $e){Log::warning('Loyalty sync failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);DB::table('sales')->where('id',$saleId)->update(['loyalty_status'=>$sale->status==='refunded'?$sale->loyalty_status:'failed','loyalty_refund_status'=>$sale->status==='refunded'?'failed':$sale->loyalty_refund_status,'loyalty_error'=>$e->getMessage()]);return false;}
    }
}
