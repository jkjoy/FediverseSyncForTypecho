<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Fediverse Sync for Typecho
 * 将新文章自动同步到 Mastodon/GoToSocial 实例
 * 
 * @package FediverseSync 
 * @version 1.1.1
 * @author jkjoy
 * @link https://github.com/jkjoy
 */
class FediverseSync_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName = $db->getAdapterName();
        
        // 根据数据库类型选择合适的建表语句
        if (stripos($adapterName, 'mysql') !== false) {
            // MySQL
            $sqls = [
                // 文章绑定表
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

                // 评论同步表
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_comments` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `post_id` bigint(20) NOT NULL,
                    `comment_id` bigint(20) DEFAULT NULL,
                    `toot_id` varchar(255) NOT NULL,
                    `reply_to_id` varchar(255) DEFAULT NULL,
                    `content` text NOT NULL,
                    `author` varchar(255) NOT NULL,
                    `author_url` varchar(512) DEFAULT NULL,
                    `author_avatar` varchar(512) DEFAULT NULL,
                    `instance_url` varchar(255) NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `synced_at` timestamp NULL DEFAULT NULL,
                    `status` varchar(32) DEFAULT 'pending',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_toot_instance` (`toot_id`, `instance_url`),
                    KEY `idx_post_id` (`post_id`),
                    KEY `idx_comment_id` (`comment_id`),
                    KEY `idx_reply_to_id` (`reply_to_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 同步日志表
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_sync_logs` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `post_id` bigint(20) NOT NULL,
                    `action` varchar(32) NOT NULL,
                    `status` varchar(32) NOT NULL,
                    `message` text,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_logs_post_id` (`post_id`),
                    KEY `idx_logs_status` (`status`),
                    KEY `idx_logs_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ];
        } else {
            // SQLite
            $sqls = [
                // 文章绑定表
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `post_id` INTEGER NOT NULL UNIQUE,
                    `toot_id` VARCHAR(255) NOT NULL,
                    `toot_url` VARCHAR(512),
                    `instance_url` VARCHAR(255) NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );",
                "CREATE INDEX IF NOT EXISTS `idx_bindings_toot_id` ON `{$prefix}fediverse_bindings` (`toot_id`);",

                // 评论同步表
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_comments` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `post_id` INTEGER NOT NULL,
                    `comment_id` INTEGER,
                    `toot_id` VARCHAR(255) NOT NULL,
                    `reply_to_id` VARCHAR(255),
                    `content` TEXT NOT NULL,
                    `author` VARCHAR(255) NOT NULL,
                    `author_url` VARCHAR(512),
                    `author_avatar` VARCHAR(512),
                    `instance_url` VARCHAR(255) NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `synced_at` TIMESTAMP,
                    `status` VARCHAR(32) DEFAULT 'pending'
                );",
                "CREATE UNIQUE INDEX IF NOT EXISTS `uk_toot_instance` ON `{$prefix}fediverse_comments` (`toot_id`, `instance_url`);",
                "CREATE INDEX IF NOT EXISTS `idx_comments_post_id` ON `{$prefix}fediverse_comments` (`post_id`);",
                "CREATE INDEX IF NOT EXISTS `idx_comments_comment_id` ON `{$prefix}fediverse_comments` (`comment_id`);",
                "CREATE INDEX IF NOT EXISTS `idx_comments_reply_to_id` ON `{$prefix}fediverse_comments` (`reply_to_id`);",
                "CREATE INDEX IF NOT EXISTS `idx_comments_status` ON `{$prefix}fediverse_comments` (`status`);",
                "CREATE INDEX IF NOT EXISTS `idx_comments_created_at` ON `{$prefix}fediverse_comments` (`created_at`);",

                // 同步日志表
                "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_sync_logs` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `post_id` INTEGER NOT NULL,
                    `action` VARCHAR(32) NOT NULL,
                    `status` VARCHAR(32) NOT NULL,
                    `message` TEXT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );",
                "CREATE INDEX IF NOT EXISTS `idx_logs_post_id` ON `{$prefix}fediverse_sync_logs` (`post_id`);",
                "CREATE INDEX IF NOT EXISTS `idx_logs_status` ON `{$prefix}fediverse_sync_logs` (`status`);",
                "CREATE INDEX IF NOT EXISTS `idx_logs_created_at` ON `{$prefix}fediverse_sync_logs` (`created_at`);"
            ];
        }

        // 执行建表语句
        foreach ($sqls as $sql) {
            try {
                $db->query($sql);
            } catch (Typecho_Db_Exception $e) {
                // 忽略表已存在的错误
                if (stripos($adapterName, 'mysql') !== false && $e->getCode() != 1050) {
                    throw new Typecho_Plugin_Exception(_t('数据表创建失败：%s', $e->getMessage()));
                } else if (stripos($adapterName, 'sqlite') !== false && 
                         stripos($e->getMessage(), 'already exists') === false) {
                    throw new Typecho_Plugin_Exception(_t('数据表创建失败：%s', $e->getMessage()));
                }
            }
        }

        // 注册钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('FediverseSync_Plugin', 'syncToFediverse');
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('FediverseSync_Plugin', 'syncComments');

        // 添加路由
        Helper::addRoute('fediverse_comments', '/api/fediverse/comments', 'FediverseSync_Api_Comment', 'action');
        // 注册同步 Action
        Helper::addAction('fediverse-sync', 'FediverseSync_Action');
        // 注册后台面板
        Helper::addPanel(1, 'FediverseSync/panel.php', _t('Fediverse 同步'), _t('管理 Fediverse 同步'), 'administrator');

        return _t('插件已经激活，请配置 Fediverse 实例信息');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 注意：我们不再删除数据表，只是移除钩子和路由
     */
    public static function deactivate()
    {
        try {
            // 先移除路由、Action 和面板
            Helper::removeRoute('fediverse_comments');
            Helper::removeAction('fediverse-sync');
            Helper::removePanel(1, 'FediverseSync/panel.php');

            // 检查是否需要删除数据表
            $options = Helper::options()->plugin('FediverseSync');
            if ($options->drop_tables == '1') {
                $db = Typecho_Db::get();
                $prefix = $db->getPrefix();
                $adapterName = $db->getAdapterName();
                
                // 需要删除的表
                $tables = [
                    'fediverse_bindings',
                    'fediverse_comments',
                    'fediverse_sync_logs'
                ];

                // 记录操作日志
                $time = date('Y-m-d H:i:s');
                $user = Typecho_Widget::widget('Widget_User')->screenName;
                error_log("[{$time}] User {$user} is deactivating FediverseSync plugin with table deletion");

                foreach ($tables as $table) {
                    try {
                        // DROP TABLE 语句对 MySQL 和 SQLite 都适用
                        $sql = "DROP TABLE IF EXISTS `{$prefix}{$table}`";
                        $db->query($sql);
                        
                        error_log("[{$time}] Successfully dropped table: {$prefix}{$table}");
                    } catch (Typecho_Db_Exception $e) {
                        // MySQL 和 SQLite 的错误处理可能不同
                        if (stripos($adapterName, 'mysql') !== false) {
                            // MySQL 特定的错误处理
                            if ($e->getCode() != 1051) { // 1051 是"未知表"错误
                                throw $e;
                            }
                        } else {
                            // SQLite 错误处理
                            if (stripos($e->getMessage(), 'no such table') === false) {
                                throw $e;
                            }
                        }
                        error_log("[{$time}] Table {$prefix}{$table} does not exist, skipping");
                    }
                }
                
                return _t('插件已被禁用，相关数据表已删除');
            }

            return _t('插件已被禁用，数据表已保留');

        } catch (Exception $e) {
            // 确保即使发生错误，路由和面板也被移除
            try {
                Helper::removeRoute('fediverse_comments');
                Helper::removeAction('fediverse-sync');
                Helper::removePanel(1, 'FediverseSync/panel.php');
            } catch (Exception $ignored) {}

            error_log('FediverseSync Plugin Deactivation Error: ' . $e->getMessage());
            throw new Typecho_Plugin_Exception(_t('插件禁用过程中发生错误：') . $e->getMessage());
        }
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 实例类型选择
        $instance_type = new Typecho_Widget_Helper_Form_Element_Radio(
            'instance_type',
            array(
                'mastodon' => _t('Mastodon'),
                'gotosocial' => _t('GoToSocial'),
            ),
            'mastodon',
            _t('实例类型'),
            _t('请选择您使用的 Fediverse 实例类型')
        );
        $form->addInput($instance_type);

        // 实例地址
        $instance_url = new Typecho_Widget_Helper_Form_Element_Text(
            'instance_url',
            NULL,
            'https://mastodon.social',
            _t('实例地址'),
            _t('请输入您的实例地址，如 https://mastodon.social')
        );
        $form->addInput($instance_url);
        
        // Access Token
        $access_token = new Typecho_Widget_Helper_Form_Element_Text(
            'access_token',
            NULL,
            '',
            _t('Access Token'),
            _t('请输入您的 Access Token。<br/>Mastodon用户请访问：您的实例地址/settings/applications 创建应用并获取token')
        );
        $form->addInput($access_token);

        // 摘要长度
        $summary_length = new Typecho_Widget_Helper_Form_Element_Text(
            'summary_length',
            NULL,
            '100',
            _t('摘要长度'),
            _t('当文章没有AI摘要时，从正文提取的摘要长度（字数）')
        );
        $form->addInput($summary_length);

        // 可见性设置
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

        // 评论同步设置
        $sync_comments = new Typecho_Widget_Helper_Form_Element_Radio(
            'sync_comments',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '1',
            _t('评论同步'),
            _t('是否同步Fediverse上的回复作为评论')
        );
        $form->addInput($sync_comments);

        // 评论同步间隔
        $sync_interval = new Typecho_Widget_Helper_Form_Element_Text(
            'sync_interval',
            NULL,
            '5',
            _t('评论同步间隔(分钟)'),
            _t('设置从Fediverse获取新评论的时间间隔，建议不要设置太短以避免频繁请求')
        );
        $form->addInput($sync_interval);

        // 评论最大获取数
        $max_comments = new Typecho_Widget_Helper_Form_Element_Text(
            'max_comments',
            NULL,
            '50',
            _t('最大评论获取数'),
            _t('单次同步最多获取的评论数量')
        );
        $form->addInput($max_comments);

        // 是否同步历史文章评论
        $sync_history = new Typecho_Widget_Helper_Form_Element_Radio(
            'sync_history',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '0',
            _t('同步历史评论'),
            _t('是否同步文章发布前的历史评论（可能会增加服务器负载）')
        );
        $form->addInput($sync_history);

        // 评论审核设置
        $comment_audit = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_audit',
            array(
                '1' => _t('需要审核'),
                '0' => _t('直接发布'),
            ),
            '0',
            _t('评论审核'),
            _t('从Fediverse同步的评论是否需要审核后才显示')
        );
        $form->addInput($comment_audit);

        // 评论显示样式
        $comment_style = new Typecho_Widget_Helper_Form_Element_Select(
            'comment_style',
            array(
                'integrated' => _t('与普通评论集成'),
                'separated' => _t('独立显示'),
            ),
            'separated',
            _t('评论显示方式'),
            _t('选择Fediverse评论的显示方式')
        );
        $form->addInput($comment_style);

        // 禁用时删除数据
        $drop_tables = new Typecho_Widget_Helper_Form_Element_Radio(
            'drop_tables',
            array(
                '1' => _t('是'),
                '0' => _t('否'),
            ),
            '0',
            _t('禁用时删除数据'),
            _t('禁用插件时是否删除同步数据表（建议保留数据以便再次启用时使用）')
        );
        $form->addInput($drop_tables);

        // 调试模式
        $debug_mode = new Typecho_Widget_Helper_Form_Element_Radio(
            'debug_mode',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '0',
            _t('调试模式'),
            _t('启用后会记录详细日志，建议仅在出现问题时启用')
        );
        $form->addInput($debug_mode);
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 用户级别的开关
        $enable_sync = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_sync',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ),
            '1',
            _t('启用同步'),
            _t('是否启用文章同步到Fediverse（仅影响当前用户）')
        );
        $form->addInput($enable_sync);
    }

    /**
     * 同步文章到 Fediverse
     * 
     * @param array $contents 文章内容
     * @param Widget_Contents_Post_Edit $class 文章编辑对象
     * @return array
     */
    public static function syncToFediverse($contents, $class)
    {
        // 获取系统配置
        $options = Helper::options();
        $pluginOptions = $options->plugin('FediverseSync');
        
        $instance_type = $pluginOptions->instance_type;
        $instance_url = rtrim($pluginOptions->instance_url, '/');
        $access_token = $pluginOptions->access_token;
        $summary_length = intval($pluginOptions->summary_length ?? 200);
        $visibility = $pluginOptions->visibility ?? 'public';
        $isDebug = isset($pluginOptions->debug_mode) && $pluginOptions->debug_mode == '1';

        if ($isDebug) {
            error_log('FediverseSync Debug - Starting sync process');
            error_log('FediverseSync Debug - Raw contents: ' . print_r($contents, true));
        }

        // 检查必要配置
        if (empty($instance_url) || empty($access_token)) {
            self::log(0, 'sync', 'error', '缺少必要的配置信息');
            return $contents;
        }

        try {
            // 获取文章标题和数据
            $title = isset($contents['title']) ? $contents['title'] : '';
            
            // 获取文章数据
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select()
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->where('title = ?', $title)
                ->order('created', Typecho_Db::SORT_DESC)
                ->limit(1));

            if (empty($row)) {
                if ($isDebug) {
                    error_log('FediverseSync Debug - Could not find the post in database');
                }
                return $contents;
            }

            if ($isDebug) {
                error_log('FediverseSync Debug - Found post data: ' . print_r($row, true));
            }

            // 检查是否已经同步过
            $binding = $db->fetchRow($db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $row['cid']));

            if ($binding && !isset($contents['forceSync'])) {
                if ($isDebug) {
                    error_log('FediverseSync Debug - Post already synced: ' . $row['cid']);
                }
                return $contents;
            }

            // 使用 Widget_Abstract_Contents 获取永久链接
            $widget = new Widget_Abstract_Contents($class->request, $class->response);
            $widget->push($row);
            $permalink = $widget->permalink;

            // 如果上面的方法获取失败，尝试其他方式
            if (empty($permalink)) {
                $routeExists = (NULL != Typecho_Router::get('post'));
                if ($routeExists) {
                    $permalink = Typecho_Router::url('post', $row);
                } else {
                    $permalink = Typecho_Common::url('index.php/archives/' . $row['cid'], $options->siteUrl);
                }
            }

            // 获取文章摘要
            $text = isset($contents['text']) ? $contents['text'] : '';
            $text = str_replace('<!--markdown-->', '', $text);
            $text = strip_tags($text);
            $summary = mb_strlen($text) > $summary_length ? 
                      mb_substr($text, 0, $summary_length) . '...' : 
                      $text;

            // 构建消息内容
            $message = "## {$title}\n\n";
            $message .= $summary . "\n\n";
            $message .= "🔗 阅读全文: {$permalink}";

            if ($isDebug) {
                error_log('FediverseSync Debug - Prepared message: ' . $message);
            }

            // 准备发送的数据
            $post_data = [
                'status' => $message,
                'visibility' => $visibility
            ];

            if ($instance_type !== 'mastodon') {
                $post_data['content_type'] = 'text/markdown';
            }

            // 发送到 Fediverse
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $instance_url . '/api/v1/statuses',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($post_data),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                    'Accept: */*',
                    'User-Agent: TypechoFediverseSync/1.0.5'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('CURL错误: ' . curl_error($ch));
            }

            curl_close($ch);

            $tootData = json_decode($response, true);
            if (!isset($tootData['id']) || !isset($tootData['url'])) {
                throw new Exception('发送失败 (HTTP ' . $http_code . '): ' . $response);
            }

            // 保存或更新绑定关系
            $binding_data = [
                'post_id' => $row['cid'],
                'toot_id' => $tootData['id'],
                'toot_url' => $tootData['url'],
                'instance_url' => $instance_url
            ];

            if ($binding) {
                $db->query($db->update('table.fediverse_bindings')
                    ->rows($binding_data)
                    ->where('post_id = ?', $row['cid']));
            } else {
                $db->query($db->insert('table.fediverse_bindings')->rows($binding_data));
            }

            self::log($row['cid'], 'sync', 'success', sprintf(
                '同步成功：%s -> %s',
                $permalink,
                $tootData['url']
            ));

            return $contents;

        } catch (Exception $e) {
            $errorCid = isset($row['cid']) ? $row['cid'] : 0;
            self::log($errorCid, 'sync', 'error', $e->getMessage());
            error_log('FediverseSync Error: ' . $e->getMessage());
            if ($isDebug) {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
            return $contents;
        }
    }

    /**
     * 同步评论
     */
    public static function syncComments($archive)
    {
        if ($archive->is('single') && $archive->is('post')) {
            $commentModel = new FediverseSync_Models_Comment();
            $comments = $commentModel->syncCommentsForPost($archive->cid);
            
            // 将评论数据添加到上下文中
            $archive->setThemeFile('post.php');
            $archive->fediverse_comments = $comments;
        }
    }

    /**
     * 获取文章关联的Fediverse评论
     * 供模板调用的公共方法
     */
    public static function getComments($post_id)
    {
        $commentModel = new FediverseSync_Models_Comment();
        return $commentModel->getComments($post_id);
    }
    /**
     * 渲染 Fediverse 评论
     * 
     * @param array $comments 评论数据数组
     * @return string 评论HTML
     */
    public static function renderFediverseComments($comments)
    {
        $html = '<div class="fediverse-comments">';
        
        // 直接从当前上下文获取文章ID
        try {
            $cid = Typecho_Widget::widget('Widget_Archive')->cid;
            // 获取文章的绑定信息
            $db = Typecho_Db::get();
            $binding = $db->fetchRow($db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $cid)
                ->limit(1));
            
            // 标题部分
            $html .= '<div class="fediverse-comments-header">';
            $html .= '<h3><svg class="fediverse-icon" viewBox="0 0 24 24" width="24" height="24">
                <path fill="currentColor" d="M21.327 8.566c0-4.339-2.843-5.61-2.843-5.61-1.433-.658-3.894-.935-6.451-.956h-.063c-2.557.021-5.016.298-6.45.956 0 0-2.843 1.272-2.843 5.61 0 .993-.019 2.181.012 3.441.103 4.243.778 8.425 4.701 9.463 1.809.479 3.362.579 4.612.51 2.268-.126 3.541-.809 3.541-.809l-.075-1.646s-1.621.511-3.441.449c-1.804-.062-3.707-.194-3.999-2.409a4.523 4.523 0 0 1-.04-.621s1.77.433 4.014.535c1.372.063 2.658-.08 3.965-.236 2.506-.299 4.688-1.843 4.962-3.254.434-2.223.398-5.424.398-5.424zm-3.353 5.59h-2.081V9.057c0-1.075-.452-1.62-1.357-1.62-1 0-1.501.647-1.501 1.927v2.791h-2.069V9.364c0-1.28-.501-1.927-1.502-1.927-.905 0-1.357.546-1.357 1.62v5.099H6.026V8.903c0-1.074.273-1.927.823-2.558.566-.631 1.307-.955 2.228-.955 1.065 0 1.872.409 2.405 1.228l.518.869.519-.869c.533-.819 1.34-1.228 2.405-1.228.92 0 1.662.324 2.228.955.549.631.822 1.484.822 2.558v5.253z"/></svg>';
            
            // 如果找到绑定信息且有嘟文链接，添加带链接的标题
            if ($binding && !empty($binding['toot_url'])) {
                $html .= '来自 <a href="' . htmlspecialchars($binding['toot_url']) . 
                        '" target="_blank" title="' . _t('去 Fediverse 中评论') . '">' . 
                        _t('Fediverse ') . '</a><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M10 6V8H5V19H16V14H18V20C18 20.5523 17.5523 21 17 21H4C3.44772 21 3 20.5523 3 20V7C3 6.44772 3.44772 6 4 6H10ZM21 3V11H19L18.9999 6.413L11.2071 14.2071L9.79289 12.7929L17.5849 5H13V3H21Z"></path></svg>的评论';
            } else {
                $html .= _t('评论');
            }
            $html .= '</h3>';
            $html .= '<div class="fediverse-comments-count">' . count($comments) . ' ' . _t('条评论') . '</div>';
            $html .= '</div>';
            
        } catch (Exception $e) {
            error_log('FediverseSync Error in renderFediverseComments: ' . $e->getMessage());
        }
        
        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $html .= '<div class="fediverse-comment">';
                $html .= '<div class="comment-metadata">';
                
                // 作者信息
                $html .= '<div class="comment-author">';
                if (!empty($comment['author_url'])) {
                    $html .= '<a href="' . htmlspecialchars($comment['author_url']) . 
                            '" target="_blank" rel="nofollow" class="author-link">';
                }
                $html .= '<span class="author-name">' . htmlspecialchars($comment['author']) . '</span>';
                if (!empty($comment['author_url'])) {
                    $html .= '</a>';
                }
                
                // 时间
                $html .= '<span class="comment-time" title="' . date('Y-m-d H:i:s', strtotime($comment['created_at'])) . '">' . 
                        self::timeAgo(strtotime($comment['created_at'])) . '</span>';
                $html .= '</div>';
                $html .= '</div>';
                
                // 评论内容
                $html .= '<div class="comment-content">' . $comment['content'] . '</div>';
                
                $html .= '</div>';
            }
        } else {
            $html .= '<div class="no-comments">';
            $html .= '<svg class="empty-icon" viewBox="0 0 24 24" width="32" height="32">
                        <path fill="currentColor" d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                     </svg>';
            $html .= '<p>' . _t('暂无评论') . '</p>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // 添加样式
        $html .= '<style>.fediverse-comments{max-width:100%;margin:2em 0;padding:1.5em;background:#ffffff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);color:#2d3748;}.fediverse-comments-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5em;padding-bottom:1em;border-bottom:1px solid #edf2f7;}.fediverse-comments-header h3{margin:0;font-size:1.25em;color:#2d3748;display:flex;align-items:center;gap:0.5em;}.fediverse-icon{color:#563acc;}.fediverse-comments-count{color:#718096;font-size:0.9em;}.fediverse-comment{padding:1.25em;margin-bottom:1em;background:#f8fafc;border-radius:8px;transition:all 0.2s ease;}.fediverse-comment:hover{background:#f1f5f9;}.fediverse-comment:last-child{margin-bottom:0;}.comment-metadata{margin-bottom:0.75em;}.comment-author{display:flex;align-items:center;gap:1em;}.author-link{text-decoration:none;color:inherit;}.author-name{font-weight:600;color:#2d3748;}.comment-time{color:#718096;font-size:0.875em;}.comment-content{color:#2d3748;line-height:1.6;overflow-wrap:break-word;}.comment-content p{margin:0 0 1em;}.comment-content p:last-child{margin-bottom:0;}.comment-content a{color:#563acc;text-decoration:none;border-bottom:1px solid transparent;transition:border-color 0.2s ease;}.comment-content a:hover{border-bottom-color:#563acc;}.no-comments{padding:2em;text-align:center;color:#718096;display:flex;flex-direction:column;align-items:center;gap:1em;}.empty-icon{color:#cbd5e0;}.dark .fediverse-comments{backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);background:#1a1b1e;box-shadow:0 2px 12px rgba(0,0,0,0.3);color:#e2e8f0;}.dark .fediverse-comments-header{border-bottom:1px solid #2d3748;}.dark .fediverse-comments-header h3{color:#e2e8f0;}.dark .fediverse-comment{background:#2d2e32;border:1px solid #2d3748;}.dark .fediverse-comment:hover{background:#34353a;}.dark .author-name{color:#e2e8f0;}.dark .comment-content{color:#e2e8f0;}.dark .comment-content a{text-decoration:underline;text-decoration-color:rgba(124,93,255,0.4);text-underline-offset:2px;}.dark .comment-content a:hover{text-decoration-color:#7c5dff;}.dark .comment-content img{opacity:0.8;transition:opacity 0.2s ease;}.dark .comment-content img:hover{opacity:1;}@media (max-width:768px){.fediverse-comments{padding:1em;}.fediverse-comments-header{flex-direction:column;align-items:flex-start;gap:0.5em;}.fediverse-comment{padding:1em;}}</style>';
        
        
        return $html;
    }

    /**
     * 时间转换为友好格式
     * 
     * @param int $timestamp
     * @return string
     */
    private static function timeAgo($timestamp)
    {
        $current_time = time();
        $diff = $current_time - $timestamp;
        
        if ($diff < 60) {
            return _t('刚刚');
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return sprintf(_t('%d 分钟前'), $minutes);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(_t('%d 小时前'), $hours);
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return sprintf(_t('%d 天前'), $days);
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return sprintf(_t('%d 个月前'), $months);
        } else {
            return date('Y-m-d', $timestamp);
        }
    }

    /**
     * 获取 Fediverse 评论数据
     * 供主题调用的公共方法
     * 
     * @param int $cid 文章ID
     * @return array 评论数据数组
     */
    public static function getFediverseComments($cid)
    {
        try {
            $commentModel = new FediverseSync_Models_Comment();
            $comments = $commentModel->getComments($cid);
            
            // 如果评论同步功能开启且设置了自动同步，则同步最新评论
            $options = Helper::options()->plugin('FediverseSync');
            if ($options->sync_comments == '1') {
                // 检查是否需要同步
                $lastSync = isset($GLOBALS['lastCommentSync_' . $cid]) ? 
                           $GLOBALS['lastCommentSync_' . $cid] : 0;
                $interval = intval($options->sync_interval ?? 5) * 60;
                
                if (time() - $lastSync >= $interval) {
                    $commentModel->syncCommentsForPost($cid);
                    $GLOBALS['lastCommentSync_' . $cid] = time();
                    // 重新获取评论
                    $comments = $commentModel->getComments($cid);
                }
            }
            
            return $comments;
        } catch (Exception $e) {
            error_log('FediverseSync Error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 输出自定义样式
     *
     * @access public
     * @return void
     */
    public static function renderHeader()
    {
    }

    /**
     * 记录日志到数据库
     * 
     * @param string|int $post_id 文章ID
     * @param string $action 操作类型
     * @param string $status 状态
     * @param string $message 消息内容
     * @return void
     */
    private static function log($post_id, $action, $status, $message)
    {
        try {
            $db = Typecho_Db::get();
            $adapterName = $db->getAdapterName();
            
            // 准备日志数据
            $data = array(
                'post_id' => (int)$post_id,
                'action' => $action,
                'status' => $status,
                'message' => $message
            );

            // 获取插件配置
            $options = Helper::options();
            $isDebug = isset($options->plugin('FediverseSync')->debug_mode) 
                      && $options->plugin('FediverseSync')->debug_mode == '1';

            // 调试模式下记录详细信息
            if ($isDebug) {
                error_log(sprintf(
                    'FediverseSync Log - Post: %d, Action: %s, Status: %s, Message: %s',
                    $post_id,
                    $action,
                    $status,
                    $message
                ));
            }

            // 检查表是否存在并尝试插入
            try {
                $db->query($db->insert('table.fediverse_sync_logs')->rows($data));
            } catch (Typecho_Db_Exception $e) {
                // 如果表不存在，尝试创建
                if ((stripos($adapterName, 'mysql') !== false && $e->getCode() == 1146) ||
                    (stripos($adapterName, 'sqlite') !== false && stripos($e->getMessage(), 'no such table') !== false)) {
                    
                    $prefix = $db->getPrefix();
                    if (stripos($adapterName, 'mysql') !== false) {
                        // MySQL
                        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_sync_logs` (
                            `id` bigint(20) NOT NULL AUTO_INCREMENT,
                            `post_id` bigint(20) NOT NULL,
                            `action` varchar(32) NOT NULL,
                            `status` varchar(32) NOT NULL,
                            `message` text,
                            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (`id`),
                            KEY `idx_logs_post_id` (`post_id`),
                            KEY `idx_logs_status` (`status`),
                            KEY `idx_logs_created_at` (`created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                        
                        $db->query($sql);
                    } else {
                        // SQLite
                        $sqls = [
                            "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_sync_logs` (
                                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                                `post_id` INTEGER NOT NULL,
                                `action` VARCHAR(32) NOT NULL,
                                `status` VARCHAR(32) NOT NULL,
                                `message` TEXT,
                                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            );",
                            "CREATE INDEX IF NOT EXISTS `idx_logs_post_id` ON `{$prefix}fediverse_sync_logs` (`post_id`);",
                            "CREATE INDEX IF NOT EXISTS `idx_logs_status` ON `{$prefix}fediverse_sync_logs` (`status`);",
                            "CREATE INDEX IF NOT EXISTS `idx_logs_created_at` ON `{$prefix}fediverse_sync_logs` (`created_at`);"
                        ];

                        foreach ($sqls as $sql) {
                            $db->query($sql);
                        }
                    }

                    // 重试插入
                    $db->query($db->insert('table.fediverse_sync_logs')->rows($data));
                } else {
                    // 其他错误则抛出
                    throw $e;
                }
            }

        } catch (Exception $e) {
            // 记录错误到系统日志
            error_log(sprintf(
                'FediverseSync Log Error: %s - Post: %d, Action: %s, Status: %s',
                $e->getMessage(),
                $post_id,
                $action,
                $status
            ));
        }
    }
}