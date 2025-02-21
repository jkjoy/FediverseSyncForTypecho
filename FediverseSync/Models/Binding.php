<?php
class FediverseSync_Models_Binding
{
    private $_db;

    public function __construct()
    {
        $this->_db = Typecho_Db::get();
    }

    /**
     * 保存文章和嘟文的绑定关系
     * 一个文章只能绑定一条嘟文
     *
     * @param array $data 绑定数据
     * @return boolean
     */
    public function saveBinding($data)
    {
        // 检查必要字段
        if (empty($data['post_id']) || empty($data['toot_id']) || 
            empty($data['instance_url']) || empty($data['toot_url'])) {
            return false;
        }

        try {
            // 首先检查是否已存在绑定
            $existing = $this->_db->fetchRow(
                $this->_db->select()
                    ->from('table.fediverse_bindings')
                    ->where('post_id = ?', $data['post_id'])
                    ->limit(1)
            );

            // 如果已存在绑定，不再创建新的绑定
            if ($existing) {
                if (Helper::options()->plugin('FediverseSync')->debug_mode == '1') {
                    error_log('FediverseSync: Binding already exists for post_id ' . $data['post_id']);
                }
                return false;
            }

            // 不存在绑定时，创建新的绑定
            $this->_db->query($this->_db->insert('table.fediverse_bindings')
                ->rows([
                    'post_id' => $data['post_id'],
                    'toot_id' => $data['toot_id'],
                    'instance_url' => $data['instance_url'],
                    'toot_url' => $data['toot_url']
                    // created_at 字段会由数据库自动设置为当前时间
                ]));

            if (Helper::options()->plugin('FediverseSync')->debug_mode == '1') {
                error_log('FediverseSync: Successfully created binding for post_id ' . $data['post_id']);
            }

            return true;

        } catch (Exception $e) {
            error_log('FediverseSync Error in saveBinding: ' . $e->getMessage());
            if (Helper::options()->plugin('FediverseSync')->debug_mode == '1') {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
            return false;
        }
    }

    /**
     * 获取文章的绑定信息
     *
     * @param integer $post_id 文章ID
     * @return array|null
     */
    public function getBinding($post_id)
    {
        try {
            return $this->_db->fetchRow(
                $this->_db->select()
                    ->from('table.fediverse_bindings')
                    ->where('post_id = ?', $post_id)
                    ->limit(1)
            );
        } catch (Exception $e) {
            error_log('FediverseSync Error in getBinding: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 删除文章的绑定关系
     *
     * @param integer $post_id 文章ID
     * @return boolean
     */
    public function deleteBinding($post_id)
    {
        try {
            $this->_db->query($this->_db->delete('table.fediverse_bindings')
                ->where('post_id = ?', $post_id));
            return true;
        } catch (Exception $e) {
            error_log('FediverseSync Error in deleteBinding: ' . $e->getMessage());
            return false;
        }
    }
}