<?php
// monitor_mempool_address.php
header('Content-Type: application/json');

$address = isset($_GET['address']) ? trim($_GET['address']) : '';
if ($address === '') {
    echo json_encode(['error' => 'No address provided']);
    exit;
}

$apiUrl = "https://mempool.space/api/address/" . urlencode($address) . "/txs/mempool";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['error' => 'API request failed']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'Invalid API response']);
    exit;
}

// If there are mempool transactions, return the first one’s value
if (count($data) > 0) {
    $tx = $data[0]; // first mempool transaction
    $amount = 0;
    foreach ($tx['vout'] as $out) {
        if ($out['scriptpubkey_address'] === $address) {
            $amount += $out['value']; // sats
        }
    }
    echo json_encode([
        'received' => true,
        'txid' => $tx['txid'],
        'amount_btc' => $amount / 100000000
    ]);
} else {
    echo json_encode(['received' => false]);
}

