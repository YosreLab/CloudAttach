<?php

namespace TypechoPlugin\CloudAttach;

// 启用输出缓冲，捕获所有意外输出（包括PHP警告）
ob_start();

// 设置错误报告
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 设置错误处理器，将错误记录到日志而非输出到响应体
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // 记录错误到PHP错误日志
    error_log("[$errno] $errstr in $errfile:$errline");
    return true; // 阻止默认错误处理
});

// 设置异常处理器
set_exception_handler(function($exception) {
    // 清空缓冲区，确保纯JSON响应
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => '系统错误：' . $exception->getMessage()
    ), JSON_UNESCAPED_UNICODE);
    exit;
});

if (!defined('__TYPECHO_ROOT_DIR__')) {
    // 如果不在Typecho环境中，尝试加载
    $dir = dirname(__FILE__);
    while ($dir && !is_file($dir . '/config.inc.php')) {
        $dir = dirname($dir);
    }

    if (!$dir) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        die('无法找到Typecho配置文件');
    }

    define('__TYPECHO_ROOT_DIR__', $dir);
    // 使用 @ 抑制重复定义警告
    @require_once $dir . '/config.inc.php';
}

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 检查请求类型
$action = '';
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
}

try {
    // 包含必要的文件
    require_once __DIR__ . '/Action.php';
    require_once __DIR__ . '/CosUploader.php';
    
    // 启用自动加载
    spl_autoload_register(function($className) {
        if (strpos($className, 'TypechoPlugin\\CloudAttach\\') === 0) {
            $className = str_replace('TypechoPlugin\\CloudAttach\\', '', $className);
            $filePath = __DIR__ . '/' . $className . '.php';
            if (file_exists($filePath)) {
                require_once $filePath;
            }
        }
    });
    
    switch ($action) {
        case 'upload':
            $handler = new Action();
            $handler->upload();
            break;

        case 'delete':
            $handler = new Action();
            $handler->delete();
            break;

        case 'list':
            $handler = new Action();
            $handler->list();
            break;

        case 'test':
            // 测试COS配置
            if (ob_get_level() > 0) {
                ob_clean();
            }

            try {
                $config = \Widget\Options::alloc()->plugin('CloudAttach');
                $uploader = new CosUploader($config);
                $result = $uploader->testConnection();
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
            } catch (\Exception $e) {
                echo json_encode(array(
                    'success' => false,
                    'message' => '测试失败：' . $e->getMessage()
                ), JSON_UNESCAPED_UNICODE);
            }
            exit; // 立即退出，避免被 ob_end_clean() 清空输出

        default:
            // 清空缓冲区，确保纯JSON响应
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '无效的操作'), JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (\Exception $e) {
    // 清空缓冲区，确保纯JSON响应
    if (ob_get_level() > 0) {
        ob_clean();
    }
    // 捕获所有异常并返回标准JSON格式
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => '系统错误：' . $e->getMessage()
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// 输出缓冲区内容并关闭
if (ob_get_level() > 0) {
    ob_end_flush();
}