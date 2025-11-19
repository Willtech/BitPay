<?php
declare(strict_types=1);

// --- DEBUG (remove after) ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Capture fatals and emit JSON
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'error' => 'fatal',
            'type' => $e['type'],
            'message' => $e['message'],
            'file' => $e['file'],
            'line' => $e['line'],
        ]);
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    // Turn warnings/notices into exceptions so we see them
    throw new ErrorException($message, 0, $severity, $file, $line);
});
// --- END DEBUG ---

/**
 * BitPay QR Payment API (standalone)
 * - Accepts GET or POST.
 * - Returns a QR PNG (or JSON if format=json) with a BIP21 bitcoin URI.
 * - Minimal errors in JSON.
 * - Currency-aware: uses /data/prices.json updated by price.php every 15 min.
 */
 
 // Include the spam blocker class
use Reaper\Security\SpamBlocker;
include 'spamblock/spam_blocker_class.php';

// Instantiate the spam blocker and execute the control method to log/analyze traffic
$ip = new SpamBlocker();
$ip->spam_blocker_control("spamblock/");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Ensure session is active so we can read CSRF token
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
    
// CSRF token for settings
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Expanded ISO currency list with symbols (common + AU/NZ)
$currencies = [
  ['code' => 'AUD', 'label' => '$ AUD'],
  ['code' => 'USD', 'label' => '$ USD'],
  ['code' => 'EUR', 'label' => '€ EUR'],
  ['code' => 'GBP', 'label' => '£ GBP'],
  ['code' => 'JPY', 'label' => '¥ JPY'],
  ['code' => 'CAD', 'label' => '$ CAD'],
  ['code' => 'NZD', 'label' => '$ NZD'],
  ['code' => 'CHF', 'label' => '₣ CHF'],
  ['code' => 'SEK', 'label' => 'kr SEK'],
  ['code' => 'NOK', 'label' => 'kr NOK'],
  ['code' => 'DKK', 'label' => 'kr DKK'],
  ['code' => 'SGD', 'label' => '$ SGD'],
  ['code' => 'HKD', 'label' => '$ HKD'],	
  ['code' => 'CNY', 'label' => '¥ CNY'],
  ['code' => 'INR', 'label' => '₹ INR'],
  ['code' => 'ZAR', 'label' => 'R ZAR'],
  ['code' => 'BRL', 'label' => 'R$ BRL'],
  ['code' => 'MXN', 'label' => '$ MXN'],
  ['code' => 'TRY', 'label' => '₺ TRY'],
  ['code' => 'PLN', 'label' => 'zł PLN'],
];

$in = array_merge($_GET ?? [], $_POST ?? []);
$format = strtolower(trim((string)($in['format'] ?? ''))) === 'json' ? 'json' : 'png';

// 1) Validate address
$address = trim((string)($in['address'] ?? ''));
if ($address === '') returnError('missing_address', $format);
if (!isValidBitcoinAddress($address)) returnError('invalid_address', $format);

// 2) Validate amount
$amountRaw = trim((string)($in['amount'] ?? ''));
if ($amountRaw === '') returnError('missing_amount', $format);
if (!isValidPositiveNumber($amountRaw)) returnError('invalid_amount', $format);
$amount = (float)$amountRaw;

// 3) BTC vs fiat
$amountIsBTC = isset($in['amount_btc']) && (string)$in['amount_btc'] === '1';
$currency = strtoupper(trim((string)($in['currency'] ?? 'AUD')));

if (!validateCurrency($currency, $currencies)) {
    returnError('unsupported_currency', $format);
    exit;
}

// 4) Load cached prices (robust, tolerant, and PHP 7+ compatible)
$response = fetchPriceAndFees($currency);
$cacheFile = __DIR__ . '/data/prices.json';
$cache = null;
$now = time();

// If amount is BTC we don't need the fiat cache; skip heavy checks
if (!$amountIsBTC) {
    if (!is_readable($cacheFile)) {
        returnErrorWithCache('rate_unavailable', $format, ['error' => 'missing_or_unreadable', 'file' => $cacheFile]);
    }

    $json = @file_get_contents($cacheFile);
    if ($json === false || $json === '') {
        returnErrorWithCache('rate_unavailable', $format, ['error' => 'empty_or_unreadable', 'file' => $cacheFile]);
    }

    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        returnErrorWithCache('rate_unavailable', $format, [
            'error' => 'json_decode_failed',
            'json_error' => json_last_error_msg(),
            'raw' => substr($json, 0, 500) // keep diagnostics small
        ]);
    }

    // Support two shapes:
    // 1) canonical: { "lastUpdated": ..., "prices": {...}, "fees": {...} }
    // 2) wrapped:  { "error": "...", "cache": { "lastUpdated":..., "prices": {...} } }
    if (isset($decoded['cache']) && is_array($decoded['cache'])) {
        $cache = $decoded['cache'];
    } else {
        $cache = $decoded;
    }

    // Validate structure
    if (!isset($cache['lastUpdated']) || !is_numeric($cache['lastUpdated'])) {
        returnErrorWithCache('rate_unavailable', $format, ['error' => 'missing_lastUpdated', 'cache' => $cache]);
    }
    if (!isset($cache['prices']) || !is_array($cache['prices'])) {
        returnErrorWithCache('rate_unavailable', $format, ['error' => 'missing_prices', 'cache' => $cache]);
    }

    // Freshness check (900 seconds = 15 minutes)
    if ($now - (int)$cache['lastUpdated'] > 900) {
        returnErrorWithCache('rate_unavailable', $format, ['error' => 'stale_cache', 'lastUpdated' => (int)$cache['lastUpdated'], 'now' => $now]);
    }
}

// 5) Fee model (unchanged)
$blocks = isset($in['blocks']) && isValidPositiveInteger((string)$in['blocks']) ? (int)$in['blocks'] : 8;
$extraSats = isset($in['extra_sats']) && isValidPositiveInteger((string)$in['extra_sats']) ? (int)$in['extra_sats'] : 709;
$feeSats = estimateFeeSats($blocks);
$totalFeeSats = $feeSats + $extraSats;

// 6) Compute BTC due
if ($amountIsBTC) {
    $btcAmountBase = (float)$amount;
    $rateCurrency = null;
} else {
    // Use lowercase key lookup because prices often store lowercase currency keys
    $prices = isset($cache['prices']) && is_array($cache['prices']) ? $cache['prices'] : [];
    $key = strtolower($currency);
    // Allow numeric strings and ints
    $rateCurrency = null;
    if (isset($prices[$key]) && is_numeric($prices[$key]) && (float)$prices[$key] > 0.0) {
        $rateCurrency = (float)$prices[$key];
    } else {
        // helpful diagnostic: include cache snapshot
        returnErrorWithCache('unsupported_currency', $format, ['requested' => $currency, 'available' => array_keys($prices), 'cache' => $cache]);
    }

    // Convert fiat amount to BTC, round to 8 decimals
    // Avoid division by zero (already guarded by > 0.0)
    $btcAmountBase = round(((float)$amount) / $rateCurrency, 8);
}

$feeBtc = satsToBtc($totalFeeSats);
$btcDue = normalizeBtc($btcAmountBase + $feeBtc);


// 7) Build URI
$label = trim((string)($in['label'] ?? ''));
$message = trim((string)($in['message'] ?? ''));
$uri = buildBip21Uri($address, $btcDue, $label, $message);

// 8) Generate QR
$qrPng = generateQrPng($uri);
if ($qrPng === null) returnError('qr_failed', $format);

// 9) Output
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $dataUrl = 'data:image/png;base64,' . base64_encode($qrPng);
    $out = [
        'uri' => $uri,
        'amount_btc' => number_format($btcDue, 8, '.', ''),
        'rate_currency_per_btc' => $rateCurrency !== null ? number_format($rateCurrency, 2, '.', '') : null,
        'currency' => $amountIsBTC ? 'BTC' : $currency,
        'qr_png_data_url' => $dataUrl,
    ];
    echo json_encode($out);
} else {
    header('Content-Type: image/png');
    echo $qrPng;
}
exit;

/* ---------- Helpers ---------- */

function returnError(string $code, string $format)
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['error' => $code]);
    exit;
}

function returnErrorWithCache($code, $format, $cache)
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    $out = [
        'error' => $code,
        'cache' => $cache,
    ];
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Validate currency against the supported list
 */
function validateCurrency($currency, array $currencies)
{
    $validCodes = array_column($currencies, 'code');
    return in_array(strtoupper((string)$currency), $validCodes, true);
}

/**
 * Positive number check (allows integers and decimals)
 */
function isValidPositiveNumber($s)
{
    $s = (string)$s;
    return preg_match('/^\d+(\.\d+)?$/', $s) === 1 && ((float)$s > 0.0);
}

/**
 * Positive integer check (1,2,3,...)
 */
function isValidPositiveInteger($s)
{
    $s = (string)$s;
    return preg_match('/^[1-9]\d*$/', $s) === 1;
}

/**
 * Basic Bitcoin address validation (Bech32 bc1 and legacy 1/3)
 */
function isValidBitcoinAddress($addr)
{
    $addr = (string)$addr;
    if ($addr === '') {
        return false;
    }

    // Bech32 (lowercase) starts with "bc1"
    if (substr($addr, 0, 3) === 'bc1') {
        return preg_match('/^bc1[ac-hj-np-z02-9]{11,71}$/', $addr) === 1;
    }

    // Legacy/Base58: first char 1 or 3
    $first = substr($addr, 0, 1);
    if ($first === '1' || $first === '3') {
        return preg_match('/^[123][A-Za-z0-9]{25,34}$/', $addr) === 1;
    }

    return false;
}

/**
 * Fee estimation placeholder
 */
function estimateFeeSats($blocks)
{
    $blocks = (int)$blocks;
    if ($blocks < 1) $blocks = 1;
    $oneBlockFee = 100 * 140; // placeholder integer
    $val = round($oneBlockFee / $blocks);
    $val = (int)max(1000, $val);
    return $val;
}

/**
 * Convert sats to BTC (avoid PHP 8 numeric literal underscores)
 */
function satsToBtc($sats)
{
    return ((float)$sats) / 100000000.0;
}

/**
 * Normalize BTC to 8 decimal places and return as float
 */
function normalizeBtc($x)
{
    return (float)number_format((float)$x, 8, '.', '');
}

/**
 * Build a BIP21 URI string
 */
function buildBip21Uri($address, $amountBtc, $label, $message)
{
    $params = array();
    $params[] = 'amount=' . rawurlencode(number_format((float)$amountBtc, 8, '.', ''));
    if ((string)$label !== '') $params[] = 'label=' . rawurlencode((string)$label);
    if ((string)$message !== '') $params[] = 'message=' . rawurlencode((string)$message);
    $qs = implode('&', $params);
    return 'bitcoin:' . $address . ($qs !== '' ? ('?' . $qs) : '');
}

/**
 * Generate QR PNG via external service (guarded)
 * Returns binary PNG or null on failure
 */
function generateQrPng($text)
{
    $chl = rawurlencode((string)$text);
    $size = 512;
    $url = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$chl}&ecc=H&margin=1";

    // Small timeout context to avoid long hangs
    $ctx = stream_context_create(array('http' => array('timeout' => 5)));
    $png = @file_get_contents($url, false, $ctx);
    if ($png === false || $png === '') {
        return null;
    }
    return $png;
}

function fetchPriceAndFees($currency) {

	$cacheFile = __DIR__ . '/data/prices.json';
	$ttl = 900; // 15 minutes
	$now = time();

	// Load cache if exists
	$cache = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;

	// Serve cache if fresh
	if ($cache && isset($cache['lastUpdated']) && ($now - $cache['lastUpdated'] < $ttl)) {
	    return json_encode($cache);
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

	    return json_encode($data);
	} catch (Exception $e) {
	    // Fallback: serve old cache if available
	    if ($cache) {
		return json_encode($cache);
	    } else {
		http_response_code(502);
		return json_encode(['error' => 'Price/fee data unavailable']);
	    }
	}
}
?>
