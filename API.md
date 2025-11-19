Here’s the **full `README.md`** for your `api.php` endpoint, with the currency support integrated and written as a complete manual:

---

```markdown
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

---

## 🔑 Request Parameters

| Parameter     | Required | Type   | Description                                                                 |
|---------------|----------|--------|-----------------------------------------------------------------------------|
| `address`     | ✅       | string | Destination Bitcoin address (P2PKH, P2SH, Bech32).                          |
| `amount`      | ✅       | number | Payment amount. Interpreted as fiat unless `amount_btc=1`.                  |
| `amount_btc`  | ❌       | flag   | If set to `1`, `amount` is treated as BTC directly.                         |
| `currency`    | ❌       | string | Fiat currency code (default `AUD`). Supported: AUD, USD, EUR, GBP, JPY, CAD, NZD, CHF, SEK, NOK, DKK, SGD, HKD, CNY, INR, ZAR, BRL, MXN, TRY, PLN. Ignored if `amount_btc=1`. |
| `rate`        | ❌       | number | Override fiat/BTC rate. Useful for testing or fallback.                     |
| `blocks`      | ❌       | int    | Target confirmation blocks (default: 8).                                   |
| `extra_sats`  | ❌       | int    | Fixed extra sats added to fee (default: 709).                              |
| `label`       | ❌       | string | Optional label for the payment URI.                                        |
| `message`     | ❌       | string | Optional message (e.g., order ID).                                         |
| `format`      | ❌       | string | Response format: `json` or `png` (default: `png`).                         |

---

## 📤 Responses

### ✅ Success (PNG)
- **Content-Type:** `image/png`  
- **Body:** Binary PNG QR code encoding the BIP21 URI.

### ✅ Success (JSON)
If `format=json` is specified:

```json
{
  "uri": "bitcoin:18NRM5Sg71FXTmFkZTC19TC?amount=0.00007167&label=Willtech&message=Order%20#123",
  "amount_btc": "0.00007167",
  "rate_currency_per_btc": "154858.00",
  "currency": "AUD",
  "qr_png_data_url": "data:image/png;base64,iVBORw0KGgoAAAANS..."
}
```

### ❌ Error (JSON)
Minimal descriptive errors:

```json
{"error":"missing_address"}
{"error":"invalid_address"}
{"error":"missing_amount"}
{"error":"invalid_amount"}
{"error":"rate_unavailable"}
{"error":"unsupported_currency"}
{"error":"qr_failed"}
```

---

## ⚙️ Fee Model

- **Blocks target:** Default `8` (approx. ~8 blocks confirmation).  
- **Extra sats:** Default `709`.  
- **Fee calculation:**  
  - Miner fee estimated via `estimateFeeSats(blocks)` (stubbed).  
  - Converted to BTC and added to the base amount.  

Formula:

\[
BTC_{due} = BTC_{base} + \frac{Fee_{sats}}{100{,}000{,}000}
\]

---

## 🧮 Amount Handling

- **BTC input (`amount_btc=1`):**  
  - `amount` is taken directly as BTC.  
  - Fee (sats → BTC) is added.  

- **Fiat input (default AUD):**  
  - Requires valid fiat/BTC rate from `/data/prices.json`.  
  - Conversion:  

    \[
    BTC_{base} = \frac{FiatAmount}{Rate_{currency}}
    \]

  - Fee (sats → BTC) is added.  

---

## 📚 Examples

### Example 1: AUD → BTC → QR PNG
```
GET /api.php?address=18NRM5Sg71FXTmFkZTC19TC&amount=10&currency=AUD&format=png
```
- Returns PNG QR code for ~0.00007167 BTC.

---

### Example 2: BTC direct → JSON
```
POST /api.php
{
  "address": "18NRM5Sg71FXTmFkZTC19TC",
  "amount": "0.00005",
  "amount_btc": "1",
  "format": "json"
}
```

Response:
```json
{
  "uri": "bitcoin:18NRM5Sg71FXTmFkZTC19TC?amount=0.00005709",
  "amount_btc": "0.00005709",
  "rate_currency_per_btc": null,
  "currency": "BTC",
  "qr_png_data_url": "data:image/png;base64,..."
}
```

---

## 🛡️ Error Codes

| Error                | Meaning                                      |
|----------------------|----------------------------------------------|
| `missing_address`    | No address provided.                         |
| `invalid_address`    | Address format not recognized.               |
| `missing_amount`     | No amount provided.                          |
| `invalid_amount`     | Amount not numeric or ≤ 0.                   |
| `rate_unavailable`   | Fiat conversion requested but no rate found. |
| `unsupported_currency` | Currency not found in `/data/prices.json`. |
| `qr_failed`          | QR generation failed.                        |

---

## 📜 Credits

- Artifact lineage: **Reaper Harvester / Willtech**  
- Built with Microsoft Copilot collaboration.  
- ©2025 Willtech – All rights reserved.  

---
```

---

### 🔧 Remaining Placeholders
- **Fee estimation:** Currently a heuristic stub. Replace with mempool.space API or your own node’s fee estimator.  
- **QR generation:** Currently calls a remote QR service. You can replace this with:
  - **Server-side:** A PHP QR library (e.g., `phpqrcode`) for local generation.  
  - **Client-side:** Your existing `qrcode.min.js` (from your repo) can be used in the browser to render QR codes dynamically from the BIP21 URI, avoiding server-side PNG generation.  

Would you like me to show you how to wire `qrcode.min.js` into the **JSON response workflow**, so the API only returns the URI and your frontend renders the QR locally? That would eliminate the external PNG dependency.
