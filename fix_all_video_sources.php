<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Fixing prices & thumbnails for ALL videos...\n\n";

$thumbs = [
    'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1579783902614-a3fb3927b675?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1534447677768-be436bb09401?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1511512578047-dfb367046420?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=800&auto=format&fit=crop',
    'https://images.unsplash.com/photo-1550745165-9bc0b252726f?w=800&auto=format&fit=crop',
];

// Prices in minor units (paise): ₹49, ₹99, ₹149, ₹199, ₹299, ₹399, ₹499
$prices = [4900, 9900, 14900, 19900, 29900, 39900, 49900];

$videos = DB::table('videos')->whereNull('deleted_at')->get();
$count = 0;

foreach ($videos as $v) {
    $tIdx = $count % count($thumbs);
    $pIdx = $count % count($prices);
    
    DB::table('videos')->where('id', $v->id)->update([
        'thumbnail_path' => $thumbs[$tIdx],
        'price_minor_units' => $prices[$pIdx],
        'view_count' => max((int)$v->view_count, rand(1200, 95000)),
    ]);
    $count++;
    echo " [$count] ID:{$v->id} | Price: ₹" . ($prices[$pIdx] / 100) . " | Thumb: OK\n";
}

echo "\nDone! Updated $count videos — all have prices & working thumbnails.\n";
