<?php
/**
 * Project: BitPay – Bitcoin Payment & Monitoring Application
 * File: monitor_mempool_address.php
 * Location: /BitPay/
 *
 * Purpose:
 * --------
 * This script queries the mempool.space API for a given Bitcoin address
 * and returns all unconfirmed transactions currently in the mempool.
 * It is designed to support the front‑end notification system by
 * providing JSON data about txids and amounts received.
 *
 * Features:
 * ---------
 * - Accepts an address via GET parameter (?address=...)
 * - Calls mempool.space API (mainnet/testnet selectable)
 * - Extracts vout entries matching the address
 * - Returns txid and amount (in BTC) for each unconfirmed transaction
 * - Outputs JSON for consumption by index.php
 *
 * Usage:
 * ------
 * Example request:
 *   monitor_mempool_address.php?address=tb1qexample...
 *
 * Example response:
 *   [
 *     {
 *       "txid": "abcd1234ef...",
 *       "amount_btc": 0.00123456
 *     }
 *   ]
 *
 * Notes:
 * ------
 * - Default API base is testnet: https://mempool.space/testnet/api
 * - Switch to mainnet by editing $apiBase
 * - Intended to be polled periodically by index.php
 * - First poll should be treated as initialization (ignore existing txs)
 *
 * Author: Willtech / master Damian Williamson Grad. (Reaper Harvester)
 *   - Microsoft Copilot (AI Companion, collaborative co-author)
 * License: MIT (see LICENSE in project root)
 * Created: 2025‑10‑28
 * Updated: 2025‑10‑30
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
