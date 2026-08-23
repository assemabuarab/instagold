<?php
/**
 * InstaGold secure masked download gateway.
 * - Keeps the real APK URL on the server only.
 * - Generates expiring AES-256-CBC tokens.
 * - Streams the remote file with Range/resume support.
 */

while (ob_get_level()) {
    ob_end_clean();
}

// يفضّل وضع DOWNLOAD_SECURITY_KEY في إعدادات السيرفر/.env.
// المفتاح أدناه احتياطي ثابت حتى تعمل الحزمة مباشرة بعد الرفع.
$securityKey = getenv('DOWNLOAD_SECURITY_KEY') ?: 'DTWP0WBzA1zLJrhDaW3XzlKaMEO85bP70jhQxBbmoxDW4jkXGJkhCPDTutlGw8Z-';

// الروابط الحقيقية تبقى هنا على السيرفر ولا ترسل إلى HTML/JavaScript.
$downloads = [
    'instagold' => [
        'url' => 'https://dlll.apkm.app/Instagram/12.35/InstaGold_v12.35.apk',
        'filename' => 'InstaGold_v12.35.apk',
    ],
];

function base64UrlDecode($value) {
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

function encryptDownloadToken($downloadId, $filename, $key, $ttl = 86400) {
    $payload = json_encode([
        'id' => $downloadId,
        'filename' => $filename,
        'exp' => time() + $ttl,
        'salt' => bin2hex(random_bytes(8)),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $iv = random_bytes(16);
    $aesKey = hash('sha256', $key, true);
    $encrypted = openssl_encrypt($payload, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return false;

    return rtrim(strtr(base64_encode($iv . $encrypted), '+/', '-_'), '=');
}

function decryptDownloadToken($token, $key) {
    $data = base64UrlDecode($token);
    if ($data === false || strlen($data) <= 16) return false;

    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $aesKey = hash('sha256', $key, true);
    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) return false;

    $payload = json_decode($decrypted, true);
    if (!is_array($payload) || empty($payload['id']) || empty($payload['exp'])) return false;
    if (time() > (int)$payload['exp']) return false;

    return $payload;
}

function sendJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// إنشاء رابط مؤقت مشفّر باستخدام معرّف معروف فقط.
if (($_GET['action'] ?? '') === 'encrypt') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    if (!$id || !isset($downloads[$id])) {
        sendJson(['success' => false, 'error' => 'Unknown download id'], 404);
    }

    $item = $downloads[$id];
    $token = encryptDownloadToken($id, $item['filename'], $securityKey);
    if (!$token) {
        sendJson(['success' => false, 'error' => 'Encryption failed'], 500);
    }

    $maskedUrl = 'dl.php?t=' . rawurlencode($token);
    sendJson([
        'success' => true,
        'maskedUrl' => $maskedUrl,
        'expiresIn' => 86400,
    ]);
}

// تنفيذ التنزيل من الـ token المشفّر.
$token = $_GET['t'] ?? $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    exit('Missing or invalid download token.');
}

$payload = decryptDownloadToken($token, $securityKey);
if (!$payload || !isset($downloads[$payload['id']])) {
    http_response_code(403);
    exit('The encrypted download link is invalid or expired.');
}

$item = $downloads[$payload['id']];
$targetUrl = $item['url'];
$filename = $payload['filename'] ?? $item['filename'];
$filename = preg_replace('/[^\w\.\-\p{Arabic}\s]/u', '_', $filename);
if (!pathinfo($filename, PATHINFO_EXTENSION)) {
    $filename .= '.apk';
}

set_time_limit(0);
ignore_user_abort(true);
@ini_set('zlib.output_compression', 'Off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

// معرفة الحجم والنوع إن أمكن.
$contentLength = 0;
$contentType = 'application/vnd.android.package-archive';
$head = curl_init($targetUrl);
curl_setopt_array($head, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
]);
curl_exec($head);
$len = curl_getinfo($head, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$type = curl_getinfo($head, CURLINFO_CONTENT_TYPE);
if ($len > 0) $contentLength = (int)$len;
if ($type) $contentType = $type;
curl_close($head);

$isRangeRequest = false;
$rangeStart = 0;
$rangeEnd = $contentLength > 0 ? $contentLength - 1 : 0;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches)) {
    $rangeStart = (int)$matches[1];
    if ($matches[2] !== '') {
        $rangeEnd = (int)$matches[2];
    }
    if ($contentLength > 0) {
        $rangeEnd = min($rangeEnd, $contentLength - 1);
    }
    $isRangeRequest = true;
}

header('Accept-Ranges: bytes');
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($isRangeRequest && $contentLength > 0) {
    if ($rangeStart > $rangeEnd || $rangeStart >= $contentLength) {
        http_response_code(416);
        header('Content-Range: bytes */' . $contentLength);
        exit;
    }

    $chunkSize = ($rangeEnd - $rangeStart) + 1;
    http_response_code(206);
    header("Content-Range: bytes $rangeStart-$rangeEnd/$contentLength");
    header('Content-Length: ' . $chunkSize);
} elseif ($contentLength > 0) {
    header('Content-Length: ' . $contentLength);
}

$ch = curl_init($targetUrl);
$options = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_BUFFERSIZE => 65536,
    CURLOPT_WRITEFUNCTION => function($curlHandle, $chunk) {
        echo $chunk;
        flush();
        return strlen($chunk);
    },
];
if ($isRangeRequest) {
    $options[CURLOPT_RANGE] = $rangeStart . '-' . $rangeEnd;
}
curl_setopt_array($ch, $options);
$ok = curl_exec($ch);
if ($ok === false && !headers_sent()) {
    http_response_code(502);
}
curl_close($ch);
exit;
