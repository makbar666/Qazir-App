<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Stok menipis</title></head><body style="font-family:Arial,sans-serif;color:#172b22;font-size:14px;line-height:1.5">
<h1 style="font-size:20px">Stok menipis · {{ $outletName }}</h1>
<p>{{ $products->count() }} produk telah mencapai batas minimum stok.</p>
<table cellpadding="10" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#d9e1da;width:100%;text-align:left"><thead><tr><th>Produk</th><th>Stok</th><th>Minimum</th><th>Dalam pesanan</th><th>Saran pesan</th></tr></thead><tbody>
@foreach($products as $product)
<tr><td>{{ $product->name }} ({{ $product->sku }})</td><td>{{ number_format($product->quantity, 0, ',', '.') }}</td><td>{{ number_format($product->minimum_quantity, 0, ',', '.') }}</td><td>{{ number_format($product->incoming_quantity, 0, ',', '.') }}</td><td>{{ number_format($product->suggested_quantity, 0, ',', '.') }}</td></tr>
@endforeach
</tbody></table>
<p>Saran pesan memperhitungkan pesanan pembelian yang belum diterima. Periksa stok fisik dan pesanan pemasok sebelum membuat pembelian baru.</p>
<p><a href="{{ route('stock_alerts') }}">Lihat stok menipis di Kasir</a></p>
</body></html>
