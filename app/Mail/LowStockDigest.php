<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class LowStockDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $outletName, public Collection $products) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Stok menipis — '.$this->outletName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.low-stock');
    }
}
