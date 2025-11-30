<?php

namespace TypechoPlugin\CloudAttach;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * CloudAttach - 云端附件管家
 *
 * 为 Typecho 提供强大的云端附件管理功能，支持腾讯云 COS 对象存储
 * 特性：批量上传、分类管理、分页浏览、可视化图标
 *
 * @package CloudAttach
 * @author CloudAttach Team
 * @version 1.0.0
 * @link https://github.com/your-repo/cloudattach
 */
class Plugin implements PluginInterface
{
    /**
     * 插件信息
     */
    public static function info()
    {
        return array(
            'name' => 'CloudAttach',
            'description' => '云端附件管家，基于腾讯云COS的附件管理插件，支持批量上传、分类管理等功能',
            'version' => '1.0.0',
            'author' => 'CloudAttach Team',
            'homepage' => 'https://github.com/your-repo/cloudattach'
        );
    }

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        try {
            // 创建附件数据表
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            // 检查表是否已存在
            try {
                $tables = $db->fetchAll($db->query("SHOW TABLES LIKE '{$prefix}cloud_attachments'"));
            } catch (\Exception $e) {
                $tables = array();
            }
            
            if (empty($tables)) {
                $sql = "CREATE TABLE {$prefix}cloud_attachments (
                    id int(10) unsigned NOT NULL AUTO_INCREMENT,
                    cid int(10) unsigned NOT NULL DEFAULT '0',
                    attachment_id int(10) unsigned NOT NULL DEFAULT '0',
                    cos_key varchar(255) NOT NULL DEFAULT '',
                    cos_url varchar(500) NOT NULL DEFAULT '',
                    file_size int(10) unsigned NOT NULL DEFAULT '0',
                    mime_type varchar(100) NOT NULL DEFAULT '',
                    created int(10) unsigned NOT NULL DEFAULT '0',
                    PRIMARY KEY (id),
                    KEY cid (cid),
                    KEY attachment_id (attachment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                $db->query($sql);
            } else {
                // 表已存在，检查并添加缺失的字段
                try {
                    $columns = $db->fetchAll($db->query("SHOW COLUMNS FROM {$prefix}cloud_attachments"));
                    $columnNames = array();
                    foreach ($columns as $column) {
                        $columnNames[] = $column['Field'];
                    }

                    // 检查并添加 cos_key 字段
                    if (!in_array('cos_key', $columnNames)) {
                        $db->query("ALTER TABLE {$prefix}cloud_attachments ADD COLUMN cos_key varchar(255) NOT NULL DEFAULT '' AFTER attachment_id");
                    }

                    // 检查并添加 cos_url 字段
                    if (!in_array('cos_url', $columnNames)) {
                        $db->query("ALTER TABLE {$prefix}cloud_attachments ADD COLUMN cos_url varchar(500) NOT NULL DEFAULT '' AFTER cos_key");
                    }

                    // 检查并添加 file_size 字段
                    if (!in_array('file_size', $columnNames)) {
                        $db->query("ALTER TABLE {$prefix}cloud_attachments ADD COLUMN file_size int(10) unsigned NOT NULL DEFAULT '0' AFTER cos_url");
                    }

                    // 检查并添加 mime_type 字段
                    if (!in_array('mime_type', $columnNames)) {
                        $db->query("ALTER TABLE {$prefix}cloud_attachments ADD COLUMN mime_type varchar(100) NOT NULL DEFAULT '' AFTER file_size");
                    }

                    // 检查并添加 created 字段
                    if (!in_array('created', $columnNames)) {
                        $db->query("ALTER TABLE {$prefix}cloud_attachments ADD COLUMN created int(10) unsigned NOT NULL DEFAULT '0' AFTER mime_type");
                    }
                } catch (\Exception $e) {
                    // 如果检查失败，忽略错误继续
                }
            }

            // 注册钩子
            \Typecho\Plugin::factory('admin/write-post.php')->bottom = array(__CLASS__, 'renderAttachmentPanel');
            \Typecho\Plugin::factory('admin/write-page.php')->bottom = array(__CLASS__, 'renderAttachmentPanel');
            \Typecho\Plugin::factory('Widget_Archive')->handle = array(__CLASS__, 'handleContent');

        } catch (\Exception $e) {
            throw new \Exception('插件激活失败：' . $e->getMessage());
        }
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
        // 清理工作（如果需要）
        return true;
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form)
    {
        $secretId = new Text('secretId', null, null, _t('Secret ID *'), _t('腾讯云API密钥ID，在访问管理中获取'));
        $form->addInput($secretId);

        $secretKey = new Text('secretKey', null, null, _t('Secret Key *'), _t('腾讯云API密钥Key'));
        $form->addInput($secretKey);

        $region = new Select('region', array(
            'ap-beijing' => '北京',
            'ap-shanghai' => '上海', 
            'ap-guangzhou' => '广州',
            'ap-chengdu' => '成都',
            'ap-chongqing' => '重庆',
            'ap-singapore' => '新加坡',
            'ap-hongkong' => '香港',
            'ap-tokyo' => '东京',
            'na-siliconvalley' => '硅谷',
            'na-ashburn' => '弗吉尼亚'
        ), 'ap-guangzhou', _t('存储地域 *'), _t('选择COS存储桶所在地域'));
        $form->addInput($region);

        $bucket = new Text('bucket', null, null, _t('存储桶名称 *'), _t('COS存储桶名称，格式：bucket-name-appid'));
        $form->addInput($bucket);

        $domain = new Text('domain', null, null, _t('CDN域名'), _t('自定义CDN加速域名，如：https://cdn.example.com'));
        $form->addInput($domain);

        $storagePath = new Text('storagePath', null, 'usr/uploads', _t('对象存储路径'), _t('自定义COS存储路径，如：usr/uploads 或 attachments'));
        $form->addInput($storagePath);

        // 生成 handler.php 的 URL
        $pluginDir = str_replace('\\', '/', dirname(__FILE__));
        $rootDir = str_replace('\\', '/', __TYPECHO_ROOT_DIR__);
        $pluginPath = str_replace($rootDir, '', $pluginDir);
        $handlerUrl = rtrim(Options::alloc()->siteUrl, '/') . $pluginPath . '/handler.php';
        $handlerUrl = htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8');

        // 添加测试按钮和提示信息
        echo '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
            <h4 style="margin-top: 0;">配置测试</h4>
            <p style="color: #666; margin-bottom: 15px;">保存配置后，点击下方按钮测试 COS 连接是否正常</p>
            <button type="button" id="cos-test-btn" class="btn primary" style="margin-right: 10px;">测试 COS 配置</button>
            <span id="cos-test-result" style="display: inline-block; margin-left: 10px;"></span>
        </div>';

        // 添加测试功能的 JavaScript
        echo '<script>
        (function() {
            var handlerUrl = "' . $handlerUrl . '";

            document.addEventListener("DOMContentLoaded", function() {
                var testBtn = document.getElementById("cos-test-btn");
                var testResult = document.getElementById("cos-test-result");

                if (testBtn) {
                    testBtn.addEventListener("click", function() {
                        testBtn.disabled = true;
                        testBtn.textContent = "测试中...";
                        testResult.innerHTML = "";

                        var xhr = new XMLHttpRequest();
                        xhr.addEventListener("load", function() {
                            testBtn.disabled = false;
                            testBtn.textContent = "测试 COS 配置";

                            if (xhr.status === 200) {
                                try {
                                    var response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        testResult.innerHTML = "<span style=\"color: #4caf50; font-weight: bold;\">✓ " + response.message + "</span>";
                                        if (response.details) {
                                            testResult.innerHTML += "<br><small style=\"color: #666;\">存储桶: " + response.details.bucket + " | 地域: " + response.details.region + "</small>";
                                        }
                                    } else {
                                        testResult.innerHTML = "<span style=\"color: #f44336; font-weight: bold;\">✗ " + response.message + "</span>";
                                    }
                                } catch (e) {
                                    testResult.innerHTML = "<span style=\"color: #f44336;\">✗ 响应解析失败</span>";
                                    console.error("测试响应解析失败:", e);
                                    console.error("原始响应:", xhr.responseText);
                                }
                            } else {
                                testResult.innerHTML = "<span style=\"color: #f44336;\">✗ 请求失败: HTTP " + xhr.status + "</span>";
                            }
                        });

                        xhr.addEventListener("error", function() {
                            testBtn.disabled = false;
                            testBtn.textContent = "测试 COS 配置";
                            testResult.innerHTML = "<span style=\"color: #f44336;\">✗ 网络错误</span>";
                        });

                        xhr.open("GET", handlerUrl + "?action=test");
                        xhr.send();
                    });
                }
            });
        })();
        </script>';
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
        // 个人用户配置（如果需要）
    }

    /**
     * 渲染附件管理面板（文章编辑页）
     *
     * @access public
     * @return void
     */
    public static function renderAttachmentPanel()
    {
        // 生成 handler.php 的 URL
        $pluginDir = str_replace('\\', '/', dirname(__FILE__));
        $rootDir = str_replace('\\', '/', __TYPECHO_ROOT_DIR__);
        $pluginPath = str_replace($rootDir, '', $pluginDir);
        $handlerUrl = rtrim(Options::alloc()->siteUrl, '/') . $pluginPath . '/handler.php';
        $handlerUrl = htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8');

        echo '<style>
#cos-attachment-manager {
    position: fixed;
    right: 0;
    top: 0;
    width: 380px;
    height: 100vh;
    background: white;
    box-shadow: -2px 0 10px rgba(0,0,0,0.1);
    z-index: 10000;
    display: none;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}
.cos-panel-header {
    background: linear-gradient(135deg, #1e88e5, #1565c0);
    color: white;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.cos-panel-close {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    font-size: 18px;
}
.cos-panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}
.cos-upload-zone {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}
.cos-upload-zone:hover {
    border-color: #1e88e5;
    background: #f8fbff;
}
.cos-trigger {
    position: fixed;
    right: 20px;
    bottom: 80px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e88e5, #1565c0);
    color: white;
    border: none;
    box-shadow: 0 3px 10px rgba(0,0,0,0.3);
    cursor: pointer;
    font-size: 24px;
    z-index: 9999;
    transition: all 0.3s;
}
.cos-trigger:hover {
    transform: scale(1.1);
}
</style>';

        echo '<div id="cos-attachment-manager">
    <div class="cos-panel-header">
        <h3 style="margin: 0; font-size: 16px;">☁️ CloudAttach</h3>
        <button type="button" class="cos-panel-close">×</button>
    </div>
    
    <div class="cos-panel-content">
        <div style="margin-bottom: 25px;">
            <div class="cos-upload-zone">
                <div style="font-size: 48px; margin-bottom: 15px;">⬆️</div>
                <p style="margin: 0 0 8px 0; color: #333; font-weight: 500;">拖拽文件到此处或点击上传</p>
                <small style="color: #666;">支持批量上传 | 格式：jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar</small>
                <input type="file" id="cos-file-input" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar" style="display: none;">
            </div>
            
            <!-- 上传队列显示 -->
            <div id="cos-upload-queue" style="display: none; margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 12px; font-weight: 500; color: #333;">上传队列</span>
                    <span id="cos-upload-status" style="font-size: 12px; color: #666;"></span>
                </div>
                <div id="cos-upload-list" style="max-height: 200px; overflow-y: auto;"></div>
            </div>
        </div>

        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #eee;">
                <h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #333;">附件列表</h4>
                <div>
                    <button type="button" onclick="refreshCosAttachments()" style="background: none; border: 1px solid #1e88e5; color: #1e88e5; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;">刷新</button>
                </div>
            </div>

            <!-- 批量操作工具栏 -->
            <div id="cos-bulk-actions" style="display: none; margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <span id="cos-selected-count" style="color: #666; font-size: 12px; font-weight: 500;">已选中 0 个文件</span>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button id="cos-select-all" style="padding: 6px 12px; border: 1px solid #1e88e5; background: white; color: #1e88e5; border-radius: 4px; cursor: pointer; font-size: 12px;">全选</button>
                        <button id="cos-deselect-all" style="padding: 6px 12px; border: 1px solid #999; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 12px;">取消</button>
                        <button id="cos-bulk-insert" style="padding: 6px 12px; border: none; background: #4caf50; color: white; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">批量插入</button>
                        <button id="cos-bulk-copy" style="padding: 6px 12px; border: none; background: #ff9800; color: white; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">批量复制</button>
                    </div>
                </div>
            </div>

            <div id="cos-attachment-list" style="display: flex; flex-direction: column; gap: 12px;">
                <div style="text-align: center; padding: 40px; color: #999;">
                    <div style="font-size: 32px; margin-bottom: 10px;">📂</div>
                    <p>暂无附件</p>
                    <small style="color: #666;">上传文件后将显示在这里</small>
                </div>
            </div>
        </div>
    </div>
</div>';

        echo '<button type="button" id="cos-panel-trigger" class="cos-trigger">☁️</button>';

        // JavaScript代码保持原有功能，但简化处理
        echo '<script>
(function() {
    var handlerUrl = "' . $handlerUrl . '";

    console.log("CloudAttach插件开始加载...");
    console.log("Handler URL:", handlerUrl);

    let currentPage = 1;
    let currentCategory = "all";
    let totalPages = 1;

    // 存储选中的附件
    let selectedAttachments = [];
    let currentAttachments = [];

    // 获取文件图标
    function getFileIcon(mimeType, fileName) {
        if (mimeType.startsWith(\'image/\')) return \'🖼️\';
        if (mimeType === \'application/pdf\') return \'📄\';
        if (mimeType.includes(\'word\') || mimeType.includes(\'msword\')) return \'📝\';
        if (mimeType.includes(\'excel\') || mimeType.includes(\'spreadsheet\')) return \'📊\';
        if (mimeType.includes(\'powerpoint\') || mimeType.includes(\'presentation\')) return \'📊\';
        if (mimeType.includes(\'zip\') || mimeType.includes(\'rar\') || mimeType.includes(\'compressed\')) return \'📦\';
        if (mimeType.startsWith(\'video/\')) return \'🎬\';
        if (mimeType.startsWith(\'audio/\')) return \'🎵\';

        const ext = fileName.split(\'.\').pop().toLowerCase();
        if ([\'js\', \'php\', \'py\', \'java\', \'cpp\', \'c\', \'html\', \'css\'].includes(ext)) return \'💻\';
        if ([\'txt\', \'md\'].includes(ext)) return \'📝\';
        if ([\'csv\'].includes(ext)) return \'📊\';

        return \'📎\';
    }

    // 获取编辑器
    function getEditor() {
        const textarea = document.querySelector(\'textarea[name="text"]\');
        if (textarea) {
            return { type: \'textarea\', element: textarea };
        }

        const contentEditable = document.querySelector(\'[contenteditable="true"]\');
        if (contentEditable) {
            return { type: \'contenteditable\', element: contentEditable };
        }

        return null;
    }

    // 插入内容到编辑器
    function insertToEditor(fileUrl, fileName, mimeType) {
        const editor = getEditor();
        if (!editor) {
            return false;
        }

        let content = \'\';
        const isImage = mimeType && mimeType.startsWith(\'image/\');

        if (isImage) {
            content = \'![\' + fileName + \'](\' + fileUrl + \')\';
        } else {
            content = \'[\' + fileName + \'](\' + fileUrl + \')\';
        }

        if (editor.type === \'textarea\') {
            const textarea = editor.element;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;

            textarea.value = text.substring(0, start) + content + text.substring(end);

            const newPos = start + content.length;
            textarea.selectionStart = newPos;
            textarea.selectionEnd = newPos;
            textarea.focus();
            return true;
        }

        return false;
    }

    function getCid() {
        const cidInput = document.querySelector("input[name=\\"cid\\"]");
        if (cidInput && cidInput.value) {
            return cidInput.value;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const cidFromUrl = urlParams.get("cid");
        if (cidFromUrl) {
            return cidFromUrl;
        }

        const pathMatch = window.location.pathname.match(/\\/write-post\\.php\\/(\\d+)/);
        if (pathMatch) {
            return pathMatch[1];
        }

        return null;
    }

    const cid = getCid();
    console.log("当前文章CID:", cid);

    console.log("显示附件管理功能");
    document.getElementById("cos-panel-trigger").style.display = "block";

    const panel = document.getElementById("cos-attachment-manager");
    const trigger = document.getElementById("cos-panel-trigger");
    const closeBtn = document.querySelector(".cos-panel-close");
    const uploadZone = document.querySelector(".cos-upload-zone");
    const fileInput = document.getElementById("cos-file-input");

    let cosPanelOpen = false;

    function toggleCosPanel() {
        cosPanelOpen = !cosPanelOpen;
        console.log("切换面板状态:", cosPanelOpen);

        if (cosPanelOpen) {
            panel.style.display = "flex";
            setTimeout(function() {
                panel.style.transform = "translateX(0)";
            }, 10);
            refreshCosAttachments();
        } else {
            panel.style.transform = "translateX(100%)";
            setTimeout(function() {
                panel.style.display = "none";
            }, 300);
        }
    }

    trigger.addEventListener("click", toggleCosPanel);
    closeBtn.addEventListener("click", toggleCosPanel);

    uploadZone.addEventListener("click", function() {
        fileInput.click();
    });

    fileInput.addEventListener("change", function(e) {
        if (e.target.files.length > 0) {
            console.log("选择了文件:", e.target.files);
            uploadFiles(Array.from(e.target.files));
            e.target.value = "";
        }
    });

    // 上传文件功能
    let uploadQueue = [];
    let uploadingCount = 0;
    let uploadedCount = 0;
    let totalUploadCount = 0;

    function uploadFiles(files) {
        if (!files || files.length === 0) return;

        totalUploadCount = files.length;
        uploadedCount = 0;
        uploadQueue = Array.from(files);

        const queueDiv = document.getElementById(\'cos-upload-queue\');
        const listDiv = document.getElementById(\'cos-upload-list\');
        queueDiv.style.display = \'block\';
        listDiv.innerHTML = \'\';

        // 为每个文件创建进度条
        uploadQueue.forEach(function(file, index) {
            const itemId = \'upload-item-\' + index;
            const itemHtml = \'<div id="\' + itemId + \'" style="margin-bottom: 8px; padding: 8px; background: #f8f9fa; border-radius: 4px;">\' +
                \'<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">\' +
                    \'<span style="font-size: 12px; color: #333; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;">\' + file.name + \'</span>\' +
                    \'<span id="\' + itemId + \'-status" style="font-size: 11px; color: #999; margin-left: 8px;">等待中...</span>\' +
                \'</div>\' +
                \'<div style="height: 4px; background: #e0e0e0; border-radius: 2px; overflow: hidden;">\' +
                    \'<div id="\' + itemId + \'-progress" style="height: 100%; background: #1e88e5; width: 0%; transition: width 0.3s;"></div>\' +
                \'</div>\' +
            \'</div>\';
            listDiv.innerHTML += itemHtml;
        });

        updateUploadStatus();

        // 开始上传（最多同时上传3个文件）
        for (let i = 0; i < Math.min(3, uploadQueue.length); i++) {
            uploadNextFile();
        }
    }

    function uploadNextFile() {
        if (uploadQueue.length === 0) return;

        const file = uploadQueue.shift();
        const index = totalUploadCount - uploadQueue.length - 1;
        uploadingCount++;

        uploadFile(file, index);
    }

    function uploadFile(file, index) {
        const itemId = \'upload-item-\' + index;
        const statusSpan = document.getElementById(itemId + \'-status\');
        const progressBar = document.getElementById(itemId + \'-progress\');

        statusSpan.textContent = \'上传中...\';
        statusSpan.style.color = \'#1e88e5\';

        const formData = new FormData();
        formData.append("file", file);
        const currentCid = getCid() || "0";
        formData.append("cid", currentCid);

        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener(\'progress\', function(e) {
            if (e.lengthComputable) {
                const percent = (e.loaded / e.total) * 100;
                progressBar.style.width = percent + \'%\';
            }
        });

        xhr.onload = function() {
            uploadingCount--;
            uploadedCount++;

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        statusSpan.textContent = \'✓ 成功\';
                        statusSpan.style.color = \'#4caf50\';
                        progressBar.style.background = \'#4caf50\';
                        progressBar.style.width = \'100%\';
                    } else {
                        statusSpan.textContent = \'✗ 失败: \' + response.message;
                        statusSpan.style.color = \'#f44336\';
                        progressBar.style.background = \'#f44336\';
                    }
                } catch (e) {
                    statusSpan.textContent = \'✗ 解析错误\';
                    statusSpan.style.color = \'#f44336\';
                    progressBar.style.background = \'#f44336\';
                }
            } else {
                statusSpan.textContent = \'✗ HTTP \' + xhr.status;
                statusSpan.style.color = \'#f44336\';
                progressBar.style.background = \'#f44336\';
            }

            updateUploadStatus();

            // 检查是否所有文件都已上传完成
            if (uploadedCount === totalUploadCount) {
                setTimeout(function() {
                    document.getElementById(\'cos-upload-queue\').style.display = \'none\';
                    refreshCosAttachments();
                }, 2000);
            } else {
                // 继续上传下一个文件
                uploadNextFile();
            }
        };

        xhr.onerror = function() {
            uploadingCount--;
            uploadedCount++;
            statusSpan.textContent = \'✗ 网络错误\';
            statusSpan.style.color = \'#f44336\';
            progressBar.style.background = \'#f44336\';
            updateUploadStatus();

            if (uploadedCount === totalUploadCount) {
                setTimeout(function() {
                    document.getElementById(\'cos-upload-queue\').style.display = \'none\';
                    refreshCosAttachments();
                }, 2000);
            } else {
                uploadNextFile();
            }
        };

        xhr.open("POST", handlerUrl + "?action=upload");
        xhr.send(formData);
    }

    function updateUploadStatus() {
        const statusSpan = document.getElementById(\'cos-upload-status\');
        if (statusSpan) {
            statusSpan.textContent = \'已完成 \' + uploadedCount + \' / \' + totalUploadCount;
        }
    }

    // 批量操作按钮
    document.getElementById("cos-select-all").addEventListener("click", function() {
        selectedAttachments = currentAttachments.slice();
        renderAttachmentList(currentAttachments);
        updateBulkActionsUI();
    });

    document.getElementById("cos-deselect-all").addEventListener("click", function() {
        selectedAttachments = [];
        renderAttachmentList(currentAttachments);
        updateBulkActionsUI();
    });

    document.getElementById("cos-bulk-insert").addEventListener("click", function() {
        bulkInsert();
    });

    document.getElementById("cos-bulk-copy").addEventListener("click", function() {
        bulkCopy();
    });

    window.refreshCosAttachments = function() {
        console.log("刷新附件列表");
        const xhr = new XMLHttpRequest();
        xhr.open("GET", handlerUrl + "?action=list");
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        console.log("附件列表加载成功:", response);
                        renderAttachmentList(response.data);
                    }
                } catch (e) {
                    console.error("解析响应失败:", e);
                }
            }
        };
        xhr.send();
    };

    function renderAttachmentList(attachments) {
        const listContainer = document.getElementById("cos-attachment-list");
        if (!listContainer) return;

        if (!attachments || attachments.length === 0) {
            listContainer.innerHTML = \'<div style="text-align: center; padding: 40px; color: #999;"><div style="font-size: 32px; margin-bottom: 10px;">📂</div><p>暂无附件</p><small style="color: #666;">上传文件后将显示在这里</small></div>\';
            currentAttachments = [];
            return;
        }

        // 保存当前附件列表
        currentAttachments = attachments;

        let html = \'\';
        attachments.forEach(function(item, index) {
            const isImage = item.mime_type && item.mime_type.startsWith(\'image/\');
            const fileSize = formatFileSize(item.file_size);
            const fileName = item.cos_key ? item.cos_key.split(\'/\').pop() : \'未知文件\';
            const fileUrl = item.cos_url || item.cloud_url || \'\';
            const isSelected = selectedAttachments.some(function(att) { return att.cos_key === item.cos_key; });

            html += \'<div class="cos-attachment-item" data-cos-key="\' + item.cos_key + \'" style="border: 1px solid \' + (isSelected ? \'#1e88e5\' : \'#e0e0e0\') + \'; border-radius: 8px; padding: 12px; margin-bottom: 8px; transition: all 0.2s;">\';
            html += \'<div style="display: flex; align-items: center; gap: 12px;">\';

            // 复选框
            html += \'<input type="checkbox" class="cos-attachment-checkbox" data-index="\' + index + \'" \' + (isSelected ? \'checked\' : \'\') + \' style="width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; margin: 0;" />\';

            // 文件预览或图标
            html += \'<div style="width: 50px; height: 50px; background: #f8f9fa; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">\';
            if (isImage) {
                html += \'<img src="\' + fileUrl + \'" style="max-width: 100%; max-height: 100%; border-radius: 4px; object-fit: cover;">\';
            } else {
                html += \'<span style="font-size: 20px;">📄</span>\';
            }
            html += \'</div>\';

            // 文件信息
            html += \'<div style="flex: 1; min-width: 0;">\';
            html += \'<div style="font-size: 13px; font-weight: 500; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="\' + fileName + \'">\' + fileName + \'</div>\';
            html += \'<div style="font-size: 11px; color: #666;">\' + fileSize + \'</div>\';
            html += \'</div>\';

            // 操作按钮
            html += \'<div style="display: flex; gap: 4px;">\';
            html += \'<button type="button" onclick="insertAttachment(\\\'\' + fileUrl.replace(/\'/g, "\\\\\'") + \'\\\', \\\'\' + fileName.replace(/\'/g, "\\\\\'") + \'\\\', \\\'\' + (item.mime_type || \'\').replace(/\'/g, "\\\\\'") + \'\\\')" style="background: #4caf50; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;" title="插入到编辑器">插入</button>\';
            html += \'<button type="button" onclick="copyToClipboard(\\\'\' + fileUrl.replace(/\'/g, "\\\\\'") + \'\\\')" style="background: #2196f3; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;" title="复制链接">复制</button>\';
            html += \'<button type="button" onclick="deleteAttachment(\\\'\' + item.cos_key.replace(/\'/g, "\\\\\'") + \'\\\')" style="background: #f44336; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;" title="删除">删除</button>\';
            html += \'</div>\';

            html += \'</div>\';
            html += \'</div>\';
        });

        listContainer.innerHTML = html;

        // 绑定复选框事件
        document.querySelectorAll(\'.cos-attachment-checkbox\').forEach(function(checkbox) {
            checkbox.addEventListener(\'change\', function(e) {
                e.stopPropagation();
                const index = parseInt(this.getAttribute(\'data-index\'));
                const item = currentAttachments[index];
                toggleSelection(item);
            });
        });
    }

    function formatFileSize(bytes) {
        if (!bytes) return \'0 B\';
        const k = 1024;
        const sizes = [\'B\', \'KB\', \'MB\', \'GB\'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + \' \' + sizes[i];
    }

    // 插入附件到编辑器
    window.insertAttachment = function(fileUrl, fileName, mimeType) {
        insertToEditor(fileUrl, fileName, mimeType);
    };

    // 切换选中状态
    function toggleSelection(item) {
        const index = selectedAttachments.findIndex(function(att) {
            return att.cos_key === item.cos_key;
        });

        if (index > -1) {
            selectedAttachments.splice(index, 1);
        } else {
            selectedAttachments.push(item);
        }

        updateBulkActionsUI();
        updateAttachmentItemStyle(item.cos_key);
    }

    // 更新附件项样式
    function updateAttachmentItemStyle(cosKey) {
        const item = document.querySelector(\'.cos-attachment-item[data-cos-key="\' + cosKey + \'"]\');
        if (!item) return;

        const isSelected = selectedAttachments.some(function(att) {
            return att.cos_key === cosKey;
        });

        item.style.borderColor = isSelected ? \'#1e88e5\' : \'#e0e0e0\';
    }

    // 更新批量操作工具栏
    function updateBulkActionsUI() {
        const bulkActions = document.getElementById(\'cos-bulk-actions\');
        const selectedCount = document.getElementById(\'cos-selected-count\');

        if (selectedAttachments.length > 0) {
            bulkActions.style.display = \'block\';
            selectedCount.textContent = \'已选中 \' + selectedAttachments.length + \' 个文件\';
        } else {
            bulkActions.style.display = \'none\';
        }
    }

    // 批量插入
    window.bulkInsert = function() {
        if (selectedAttachments.length === 0) {
            return;
        }

        const editor = getEditor();
        if (!editor || editor.type !== \'textarea\') {
            return;
        }

        const textarea = editor.element;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;

        let content = \'\';
        selectedAttachments.forEach(function(item, index) {
            const fileUrl = item.cos_url || item.cloud_url || \'\';
            const fileName = item.cos_key ? item.cos_key.split(\'/\').pop() : \'未知文件\';
            const mimeType = item.mime_type || \'\';
            const isImage = mimeType && mimeType.startsWith(\'image/\');

            if (isImage) {
                content += \'![\' + fileName + \'](\' + fileUrl + \')\';
            } else {
                content += \'[\' + fileName + \'](\' + fileUrl + \')\';
            }

            // 每个链接后添加换行，最后一个除外
            if (index < selectedAttachments.length - 1) {
                content += \'\\n\';
            }
        });

        textarea.value = text.substring(0, start) + content + text.substring(end);
        const newPos = start + content.length;
        textarea.selectionStart = newPos;
        textarea.selectionEnd = newPos;
        textarea.focus();
    };

    // 批量复制
    window.bulkCopy = function() {
        if (selectedAttachments.length === 0) {
            return;
        }

        const urls = selectedAttachments.map(function(item) {
            return item.cos_url || item.cloud_url || \'\';
        }).filter(function(url) {
            return url !== \'\';
        });

        if (urls.length === 0) {
            return;
        }

        const text = urls.join(\'\\n\');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function() {
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    };

    window.copyToClipboard = function(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function() {
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    };

    function fallbackCopyToClipboard(text) {
        const textarea = document.createElement(\'textarea\');
        textarea.value = text;
        textarea.style.position = \'fixed\';
        textarea.style.opacity = \'0\';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand(\'copy\');
        } catch (err) {
            // 静默失败
        }
        document.body.removeChild(textarea);
    }

    window.deleteAttachment = function(cosKey) {
        if (!confirm(\'确定要删除这个附件吗？\')) return;

        const xhr = new XMLHttpRequest();
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        alert(\'删除成功\');
                        refreshCosAttachments();
                    } else {
                        alert(\'删除失败: \' + response.message);
                    }
                } catch (e) {
                    alert(\'删除失败\');
                }
            }
        };
        xhr.open("POST", handlerUrl + "?action=delete");
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.send("cos_key=" + encodeURIComponent(cosKey));
    };

    console.log("CloudAttach插件加载完成！");
})();
</script>';
    }

    /**
     * 处理文章内容
     *
     * @access public
     * @return void
     */
    public static function handleContent($archive, $select)
    {
        if ($archive->is('single') && $archive->cid) {
            $db = Db::get();
            $prefix = $db->getPrefix();
            
            $attachments = $db->fetchAll($db->select()->from($prefix . 'cloud_attachments')
                ->where('cid = ?', $archive->cid)
                ->order('created', Db::SORT_DESC));
                
            if ($attachments) {
                $archive->cloudAttachments = $attachments;
                
                $attachmentHtml = '<div class="cloud-attachments-section" style="margin: 30px 0; padding: 25px; background: #f8f9fa; border-radius: 12px; border: 1px solid #e9ecef;">
                    <h3 style="margin: 0 0 20px 0; color: #2c3e50;">📎 相关附件</h3>';
                    
                foreach ($attachments as $att) {
                    $fileName = basename($att['cos_key']);
                    $fileSize = number_format($att['file_size'] / 1024, 2) . ' KB';
                    $isImage = in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), array('jpg', 'jpeg', 'png', 'gif'));
                    
                    $attachmentHtml .= '<div style="display: flex; align-items: center; padding: 15px; background: white; border-radius: 8px; margin-bottom: 10px;">
                        <div style="width: 60px; height: 60px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">';
                    
                    if ($isImage) {
                        $attachmentHtml .= '<img src="' . $att['cos_url'] . '" style="max-width: 100%; max-height: 100%; border-radius: 4px;">';
                    } else {
                        $attachmentHtml .= '<span style="font-size: 24px;">📄</span>';
                    }
                    
                    $attachmentHtml .= '</div>
                        <div style="flex: 1;">
                            <div style="font-weight: 500; color: #333; margin-bottom: 5px;">' . $fileName . '</div>
                            <div style="font-size: 12px; color: #666;">' . $fileSize . '</div>
                        </div>
                        <a href="' . $att['cos_url'] . '" target="_blank" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px;">下载</a>
                    </div>';
                }
                
                $attachmentHtml .= '</div>';
                
                $archive->content .= $attachmentHtml;
            }
        }
    }
}