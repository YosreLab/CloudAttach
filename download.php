<?php
/**
 * 独立的文件下载代理脚本
 * 完全绕过 Typecho 加载，避免输出污染
 */

// 设置最大执行时间
set_time_limit(300);

// 立即清空所有输出缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 启用错误显示（调试用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 禁用输出压缩
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');

// 获取文件 URL
$fileUrl = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($fileUrl)) {
    http_response_code(400);
    die('Missing URL parameter');
}

// 验证 URL
if (!filter_var($fileUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $fileUrl)) {
    http_response_code(400);
    die('Invalid URL: ' . htmlspecialchars($fileUrl));
}

// 从 URL 中提取文件名
$fileName = basename(parse_url($fileUrl, PHP_URL_PATH));
if (empty($fileName)) {
    $fileName = 'download';
}
$fileName = urldecode($fileName);

// 先获取文件大小
$ch = curl_init($fileUrl);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_exec($ch);
$fileSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    die('HTTP Error: ' . $httpCode . ' - Failed to access file from CDN');
}

// 再次清空缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 设置响应头
header('Content-Type: application/octet-stream');
header('Content-Transfer-Encoding: binary');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
if ($fileSize > 0) {
    header('Content-Length: ' . $fileSize);
}
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 使用流式传输（不占用内存）
$ch = curl_init($fileUrl);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

// 直接输出到浏览器（流式传输）
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);

$result = curl_exec($ch);
$error = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($result === false) {
    error_log('cURL Error (' . $errno . '): ' . $error);
}

exit;
