<?php

namespace TypechoPlugin\CloudAttach;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 腾讯云COS上传类
 */
class CosUploader
{
    private $secretId;
    private $secretKey;
    private $region;
    private $bucket;
    private $domain;
    private $storagePath;
    
    public function __construct($config)
    {
        $this->secretId = $config->secretId;
        $this->secretKey = $config->secretKey;
        $this->region = $config->region;
        $this->bucket = $config->bucket;
        $this->domain = $config->domain;
        $this->storagePath = $config->storagePath ?: 'usr/uploads';
    }
    
    /**
     * 生成腾讯云 COS 签名（符合官方规范）
     */
    private function generateSignature($method, $key, $keyTime, $signKey, $headers = array())
    {
        // 1. 生成 HttpString
        $httpMethod = strtolower($method);
        $uriPathname = '/' . $key;
        $httpParameters = '';

        // 处理 headers
        ksort($headers);
        $httpHeaders = '';
        $headerList = array();
        foreach ($headers as $k => $v) {
            $headerList[] = strtolower($k);
            $httpHeaders .= strtolower($k) . '=' . urlencode($v) . '&';
        }
        $httpHeaders = rtrim($httpHeaders, '&');

        $httpString = $httpMethod . "\n" . $uriPathname . "\n" . $httpParameters . "\n" . $httpHeaders . "\n";

        // 2. 生成 StringToSign
        $sha1HttpString = sha1($httpString);
        $stringToSign = "sha1\n" . $keyTime . "\n" . $sha1HttpString . "\n";

        // 3. 生成 Signature
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        return array(
            'signature' => $signature,
            'header_list' => implode(';', $headerList),
            'url_param_list' => ''
        );
    }

    /**
     * 生成临时授权URL（预签名 URL）
     */
    private function generatePreSignedUrl($key, $contentType = '')
    {
        $currentTimestamp = time();
        $expirationTimestamp = $currentTimestamp + 3600; // 1小时后过期
        $keyTime = $currentTimestamp . ';' . $expirationTimestamp;

        // 生成 SignKey
        $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);

        // 准备 headers
        $host = $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
        $headers = array('host' => $host);

        // 生成签名
        $signResult = $this->generateSignature('put', $key, $keyTime, $signKey, $headers);

        // 构建 URL
        $url = 'https://' . $host . '/' . $key;

        $query = array(
            'q-sign-algorithm' => 'sha1',
            'q-ak' => $this->secretId,
            'q-sign-time' => $keyTime,
            'q-key-time' => $keyTime,
            'q-header-list' => $signResult['header_list'],
            'q-url-param-list' => $signResult['url_param_list'],
            'q-signature' => $signResult['signature']
        );

        return $url . '?' . http_build_query($query);
    }
    
    /**
     * 上传文件
     */
    public function uploadFile($file, $cid, $fileName)
    {
        try {
            // 验证配置
            if (empty($this->secretId) || empty($this->secretKey) || empty($this->bucket)) {
                throw new \Exception('COS配置不完整，请检查插件设置');
            }
            
            // 生成存储路径（格式：年/月）
            $uploadDir = date('Y/m');
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // 处理原始文件名：移除扩展名，清理特殊字符
            $originalName = pathinfo($fileName, PATHINFO_FILENAME);
            // 移除特殊字符，只保留字母、数字、中文、下划线和连字符
            $cleanName = preg_replace('/[^\w\x{4e00}-\x{9fa5}-]/u', '_', $originalName);
            // 限制长度为30个字符（避免文件名过长）
            if (mb_strlen($cleanName, 'UTF-8') > 30) {
                $cleanName = mb_substr($cleanName, 0, 30, 'UTF-8');
            }

            // 生成6位随机字符（比uniqid更短）
            $randomStr = substr(md5(uniqid(mt_rand(), true)), 0, 6);

            // 组合：原始文件名_随机字符.扩展名
            $uniqueName = $cleanName . '_' . $randomStr . '.' . $fileExt;
            $cosKey = $this->storagePath . '/' . $uploadDir . '/' . $uniqueName;
            
            // 生成预签名URL
            $uploadUrl = $this->generatePreSignedUrl($cosKey, $file['type']);
            
            // 使用cURL上传文件
            $ch = curl_init($uploadUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file['tmp_name']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: ' . $file['type'],
                'Content-Length: ' . $file['size'],
                'Host: ' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com'
            ));
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new \Exception('上传失败：' . ($error ?: 'HTTP状态码：' . $httpCode));
            }
            
            // 生成最终URL
            $cosUrl = 'https://' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com/' . $cosKey;
            if ($this->domain) {
                $cosUrl = rtrim($this->domain, '/') . '/' . $cosKey;
            }
            
            // 保存到数据库
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            
            $row = array(
                'cid' => $cid,
                'attachment_id' => 0,
                'cos_key' => $cosKey,
                'cos_url' => $cosUrl,
                'file_size' => $file['size'],
                'mime_type' => $file['type'],
                'created' => time()
            );
            
            $db->query($db->insert($prefix . 'cloud_attachments')->rows($row));
            
            return array(
                'success' => true,
                'url' => $cosUrl,
                'key' => $cosKey,
                'size' => $file['size'],
                'message' => '上传成功'
            );
            
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * 删除文件
     */
    public function deleteFile($cosKey)
    {
        try {
            $host = $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
            $url = 'https://' . $host . '/' . $cosKey;

            // 生成删除签名（使用统一的签名方法）
            $currentTimestamp = time();
            $expirationTimestamp = $currentTimestamp + 3600;
            $keyTime = $currentTimestamp . ';' . $expirationTimestamp;

            // 生成 SignKey
            $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);

            // 准备 headers
            $headers = array('host' => $host);

            // 生成签名
            $signResult = $this->generateSignature('delete', $cosKey, $keyTime, $signKey, $headers);

            // 构建删除 URL
            $query = array(
                'q-sign-algorithm' => 'sha1',
                'q-ak' => $this->secretId,
                'q-sign-time' => $keyTime,
                'q-key-time' => $keyTime,
                'q-header-list' => $signResult['header_list'],
                'q-url-param-list' => $signResult['url_param_list'],
                'q-signature' => $signResult['signature']
            );

            $deleteUrl = $url . '?' . http_build_query($query);

            // 发送 DELETE 请求
            $ch = curl_init($deleteUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Host: ' . $host
            ));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 204 || $httpCode === 200) {
                return array('success' => true, 'message' => '删除成功');
            } else {
                return array('success' => false, 'message' => '删除失败：HTTP ' . $httpCode . ($error ? ' - ' . $error : ''));
            }

        } catch (\Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * 列出COS中的文件
     */
    public function listFiles($prefix = '', $maxKeys = 100)
    {
        try {
            // 验证配置
            if (empty($this->secretId) || empty($this->secretKey) || empty($this->bucket)) {
                throw new \Exception('COS配置不完整');
            }

            $host = $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
            $path = '/';

            // 构建查询参数
            $params = array(
                'max-keys' => $maxKeys
            );
            if (!empty($prefix)) {
                $params['prefix'] = $prefix;
            }
            $queryString = http_build_query($params);
            $url = 'https://' . $host . $path . '?' . $queryString;

            // 生成签名
            $currentTimestamp = time();
            $expirationTimestamp = $currentTimestamp + 300;
            $keyTime = $currentTimestamp . ';' . $expirationTimestamp;
            $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);

            // 对参数键进行排序
            $paramKeys = array_keys($params);
            sort($paramKeys);
            $urlParamList = implode(';', $paramKeys);

            // 构建 HTTP 字符串
            $httpString = "get\n" . $path . "\n" . $queryString . "\nhost=" . $host . "\n";
            $sha1HttpString = sha1($httpString);
            $stringToSign = "sha1\n" . $keyTime . "\n" . $sha1HttpString . "\n";
            $signature = hash_hmac('sha1', $stringToSign, $signKey);

            // 构建 Authorization 头
            $authorization = 'q-sign-algorithm=sha1&q-ak=' . $this->secretId .
                           '&q-sign-time=' . $keyTime .
                           '&q-key-time=' . $keyTime .
                           '&q-header-list=host&q-url-param-list=' . $urlParamList .
                           '&q-signature=' . $signature;

            // 发送 GET 请求
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Host: ' . $host,
                'Authorization: ' . $authorization
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('获取文件列表失败: HTTP ' . $httpCode . ($error ? ' - ' . $error : ''));
            }

            // 解析 XML 响应
            $xml = simplexml_load_string($response);
            if ($xml === false) {
                throw new \Exception('解析响应失败');
            }

            $files = array();
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $key = (string)$content->Key;
                    $size = (int)$content->Size;
                    $lastModified = (string)$content->LastModified;

                    // 跳过目录
                    if (substr($key, -1) === '/') {
                        continue;
                    }

                    // 构建文件 URL
                    $fileUrl = $this->getFileUrl($key);

                    // 获取文件扩展名和 MIME 类型
                    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                    $mimeType = $this->getMimeType($ext);

                    $files[] = array(
                        'id' => md5($key), // 使用 key 的 MD5 作为唯一标识
                        'cos_key' => $key,
                        'cos_url' => $fileUrl,
                        'file_size' => $size,
                        'mime_type' => $mimeType,
                        'created' => strtotime($lastModified),
                        'last_modified' => $lastModified
                    );
                }
            }

            // 按修改时间倒序排序
            usort($files, function($a, $b) {
                return $b['created'] - $a['created'];
            });

            return array(
                'success' => true,
                'data' => $files,
                'total' => count($files)
            );

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array()
            );
        }
    }

    /**
     * 根据文件 key 生成访问 URL
     */
    private function getFileUrl($key)
    {
        // 如果配置了自定义域名，使用自定义域名
        if ($this->domain) {
            return rtrim($this->domain, '/') . '/' . $key;
        }

        // 否则使用 COS 默认域名
        return 'https://' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com/' . $key;
    }

    /**
     * 根据扩展名获取 MIME 类型
     */
    private function getMimeType($ext)
    {
        $mimeTypes = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'txt' => 'text/plain',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg'
        );

        return isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';
    }

    /**
     * 测试COS配置是否正确
     */
    public function testConnection()
    {
        try {
            // 验证配置完整性
            if (empty($this->secretId)) {
                throw new \Exception('Secret ID 未配置');
            }
            if (empty($this->secretKey)) {
                throw new \Exception('Secret Key 未配置');
            }
            if (empty($this->bucket)) {
                throw new \Exception('存储桶名称未配置');
            }
            if (empty($this->region)) {
                throw new \Exception('存储地域未配置');
            }

            // 尝试列出存储桶（HEAD Bucket 请求）
            $host = $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
            $url = 'https://' . $host . '/';

            // 生成签名
            $currentTimestamp = time();
            $expirationTimestamp = $currentTimestamp + 300; // 5分钟有效期
            $keyTime = $currentTimestamp . ';' . $expirationTimestamp;
            $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);

            // 构建 HTTP 字符串
            $httpString = "head\n/\n\nhost=" . $host . "\n";
            $sha1HttpString = sha1($httpString);
            $stringToSign = "sha1\n" . $keyTime . "\n" . $sha1HttpString . "\n";
            $signature = hash_hmac('sha1', $stringToSign, $signKey);

            // 构建 Authorization 头
            $authorization = 'q-sign-algorithm=sha1&q-ak=' . $this->secretId .
                           '&q-sign-time=' . $keyTime .
                           '&q-key-time=' . $keyTime .
                           '&q-header-list=host&q-url-param-list=&q-signature=' . $signature;

            // 发送 HEAD 请求测试连接
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD 请求
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Host: ' . $host,
                'Authorization: ' . $authorization
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10秒超时

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // 判断结果
            if ($httpCode === 200) {
                return array(
                    'success' => true,
                    'message' => 'COS 配置正确，连接成功！',
                    'details' => array(
                        'bucket' => $this->bucket,
                        'region' => $this->region,
                        'endpoint' => $host
                    )
                );
            } elseif ($httpCode === 403) {
                throw new \Exception('认证失败：Secret ID 或 Secret Key 不正确，或者没有访问该存储桶的权限');
            } elseif ($httpCode === 404) {
                throw new \Exception('存储桶不存在：请检查存储桶名称和地域是否正确');
            } elseif ($httpCode === 0) {
                throw new \Exception('网络连接失败：' . ($error ?: '无法连接到 COS 服务器'));
            } else {
                throw new \Exception('连接失败：HTTP ' . $httpCode . ($error ? ' - ' . $error : ''));
            }

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
}