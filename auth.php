<?php
/**
 * File: auth.php
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

session_start();

define('PASS_DIR', __DIR__ . '/.users/');
define('LOCK_FILE', PASS_DIR . 'lockouts.json');
define('SALT', 'Use_Salt');
define('MORESALT', 'Use_More_Salt');
define('FRONT_SALT', 'Front_Salt');

if (!file_exists(PASS_DIR)) {
    mkdir(PASS_DIR, 0700, true);
}

// --- Utility: compute filename ---
function credentialFileName($username, $password) {
//    $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username); // Username can be non-English
    $hash = hash('sha256', $username . $password . SALT);
    return substr($hash, 0, 16) . '.dat';
}

// --- Utility: lockout tracking ---
function loadLockouts() {
    return file_exists(LOCK_FILE) ? json_decode(file_get_contents(LOCK_FILE), true) : [];
}
function saveLockouts($lockouts) {
    file_put_contents(LOCK_FILE, json_encode($lockouts));
}
function recordFail($username) {
    $lockouts = loadLockouts();
    $entry = $lockouts[$username] ?? ['fails' => 0, 'lockUntil' => 0];
    if (time() < $entry['lockUntil']) return false;
    $entry['fails']++;
    if ($entry['fails'] >= 3) {
        $entry['lockUntil'] = time() + 300; // 5 minutes
        $entry['fails'] = 0;
    }
    $lockouts[$username] = $entry;
    saveLockouts($lockouts);
    return true;
}
function clearFails($username) {
    $lockouts = loadLockouts();
    unset($lockouts[$username]);
    saveLockouts($lockouts);
}

// --- Create user ---
function createUser($username, $password) {
    $fileName = credentialFileName($username, $password);
    $filePath = PASS_DIR . $fileName;
    if (file_exists($filePath)) return false;

    $contentHash = hash('sha256', $username . $password . $fileName . MORESALT);
    file_put_contents($filePath, $contentHash);
    chmod($filePath, 0600);

    // --- Update settings.json ---
    $settingsFile = __DIR__ . '/data/settings.json';
    $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : ['users' => []];

    $now = time();
    $settings['users'][$fileName] = [
        'usernameHash'   => $fileName,
        'btcAddress'     => '',   // can be set later
        'currency'       => 'AUD', // default
        'created'        => $now,
        'passwordChanged'=> $now
    ];

    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    chmod($settingsFile, 0600);

    return true;
}

// --- Login ---
function login($username, $password) {
    $lockouts = loadLockouts();
    if (isset($lockouts[$username]) && time() < $lockouts[$username]['lockUntil']) {
        return ['error' => 'Locked until ' . date('H:i:s', $lockouts[$username]['lockUntil'])];
    }

    $fileName = credentialFileName($username, $password);
    $filePath = PASS_DIR . $fileName;
    if (!file_exists($filePath)) {
        recordFail($username);
        return false;
    }

    $storedHash = trim(file_get_contents($filePath));
    $checkHash  = hash('sha256', $username . $password . $fileName . MORESALT);

    if (hash_equals($storedHash, $checkHash)) {
        $_SESSION['username'] = $username;
        clearFails($username);
        return true;
    } else {
        recordFail($username);
        return false;
    }
}

// --- Update password ---
function updatePassword($username, $oldPassword, $newPassword) {
    $oldFile = credentialFileName($username, $oldPassword);
    $oldPath = PASS_DIR . $oldFile;
    if (!file_exists($oldPath)) return false;

    $storedHash = trim(file_get_contents($oldPath));
    $checkHash  = hash('sha256', $username . $oldPassword . $oldFile . MORESALT);
    if (!hash_equals($storedHash, $checkHash)) return false;

    unlink($oldPath);

    $newFile = credentialFileName($username, $newPassword);
    $newPath = PASS_DIR . $newFile;
    $newHash = hash('sha256', $username . $newPassword . $newFile . MORESALT);

    file_put_contents($newPath, $newHash);
    chmod($newPath, 0600);

    // --- Update settings.json ---
    $settingsFile = __DIR__ . '/data/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (isset($settings['users'][$oldFile])) {
            $settings['users'][$newFile] = $settings['users'][$oldFile];
            unset($settings['users'][$oldFile]);
            $settings['users'][$newFile]['usernameHash'] = $newFile;
            $settings['users'][$newFile]['passwordChanged'] = time();
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
            chmod($settingsFile, 0600);
        }
    }

    return true;
}

// --- Logout ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// --- Handle form submission ---
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
    $decoded = base64_decode($_POST['payload']);
    $parts = explode(':', $decoded);

    $username   = isset($parts[0]) ? trim($parts[0]) : '';
    $hash1      = $parts[1] ?? '';
    $datetime   = $parts[2] ?? '';
    $nonce      = $parts[3] ?? '';
    $frontSalt  = $parts[4] ?? '';
    $newHash1   = $parts[5] ?? null;
    $action     = $_POST['action'] ?? 'login';

    // Freshness check (2 minutes)
    if (abs(time() - (int)$datetime) > 10) {
        $error = "Stale request.";
    }
    // Nonce check
    elseif (!isset($_SESSION['nonce']) || $nonce !== $_SESSION['nonce']) {
        $error = "Invalid nonce.";
        recordFail($username);
    }
    // Front salt check
    elseif ($frontSalt !== FRONT_SALT) {
        $error = "Invalid front salt.";
        recordFail($username);
    }
    else {	
        $password = $hash1; // treat hash1 as the password

        if ($action === 'register') {
            if (!createUser($username, $password)) {
                $error = 'User exists or failed to create.';
            } else {
                $_SESSION['username'] = $username;
                unset($_SESSION['nonce']);
                header('Location: index.php');
                exit;
            }
        }
        elseif ($action === 'login') {
            $result = login($username, $password);
            if ($result === true) {
                unset($_SESSION['nonce']);
                header('Location: index.php');
                exit;
            } elseif (is_array($result)) {
                $error = $result['error'];
                recordFail($username);
            } else {
                $error = 'Invalid credentials.';
                recordFail($username);
            }
        }
        elseif ($action === 'update' && $newHash1) {
            // Use provided username + old password hash
            if (updatePassword($username, $password, $newHash1)) {
                $error = 'Password updated.';
            } else {
                $error = 'Failed to update password.';
                recordFail($username);
            }
        }
    }
}

// --- Generate nonce for form ---
$nonce = bin2hex(random_bytes(8));
$_SESSION['nonce'] = $nonce;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Auth</title>
  <style>
    body { background:#0f1440; color:#fff; font-family: system-ui; display:grid; place-items:center; min-height:100vh; }
    .card { background:#0b102f; border:1px solid rgba(255,255,255,0.18); border-radius:14px; padding:18px; width:320px; }
    input, button, select { width:100%; margin-top:8px; padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.18); background:#0d1340; color:#fff; }
    button { background:#1E90FF; border-color:#1E90FF; font-weight:600; }
    a { color:#9fb7ff; text-decoration:none; }
    .err { color:#ffb3b3; margin-top:6px; min-height:20px; }
  </style>
</head>
<body>
<div class="card">
  <h3>Authentication</h3>
  <form id="authForm" method="post">
    <select name="action" id="actionSelect">
      <option value="login">Login</option>
      <option value="register">Register</option>
      <option value="update">Set New Password</option>
    </select>
    <input type="hidden" name="payload" id="payloadField" />
    <input type="text" id="username" placeholder="Username" required />
    <input type="password" id="password" placeholder="Password" required />
    <input type="password" id="newPassword" placeholder="New Password" style="display:none;" />
    <button type="submit">Continue</button>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  </form>
  <p><a href="index.php">Back</a></p>
</div>

<script>
const FRONT_SALT = "<?= FRONT_SALT ?>";
const NONCE = "<?= $nonce ?>";

async function sha256(str) {
  const buf = new TextEncoder().encode(str);
  const hash = await crypto.subtle.digest("SHA-256", buf);
  return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2,"0")).join("");
}

document.getElementById('actionSelect').addEventListener('change', (e) => {
  document.getElementById('newPassword').style.display = (e.target.value === 'update') ? 'block' : 'none';
});

document.getElementById('authForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const action = document.getElementById('actionSelect').value;
  const username = document.getElementById('username').value;
  const password = document.getElementById('password').value;
  const newPassword = document.getElementById('newPassword').value;
  const hash1 = await sha256(password);
  const datetime = Math.floor(Date.now() / 1000); // seconds since epoch

  let payload = username + ":" + hash1 + ":" + datetime + ":" + NONCE + ":" + FRONT_SALT;
  if (action === 'update' && newPassword) {
    const newHash1 = await sha256(newPassword);
    payload += ":" + newHash1;
  }

  document.getElementById('payloadField').value = btoa(payload);
  e.target.submit();
});
</script>
</body>
</html>
