<?php
/**
 * File: price.php
 * Project: BTC Pay Calculator (PHP/HTML/JS)
 * Description:
 *   Server proxy to fetch current BTC price for a fiat currency.
 *   Uses CoinGecko Simple Price API. Returns { price, currency }.
 *   Avoids CORS issues and allows rate limiting/logging if needed.
 *
 * Credits:
 *   - Reaper Harvester / Wills / master Damian Williamson Grad.
 *     (Architect, Ritual Technologist, Systems Designer)
 *   - Microsoft Copilot (AI Companion, collaborative co-author)
 *
 * Date: 2025-10-28
 */

header('Content-Type: application/json');

$cacheFile = __DIR__ . '/data/prices.json';
$ttl = 900; // 15 minutes
$now = time();

// Load cache if exists
$cache = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;

// Serve cache if fresh
if ($cache && isset($cache['lastUpdated']) && ($now - $cache['lastUpdated'] < $ttl)) {
    echo json_encode($cache);
    exit;
}

// Build currency list
$currencies = [
  'AUD','USD','EUR','GBP','JPY','CAD','NZD','CHF','SEK','NOK',
  'DKK','SGD','HKD','CNY','INR','ZAR','BRL','MXN','TRY','PLN'
];
$vs = strtolower(implode(',', $currencies));

// API endpoints
$priceUrl = "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies={$vs}";
$feeUrl   = "https://mempool.space/api/v1/fees/recommended";

try {
    $ctx = stream_context_create(['http' => ['timeout' => 4]]);

    // Fetch prices
    $priceJson = @file_get_contents($priceUrl, false, $ctx);
    if ($priceJson === false) throw new Exception('price fetch failed');
    $priceData = json_decode($priceJson, true);

    // Fetch fees
    $feeJson = @file_get_contents($feeUrl, false, $ctx);
    if ($feeJson === false) throw new Exception('fee fetch failed');
    $feeData = json_decode($feeJson, true);

    // Extract fees
    $fastest = $feeData['fastestFee'] ?? null;
    $halfHour = $feeData['halfHourFee'] ?? null;
    $hour = $feeData['hourFee'] ?? null;
    $economy = $feeData['economyFee'] ?? null;
    $eight = null;
    if (is_numeric($hour) && is_numeric($economy)) {
        $eight = round(($hour * 2 + $economy) / 3);
    } elseif (is_numeric($hour)) {
        $eight = intval($hour);
    } elseif (is_numeric($halfHour)) {
        $eight = intval($halfHour);
    }

    $data = [
        'lastUpdated' => $now,
        'prices'      => $priceData['bitcoin'],
        'fees'        => [
            'fastestFee'   => $fastest,
            'halfHourFee'  => $halfHour,
            'hourFee'      => $hour,
            'economyFee'   => $economy,
            'eightBlockFee'=> $eight
        ]
    ];

    file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    chmod($cacheFile, 0600);

    echo json_encode($data);
} catch (Exception $e) {
    // Fallback: serve old cache if available
    if ($cache) {
        echo json_encode($cache);
    } else {
        http_response_code(502);
        echo json_encode(['error' => 'Price/fee data unavailable']);
    }
}

