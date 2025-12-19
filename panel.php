<?php
include 'header.php';
include 'menu.php';
?>
<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <?php
                $config = get_sync_config_info();
                $posts = get_posts_for_sync();
                ?>
                <div class="sync-config-info">
                    <h3>同步配置信息</h3>
                    <div class="config-item">
                        <span class="config-label">实例类型：</span>
                        <span class="config-value"><?php echo htmlspecialchars($config['instance_type']); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">可见性：</span>
                        <span class="config-value"><?php echo htmlspecialchars($config['visibility']); ?></span>
                    </div>
                    <div class="config-item">
                        <span class="config-label">显示原文：</span>
                        <span class="config-value"><?php echo $config['show_content'] ? '是' : '否'; ?></span>
                    </div>
                    <?php if ($config['show_content']): ?>
                    <div class="config-item">
                        <span class="config-label">内容长度限制：</span>
                        <span class="config-value"><?php echo $config['content_length']; ?> 字</span>
                    </div>
                    <?php endif; ?>
                    <div class="config-item">
                        <span class="config-label">内容模板：</span>
                        <div class="config-value template-preview">
                            <pre><?php echo htmlspecialchars($config['template']); ?></pre>
                        </div>
                    </div>
                    <div class="config-help">
                        <p><strong>模板变量说明：</strong></p>
                        <ul>
                            <li><code>{title}</code> - 文章标题</li>
                            <li><code>{permalink}</code> - 文章链接</li>
                            <li><code>{content}</code> - 文章内容</li>
                            <li><code>{author}</code> - 作者名称</li>
                            <li><code>{created}</code> - 发布时间</li>
                            <li><code>{site_name}</code> - 站点名称</li>
                        </ul>
                    </div>
                </div>

                <div class="typecho-list-operate clearfix">
                    <form method="post" action="<?php $security->index('/action/fediverse-sync?do=sync'); ?>">
                        <div class="operate">
                            <button type="submit" class="btn btn-s btn-warn btn-operate"><?php _e('同步到 Fediverse'); ?></button>
                        </div>
                    
                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table">
                                <colgroup>
                                    <col width="20"/>
                                    <col width="50%"/>
                                    <col width=""/>
                                    <col width=""/>
                                    <col width=""/>
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th class="typecho-item-check"> </th>
                                        <th><?php _e('标题'); ?></th>
                                        <th><?php _e('作者'); ?></th>
                                        <th><?php _e('发布时间'); ?></th>
                                        <th><?php _e('同步状态'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $posts = get_posts_for_sync();
                                    if (!empty($posts)): ?>
                                        <?php foreach ($posts as $post): ?>
                                        <tr id="post-<?php echo htmlspecialchars($post['cid']); ?>">
                                            <td class="typecho-item-check">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($post['cid']); ?>" name="cid[]"/>
                                            </td>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($post['permalink']); ?>" target="_blank">
                                                    <?php echo htmlspecialchars($post['title']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($post['author']); ?></td>
                                            <td><?php echo date('Y-m-d H:i', $post['created']); ?></td>
                                            <td>
                                                <?php if ($post['synced']): ?>
                                                <a href="<?php echo htmlspecialchars($post['toot_url']); ?>" target="_blank" class="status-synced">
                                                    <?php _e('已同步'); ?>
                                                </a>
                                                <?php else: ?>
                                                <span class="status-not-synced"><?php _e('未同步'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="5"><h6 class="typecho-list-table-title"><?php _e('没有文章'); ?></h6></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function get_posts_for_sync() {
    $db = Typecho_Db::get();
    $options = Helper::options();
    $pluginOptions = $options->plugin('FediverseSync');
    $template = $pluginOptions->content_template ?? FediverseSync_Utils_Template::getDefaultTemplate();
    if (empty($template)) {
        $template = FediverseSync_Utils_Template::getDefaultTemplate();
    }
    $showContent = strpos($template, '{content}') !== false;
    
    $posts = $db->fetchAll($db->select('table.contents.cid', 'table.contents.title',
                                     'table.contents.created', 'table.contents.authorId',
                                     'table.contents.text', 'table.users.screenName', 'table.contents.slug')
        ->from('table.contents')
        ->join('table.users', 'table.contents.authorId = table.users.uid')
        ->where('table.contents.type = ?', 'post')
        ->where('table.contents.status = ?', 'publish')
        ->order('table.contents.created', Typecho_Db::SORT_DESC));

    $synced_posts = $db->fetchAll($db->select('post_id', 'toot_url')
        ->from('table.fediverse_bindings'));
    
    $synced_map = [];
    foreach ($synced_posts as $synced) {
        $synced_map[$synced['post_id']] = $synced['toot_url'];
    }

    $result = [];
    foreach ($posts as $post) {
        $pathinfo = [
            'slug' => $post['slug'],
            'cid' => $post['cid'],
            'year' => date('Y', $post['created']),
            'month' => date('m', $post['created']),
            'day' => date('d', $post['created'])
        ];

        // 获取文章摘要（是否显示由模板是否包含 {content} 决定）
        $content_preview = '';
        if ($showContent) {
            $content = strip_tags($post['text'] ?? '');
            $contentLength = intval($pluginOptions->content_length ?? 100);
            if ($contentLength > 0 && mb_strlen($content) > $contentLength) {
                $content_preview = mb_substr($content, 0, $contentLength) . '...';
            } else {
                $content_preview = $content;
            }
        }

        $result[] = [
            'cid' => $post['cid'],
            'title' => $post['title'],
            'author' => $post['screenName'],
            'created' => $post['created'],
            'permalink' => Typecho_Router::url('post', $pathinfo, $options->index),
            'synced' => isset($synced_map[$post['cid']]),
            'toot_url' => $synced_map[$post['cid']] ?? '',
            'content_preview' => $content_preview
        ];
    }

    return $result;
}

// 获取同步配置信息
function get_sync_config_info() {
    $options = Helper::options();
    $pluginOptions = $options->plugin('FediverseSync');
    $template = $pluginOptions->content_template ?? FediverseSync_Utils_Template::getDefaultTemplate();
    if (empty($template)) {
        $template = FediverseSync_Utils_Template::getDefaultTemplate();
    }
    
    $config = [
        'show_content' => strpos($template, '{content}') !== false,
        'content_length' => intval($pluginOptions->content_length ?? 500),
        'template' => $template,
        'instance_type' => $pluginOptions->instance_type ?? 'mastodon',
        'visibility' => $pluginOptions->visibility ?? 'public'
    ];
    
    return $config;
}

include 'footer.php';
?>

<style>
.status-synced {
    color: #52c41a;
    background: rgba(82, 196, 26, 0.1);
    border: 1px solid #b7eb8f;
    padding: 2px 8px;
    border-radius: 4px;
    text-decoration: none;
    display: inline-block;
}

.status-not-synced {
    color: #faad14;
    background: rgba(250, 173, 20, 0.1);
    border: 1px solid #ffe58f;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
}
</style>

<!-- 同步配置信息样式 -->
<style>
.sync-config-info {
    background: #f5f5f5;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
}

.sync-config-info h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #333;
    font-size: 16px;
}

.config-item {
    margin-bottom: 10px;
    display: flex;
    align-items: flex-start;
}

.config-label {
    font-weight: bold;
    color: #666;
    min-width: 120px;
    margin-right: 10px;
}

.config-value {
    color: #333;
    flex: 1;
}

.template-preview pre {
    background: #fff;
    border: 1px solid #ddd;
    padding: 10px;
    border-radius: 4px;
    margin: 5px 0;
    font-size: 12px;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.config-help {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e8e8e8;
}

.config-help p {
    margin-bottom: 8px;
    font-weight: bold;
    color: #666;
}

.config-help ul {
    margin: 0;
    padding-left: 20px;
}

.config-help li {
    margin-bottom: 5px;
    color: #666;
}

.config-help code {
    background: #fff;
    padding: 2px 4px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-family: monospace;
    color: #d73a49;
}
</style>
