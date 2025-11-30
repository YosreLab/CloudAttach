<?php
/**
 * 独立的文件下载代理脚本
 * 完全绕过 Typecho 加载，避免输出污染
 */

// 立即清空所有输出缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 禁用错误显示
ini_set('display_errors', 0);
error_reporting(0);

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
    die('Invalid URL');
}

// 从 URL 中提取文件名
$fileName = basename(parse_url($fileUrl, PHP_URL_PATH));
if (empty($fileName)) {
    $fileName = 'download';
}
$fileName = urldecode($fileName);

// 使用 cURL 获取文件
$ch = curl_init($fileUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);

$fileContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || $fileContent === false) {
    http_response_code(500);
    die('Download failed: ' . ($error ?: 'HTTP ' . $httpCode));
}

// 再次清空缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 设置响应头
header('Content-Type: application/octet-stream');
header('Content-Transfer-Encoding: binary');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fileName));
header('Content-Length: ' . strlen($fileContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 输出文件内容
echo $fileContent;
flush();
exit;
