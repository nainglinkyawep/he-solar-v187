<?php
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(array('ok' => false, 'error' => 'POST only'));
  exit;
}

$ADMIN = 'HE-VAULT-4N8C-Q6WM';
$GAS_PASS = 'HE-VAULT-4N8C-Q6WM';
$GAS = 'https://script.google.com/macros/s/AKfycbwO2CJbF9rOD2N3j15706mdCaiyUJ8uVxWFNOwOyl6ws96WbgJymJeTZHQ0h2jQ_MRZYQ/exec';

$in = array();
$raw = file_get_contents('php://input');
if ($raw) {
  $j = json_decode($raw, true);
  if (is_array($j)) $in = $j;
}
if (!$in && !empty($_POST)) $in = $_POST;
$action = isset($in['lic_action']) ? $in['lic_action'] : (isset($in['action']) ? $in['action'] : '');
if ($action === 'license') $action = isset($in['lic_action']) ? $in['lic_action'] : 'activate';
$action = strtolower(trim((string)$action));
$key = isset($in['key']) ? trim((string)$in['key']) : '';
$device = isset($in['device']) ? trim((string)$in['device']) : '';
$os = isset($in['os']) ? trim((string)$in['os']) : 'android';
$pass = isset($in['pass']) ? (string)$in['pass'] : '';

function he_out($code, $obj) {
  http_response_code($code);
  echo json_encode($obj);
  exit;
}
function he_site_ok() {
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
  $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
  $ok = array(
    'https://training.harnessengineering.com',
    'https://www.training.harnessengineering.com',
    'https://harnessengineering.com',
    'https://www.harnessengineering.com',
    'capacitor://localhost',
    'ionic://localhost',
    'http://localhost',
    'https://localhost',
    'null',
    ''
  );
  if (in_array($origin, $ok, true)) return true;
  if (stripos($referer, 'https://training.harnessengineering.com/') === 0) return true;
  if (stripos($referer, 'https://www.training.harnessengineering.com/') === 0) return true;
  if (stripos($referer, 'https://harnessengineering.com/') === 0) return true;
  if (stripos($referer, 'https://www.harnessengineering.com/') === 0) return true;
  return false;
}
function he_rate_ok($bucket, $limit, $window) {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
  $f = sys_get_temp_dir() . '/he-lic-' . $bucket . '-' . hash('sha256', $ip);
  $now = time();
  $hits = array();
  if (is_file($f)) {
    $old = json_decode((string)@file_get_contents($f), true);
    if (is_array($old)) $hits = $old;
  }
  $keep = array();
  foreach ($hits as $t) {
    if (($now - (int)$t) < $window) $keep[] = (int)$t;
  }
  if (count($keep) >= $limit) return false;
  $keep[] = $now;
  @file_put_contents($f, json_encode($keep), LOCK_EX);
  return true;
}
function he_locked() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
  $f = sys_get_temp_dir() . '/he-lock-' . hash('sha256', $ip);
  if (!is_file($f)) return false;
  $old = json_decode((string)@file_get_contents($f), true);
  if (!is_array($old)) return false;
  return isset($old['until']) && (int)$old['until'] > time();
}
function he_lock_fail() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
  $f = sys_get_temp_dir() . '/he-lock-' . hash('sha256', $ip);
  $now = time();
  $n = 0;
  if (is_file($f)) {
    $old = json_decode((string)@file_get_contents($f), true);
    if (is_array($old) && isset($old['n'])) $n = (int)$old['n'];
  }
  $n++;
  $until = ($n >= 8) ? ($now + 1800) : 0;
  if ($n >= 8) $n = 0;
  @file_put_contents($f, json_encode(array('n' => $n, 'until' => $until)), LOCK_EX);
}
function he_lock_clear() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
  @unlink(sys_get_temp_dir() . '/he-lock-' . hash('sha256', $ip));
}
function he_gas_once($url, $sec) {
  $out = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, $sec);
    $out = curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(array('http' => array('timeout' => $sec, 'follow_location' => 1)));
    $out = @file_get_contents($url, false, $ctx);
  }
  if ($out === false || $out === '') return null;
  $j = json_decode($out, true);
  return is_array($j) ? $j : array('ok' => false, 'error' => 'Bad license response');
}
function he_gas($url) {
  return he_gas_once($url, 15);
}

function he_boot_ok() {
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? strtolower(trim((string)$_SERVER['HTTP_ORIGIN'])) : '';
  $referer = isset($_SERVER['HTTP_REFERER']) ? strtolower(trim((string)$_SERVER['HTTP_REFERER'])) : '';
  $allow = array(
    'https://training.harnessengineering.com',
    'https://www.training.harnessengineering.com',
    'https://harnessengineering.com',
    'https://www.harnessengineering.com',
    'capacitor://localhost',
    'ionic://localhost'
  );
  if (in_array($origin, $allow, true)) return true;
  $prefs = array(
    'https://training.harnessengineering.com/',
    'https://www.training.harnessengineering.com/',
    'https://harnessengineering.com/',
    'https://www.harnessengineering.com/'
  );
  foreach ($prefs as $p) {
    if (strpos($referer, $p) === 0) return true;
  }
  return false;
}

function he_client_ip() {
  return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
}
function he_ticket_issue() {
  $ticket = bin2hex(random_bytes(32));
  $f = sys_get_temp_dir() . '/he-ticket-' . hash('sha256', $ticket);
  @file_put_contents($f, json_encode(array('exp' => time() + 90, 'ip' => he_client_ip())), LOCK_EX);
  setcookie('he_gate', $ticket, array(
    'expires' => time() + 90,
    'path' => '/wp-content',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
  ));
}
function he_key() {
  $mask = 'HE-LOCK-176-HARNESS-ENG';
  $blob = base64_decode('egtvPAcVYGhgR3RaJCsHCnN8Ek82LTECN28pPjUFXgcDcUY6JSEIHDchaHg=');
  $out = '';
  $n = strlen($mask);
  for ($i = 0; $i < strlen($blob); $i++) $out .= $blob[$i] ^ $mask[$i % $n];
  return $out;
}
function he_use_issue() {
  $token = bin2hex(random_bytes(24));
  $f = sys_get_temp_dir() . '/he-use-' . hash('sha256', $token);
  @file_put_contents($f, json_encode(array('exp' => time() + 28800, 'ip' => he_client_ip())), LOCK_EX);
  setcookie('he_use', $token, array(
    'expires' => time() + 28800,
    'path' => '/wp-content',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
  ));
  return $token;
}
function he_use_ok() {
  $token = isset($_COOKIE['he_use']) ? (string)$_COOKIE['he_use'] : '';
  if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;
  $f = sys_get_temp_dir() . '/he-use-' . hash('sha256', $token);
  if (!is_file($f)) return false;
  $raw = json_decode((string)@file_get_contents($f), true);
  if (!is_array($raw) || !isset($raw['exp']) || (int)$raw['exp'] < time()) return false;
  if (!isset($raw['ip']) || !hash_equals((string)$raw['ip'], he_client_ip())) return false;
  return true;
}
function he_ticket_take() {
  $ticket = isset($_COOKIE['he_gate']) ? (string)$_COOKIE['he_gate'] : '';
  if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) return false;
  $f = sys_get_temp_dir() . '/he-ticket-' . hash('sha256', $ticket);
  if (!is_file($f)) return false;
  $raw = json_decode((string)@file_get_contents($f), true);
  @unlink($f);
  setcookie('he_gate', '', array(
    'expires' => time() - 3600,
    'path' => '/wp-content',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
  ));
  if (!is_array($raw) || !isset($raw['exp']) || (int)$raw['exp'] < time()) return false;
  if (!isset($raw['ip']) || !hash_equals((string)$raw['ip'], he_client_ip())) return false;
  return true;
}

if (strlen($key) > 48 || strlen($device) > 80 || strlen($os) > 24) he_out(400, array('ok' => false, 'error' => 'bad request'));

if ($action === 'ticket') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_rate_ok('ticket', 120, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  he_ticket_issue();
  he_out(200, array('ok' => true));
}

if ($action === 'boot') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_rate_ok('boot', 120, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  if (!he_ticket_take()) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  if (!function_exists('openssl_encrypt')) he_out(200, array('ok' => false, 'error' => 'Security module missing'));
  $vault = __DIR__ . '/he-vault-V187.php';
  if (!is_file($vault)) he_out(200, array('ok' => false, 'error' => 'he-vault.php မတင်ရသေးပါ'));
  require_once $vault;
  if (!function_exists('he_vault_open')) he_out(200, array('ok' => false, 'error' => 'he-vault.php မတင်ရသေးပါ'));
  $plain = he_vault_open(he_key());
  if (!is_string($plain) || strlen($plain) < 1000) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  $ip = he_client_ip();
  $trace = hash('sha256', $ip . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
  $stamp = '<script>window.__HE_TRACE=' . json_encode($trace) . ';try{sessionStorage.removeItem("he_direct_html");sessionStorage.removeItem("he_direct_once");}catch(e){}setInterval(function(){try{sessionStorage.removeItem("he_direct_html");sessionStorage.removeItem("he_direct_once");}catch(e){}},4000);</script>';
  $pos = strripos($plain, '</body>');
  if ($pos !== false) $plain = substr($plain, 0, $pos) . $stamp . substr($plain, $pos);
  else $plain .= $stamp;
  @file_put_contents(sys_get_temp_dir() . '/he-boot.log', gmdate('c') . "\t" . $trace . "\t" . $ip . "\n", FILE_APPEND | LOCK_EX);
  $useTok = he_use_issue();
  @file_put_contents(sys_get_temp_dir() . '/he-seal-' . hash('sha256', $trace), json_encode(array('exp' => time() + 28800, 'tok' => $useTok)), LOCK_EX);
  $wantWrap = isset($in['v']) && (int)$in['v'] >= 3;
  if ($wantWrap) {
    $pageId = bin2hex(random_bytes(16));
    $pageFile = sys_get_temp_dir() . '/he-page-' . $pageId;
    @file_put_contents($pageFile, json_encode(array('exp' => time() + 90, 'tok' => $useTok, 'html' => $plain)), LOCK_EX);
    $plain = '';
    he_out(200, array('ok' => true, 'id' => $pageId));
  }
  $one = random_bytes(32);
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', $one, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  $plain = '';
  if ($ct === false || strlen($tag) !== 16) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  he_out(200, array('ok' => true, 'k' => base64_encode($one), 'd' => base64_encode($iv . $ct . $tag)));
}

if ($action === 'pulse') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'lock' => true));
  $trace = isset($in['trace']) ? (string)$in['trace'] : '';
  $tok = isset($_COOKIE['he_use']) ? (string)$_COOKIE['he_use'] : '';
  if (!preg_match('/^[a-f0-9]{64}$/', $trace) || !preg_match('/^[a-f0-9]{48}$/', $tok)) he_out(200, array('ok' => false, 'lock' => true));
  $f = sys_get_temp_dir() . '/he-seal-' . hash('sha256', $trace);
  $raw = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
  if (!is_array($raw) || !isset($raw['exp']) || (int)$raw['exp'] < time() || !isset($raw['tok']) || !hash_equals((string)$raw['tok'], $tok)) {
    he_out(200, array('ok' => false, 'lock' => true));
  }
  he_out(200, array('ok' => true));
}

if ($action === 'open') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_rate_ok('open', 30, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  $id = isset($in['id']) ? (string)$in['id'] : '';
  $tok = isset($_COOKIE['he_use']) ? (string)$_COOKIE['he_use'] : '';
  if (!preg_match('/^[a-f0-9]{32}$/', $id) || !preg_match('/^[a-f0-9]{48}$/', $tok)) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  $f = sys_get_temp_dir() . '/he-page-' . $id;
  $rawPage = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
  @unlink($f);
  if (!is_array($rawPage) || !isset($rawPage['exp']) || (int)$rawPage['exp'] < time() || !isset($rawPage['tok']) || !hash_equals((string)$rawPage['tok'], $tok) || !isset($rawPage['html'])) {
    he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  }
  $html = (string)$rawPage['html'];
  $rawPage = null;
  he_out(200, array('ok' => true, 'html' => $html));
}

if ($action === 'growatt') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_use_ok()) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  if (!he_rate_ok('growatt', 40, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  if (!function_exists('openssl_encrypt')) he_out(200, array('ok' => false, 'error' => 'Security module missing'));
  $gfile = __DIR__ . '/he-growatt.php';
  if (!is_file($gfile)) he_out(200, array('ok' => false, 'error' => 'he-growatt.php မတင်ရသေးပါ'));
  require_once $gfile;
  if (!function_exists('he_growatt_open')) he_out(200, array('ok' => false, 'error' => 'he-growatt.php မတင်ရသေးပါ'));
  $plain = he_growatt_open(he_key());
  if (!is_string($plain) || strlen($plain) < 1000) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  $one = random_bytes(32);
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', $one, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  $plain = '';
  if ($ct === false || strlen($tag) !== 16) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  he_out(200, array('ok' => true, 'k' => base64_encode($one), 'd' => base64_encode($iv . $ct . $tag)));
}

if ($action === 'shade') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_use_ok()) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  if (!he_rate_ok('shade', 40, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  if (!function_exists('openssl_encrypt')) he_out(200, array('ok' => false, 'error' => 'Security module missing'));
  $sfile = __DIR__ . '/he-shade.php';
  if (!is_file($sfile)) he_out(200, array('ok' => false, 'error' => 'he-shade.php မတင်ရသေးပါ'));
  require_once $sfile;
  if (!function_exists('he_shade_open')) he_out(200, array('ok' => false, 'error' => 'he-shade.php မတင်ရသေးပါ'));
  $plain = he_shade_open(he_key());
  if (!is_string($plain) || strlen($plain) < 1000) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  $one = random_bytes(32);
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', $one, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  $plain = '';
  if ($ct === false || strlen($tag) !== 16) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  he_out(200, array('ok' => true, 'k' => base64_encode($one), 'd' => base64_encode($iv . $ct . $tag)));
}

if ($action === 'studio') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_use_ok()) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  if (!he_rate_ok('studio', 80, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
  if (!function_exists('openssl_encrypt')) he_out(200, array('ok' => false, 'error' => 'Security module missing'));
  $id = isset($in['id']) ? strtolower(trim((string)$in['id'])) : '';
  $allow = array('as' => 1, 'wf' => 1, 'sm' => 1, 'dw' => 1, 'shade' => 1, 'uniline' => 1, 'frame' => 1);
  if (!isset($allow[$id])) he_out(400, array('ok' => false, 'error' => 'bad request'));
  $sfile = __DIR__ . '/he-studios.php';
  if (!is_file($sfile)) he_out(200, array('ok' => false, 'error' => 'he-studios.php မတင်ရသေးပါ'));
  require_once $sfile;
  if (!function_exists('he_studios_open')) he_out(200, array('ok' => false, 'error' => 'he-studios.php မတင်ရသေးပါ'));
  $pack = he_studios_open(he_key());
  $map = json_decode((string)$pack, true);
  $pack = '';
  if (!is_array($map) || !isset($map[$id]) || !is_string($map[$id]) || strlen($map[$id]) < 500) {
    he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  }
  $plain = $map[$id];
  $map = null;
  $one = random_bytes(32);
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', $one, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  $plain = '';
  if ($ct === false || strlen($tag) !== 16) he_out(200, array('ok' => false, 'error' => 'Security check failed'));
  he_out(200, array('ok' => true, 'k' => base64_encode($one), 'd' => base64_encode($iv . $ct . $tag)));
}

if ($action === 'auth' || $action === 'reset') {
  if (!he_site_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (he_locked()) he_out(429, array('ok' => false, 'error' => 'Locked. Try again later.'));
  if (!he_rate_ok('admin', 8, 900)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));
}
if ($action === 'auth') {
  $good = ($pass !== '' && hash_equals($ADMIN, $pass));
  if (!$good) {
    he_lock_fail();
    he_out(401, array('ok' => false, 'error' => 'Admin password မမှန်ပါ'));
  }
  he_lock_clear();
  he_out(200, array('ok' => true));
}
if ($action === 'reset') {
  if ($pass === '' || !hash_equals($ADMIN, $pass)) {
    he_lock_fail();
    he_out(401, array('ok' => false, 'error' => 'Admin password မမှန်ပါ'));
  }
  he_lock_clear();
  if ($key === '' || !preg_match('/^HE-[A-Z0-9-]{6,40}$/', strtoupper($key))) he_out(400, array('ok' => false, 'error' => 'Key to reset ထည့်ပါ'));
  $url = $GAS . '?action=reset&admin=1&pass=' . rawurlencode($GAS_PASS) . '&key=' . rawurlencode($key) . '&t=' . time();
  $j = he_gas($url);
  if (!$j) he_out(200, array('ok' => false, 'error' => 'License server unreachable'));
  unset($j['pass'], $j['token'], $j['admin']);
  he_out(200, $j);
}

function he_pro_cache_ok($key, $device) {
  $f = sys_get_temp_dir() . '/he-pro-' . hash('sha256', strtoupper((string)$key) . '|' . (string)$device);
  if (!is_file($f)) return false;
  $raw = json_decode((string)@file_get_contents($f), true);
  return is_array($raw) && isset($raw['exp']) && (int)$raw['exp'] >= time();
}
function he_pro_cache_set($key, $device) {
  $f = sys_get_temp_dir() . '/he-pro-' . hash('sha256', strtoupper((string)$key) . '|' . (string)$device);
  @file_put_contents($f, json_encode(array('exp' => time() + 600)), LOCK_EX);
}

if ($action === 'use') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  he_use_issue();
  he_out(200, array('ok' => true));
}

if ($action === 'calc') {
  if (!he_boot_ok()) he_out(403, array('ok' => false, 'error' => 'blocked'));
  if (!he_use_ok()) he_out(403, array('ok' => false, 'error' => 'Server security check failed'));
  if (!he_rate_ok('calc', 240, 600)) he_out(429, array('ok' => false, 'error' => 'Too many calculations. Wait a minute.'));
  $calc = isset($in['calc']) ? strtolower(trim((string)$in['calc'])) : '';
  require_once __DIR__ . '/he-calc.php';
  $proOk = true;
  if (he_calc_needs_pro($calc)) {
    if (!preg_match('/^HE-[A-Z0-9-]{6,40}$/', strtoupper($key))) {
      he_out(200, array('ok' => false, 'need_pro' => true, 'error' => 'Pro feature အတွက် license key လိုအပ်သည်။'));
    }
    if (he_pro_cache_ok($key, $device)) {
      $proOk = true;
    } else {
    $url = $GAS . '?action=check&key=' . rawurlencode($key) . '&device=' . rawurlencode($device) . '&os=' . rawurlencode($os) . '&t=' . time();
    $lic = he_gas($url);
    if (!$lic) he_out(200, array('ok' => false, 'error' => 'License server unreachable. Try again.'));
    $st = strtoupper(isset($lic['status']) ? (string)$lic['status'] : '');
    $proOk = !empty($lic['ok']) && $st === 'ACTIVE' && empty($lic['revoked']);
    if (!$proOk) he_out(200, array('ok' => false, 'need_pro' => true, 'error' => 'Pro feature အတွက် license key လိုအပ်သည်။'));
    he_pro_cache_set($key, $device);
    }
  }
  he_out(200, he_calc_run($calc, $in, $proOk));
}

if (!he_rate_ok('use', 60, 600)) he_out(429, array('ok' => false, 'error' => 'Too many tries. Wait a few minutes.'));

if ($action !== 'activate' && $action !== 'check' && $action !== 'status') {
  he_out(400, array('ok' => false, 'error' => 'Unknown action'));
}
if ($action !== 'status' && !preg_match('/^HE-[A-Z0-9-]{6,40}$/', strtoupper($key))) he_out(400, array('ok' => false, 'error' => 'License key is required'));
$url = $GAS . '?action=' . rawurlencode($action) . '&key=' . rawurlencode($key) . '&device=' . rawurlencode($device) . '&os=' . rawurlencode($os) . '&t=' . time();
$j = he_gas($url);
if (!$j) he_out(200, array('ok' => false, 'error' => 'License server unreachable'));
he_out(200, $j);
