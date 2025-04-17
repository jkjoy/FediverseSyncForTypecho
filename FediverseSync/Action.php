
<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

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

    protected function init()
    {
        parent::init();
        $this->security = Typecho_Widget::widget('Widget_Security');
        $this->db = Typecho_Db::get();
    }

    public function action()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            throw new Typecho_Widget_Exception(_t('未经授权的访问'), 403);
        }

        if ($this->request->is('do=sync')) {
            $this->security->protect();
            $this->syncSelected();
        } else if ($this->request->is('do=syncSingle')) {
            $this->security->protect();
            $this->syncSingle();
        }
    }

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
            
            if (empty($pluginOptions->instance_url) || empty($pluginOptions->access_token)) {
                throw new Exception(_t('请先配置 Fediverse 实例地址和访问令牌'));
            }

            $post = Typecho_Widget::widget('Widget_Archive@sync_' . $cid, 'pageSize=1&type=post', 'cid=' . $cid);
            
            if (!$post->have()) {
                throw new Exception(_t('文章不存在或未发布'));
            }

            $post->next();

            $content = $post->title . "\n\n";
            $content .= strip_tags($post->text) . "\n\n";
            $content .= $post->permalink;

            $response = $this->postToFediverse($pluginOptions->instance_url, $pluginOptions->access_token, $content);
            $tootData = json_decode($response, true);

            if (!isset($tootData['id']) || !isset($tootData['url'])) {
                throw new Exception(_t('发送到 Fediverse 失败：无效的响应'));
            }

            $instanceUrl = rtrim($pluginOptions->instance_url, '/');
            $data = [
                'post_id' => $post->cid,
                'toot_id' => $tootData['id'],
                'toot_url' => $tootData['url'],
                'instance_url' => $instanceUrl
            ];

            $binding = $this->db->fetchRow($this->db->select()
                ->from('table.fediverse_bindings')
                ->where('post_id = ?', $post->cid)
                ->limit(1));

            if ($binding) {
                $this->db->query($this->db->update('table.fediverse_bindings')
                    ->rows($data)
                    ->where('post_id = ?', $post->cid));
            } else {
                $this->db->query($this->db->insert('table.fediverse_bindings')
                    ->rows($data));
            }

            $this->response->throwJson([
                'success' => true,
                'message' => _t('同步成功'),
                'post_url' => $post->permalink,
                'toot_url' => $tootData['url']
            ]);
        } catch (Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'message' => _t('同步失败：') . $e->getMessage()
            ]);
        }
    }

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

        foreach ($cids as $cid) {
            try {
                $post = $this->db->fetchRow($this->db->select()
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish'));

                if ($post) {
                    $widget = new Widget_Abstract_Contents($this->request, $this->response);
                    $widget->push($post);
                    $permalink = $widget->permalink;

                    $response = $sync->postToFediverse([
                        'cid' => $post['cid'],
                        'title' => $post['title'],
                        'text' => $post['text'],
                        'permalink' => $permalink
                    ]);

                    if ($response && isset($response['id'])) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }
            } catch (Exception $e) {
                $failCount++;
            }
        }

        $message = _t('同步完成：%d 篇成功，%d 篇失败', $successCount, $failCount);
        $this->widget('Widget_Notice')->set($message, $failCount > 0 ? 'error' : 'success');
        $this->response->goBack();
    }
}
