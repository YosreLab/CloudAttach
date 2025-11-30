<?php
/**
 * 腾讯云COS上传类
 * 简化版COS上传实现，避免引入大型SDK
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CosUploader
{
    private $secretId;
    private $secretKey;
    private $region;
    private $bucket;
    private $scheme = 'https';
    private $domain;
    private $error;

    public function __construct($config)
    {
        $this->secretId = $config->secretId;
        $this->secretKey = $config->secretKey;
        $this->region = $config->region;
        $this->bucket = $config->bucket;
        $this->domain = !empty($config->domain) ? rtrim($config->domain, '/') : null;
    }

    /**
     * 上传文件到COS
     */
    public function uploadFile($localPath, $cosKey)
    {
        if (!$this->validateConfig()) {
            return false;
        }

        try {
            // 获取签名
            $authorization = $this->getAuthorization('PUT', $cosKey);
            
            // 构建URL
            $url = $this->buildUrl($cosKey);
            
            // 读取文件内容
            $fileContent = file_get_contents($localPath);
            if ($fileContent === false) {
                $this->error = '无法读取本地文件';
                return false;
            }

            // 发送请求
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: ' . $authorization,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($fileContent),
                'Host: ' . parse_url($url, PHP_URL_HOST),
                'x-cos-acl: public-read'
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->error = 'CURL错误: ' . $error;
                return false;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return $this->domain ? $this->domain . '/' . $cosKey : $url;
            } else {
                $this->error = '上传失败，HTTP状态码: ' . $httpCode;
                return false;
            }

        } catch (Exception $e) {
            $this->error = '上传异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 从URL上传文件
     */
    public function uploadFromUrl($url, $cosKey)
    {
        if (!$this->validateConfig()) {
            return false;
        }

        try {
            // 下载远程文件
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$fileContent) {
                $this->error = '无法下载远程文件';
                return false;
            }

            // 上传到COS
            return $this->uploadContent($fileContent, $cosKey);

        } catch (Exception $e) {
            $this->error = '上传异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 直接上传内容
     */
    public function uploadContent($content, $cosKey)
    {
        if (!$this->validateConfig()) {
            return false;
        }

        try {
            // 获取签名
            $authorization = $this->getAuthorization('PUT', $cosKey);
            
            // 构建URL
            $url = $this->buildUrl($cosKey);
            
            // 发送请求
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: ' . $authorization,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($content),
                'Host: ' . parse_url($url, PHP_URL_HOST),
                'x-cos-acl: public-read'
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return $this->domain ? $this->domain . '/' . $cosKey : $url;
            } else {
                $this->error = '上传失败，HTTP状态码: ' . $httpCode;
                return false;
            }

        } catch (Exception $e) {
            $this->error = '上传异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 删除文件
     */
    public function deleteFile($cosKey)
    {
        if (!$this->validateConfig()) {
            return false;
        }

        try {
            // 获取签名
            $authorization = $this->getAuthorization('DELETE', $cosKey);
            
            // 构建URL
            $url = $this->buildUrl($cosKey);
            
            // 发送删除请求
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: ' . $authorization,
                'Host: ' . parse_url($url, PHP_URL_HOST)
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;

        } catch (Exception $e) {
            $this->error = '删除异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 检查文件是否存在
     */
    public function fileExists($cosKey)
    {
        if (!$this->validateConfig()) {
            return false;
        }

        try {
            // 获取签名
            $authorization = $this->getAuthorization('HEAD', $cosKey);
            
            // 构建URL
            $url = $this->buildUrl($cosKey);
            
            // 发送HEAD请求
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: ' . $authorization,
                'Host: ' . parse_url($url, PHP_URL_HOST)
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (Exception $e) {
            $this->error = '检查异常: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 生成签名
     */
    private function getAuthorization($method, $cosKey)
    {
        $keyTime = time() . ';' . (time() + 3600);
        $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);
        
        $httpString = $method . "\n" . 
                     '/' . $cosKey . "\n" . 
                     "\n" . 
                     'host=' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com' . "\n";
        
        $sha1HttpString = sha1($httpString);
        $stringToSign = "sha1\n" . $keyTime . "\n" . $sha1HttpString . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        
        return 'q-sign-algorithm=sha1&q-ak=' . $this->secretId . 
               '&q-sign-time=' . $keyTime . 
               '&q-key-time=' . $keyTime . 
               '&q-header-list=host' . 
               '&q-url-param-list=' . 
               '&q-signature=' . $signature;
    }

    /**
     * 构建URL
     */
    private function buildUrl($cosKey)
    {
        if ($this->domain) {
            return $this->domain . '/' . $cosKey;
        }
        
        return $this->scheme . '://' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com/' . $cosKey;
    }

    /**
     * 验证配置
     */
    private function validateConfig()
    {
        if (empty($this->secretId) || empty($this->secretKey) || empty($this->region) || empty($this->bucket)) {
            $this->error = 'COS配置不完整，请检查参数设置';
            return false;
        }
        return true;
    }

    /**
     * 获取错误信息
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 生成随机文件名
     */
    public static function generateFileName($originalName, $prefix = '')
    {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $random = uniqid();
        return $prefix . $random . '.' . $ext;
    }

    /**
     * 验证文件类型
     */
    public static function validateFileType($fileName, $allowedTypes)
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', is_array($allowedTypes) ? $allowedTypes : explode(',', $allowedTypes));
        return in_array($ext, $allowed);
    }

    /**
     * 验证文件大小
     */
    public static function validateFileSize($fileSize, $maxSize)
    {
        return $fileSize <= $maxSize;
    }

    /**
     * 获取MIME类型
     */
    public static function getMimeType($fileName)
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeTypes = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
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
        );
        
        return isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';
    }
}