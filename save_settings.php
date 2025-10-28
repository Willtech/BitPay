<?php
/**
 * File: save_settings.php
 * Project: BTC Pay Calculator (PHP/HTML/JS)
 * Description:
 *   Persists user currency and Bitcoin address settings to flat file.
 *   Supports both guest session storage and per-user storage with CSRF protection.
 *   Performs basic Bitcoin address format validation (Bech32/P2PKH/P2SH).
 *
 * Credits:
 *   - Reaper Harvester / Wills / master Damian Williamson Grad.
 *     (Architect, Ritual Technologist, Systems Designer)
 *   - Microsoft Copilot (AI Companion, collaborative co-author)
 *
 * Date: 2025-10-28
 */

session_start();

header('Content-Type: application/json');

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
}

$currency = $_POST['currency'] ?? null;
$btcAddress = trim($_POST['btcAddress'] ?? '');

function isValidBtcAddress($addr) {
  $a = trim($addr);
  if ($a === '') return true; // allow empty (guest may not want to save)
  // Bech32 (mainnet typical lengths): bc1 + charset
  if (preg_match('/^(bc1)[a-z0-9]{11,71}$/i', $a)) return true;
  // Base58 P2PKH (starts with 1) or P2SH (starts with 3)
  if (preg_match('/^1[1-9A-HJ-NP-Za-km-z]{20,40}$/', $a)) return true;
  if (preg_match('/^3[1-9A-HJ-NP-Za-km-z]{20,40}$/', $a)) return true;
  return false;
}

if ($currency) {
  $_SESSION['currency'] = $currency; // Remember for guest session
}

if ($btcAddress !== '' && !isValidBtcAddress($btcAddress)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid Bitcoin address format.']); exit;
}

$settingsFile = __DIR__ . '/data/settings.json';
if (!file_exists(dirname($settingsFile))) { mkdir(dirname($settingsFile), 0775, true); }
$settings = [];
if (file_exists($settingsFile)) {
  $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}

if (isset($_SESSION['username'])) {
  $u = $_SESSION['username'];
  if (!isset($settings[$u])) $settings[$u] = ['currency' => 'AUD', 'btcAddress' => ''];
  if ($currency)   $settings[$u]['currency'] = $currency;
  $settings[$u]['btcAddress'] = $btcAddress;
  file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
}

echo json_encode(['ok' => true]);

