# BitPay – Bitcoin Payment Calculator

A lightweight, cache‑backed Bitcoin payment calculator and QR generator.  
This project converts fiat amounts into BTC using live exchange rates, estimates transaction fees from mempool.space, and produces BIP21‑compliant URIs for wallet integration.

---

## ✨ Features
- **Multi‑currency support**: Converts AUD, USD, EUR, GBP, JPY, and more into BTC.  
- **Server‑side caching**: Prices and fees are cached in `prices.json` to reduce API calls and ensure consistent values.  
- **Fee estimation**: Integrates mempool.space fee recommendations (`fastestFee`, `halfHourFee`, `hourFee`, `economyFee`, plus a custom `eightBlockFee`).  
- **Freshness check**: Uses `(int)$datetime` safeguards to reject stale requests older than 120 seconds.  
- **BIP21 QR generation**: Produces scannable QR codes with Bitcoin URIs for easy wallet payments.  
- **Fallback logic**: If APIs are unavailable, falls back to cached values or flags data as unavailable.  

---

## 📂 Project Structure
```
BitPay/
 ├── .htaccess            # .htaccess
 ├── index.php            # Main calculator interface
 ├── auth.php             # Authentication handler
 ├── price.php            # Fetches and caches prices + fees
 ├── save_settings.php    # Settings handler
 ├── .users/.htaccess     # .htaccess
 ├── .users/lockouts.json # User control
 ├── assets/.htaccess     # .htaccess
 ├── assets/qrcode.min.js # QR Code generator
 ├── data/.htaccess       # .htaccess
 ├── data/prices.json     # Cached data store
 └── data/settings.json   # Settings data store
```

---

## ⚙️ Installation
1. Clone the repository:
   ```bash
   git clone https://github.com/Willtech/BitPay.git
   cd BitPay/BitPay
   ```
2. Ensure PHP 7.4+ and a web server (Apache/Nginx) are installed.  
3. Make the `data/` directory writable for caching:
   ```bash
   chmod 775 data
   ```
4. Open `index.php` in your browser.

---

## 🚀 Usage
1. Enter a fiat amount (e.g. 100 AUD).  
2. The app fetches the latest BTC price and fee estimates.  
3. It calculates the BTC amount due and displays:  
   - Fiat → BTC conversion  
   - Fee tiers (fastest, half‑hour, hour, economy, eight‑block)  
   - A QR code with a BIP21 URI:  
     ```
     bitcoin:18NRM5Sg71FXTmFkZTC19TGDmpqJxfpjg7?amount=0.00123456&label=Willtech
     ```
4. Scan the QR with any Bitcoin wallet to pre‑fill payment details.

---

## 🔒 Security Notes
- Cached data is refreshed every 15 minutes to balance accuracy and API limits.  
- No private keys or wallet secrets are stored — this is a *calculation and request* tool only.

---

## 📜 Credits

- **Reaper Harvester / Wills / master Damian Williamson Grad.**  
  *Architect, Ritual Technologist, Systems Designer*

- **Microsoft Copilot**  
  *AI Companion, collaborative co‑author*

- **QR Code generation**  
  *qrcode.js by davidshimjs, forked and adapted by Willtech*

---

## 📚 References
- [BIP21 – Bitcoin URI Specification](https://bips.xyz/21)  
- [CoinGecko API](https://www.coingecko.com/en/api)  
- [mempool.space API](https://mempool.space/docs/api/rest)

---

