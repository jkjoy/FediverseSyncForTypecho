<?php
include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <div class="typecho-list-operate clearfix">
                    <form method="post" action="<?php $security->index('/action/fediverse-sync?do=sync'); ?>">
                        <div class="operate">
                                <!-- 直接使用提交按钮替代下拉菜单 -->
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
 <!-- 在表格部分 -->
<tbody>
    <?php
    $posts = get_posts_for_sync();
    if (!empty($posts)): ?>
        <?php foreach ($posts as $post): ?>
            <?php if (isset($post['cid'])): ?>
            <tr id="post-<?php echo htmlspecialchars($post['cid']); ?>">
                <td class="typecho-item-check">
                    <input type="checkbox" value="<?php echo htmlspecialchars($post['cid']); ?>" name="cid[]"/>
                </td>
                <td>
                    <?php if (isset($post['permalink']) && isset($post['title'])): ?>
                    <a href="<?php echo htmlspecialchars($post['permalink']); ?>" target="_blank">
                        <?php echo htmlspecialchars($post['title']); ?>
                    </a>
                    <?php else: ?>
                    <?php echo _t('无标题'); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($post['author'] ?? _t('未知作者')); ?></td>
                <td><?php echo date('Y-m-d H:i', intval($post['created'] ?? time())); ?></td>
                <td>
                    <?php if (isset($post['synced']) && $post['synced']): ?>
                        <?php if (isset($post['toot_url'])): ?>
                        <a href="<?php echo htmlspecialchars($post['toot_url']); ?>" target="_blank" class="status-synced" title="<?php _e('点击查看嘟文'); ?>">
                            <?php _e('已同步'); ?>
                        </a>
                        <?php else: ?>
                        <span class="status-synced"><?php _e('已同步'); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="status-not-synced"><?php _e('未同步'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
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
include 'footer.php';

function get_posts_for_sync() {
    try {
        $db = Typecho_Db::get();
        $options = Helper::options();
        
        // 获取所有已发布的文章
        $posts = $db->fetchAll($db->select('table.contents.cid', 'table.contents.title', 
                                         'table.contents.created', 'table.contents.authorId', 
                                         'table.contents.text', 'table.users.screenName', 
                                         'table.users.name', 'table.contents.slug', 'table.contents.type')
            ->from('table.contents')
            ->join('table.users', 'table.contents.authorId = table.users.uid')
            ->where('table.contents.type = ?', 'post')
            ->where('table.contents.status = ?', 'publish')
            ->order('table.contents.created', Typecho_Db::SORT_DESC));

        // 获取同步状态
        $synced_posts = $db->fetchAll($db->select('post_id', 'toot_url')
            ->from('table.fediverse_bindings'));
        
        // 创建同步状态映射
        $synced_map = [];
        foreach ($synced_posts as $synced) {
            $synced_map[$synced['post_id']] = $synced['toot_url'];
        }

        // 处理文章数据
        $result = [];
        foreach ($posts as $post) {
            if (!isset($post['cid'])) continue;

            // 检查是否已同步
            $is_synced = isset($synced_map[$post['cid']]);
            $toot_url = $is_synced ? $synced_map[$post['cid']] : '';

            // 使用 Typecho 路由生成正确的永久链接
            $pathinfo = [];
            $pathinfo['slug'] = $post['slug'];
            $pathinfo['category'] = '';
            $pathinfo['directory'] = '';
            $pathinfo['cid'] = $post['cid'];
            $pathinfo['year'] = date('Y', $post['created']);
            $pathinfo['month'] = date('m', $post['created']);
            $pathinfo['day'] = date('d', $post['created']);

            // 获取文章的正确永久链接
            $permalink = Typecho_Router::url('post', $pathinfo, $options->index);

            $result[] = [
                'cid' => $post['cid'],
                'title' => $post['title'] ?: _t('无标题'),
                'author' => $post['screenName'] ?: ($post['name'] ?: _t('未知作者')),
                'created' => $post['created'],
                'permalink' => $permalink,  // 使用正确的永久链接
                'synced' => $is_synced,
                'toot_url' => $toot_url
            ];
        }

        return $result;

    } catch (Exception $e) {
        error_log('FediverseSync Error in get_posts_for_sync: ' . $e->getMessage());
        return [];
    }
}
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

.status-synced:hover {
    background: rgba(82, 196, 26, 0.2);
    text-decoration: none;
}

.status-not-synced {
    color: #faad14;
    background: rgba(250, 173, 20, 0.1);
    border: 1px solid #ffe58f;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
}

/* 添加操作按钮样式 */
.btn-operate {
    margin-left: 10px;
}

/* 改进表格样式 */
.typecho-list-table {
    margin-top: 1em;
}

.typecho-list-table td {
    vertical-align: middle;
}

/* 优化选择框样式 */
.typecho-item-check {
    vertical-align: middle;
    text-align: center;
}

.typecho-item-check input[type="checkbox"] {
    margin: 0;
}
</style>