<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class FediverseSync_Models_Comment
{
    private $db;
    private $prefix;
    private $http;

    public function __construct()
    {
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->http = new FediverseSync_Utils_Http();
    }

    /**
     * 同步特定文章的评论
     */
    public function syncCommentsForPost($post_id)
    {
        // 获取文章对应的嘟文信息
        $binding = new FediverseSync_Models_Binding();
        $toot = $binding->getBinding($post_id);
        
        if (!$toot) {
            return [];
        }

        // 获取嘟文的回复
        $options = Helper::options()->plugin('FediverseSync');
        $api_url = $options->instance_url . '/api/v1/statuses/' . $toot['toot_id'] . '/context';
        
        $responses = $this->http->get($api_url);
        if (!$responses || !isset($responses['descendants'])) {
            return [];
        }

        // 处理每个回复
        foreach ($responses['descendants'] as $reply) {
            $this->saveComment([
                'post_id' => $post_id,
                'toot_id' => $toot['toot_id'],
                'reply_to_id' => $reply['id'],
                'content' => $reply['content'],
                'author' => $reply['account']['display_name'],
                'author_url' => $reply['account']['url']
            ]);
        }

        return $this->getComments($post_id);
    }

    /**
     * 保存评论
     */
    private function saveComment($data)
    {
        // 检查评论是否已存在
        $existing = $this->db->fetchRow($this->db->select()
            ->from('table.fediverse_comments')
            ->where('reply_to_id = ?', $data['reply_to_id']));
            
        if ($existing) {
            return;
        }

        return $this->db->query($this->db->insert('table.fediverse_comments')->rows($data));
    }

    /**
     * 获取文章的评论
     */
    public function getComments($post_id)
    {
        return $this->db->fetchAll($this->db->select()
            ->from('table.fediverse_comments')
            ->where('post_id = ?', $post_id)
            ->order('created_at', Typecho_Db::SORT_ASC));
    }
}