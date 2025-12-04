# BitPay QR Payment API

Standalone API endpoint for **bitpay.willtech.com.au** that generates Bitcoin payment QR codes or returns minimal JSON errors.  
Supports multiple fiat currencies via `/data/prices.json` (updated every 15 minutes by `price.php`).

---

## 📌 Overview

- **File:** `api.php`  
- **Method:** Supports both `GET` and `POST`  
- **Purpose:**  
  - Accepts a Bitcoin address and amount (in fiat or BTC).  
  - Adds estimated miner fees + fixed extra sats.  
  - Builds a BIP21 URI.  
  - Returns a QR code (PNG) or JSON object.  
- **Error handling:** Minimal, descriptive JSON errors only.  
- **Security:**  
  - Integrated SpamBlocker class logs/analyzes traffic.  
  - CSRF token initialized in session.  
  - CORS headers allow cross‑origin requests.  
  - **Supports `OPTIONS` requests:** returns HTTP 204 with no body for preflight checks.  
  - HTTPS strongly recommended in production.

---

## 🔑 Request Parameters

| Parameter     | Required | Type   | Description                                                                 |
|---------------|----------|--------|-----------------------------------------------------------------------------|
| `address`     | ✅       | string | Destination Bitcoin address (P2PKH, P2SH, Bech32).                          |
| `amount`      | ✅       | number | Payment amount. Interpreted as fiat unless `amount_btc=1`.                  |
| `amount_btc`  | ❌       | flag   | If set to `1`, `amount` is treated as BTC directly.                         |
| `currency`    | ❌       | string | Fiat currency code (default `AUD`). Supported: AUD, USD, EUR, GBP, JPY, CAD, NZD, CHF, SEK, NOK, DKK, SGD, HKD, CNY, INR, ZAR, BRL, MXN, TRY, PLN. Ignored if `amount_btc=1`. |
| `rate`        | ❌       | number | Override fiat/BTC rate. Useful for testing or fallback.                     |
| `blocks`      | ❌       | int    | Target confirmation blocks (default: 8). Selects fee tier.                  |
| `extra_sats`  | ❌       | int    | Extra sats added to fee (default: 709).                                    |
| `label`       | ❌       | string | Optional label for the payment URI.                                        |
| `message`     | ❌       | string | Optional message (e.g., order ID).                                         |
| `format`      | ❌       | string | Response format: `json` or `png` (default: `png`).                         |

---

## 📤 Responses

### ✅ Success (PNG)
- **Content-Type:** `image/png`  
- **Body:** Binary PNG QR code encoding the BIP21 URI.  
- QR generated at 512×512, ECC=H, margin=1.

### ✅ Success (JSON)
If `format=json` is specified:

```json
{
  "uri": "bitcoin:18NRM5Sg71FXTmFkZTC19TC?amount=0.00007167&label=Willtech&message=Order%20#123",
  "amount_btc": "0.00007167",
  "rate_currency_per_btc": 154858.00,
  "currency": "AUD",
  "qr_png_data_url": "data:image/png;base64,iVBORw0KGgoAAAANS..."
}
```

### ❌ Error (JSON)
- **Validation errors:** HTTP 400 with concise JSON, e.g.  
  ```json
  { "error": "invalid_address" }
  ```
- **Rate unavailable:** Returned when `/data/prices.json` is missing, stale (>900s), or malformed.  
  ```json
  {
    "error": "rate_unavailable",
    "cache": { "lastUpdated": 1733380000, "prices": {}, "fees": {} }
  }
  ```
- **QR failure:** If external QR service fails.  
  ```json
  { "error": "qr_failed" }
  ```

---

## ⚙️ Fee Model
- Fee tiers sourced from mempool.space via `prices.json`:  
  - ≤1 block → fastestFee  
  - ≤3 blocks → halfHourFee  
  - ≤6 blocks → hourFee  
  - ≤8 blocks → eightBlockFee  
  - >8 blocks → economyFee  
- Fee = rate × txSize (≈180 vB). Minimum 1000 sats.  
- `extra_sats` (default 709) added after estimation.

---

## 📂 Price Cache
- File: `BitPay/data/prices.json`  
- Updated by `price.php` every 15 minutes.  
- Structure:  
  - `lastUpdated` (epoch seconds)  
  - `prices` (map of fiat codes to BTC rate)  
  - `fees` (fastestFee, halfHourFee, hourFee, economyFee, eightBlockFee)  
- TTL: 900 seconds. If stale, API returns `rate_unavailable`.

---

## 🖥️ Curl Usage Examples

### Request PNG QR Code
```bash
curl "https://bitpay.willtech.com.au/api.php?address=bc1qexampleaddress&amount=50&currency=AUD"
```
- Returns a PNG image (binary).  
- Save to file:  
  ```bash
  curl -o qr.png "https://bitpay.willtech.com.au/api.php?address=bc1qexampleaddress&amount=50&currency=AUD"
  ```

### Request JSON Response
```bash
curl "https://bitpay.willtech.com.au/api.php?address=bc1qexampleaddress&amount=50&currency=AUD&format=json"
```
- Returns JSON with BIP21 URI, BTC amount, fiat rate, and embedded QR PNG data URL.

### Preflight OPTIONS Request
```bash
curl -X OPTIONS -i "https://bitpay.willtech.com.au/api.php"
```
- Returns HTTP 204 with no body.  
- Used by browsers for CORS preflight checks.

---
