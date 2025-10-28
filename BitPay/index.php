<?php
/**
 * File: index.php
 * Project: BTC Pay Calculator (PHP/HTML/JS)
 * Description:
 *   Main UI for keypad-style currency-to-Bitcoin converter with QR code output.
 *   Currency selection (session + per-user), Bitcoin address entry (guest OK),
 *   live BTC price via PHP proxy, fee estimate for ~8 blocks via PHP proxy,
 *   and a better fee model based on address/script type.
 *
 * Credits:
 *   - Reaper Harvester / Wills / master Damian Williamson Grad.
 *     (Architect, Ritual Technologist, Systems Designer)
 *   - Microsoft Copilot (AI Companion, collaborative co-author)
 *
 * QR Code generation: qrcode.js by davidshimjs, forked by Willtech
 *
 * Date: 2025-10-28
 * Reaper Harvester / Wills / Professor. Damian A. James Williamson Grad. — with Microsoft Copilot as collaborative co‑author; qrcode.js by davidshimjs (forked by Willtech).
 */

session_start();

// HTTPS gentle reminder (cannot force, but encourage)
$httpsRecommended = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

// CSRF token for settings
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Defaults
$defaultCurrency = 'AUD';

// Load user settings if logged in
$userSettings = [
  'currency' => $defaultCurrency,
  'btcAddress' => ''
];

$settingsFile = __DIR__ . '/data/settings.json';
if (isset($_SESSION['username']) && file_exists($settingsFile)) {
  $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
  if (isset($settings[$_SESSION['username']])) {
    $userSettings['currency'] = $settings[$_SESSION['username']]['currency'] ?? $defaultCurrency;
    $userSettings['btcAddress'] = $settings[$_SESSION['username']]['btcAddress'] ?? '';
  }
}

// If not logged in, allow session currency override
if (!isset($_SESSION['username']) && isset($_SESSION['currency'])) {
  $userSettings['currency'] = $_SESSION['currency'];
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>BTC Pay Calculator</title>
<style>
  :root {
    --blue: #1E90FF;         /* DodgerBlue */
    --midnight: #191970;     /* MidnightBlue */
    --white: #FFFFFF;
  }
  body {
    margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    background: linear-gradient(180deg, var(--midnight), #0f1440);
    color: var(--white);
    display: grid; place-items: center; min-height: 100vh;
  }
  .app {
    width: 380px; max-width: 94vw; background: #0c1133; border: 1px solid rgba(255,255,255,0.12);
    border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.5); overflow: hidden;
  }
  .topbar {
    background: var(--midnight); padding: 12px 14px; display: flex; gap: 8px; align-items: center; justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.08);
  }
  .topbar .left { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  select, input[type="text"] {
    background: #0b102f; color: var(--white); border: 1px solid rgba(255,255,255,0.18);
    padding: 8px 10px; border-radius: 10px; outline: none; min-width: 88px;
  }
  .logout-link { margin-left: 4px; font-size: 12px; color: #9fb7ff; text-decoration: none; }
  .logout-link:hover { color: #ffffff; text-decoration: underline; }
  .screen {
    padding: 14px; background: #0c1133; display: grid; gap: 10px;
  }
  .amount {
    font-size: 28px; font-weight: 600; letter-spacing: 0.5px; background: #0b102f;
    padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.18);
    display: flex; justify-content: space-between; align-items: baseline;
  }
  .currency-tag {
    font-size: 13px; color: #9fb7ff; padding: 2px 8px; background: rgba(30,144,255,0.12);
    border: 1px solid rgba(30,144,255,0.35); border-radius: 100px;
  }
  .btc { font-size: 14px; color: #cfe0ff; opacity: 0.9; }
  #lastUpdated { font-size: 12px; color: #9fb7ff; }
  .keypad {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 14px;
    background: #0b102f; border-top: 1px solid rgba(255,255,255,0.08);
  }
  .key {
    background: #0d1340; color: var(--white); border: 1px solid rgba(255,255,255,0.14);
    padding: 16px 0; border-radius: 12px; text-align: center; font-size: 20px; cursor: pointer;
    user-select: none; transition: transform 0.02s ease, border-color 0.15s ease, background 0.15s ease;
  }
  .key:hover { border-color: var(--blue); background: #10185a; }
  .key:active { transform: scale(0.98); }
  .key.wide { grid-column: span 3; background: var(--blue); border-color: var(--blue); font-weight: 700; }
  .row-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 0 14px 14px; }
  .action { padding: 10px; font-size: 14px; background: #0d1340; border: 1px solid rgba(255,255,255,0.14); border-radius: 10px; cursor: pointer; text-align: center; }
  .action:hover { border-color: var(--blue); background: #10185a; }
  .notice { font-size: 12px; color: #b8c9ff; padding: 6px 14px 12px; }
  .qr-overlay {
    position: absolute; inset: 0; display: none; place-items: center; backdrop-filter: blur(4px);
    background: rgba(10, 14, 40, 0.55);
  }
  .qr-card {
    background: #0b102f; border: 1px solid rgba(255,255,255,0.18); border-radius: 16px; padding: 18px; width: 300px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.6); text-align: center;
  }
  .qr-card h3 { margin: 0 0 10px 0; font-size: 16px; color: #cfe0ff; }
  .qr-card #qrcode { display: flex; justify-content: center; align-items: center; margin: 12px auto; }
  .qr-card .close {
    margin-top: 12px; cursor: pointer; background: var(--blue); border: none; color: var(--white);
    padding: 8px 12px; border-radius: 10px; font-weight: 600;
  }
  .authbar { padding: 10px 14px; display: flex; gap: 8px; justify-content: space-between; font-size: 13px; color: #9fb7ff; }
  .authbar a { color: #9fb7ff; text-decoration: none; }
  .authbar a:hover { color: var(--white); text-decoration: underline; }
  .container-rel { position: relative; }
  .warn { color:#ffb3b3; font-size:12px; }
  .ok { color:#9ef19e; font-size:12px; }
</style>
</head>
<body>
<div class="app">
  <div class="topbar">
    <div class="left">
      <select id="currencySelect">
        <?php foreach ($currencies as $c): ?>
          <option value="<?= htmlspecialchars($c['code']) ?>" <?= $userSettings['currency']===$c['code']?'selected':''; ?>>
            <?= htmlspecialchars($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="btcAddress" placeholder="Bitcoin address" value="<?= htmlspecialchars($userSettings['btcAddress']) ?>" />
    </div>
    <div class="right">
      <?php if (isset($_SESSION['username'])): ?>
        <span>Signed in: <?= htmlspecialchars($_SESSION['username']) ?></span><br>
        <a href="auth.php?logout=1" class="logout-link">(Logout)</a>
      <?php else: ?>
        <span>Guest</span>
      <?php endif; ?>
    </div>

  </div>

  <div class="screen">
    <div class="amount">
      <span id="amountDisplay">0</span>
      <span class="currency-tag" id="currencyTag"><?= htmlspecialchars($userSettings['currency']) ?></span>
    </div>
    <div class="btc">BTC due (incl. est. fee for ~8 blocks +709sat): <strong id="btcDue">0.00000000</strong></div>
    <div class="btc">Price: 1 BTC = <span id="btcPrice">—</span> <span id="btcPriceCur"><?= htmlspecialchars($userSettings['currency']) ?></span></div>
    <div class="btc">Last updated: <span id="lastUpdated">—</span></div>
    <div class="btc">Address check: <span id="addrStatus" class="warn">Awaiting input</span></div>
    <?php if (!$httpsRecommended): ?>
      <div class="warn">Tip: Enable HTTPS for production to protect credentials and sessions.</div>
    <?php endif; ?>
  </div>

  <div class="container-rel">
    <div class="keypad" id="keypad">
      <div class="key">7</div><div class="key">8</div><div class="key">9</div>
      <div class="key">4</div><div class="key">5</div><div class="key">6</div>
      <div class="key">1</div><div class="key">2</div><div class="key">3</div>
      <div class="key">0</div><div class="key">.</div><div class="key" id="backspace">⌫</div>
      <div class="key wide" id="enterKey">Enter / Pay</div>
    </div>

    <div class="row-actions">
      <div class="action" id="clear">Clear</div>
      <div class="action" id="saveSettings">Save</div>
      <div class="action" id="refreshPrice">Refresh</div>
    </div>

    <div class="notice">
      Enter amount in selected currency. Price and fees update via server proxies. QR encodes BIP21 URI with address and total BTC.
    </div>

    <div class="qr-overlay" id="qrOverlay">
      <div class="qr-card">
        <h3>Scan to pay</h3>
        <div id="qrcode"></div>
        <button class="close" id="closeQR">Close</button>
      </div>
    </div>
  </div>

  <div class="authbar">
    <div>
      <?php if (!isset($_SESSION['username'])): ?>
        <a href="auth.php">Login</a>
      <?php else: ?>
        <a href="auth.php?logout=1">Logout</a>
      <?php endif; ?>
    </div>
    <div>
      <span>©2025 Reaper Harvester / Wills — with Microsoft Copilot — <a href="https://www.willtech.com.au/shop/terms-and-conditions/info_3.html" name="Terms of Service">Terms of Service</a></span>
    </div>
  </div>
</div>

<script src="assets/qrcode.min.js"></script>
<script>
(function(){
  const currencySelect = document.getElementById('currencySelect');
  const currencyTag = document.getElementById('currencyTag');
  const amountDisplay = document.getElementById('amountDisplay');
  const btcDueEl = document.getElementById('btcDue');
  const btcPriceEl = document.getElementById('btcPrice');
  const btcPriceCurEl = document.getElementById('btcPriceCur');
  const lastUpdatedEl = document.getElementById('lastUpdated');
  const keypad = document.getElementById('keypad');
  const backspace = document.getElementById('backspace');
  const enterKey = document.getElementById('enterKey');
  const clearBtn = document.getElementById('clear');
  const refreshBtn = document.getElementById('refreshPrice');
  const saveBtn = document.getElementById('saveSettings');
  const btcAddressEl = document.getElementById('btcAddress');
  const addrStatus = document.getElementById('addrStatus');
  const qrOverlay = document.getElementById('qrOverlay');
  const closeQR = document.getElementById('closeQR');
  const qrcodeContainer = document.getElementById('qrcode');

  let amountStr = '0';
  let btcPrice = null; // fiat per BTC
  let feeRateSatPerVb = null; // sat/vB target ~8 blocks
  let assumedVSizeVb = 225;   // default (P2PKH)
  const csrf = "<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>";

  function setAmountStr(s) {
    const parts = s.split('.');
    if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
    s = s.replace(/^0+(?=\d)/, '');
    if (s === '' || s === '.') s = '0';
    amountStr = s;
    amountDisplay.textContent = s;
    currencyTag.textContent = currencySelect.value;
    btcPriceCurEl.textContent = currencySelect.value;
    computeBTC();
  }

  function addressType(addr) {
    // Rough identification for fee-size estimation
    const a = addr.trim();
    if (/^(bc1)[a-z0-9]{11,71}$/i.test(a)) return 'bech32'; // bech32 mainnet typical lengths
    if (/^1[1-9A-HJ-NP-Za-km-z]{20,40}$/.test(a)) return 'p2pkh'; // Base58 P2PKH
    if (/^3[1-9A-HJ-NP-Za-km-z]{20,40}$/.test(a)) return 'p2sh';  // Base58 P2SH
    return 'unknown';
  }

  function updateVSizeByAddress(addr) {
    const t = addressType(addr);
    // Typical single-input to one output sizes (approx):
    // P2PKH ~ 225 vB, P2SH-P2WPKH ~ 180 vB, P2WPKH ~ 141 vB
    if (t === 'bech32') assumedVSizeVb = 141;
    else if (t === 'p2sh') assumedVSizeVb = 180;
    else if (t === 'p2pkh') assumedVSizeVb = 225;
    else assumedVSizeVb = 225;
  }

  function displayAddrStatus() {
    const a = btcAddressEl.value.trim();
    if (!a) { addrStatus.textContent = 'Awaiting input'; addrStatus.className = 'warn'; return; }
    const t = addressType(a);
    if (t === 'bech32') { 
      addrStatus.textContent = 'Bech32 (P2WPKH/P2WSH?)'; addrStatus.className = 'ok';
      monitorAddress(a);
    }
    else if (t === 'p2pkh') {
      addrStatus.textContent = 'P2PKH (legacy)'; addrStatus.className = 'ok';
      monitorAddress(a);
    }
    else if (t === 'p2sh') { 
      addrStatus.textContent = 'P2SH (compat)'; addrStatus.className = 'ok';
      monitorAddress(a);
    }
    else { addrStatus.textContent = 'Unrecognized format'; addrStatus.className = 'warn'; }
    monitorAddress(a);
    updateVSizeByAddress(a);
    computeBTC();
  }

  function computeBTC() {
    if (!btcPrice) { btcDueEl.textContent = '—'; return; }
    const amtFiat = parseFloat(amountStr || '0') || 0;
    const btcNoFee = amtFiat / btcPrice;
    let feeBtc = 0;
    if (feeRateSatPerVb != null) {
      const feeSat = feeRateSatPerVb * assumedVSizeVb;
      feeBtc = feeSat / 100000000;
    }
    const totalBtc = btcNoFee + feeBtc + 0.00000709;
    btcDueEl.textContent = totalBtc.toFixed(8);
  }

async function fetchPriceAndFees() {
    try {
      const cur = currencySelect.value.toLowerCase();
      const res = await fetch('price.php', { cache: 'no-store' });
      const data = await res.json();

      if (data.prices && typeof data.prices[cur] === 'number') {
        btcPrice = data.prices[cur];
        btcPriceEl.textContent = btcPrice.toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
        // Use server-supplied lastUpdated timestamp
        if (data.lastUpdated) {
          const dt = new Date(data.lastUpdated * 1000);
          const day = String(dt.getDate()).padStart(2, '0');
          const monthNames = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
          const month = monthNames[dt.getMonth()];
          const year = dt.getFullYear();
          let hours = dt.getHours();
          const ampm = hours >= 12 ? 'PM' : 'AM';
          hours = hours % 12;
          hours = hours ? hours : 12;
          const minutes = String(dt.getMinutes()).padStart(2, '0');
          const seconds = String(dt.getSeconds()).padStart(2, '0');
          const formatted = `${day} ${month} ${year} ${hours.toString().padStart(2,'0')}:${minutes}:${seconds} ${ampm}`;
          lastUpdatedEl.textContent = formatted;
        }
      }

      if (data.fees) {
        if (typeof data.fees.slow === 'number') {
          feeRateSatPerVb = data.fees.slow;
        } else if (typeof data.fees.medium === 'number') {
          feeRateSatPerVb = data.fees.medium;
        }
      }

      computeBTC();
    } catch (e) {
      console.warn('Proxy fetch fail', e);
    }
  }

  function showQR() {
    const addr = btcAddressEl.value.trim();
    const t = addressType(addr);
    if (!addr) { alert('Enter your Bitcoin address first.'); return; }
    if (t === 'unknown') {
      if (!confirm('Address format not recognized. Continue anyway?')) return;
    }
    const btcAmt = btcDueEl.textContent;
    const msg = encodeURIComponent(`${amountStr} ${currencySelect.value}`);
    const uri = `bitcoin:${addr}?amount=${btcAmt}&label=PayTo&message=${msg}`;
    qrcodeContainer.innerHTML = '';
    new QRCode(qrcodeContainer, {
      text: uri, width: 240, height: 240, colorDark: "#ffffff", colorLight: "#0b102f"
    });
    qrOverlay.style.display = 'grid';
  }

  keypad.addEventListener('click', (e) => {
    const key = e.target.closest('.key');
    if (!key) return;
    const val = key.textContent;
    if (key === enterKey) { showQR(); return; }
    if (key === backspace) {
      if (amountStr.length <= 1) setAmountStr('0');
      else setAmountStr(amountStr.slice(0, -1));
      return;
    }
    if (/^\d$/.test(val)) {
      setAmountStr(amountStr === '0' ? val : amountStr + val);
    } else if (val === '.') {
      if (!amountStr.includes('.')) setAmountStr(amountStr + '.');
    }
  });

  clearBtn.addEventListener('click', () => setAmountStr('0'));
  refreshBtn.addEventListener('click', () => fetchPriceAndFees());
  closeQR.addEventListener('click', () => { qrOverlay.style.display = 'none'; });

  currencySelect.addEventListener('change', async () => {
    // Update the labels
    btcPriceCurEl.textContent = currencySelect.value;
    currencyTag.textContent = currencySelect.value;

    // Recalculate immediately with whatever btcPrice we currently have
    computeBTC();

    // Then fetch the new price + fees asynchronously
    fetchPriceAndFees();

    // Save preference silently
    try {
      await fetch('save_settings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          currency: currencySelect.value,
          btcAddress: btcAddressEl.value,
          csrf_token: csrf
        })
      });
    } catch {}
  });

  btcAddressEl.addEventListener('input', displayAddrStatus);

  saveBtn.addEventListener('click', async () => {
    try {
      const res = await fetch('save_settings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ currency: currencySelect.value, btcAddress: btcAddressEl.value, csrf_token: csrf })
      });
      const data = await res.json();
      if (data && data.ok) alert('Settings saved.');
      else alert(data.error || 'Could not save settings.');
    } catch (e) { alert('Could not save settings.'); }
  });

function showNotification(amount) {
    const note = document.createElement('div');
    note.innerHTML = `
        <div style="
            position:fixed;
            top:20px;
            left:50%;
            transform:translateX(-50%);
            background:#222;
            color:#fff;
            padding:15px 25px;
            border-radius:8px;
            box-shadow:0 4px 10px rgba(0,0,0,0.3);
            font-family:sans-serif;
            text-align:center;
            z-index:9999;
        ">
            <strong>Payment Received</strong><br>
            in mempool<br>
            BTC ${amount}
        </div>
    `;
    document.body.appendChild(note);

    setTimeout(() => {
        note.remove();
    }, 5000);
}

function monitorAddress(address) {
    setInterval(() => {
        fetch(`monitor_mempool_address.php?address=${encodeURIComponent(address)}`)
            .then(res => res.json())
            .then(data => {
                if (data.received) {
                    showNotification(data.amount_btc.toFixed(8));
                }
            })
            .catch(err => console.error(err));
    }, 10000); // every 10 seconds
}

// Example: call this after user enters their address in GUI
// monitorAddress("18NRM5Sg71FXTmFkZTC19TGDmpqJxfpjg7");

  // Initialize
  setAmountStr(amountDisplay.textContent || '0');
  displayAddrStatus();
  fetchPriceAndFees();
})();
</script>
</body>
</html>
