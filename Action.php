<?php

namespace TypechoPlugin\CloudAttach;

use Typecho\Widget\Request;
use Typecho\Widget\Response;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * COS附件管理操作处理
 */
class Action
{
    private $db;
    private $prefix;
    private $config;
    private $uploader;

    public function __construct()
    {
        $this->db = \Typecho\Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->config = \Widget\Options::alloc()->plugin('CloudAttach');
        $this->uploader = new CosUploader($this->config);
    }

    /**
     * 文件上传处理
     */
    public function upload()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonResponse(array('success' => false, 'message' => '请求方式错误'));
                return;
            }

            // 从 POST 参数中获取 cid
            $cid = isset($_POST['cid']) ? intval($_POST['cid']) : 0;
            if (!$cid) {
                // 新建文章时先临时存储
                $cid = 0;
            }

            if (!isset($_FILES['file'])) {
                $this->jsonResponse(array('success' => false, 'message' => '没有上传文件'));
                return;
            }

            $file = $_FILES['file'];
            
            // 基本验证
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors = array(
                    UPLOAD_ERR_INI_SIZE => '文件大小超过限制',
                    UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
                    UPLOAD_ERR_PARTIAL => '文件上传不完整',
                    UPLOAD_ERR_NO_FILE => '没有选择文件',
                    UPLOAD_ERR_NO_TMP_DIR => '临时目录不存在',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                    UPLOAD_ERR_EXTENSION => '文件上传被扩展停止'
                );
                $errorMsg = isset($errors[$file['error']]) ? $errors[$file['error']] : '未知错误';
                $this->jsonResponse(array('success' => false, 'message' => $errorMsg));
                return;
            }

            // 使用COS上传器上传文件
            $result = $this->uploader->uploadFile($file, $cid, $file['name']);
            
            $this->jsonResponse($result);
            
        } catch (\Exception $e) {
            $this->jsonResponse(array('success' => false, 'message' => '系统错误：' . $e->getMessage()));
        }
    }

    /**
     * 删除附件处理（直接从 COS 删除）
     */
    public function delete()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonResponse(array('success' => false, 'message' => '请求方式错误'));
                return;
            }

            // 从 POST 参数中获取 cos_key（文件的完整路径）
            $cosKey = isset($_POST['cos_key']) ? trim($_POST['cos_key']) : '';
            if (empty($cosKey)) {
                $this->jsonResponse(array('success' => false, 'message' => '文件路径不能为空'));
                return;
            }

            try {
                // 直接从 COS 删除文件
                $deleteResult = $this->uploader->deleteFile($cosKey);

                if ($deleteResult['success']) {
                    $this->jsonResponse(array('success' => true, 'message' => '删除成功'));
                } else {
                    $this->jsonResponse(array('success' => false, 'message' => 'COS删除失败：' . $deleteResult['message']));
                }

            } catch (\Exception $e) {
                $this->jsonResponse(array('success' => false, 'message' => '删除失败：' . $e->getMessage()));
            }

        } catch (\Exception $e) {
            $this->jsonResponse(array('success' => false, 'message' => '系统错误：' . $e->getMessage()));
        }
    }

    /**
     * 获取附件列表（直接从 COS 读取）
     */
    public function list()
    {
        try {
            // 获取分页参数
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $pageSize = 8; // 每页显示8个

            // 获取分类参数
            $category = isset($_GET['category']) ? $_GET['category'] : 'all';

            // 获取存储路径前缀
            $prefix = $this->config->storagePath ? rtrim($this->config->storagePath, '/') . '/' : '';

            // 从 COS 获取文件列表
            $result = $this->uploader->listFiles($prefix, 1000); // 最多获取1000个文件

            if (!$result['success']) {
                $this->jsonResponse(array(
                    'success' => false,
                    'message' => $result['message']
                ));
                return;
            }

            $allFiles = $result['data'];

            // 根据分类过滤
            if ($category !== 'all') {
                $allFiles = array_filter($allFiles, function($file) use ($category) {
                    $mimeType = $file['mime_type'];
                    switch ($category) {
                        case 'image':
                            return strpos($mimeType, 'image/') === 0;
                        case 'document':
                            return in_array($mimeType, array(
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-powerpoint',
                                'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                            ));
                        case 'archive':
                            return in_array($mimeType, array(
                                'application/zip',
                                'application/x-rar-compressed',
                                'application/x-7z-compressed',
                                'application/x-tar'
                            ));
                        case 'other':
                            return strpos($mimeType, 'image/') !== 0 &&
                                   !in_array($mimeType, array(
                                       'application/pdf',
                                       'application/msword',
                                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                       'application/vnd.ms-excel',
                                       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                       'application/vnd.ms-powerpoint',
                                       'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                       'application/zip',
                                       'application/x-rar-compressed',
                                       'application/x-7z-compressed',
                                       'application/x-tar'
                                   ));
                        default:
                            return true;
                    }
                });
                // 重新索引数组
                $allFiles = array_values($allFiles);
            }

            // 计算总数和分页
            $total = count($allFiles);
            $totalPages = ceil($total / $pageSize);
            $offset = ($page - 1) * $pageSize;

            // 获取当前页的数据
            $attachments = array_slice($allFiles, $offset, $pageSize);

            $this->jsonResponse(array(
                'success' => true,
                'data' => $attachments,
                'pagination' => array(
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => $totalPages
                )
            ));

        } catch (\Exception $e) {
            $this->jsonResponse(array('success' => false, 'message' => '获取列表失败：' . $e->getMessage()));
        }
    }

    /**
     * 批量删除附件处理
     */
    public function bulkDelete()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonResponse(array('success' => false, 'message' => '请求方式错误'));
                return;
            }

            // 从 POST 参数中获取 cos_keys（JSON 格式的数组）
            $cosKeysJson = isset($_POST['cos_keys']) ? trim($_POST['cos_keys']) : '';
            if (empty($cosKeysJson)) {
                $this->jsonResponse(array('success' => false, 'message' => '文件列表不能为空'));
                return;
            }

            // 解析 JSON
            $cosKeys = json_decode($cosKeysJson, true);
            if (!is_array($cosKeys) || empty($cosKeys)) {
                $this->jsonResponse(array('success' => false, 'message' => '文件列表格式错误'));
                return;
            }

            $deletedCount = 0;
            $failedCount = 0;
            $errors = array();

            // 逐个删除文件
            foreach ($cosKeys as $cosKey) {
                if (empty($cosKey)) {
                    continue;
                }

                try {
                    $deleteResult = $this->uploader->deleteFile($cosKey);

                    if ($deleteResult['success']) {
                        $deletedCount++;
                    } else {
                        $failedCount++;
                        $errors[] = $cosKey . ': ' . $deleteResult['message'];
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $errors[] = $cosKey . ': ' . $e->getMessage();
                }
            }

            // 返回结果
            if ($failedCount === 0) {
                $this->jsonResponse(array(
                    'success' => true,
                    'message' => '全部删除成功',
                    'deleted_count' => $deletedCount
                ));
            } else {
                $this->jsonResponse(array(
                    'success' => true,
                    'message' => '部分删除成功',
                    'deleted_count' => $deletedCount,
                    'failed_count' => $failedCount,
                    'errors' => $errors
                ));
            }

        } catch (\Exception $e) {
            $this->jsonResponse(array('success' => false, 'message' => '系统错误：' . $e->getMessage()));
        }
    }

    /**
     * JSON响应
     */
    private function jsonResponse($data)
    {
        // 清空缓冲区中的任何意外输出
        if (ob_get_level() > 0) {
            ob_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}