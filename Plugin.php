<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Fediverse Sync for Typecho
 * 将新文章自动同步到 Mastodon/GoToSocial/Misskey 实例
 * 
 * @package FediverseSync
 * @version 1.6.4
 * @author 老孙
 * @link https://www.imsun.org
 */
class FediverseSync_Plugin implements Typecho_Plugin_Interface
{
    private static function getFileLogPath()
    {
        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/usr/logs/fediverse-sync.log';
    }

    private static function appendFileLog($post_id, $action, $status, $message)
    {
        $logFile = self::getFileLogPath();
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $safeMessage = str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], (string)$message);
        $line = sprintf(
            "[%s] post_id=%d action=%s status=%s message=%s\n",
            date('c'),
            (int)$post_id,
            (string)$action,
            (string)$status,
            $safeMessage
        );

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName = $db->getAdapterName();

        if (stripos($adapterName, 'mysql') !== false) {
            $sqls = [
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `post_id` bigint(20) NOT NULL,
                    `toot_id` varchar(255) NOT NULL,
                    `toot_url` varchar(512) DEFAULT NULL,
                    `instance_url` varchar(255) NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_post_id` (`post_id`),
                    KEY `idx_toot_id` (`toot_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            ];
        } else {
            $sqls = [
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `post_id` INTEGER NOT NULL UNIQUE,
                    `toot_id` VARCHAR(255) NOT NULL,
                    `toot_url` VARCHAR(512),
                    `instance_url` VARCHAR(255) NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );",
            ];
        }

	        try {
	            foreach ($sqls as $sql) {
	                $db->query($sql);
	            }
	            // 再次检测表是否存在：用 Typecho 的 table.* 语法，避免不同数据库的元数据表差异
	            foreach (['fediverse_bindings'] as $table) {
	                try {
	                    $db->fetchRow($db->select()->from('table.' . $table)->limit(1));
	                } catch (Typecho_Db_Exception $e) {
	                    throw new Typecho_Plugin_Exception(_t('数据表未正确创建，请检查数据库权限或手动建表'));
	                }
	            }
	        } catch (Typecho_Db_Exception $e) {
	            throw new Typecho_Plugin_Exception(_t('数据表创建失败：%s', $e->getMessage()));
	        }

        // 注册钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('FediverseSync_Plugin', 'syncToFediverse');
        Helper::addAction('fediverse-sync', 'FediverseSync_Action');
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
            $db->query("DROP TABLE IF EXISTS `{$prefix}fediverse_bindings`");
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

        // 内容长度限制
        $content_length = new Typecho_Widget_Helper_Form_Element_Text(
            'content_length',
            NULL,
            '500',
            _t('原文内容长度限制'),
            _t('当同步内容模板包含 {content} 时生效，用于限制显示的字数（0表示不限制）')
        );
        $form->addInput($content_length);

        // 自定义同步内容模板
        $content_template = new Typecho_Widget_Helper_Form_Element_Textarea(
            'content_template',
            NULL,
            "「{title}」\n\n{permalink}\n\nFrom「{site_name}」",
            _t('同步内容模板'),
            _t('自定义同步到Fediverse的内容模板，支持变量：<br>
                {title} - 文章标题<br>
                {permalink} - 文章链接<br>
                {content} - 文章内容<br>
                {author} - 作者名称<br>
                {created} - 发布时间<br>
                {site_name} - 站点名称<br>
                是否显示原文内容由模板是否包含 {content} 决定<br>
                留空使用默认模板')
        );
        $form->addInput($content_template);

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
        
        // 获取数据库实例
        $db = Typecho_Db::get();
        
        $instance_type = $pluginOptions->instance_type;
        $instance_url = rtrim($pluginOptions->instance_url, '/');
        $access_token = $pluginOptions->access_token;
        $visibility = $pluginOptions->visibility ?? 'public';
        $isDebug = $pluginOptions->debug_mode == '1';

        if (empty($instance_url) || empty($access_token)) {
            self::log(0, 'sync', 'error', '缺少必要的配置信息');
            return $contents;
        }

        // 只在发布新文章时触发同步
        if ($class->request->get('do') !== 'publish') {
            if ($isDebug) {
                self::log(0, 'sync', 'debug', '非发布操作，跳过同步');
            }
            return $contents;
        }

        try {
            // 获取文章ID
            $cid = $contents['cid'] ?? $class->cid;
            
            if (empty($cid)) {
                if ($isDebug) {
                    self::log(0, 'sync', 'debug', '无法获取文章ID，跳过同步');
                }
                return $contents;
            }

            // 检查是否已经同步过
            $existingBinding = $db->fetchRow($db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $cid)
                ->limit(1));

            // 获取文章创建与修改时间
            $post = $db->fetchRow($db->select()
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->limit(1));

            // 只有未同步过 且 created==modified（新建发布）才进行同步
            if ($existingBinding || !$post || $post['created'] != $post['modified']) {
                if ($isDebug) {
                $reason = $existingBinding ? '文章已经同步过' : '不是新建文章（created != modified）';
                self::log($cid, 'sync', 'debug', $reason . '，跳过同步');
                }
                return $contents;
            }

            $title = FediverseSync_Utils_Template::decodeHtmlEntities($contents['title'] ?? '');
            $siteName = FediverseSync_Utils_Template::decodeHtmlEntities($options->title);
            
            // 获取文章完整信息和固定链接
            $permalink = $class->permalink;
            
            if (empty($permalink)) {
                if ($isDebug) {
                    self::log($cid, 'sync', 'error', '无法获取文章链接');
                }
                return $contents;
            }
            
	            // 获取文章内容（是否包含由模板是否包含 {content} 决定）
	            $postContent = '';
	            $rawContent = '';
	            $template = $pluginOptions->content_template ?? FediverseSync_Utils_Template::getDefaultTemplate();
	            if (empty($template)) {
	                $template = FediverseSync_Utils_Template::getDefaultTemplate();
	            }
	            if (strpos($template, '{content}') !== false) {
	                $contentLength = intval($pluginOptions->content_length ?? 500);
	                $rawContent = $contents['text'] ?? ($post['text'] ?? '');
	                $postContent = FediverseSync_Utils_Template::processMarkdownContent($rawContent, $contentLength);
	            }

	            // 获取作者信息
	            $author = '';
	            $authorId = $contents['authorId'] ?? ($post['authorId'] ?? null);
	            if (!empty($authorId)) {
	                $authorRow = $db->fetchRow($db->select('screenName')
	                    ->from('table.users')
	                    ->where('uid = ?', $authorId)
	                    ->limit(1));
	                $author = FediverseSync_Utils_Template::decodeHtmlEntities($authorRow['screenName'] ?? '');
	            }
	            if ($author === '') {
	                try {
	                    $user = Typecho_Widget::widget('Widget_User');
	                    $author = FediverseSync_Utils_Template::decodeHtmlEntities($user->screenName ?? '');
	                } catch (Exception $e) {
	                    // ignore
	                }
	            }

            // 使用模板工具类处理内容
            $templateData = [
                'title' => $title,
                'permalink' => $permalink,
                'content' => $postContent,
                'author' => $author,
                'created' => date('Y-m-d H:i', $post['created']),
                'site_name' => $siteName
            ];
            
            $message = FediverseSync_Utils_Template::parse($template, $templateData);

            // Mastodon/GoToSocial：避免超长导致同步失败，优先缩短 {content}
            if ($instance_type !== 'misskey') {
                $maxCharacters = 500;
                if (mb_strlen($message) > $maxCharacters) {
                    if (strpos($template, '{content}') !== false) {
                        $baseMessage = FediverseSync_Utils_Template::parse($template, array_merge($templateData, ['content' => '']));
                        $available = $maxCharacters - mb_strlen($baseMessage);
                        if ($available > 0) {
                            $templateData['content'] = FediverseSync_Utils_Template::processMarkdownContent($rawContent, $available);
                            $message = FediverseSync_Utils_Template::parse($template, $templateData);
                        }
                    }
                    if (mb_strlen($message) > $maxCharacters) {
                        $message = FediverseSync_Utils_Template::truncate($message, $maxCharacters, '...');
                    }
                }
            }

            if ($isDebug) {
                self::log($cid, 'sync', 'debug', '准备发送消息：' . $message);
            }

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
                    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept: application/json',
                    'User-Agent: FediverseSync/1.6.4'
                ];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $api_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ($instance_type === 'misskey') ? json_encode($post_data) : http_build_query($post_data),
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
                self::log($cid, 'sync', 'debug', 'API Response: ' . $response);
                self::log($cid, 'sync', 'debug', 'HTTP Code: ' . $httpCode);
            }

            if (($httpCode !== 200 && $httpCode !== 204) || empty($response)) {
                throw new Exception('HTTP Error: ' . $httpCode);
            }
            
            // 处理响应并保存绑定关系
            $responseData = json_decode($response, true);
            
            if ($instance_type === 'misskey') {
                // 为Misskey创建绑定关系
                if (isset($responseData['createdNote']['id'])) {
                    $note_id = $responseData['createdNote']['id'];
                    $note_url = $instance_url . '/notes/' . $note_id;
                    
                    // 更新或插入绑定关系
                    $existingBinding = $db->fetchRow($db->select()
                        ->from('table.fediverse_bindings')
                        ->where('post_id = ?', $cid)
                        ->limit(1));
                    
                    if ($existingBinding) {
                        // 更新现有记录
                        $db->query($db->update('table.fediverse_bindings')
                            ->rows([
                                'toot_id' => $note_id,
                                'toot_url' => $note_url,
                                'instance_url' => $instance_url
                            ])
                            ->where('post_id = ?', $cid));
                    } else {
                        // 插入新记录
                        $db->query($db->insert('table.fediverse_bindings')
                            ->rows([
                                'post_id' => $cid,
                                'toot_id' => $note_id,
                                'toot_url' => $note_url,
                                'instance_url' => $instance_url
                            ]));
                    }
                    
                    if ($isDebug) {
                        self::log($cid, 'sync', 'debug', '已更新 Misskey 绑定关系：' . $note_url);
                    }
                }
            } else if (isset($responseData['id']) && isset($responseData['url'])) {
                // 为 Mastodon/GoToSocial 创建绑定关系
                $existingBinding = $db->fetchRow($db->select()
                    ->from('table.fediverse_bindings')
                    ->where('post_id = ?', $cid)
                    ->limit(1));
                
                if ($existingBinding) {
                    // 更新现有记录
                    $db->query($db->update('table.fediverse_bindings')
                        ->rows([
                            'toot_id' => $responseData['id'],
                            'toot_url' => $responseData['url'],
                            'instance_url' => $instance_url
                        ])
                        ->where('post_id = ?', $cid));
                } else {
                    // 插入新记录
                    $db->query($db->insert('table.fediverse_bindings')
                        ->rows([
                            'post_id' => $cid,
                            'toot_id' => $responseData['id'],
                            'toot_url' => $responseData['url'],
                            'instance_url' => $instance_url
                        ]));
                }
                
                if ($isDebug) {
                    self::log($cid, 'sync', 'debug', '已更新 Mastodon/GoToSocial 绑定关系：' . $responseData['url']);
                }
            }

            return $contents;

        } catch (Exception $e) {
            self::log(isset($cid) ? $cid : 0, 'sync', 'error', $e->getMessage());
            return $contents;
        }
    }

    public static function log($post_id, $action, $status, $message)
    {
        try {
            $options = Helper::options()->plugin('FediverseSync');
            if (($options->debug_mode ?? '0') == '1' || $status === 'error') {
                self::appendFileLog($post_id, $action, $status, $message);
            }
        } catch (Exception $e) {
            // ignore file log errors
        }
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人配置项可以留空，因为插件不需要个人级别的配置
    }
}
