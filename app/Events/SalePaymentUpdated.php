<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SalePaymentUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $saleId, public string $status) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sales.'.$this->saleId)];
    }

    public function broadcastAs(): string
    {
        return 'payment.updated';
    }

    public function broadcastWith(): array
    {
        return ['sale_id'=>$this->saleId,'status'=>$this->status];
    }
}
