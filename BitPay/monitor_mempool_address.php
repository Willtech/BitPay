<?php
/**
 * File: monitor_mempool_address.php
 * Project: BTC Pay Calculator (PHP/HTML/JS)
 * Description:
 *   Simple flat-file login/register/logout system with password hashing.
 *   Uses sessions and CSRF for basic safety. HTTPS recommended in production.
 *
 * Credits:
 *   - Reaper Harvester / Wills / master Damian Williamson Grad.
 *     (Architect, Ritual Technologist, Systems Designer)
 *   - Microsoft Copilot (AI Companion, collaborative co-author)
 *
 * Date: 2025-10-28
 */
 
header('Content-Type: application/json');

$address = $_GET['address'] ?? '';
if (!$address) {
    echo json_encode(['error' => 'No address provided']);
    exit;
}

$apiBase = "https://mempool.space/api"; // or mainnet
$url = $apiBase . "/address/" . urlencode($address) . "/txs/mempool";

$json = @file_get_contents($url);
if ($json === false) {
    echo json_encode(['error' => 'API request failed']);
    exit;
}

$data = json_decode($json, true);
$results = [];

foreach ($data as $tx) {
    $amount = 0;
    foreach ($tx['vout'] as $out) {
        if ($out['scriptpubkey_address'] === $address) {
            $amount += $out['value']; // sats
        }
    }
    if ($amount > 0) {
        $results[] = [
            'txid' => $tx['txid'],
            'amount_btc' => $amount / 100000000
        ];
    }
}

echo json_encode($results);
