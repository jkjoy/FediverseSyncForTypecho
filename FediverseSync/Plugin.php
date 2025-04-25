<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Fediverse Sync for Typecho
 * 将新文章自动同步到 Mastodon/GoToSocial/Misskey 实例
 * 
 * @package FediverseSync 
 * @version 1.5.0
 * @author 老孙
 * @link https://www.imsun.org
 */
class FediverseSync_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName = $db->getAdapterName();
        
        if (stripos($adapterName, 'mysql') !== false) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `post_id` bigint(20) NOT NULL,
                `toot_id` varchar(255) NOT NULL,
                `toot_url` varchar(512) DEFAULT NULL,
                `instance_url` varchar(255) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_post_id` (`post_id`),
                KEY `idx_toot_id` (`toot_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `post_id` INTEGER NOT NULL UNIQUE,
                `toot_id` VARCHAR(255) NOT NULL,
                `toot_url` VARCHAR(512),
                `instance_url` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
        }

        try {
            $db->query($sql);
        } catch (Typecho_Db_Exception $e) {
            if (!(stripos($adapterName, 'mysql') !== false && $e->getCode() == 1050) &&
                !(stripos($adapterName, 'sqlite') !== false && stripos($e->getMessage(), 'already exists') !== false)) {
                throw new Typecho_Plugin_Exception(_t('数据表创建失败：%s', $e->getMessage()));
            }
        }

        // 注册文章发布和修改钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('FediverseSync_Plugin', 'syncToFediverse');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishSave = array('FediverseSync_Plugin', 'syncToFediverse');
        
        // 注册同步Action
        Helper::addAction('fediverse-sync', 'FediverseSync_Action');
        
        // 注册后台面板
        Helper::addPanel(1, 'FediverseSync/panel.php', _t('Fediverse 同步'), _t('管理 Fediverse 同步'), 'administrator');

        return _t('插件已经激活，请配置 Fediverse 实例信息');
    }
    
    public static function deactivate()
    {
        Helper::removeAction('fediverse-sync');
        Helper::removePanel(1, 'FediverseSync/panel.php');

        $options = Helper::options()->plugin('FediverseSync');
        if ($options->drop_tables == '1') {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $sql = "DROP TABLE IF EXISTS `{$prefix}fediverse_bindings`";
            $db->query($sql);
            return _t('插件已被禁用，数据表已删除');
        }

        return _t('插件已被禁用，数据表已保留');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $instance_type = new Typecho_Widget_Helper_Form_Element_Radio(
            'instance_type',
            array(
                'mastodon' => _t('Mastodon'),
                'misskey' => _t('Misskey'),
            ),
            'mastodon',
            _t('实例类型'),
            _t('请选择您使用的 Fediverse 实例类型')
        );
        $form->addInput($instance_type);

        $instance_url = new Typecho_Widget_Helper_Form_Element_Text(
            'instance_url',
            NULL,
            'https://mastodon.social',
            _t('实例地址'),
            _t('请输入您的实例地址，如 https://mastodon.social')
        );
        $form->addInput($instance_url);
        
        $access_token = new Typecho_Widget_Helper_Form_Element_Text(
            'access_token',
            NULL,
            '',
            _t('Access Token'),
            _t('请输入您的 Access Token')
        );
        $form->addInput($access_token);

        $visibility = new Typecho_Widget_Helper_Form_Element_Radio(
            'visibility',
            array(
                'public' => _t('公开'),
                'unlisted' => _t('不公开'),
                'private' => _t('仅关注者'),
            ),
            'public',
            _t('文章可见性'),
            _t('选择同步到 Fediverse 时的文章可见性')
        );
        $form->addInput($visibility);

        $debug_mode = new Typecho_Widget_Helper_Form_Element_Radio(
            'debug_mode',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '0',
            _t('调试模式'),
            _t('启用详细日志')
        );
        $form->addInput($debug_mode);
        
        $api_timeout = new Typecho_Widget_Helper_Form_Element_Text(
            'api_timeout',
            NULL,
            '30',
            _t('API超时(秒)'),
            _t('设置API请求超时时间')
        );
        $form->addInput($api_timeout);
        
        // 添加删除数据表选项（仅在插件禁用时使用）
        $drop_tables = new Typecho_Widget_Helper_Form_Element_Radio(
            'drop_tables',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '0',
            _t('禁用时删除数据表'),
            _t('禁用插件时是否删除插件创建的数据表')
        );
        $form->addInput($drop_tables);
    }

    public static function syncToFediverse($contents, $class)
    {
        $options = Helper::options();
        $pluginOptions = $options->plugin('FediverseSync');
        
        $instance_type = $pluginOptions->instance_type;
        $instance_url = rtrim($pluginOptions->instance_url, '/');
        $access_token = $pluginOptions->access_token;
        $visibility = $pluginOptions->visibility ?? 'public';
        $isDebug = $pluginOptions->debug_mode == '1';

        if (empty($instance_url) || empty($access_token)) {
            self::log(0, 'sync', 'error', '缺少必要的配置信息');
            return $contents;
        }

        try {
            $title = $contents['title'] ?? '';
            
            // 获取站点名称
            $siteName = $options->title;
            
            // 新的消息格式：不再包含摘要
            $message = "「{$siteName}」发布新文章「{$title}」\n\n访问地址：{$contents['permalink']}";

            if ($instance_type === 'misskey') {
                // Misskey API 处理
                $api_url = $instance_url . '/api/notes/create';
                
                // Misskey的可见性设置与Mastodon不同
                $misskey_visibility = 'public';
                switch ($visibility) {
                    case 'private':
                        $misskey_visibility = 'followers';
                        break;
                    case 'unlisted':
                        $misskey_visibility = 'home';
                        break;
                    default:
                        $misskey_visibility = 'public';
                }
                
                $post_data = [
                    'i' => $access_token,  // Misskey使用i参数传递访问令牌
                    'text' => $message,
                    'visibility' => $misskey_visibility
                ];
                
                $headers = [
                    'Content-Type: application/json'
                ];
            } else {
                // Mastodon/GoToSocial API
                $api_url = $instance_url . '/api/v1/statuses';
                
                $post_data = [
                    'status' => $message,
                    'visibility' => $visibility
                ];
                
                $headers = [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json'
                ];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $api_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($post_data),
                CURLOPT_HTTPHEADER => $headers
            ]);
            
            // 设置超时
            if (!empty($pluginOptions->api_timeout)) {
                curl_setopt($ch, CURLOPT_TIMEOUT, intval($pluginOptions->api_timeout));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($isDebug) {
                self::log(isset($contents['cid']) ? $contents['cid'] : 0, 'sync', 'debug', 'API Response: ' . $response);
            }

            if (($httpCode !== 200 && $httpCode !== 204) || empty($response)) {
                throw new Exception('HTTP Error: ' . $httpCode);
            }
            
            // 处理响应并保存绑定关系
            $responseData = json_decode($response, true);
            
            if ($instance_type === 'misskey' && isset($responseData['createdNote']['id'])) {
                // 为Misskey创建绑定关系
                $note_id = $responseData['createdNote']['id'];
                $note_url = $instance_url . '/notes/' . $note_id;
                
                // 使用数据库创建绑定
                if (isset($contents['cid'])) {
                    $db = Typecho_Db::get();
                    $data = [
                        'post_id' => $contents['cid'],
                        'toot_id' => $note_id,
                        'toot_url' => $note_url,
                        'instance_url' => $instance_url
                    ];
                    
                    $db->query($db->insert('table.fediverse_bindings')->rows($data));
                }
            }

            return $contents;

        } catch (Exception $e) {
            self::log(isset($contents['cid']) ? $contents['cid'] : 0, 'sync', 'error', $e->getMessage());
            return $contents;
        }
    }

    private static function log($post_id, $action, $status, $message)
    {
        try {
            $db = Typecho_Db::get();
            $data = [
                'post_id' => (int)$post_id,
                'action' => $action,
                'status' => $status,
                'message' => $message
            ];
            $db->query($db->insert('table.fediverse_sync_logs')->rows($data));
        } catch (Exception $e) {
            error_log('FediverseSync Log Error: ' . $e->getMessage());
        }
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人配置项可以留空，因为插件不需要个人级别的配置
    }
}
