<?php
class FediverseSync_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /**
     * @var Widget_Security
     */
    private $security;

    /**
     * @var Typecho_Db
     */
    private $db;

    /**
     * 初始化函数
     */
    protected function init()
    {
        parent::init();
        $this->security = Typecho_Widget::widget('Widget_Security');
        $this->db = Typecho_Db::get(); // 初始化数据库连接
        
        if (!$this->db) {
            throw new Exception(_t('无法获取数据库连接'));
        }
    }

    /**
     * 执行函数
     */
    public function action()
    {
        // 检查用户权限
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            throw new Typecho_Widget_Exception(_t('未经授权的访问'), 403);
        }

        // 处理不同的同步请求
        if ($this->request->is('do=sync')) {
            // 批量同步选中的文章
            $this->security->protect();
            $this->syncSelected();
        } else if ($this->request->is('do=syncSingle')) {
            // 单篇文章同步
            $this->security->protect();
            $this->syncSingle();
        }
    }

    /**
     * 保存或更新绑定信息
     */
    private function saveBinding($postId, $tootId, $tootUrl)
    {
        try {
            // 记录开始保存
            error_log("FediverseSync: Starting to save binding for post {$postId}");

            // 检查是否已有绑定记录
            $binding = $this->db->fetchRow($this->db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $postId)
                ->limit(1));

            if ($binding) {
                // 更新现有记录
                $this->db->query($this->db->update('table.fediverse_bindings')
                    ->rows([
                        'toot_id' => $tootId,
                        'toot_url' => $tootUrl
                    ])
                    ->where('post_id = ?', $postId));
                error_log("FediverseSync: Updated binding for post {$postId}");
            } else {
                // 插入新记录
                $insertData = [
                    'post_id' => $postId,
                    'toot_id' => $tootId,
                    'toot_url' => $tootUrl
                ];
                
                // 记录插入数据
                error_log("FediverseSync: Inserting new binding for post {$postId}: " . json_encode($insertData));

                $this->db->query($this->db->insert('table.fediverse_bindings')
                    ->rows($insertData));
                
                error_log("FediverseSync: Inserted new binding for post {$postId}");
            }

        } catch (Exception $e) {
            error_log("FediverseSync Error in saveBinding: " . $e->getMessage());
            throw $e;
        }
    }

        /**
     * 同步单篇文章
     */
    public function syncSingle()
    {
        $cid = $this->request->get('cid');
        if (empty($cid)) {
            $this->response->throwJson([
                'success' => false,
                'message' => _t('缺少文章ID')
            ]);
        }

        try {
            $options = Helper::options();
            $pluginOptions = $options->plugin('FediverseSync');
            
            // 记录开始同步
            if ($pluginOptions->debug_mode == '1') {
                error_log(sprintf(
                    'FediverseSync: Starting sync for post %d by user %s at %s',
                    $cid,
                    Typecho_Widget::widget('Widget_User')->screenName,
                    date('Y-m-d H:i:s')
                ));
            }

            if (empty($pluginOptions->instance) || empty($pluginOptions->token)) {
                throw new Exception(_t('请先配置 Fediverse 实例地址和访问令牌'));
            }

            // 获取文章数据和它的永久链接
            $post = Typecho_Widget::widget('Widget_Archive@sync_' . $cid, 'pageSize=1&type=post', 'cid=' . $cid);
            
            if (!$post->have()) {
                throw new Exception(_t('文章不存在或未发布'));
            }

            // 移动指针到第一条记录
            $post->next();

            // 构建发送内容，使用永久链接
            $content = $post->title . "\n\n";
            $content .= strip_tags($post->text) . "\n\n";
            $content .= $post->permalink;  // 使用 Widget_Archive 提供的永久链接

            // 发送到 Fediverse
            $response = $this->postToFediverse($pluginOptions->instance, $pluginOptions->token, $content);
            $tootData = json_decode($response, true);

            if (!isset($tootData['id']) || !isset($tootData['url'])) {
                throw new Exception(_t('发送到 Fediverse 失败：无效的响应'));
            }

            // 准备数据
            $instanceUrl = rtrim($pluginOptions->instance, '/');
            $data = [
                'post_id' => $post->cid,
                'toot_id' => $tootData['id'],
                'toot_url' => $tootData['url'],
                'instance_url' => $instanceUrl
            ];

            // 检查是否已有绑定记录
            $binding = $this->db->fetchRow($this->db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $post->cid)
                ->limit(1));

            if ($binding) {
                // 更新现有记录
                $this->db->query($this->db->update('table.fediverse_bindings')
                    ->rows($data)
                    ->where('post_id = ?', $post->cid));
                
                if ($pluginOptions->debug_mode == '1') {
                    error_log(sprintf(
                        'FediverseSync: Successfully updated binding for post %d - URL: %s - Toot URL: %s',
                        $post->cid,
                        $post->permalink,
                        $tootData['url']
                    ));
                }
            } else {
                // 插入新记录
                $this->db->query($this->db->insert('table.fediverse_bindings')
                    ->rows($data));
                
                if ($pluginOptions->debug_mode == '1') {
                    error_log(sprintf(
                        'FediverseSync: Successfully created new binding for post %d - URL: %s - Toot URL: %s',
                        $post->cid,
                        $post->permalink,
                        $tootData['url']
                    ));
                }
            }

            $this->response->throwJson([
                'success' => true,
                'message' => _t('同步成功'),
                'post_url' => $post->permalink,
                'toot_url' => $tootData['url']
            ]);
        } catch (Exception $e) {
            error_log(sprintf(
                'FediverseSync Error: Failed to sync post %d - Error: %s',
                $cid,
                $e->getMessage()
            ));
            
            if ($pluginOptions->debug_mode == '1') {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
            
            $this->response->throwJson([
                'success' => false,
                'message' => _t('同步失败：') . $e->getMessage()
            ]);
        }
    }

    /**
     * 发送请求到 Fediverse 实例
     */
    private function postToFediverse($instance, $token, $content)
    {
        $instance = rtrim($instance, '/');
        $url = $instance . '/api/v1/statuses';
        
        $data = array(
            'status' => $content,
            'visibility' => 'public'
        );

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ),
            CURLOPT_POSTFIELDS => json_encode($data)
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
        }

        return $response;
    }

    /**
     * 同步选中的文章（批量同步）
     */
    public function syncSelected()
    {
        $cids = $this->request->filter('int')->getArray('cid');
        if (empty($cids)) {
            $this->widget('Widget_Notice')->set(_t('请选择要同步的文章'), 'error');
            $this->response->goBack();
            return;
        }

        $sync = new FediverseSync_Api_Sync();
        $successCount = 0;
        $failCount = 0;
        $options = Helper::options();

        foreach ($cids as $cid) {
            try {
                // 获取文章数据
                $post = $this->db->fetchRow($this->db->select()
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish'));

                if ($post) {
                    // 使用 Widget_Abstract_Contents 获取正确的永久链接
                    $widget = new Widget_Abstract_Contents($this->request, $this->response);
                    $widget->push($post);
                    $permalink = $widget->permalink;

                    // 如果上面的方法获取失败，尝试使用路由生成链接
                    if (empty($permalink)) {
                        $routeExists = (NULL != Typecho_Router::get('post'));
                        if ($routeExists) {
                            $permalink = Typecho_Router::url('post', $post);
                        } else {
                            // 如果路由不存在，使用默认格式
                            $permalink = Typecho_Common::url('/archives/' . $post['cid'] . '.html', $options->siteUrl);
                        }
                    }

                    // 发送到 Fediverse
                    $response = $sync->postToFediverse([
                        'cid' => $post['cid'],
                        'title' => $post['title'],
                        'text' => $post['text'],
                        'permalink' => $permalink
                    ]);

                    if ($response && isset($response['id'])) {
                        // 保存或更新绑定信息
                        $this->saveBinding($post['cid'], $response['id'], $response['url']);
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }
            } catch (Exception $e) {
                error_log('FediverseSync Error: Failed to sync post ' . $cid . ': ' . $e->getMessage());
                $failCount++;
            }
        }

        // 设置通知消息
        $message = _t('同步完成：%d 篇成功，%d 篇失败', $successCount, $failCount);
        $this->widget('Widget_Notice')->set($message, $failCount > 0 ? 'error' : 'success');
        $this->response->goBack();
    }

}