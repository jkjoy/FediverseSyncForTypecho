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
    
    $posts = $db->fetchAll($db->select('table.contents.cid', 'table.contents.title', 
                                     'table.contents.created', 'table.contents.authorId', 
                                     'table.users.screenName', 'table.contents.slug')
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

        $result[] = [
            'cid' => $post['cid'],
            'title' => $post['title'],
            'author' => $post['screenName'],
            'created' => $post['created'],
            'permalink' => Typecho_Router::url('post', $pathinfo, $options->index),
            'synced' => isset($synced_map[$post['cid']]),
            'toot_url' => $synced_map[$post['cid']] ?? ''
        ];
    }

    return $result;
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
