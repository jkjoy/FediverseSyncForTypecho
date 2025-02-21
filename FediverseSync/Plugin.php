<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Fediverse Sync for Typecho
 * 将新文章自动同步到 Mastodon/GoToSocial 实例
 * 
 * @package FediverseSync 
 * @version 1.1.0
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
        
        // 修改后的建表语句，移除 unsigned 属性，使用通用的数据类型
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_bindings` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `post_id` INTEGER NOT NULL,
            `toot_id` VARCHAR(255) NOT NULL,
            `toot_url` VARCHAR(255), 
            `instance_url` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )";
        
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$prefix}fediverse_comments` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `post_id` INTEGER NOT NULL,
            `toot_id` VARCHAR(255) NOT NULL,
            `reply_id` VARCHAR(255) NOT NULL,
            `content` TEXT NOT NULL,
            `toot_url` VARCHAR(255), 
            `author` VARCHAR(255) NOT NULL,
            `author_url` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )";
        
        try {
            // 创建数据表
            $db->query($sql);
            $db->query($sql2);
            
            // 创建索引
            $db->query("CREATE INDEX IF NOT EXISTS `idx_post_id` ON `{$prefix}fediverse_bindings` (`post_id`)");
            $db->query("CREATE INDEX IF NOT EXISTS `idx_toot_id` ON `{$prefix}fediverse_bindings` (`toot_id`)");
            $db->query("CREATE INDEX IF NOT EXISTS `idx_comment_post_id` ON `{$prefix}fediverse_comments` (`post_id`)");
            $db->query("CREATE INDEX IF NOT EXISTS `idx_comment_toot_id` ON `{$prefix}fediverse_comments` (`toot_id`)");

        } catch (Typecho_Db_Exception $e) {
            throw new Typecho_Plugin_Exception(_t('数据表建立失败，请检查数据库权限！') . $e->getMessage());
        }

        // 注册钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('FediverseSync_Plugin', 'syncToFediverse');
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('FediverseSync_Plugin', 'syncComments');
                // 添加评论列表标题的钩子
 //       Typecho_Plugin::factory('admin/manage-comments.php')->title = array('FediverseSync_Plugin', 'appendTootLink');

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
        Helper::removeRoute('fediverse_comments');
        return _t('插件已被禁用，数据表已保留');
        // 删除注册的 Action 和面板
        Helper::removeAction('fediverse-sync');
        Helper::removePanel(1, 'FediverseSync/panel.php');

        // +++ 新增数据表删除逻辑 +++
        $options = Helper::options()->plugin('FediverseSync');
        if ($options->drop_tables == '1') {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            
            try {
                $db->query("DROP TABLE IF EXISTS `{$prefix}fediverse_bindings`");
                $db->query("DROP TABLE IF EXISTS `{$prefix}fediverse_comments`");
            } catch (Typecho_Db_Exception $e) {
                throw new Typecho_Plugin_Exception(_t('数据表删除失败：') . $e->getMessage());
            }
            
            return _t('插件已被禁用，相关数据表已删除');
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
     * @param Widget_Contents_Post_Edit $class
     * @return void
     */
    public static function syncToFediverse($contents, $class)
    {
        // 调试日志
        if (Helper::options()->plugin('FediverseSync')->debug_mode == '1') {
            error_log('FediverseSync Debug - Contents: ' . print_r([
                'cid' => isset($contents['cid']) ? $contents['cid'] : 'not set',
                'type' => isset($contents['type']) ? $contents['type'] : 'not set',
                'status' => isset($contents['status']) ? $contents['status'] : 'not set',
                'visibility' => isset($contents['visibility']) ? $contents['visibility'] : 'not set',
                'modified' => isset($contents['modified']) ? 'yes' : 'no'
            ], true));
        }

        // 1. 基本类型和状态检查
        if (!isset($contents['type']) || $contents['type'] != 'post' || 
            $contents['visibility'] != 'publish') {
            return;
        }

        // 2. 判断是否为新文章
        // 检查数据库中是否已存在该文章
        $db = Typecho_Db::get();
        $isNewPost = !$db->fetchRow($db->select('cid')
            ->from('table.contents')
            ->where('cid = ?', $class->cid)
            ->limit(1));

        // 如果不是新文章，直接返回
        if (!$isNewPost) {
            if (Helper::options()->plugin('FediverseSync')->debug_mode == '1') {
                error_log('FediverseSync Debug - Skipping sync for existing post: ' . $class->cid);
            }
            return;
        }

        try {
            // 获取正确的文章ID和永久链接
            $cid = $class->cid;
            $permalink = $class->permalink;

            // 如果还是获取不到链接，尝试构建
            if (empty($permalink)) {
                $options = Helper::options();
                $routeExists = (NULL != Typecho_Router::get('post'));
                
                if ($routeExists) {
                    $permalink = Typecho_Router::url('post', $contents);
                } else {
                    $permalink = Typecho_Common::url(
                        'index.php/archives/' . $cid, 
                        $options->siteUrl
                    );
                }
            }

            // 获取系统配置
            $options = Helper::options()->plugin('FediverseSync');

            // 再次检查是否已同步
            $existingBinding = $db->fetchRow($db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $cid)
                ->limit(1));

            if ($existingBinding) {
                if ($options->debug_mode == '1') {
                    error_log('FediverseSync Debug - Post already synced: ' . $cid);
                }
                return;
            }

            // 准备同步数据
            $sync = new FediverseSync_Api_Sync();
            $response = $sync->postToFediverse([
                'cid' => $cid,
                'title' => $contents['title'],
                'text' => $contents['text'],
                'permalink' => $permalink
            ]);
            
            if ($response && isset($response['id'])) {
                // 从响应中获取嘟文URL
                $toot_url = $response['url'];
                
                // 如果响应中没有URL，则构建一个
                if (empty($toot_url)) {
                    $instance_url = rtrim($options->instance_url, '/');
                    $toot_url = $instance_url . '/@' . 
                               (isset($response['account']['acct']) ? 
                                $response['account']['acct'] : 
                                $response['account']['username']) . 
                               '/' . $response['id'];
                }

                // 保存文章和嘟文的绑定关系
                $binding = new FediverseSync_Models_Binding();
                $binding->saveBinding([
                    'post_id' => $cid,
                    'toot_id' => $response['id'],
                    'instance_url' => $options->instance_url,
                    'toot_url' => $toot_url
                ]);

                if ($options->debug_mode == '1') {
                    error_log('FediverseSync: Successfully synced new post ' . $cid . ' to ' . $toot_url);
                }
            }
        } catch (Exception $e) {
            error_log('FediverseSync Error: ' . $e->getMessage());
            if (isset($options->debug_mode) && $options->debug_mode == '1') {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
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

}