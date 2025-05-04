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

            // 用 Widget_Archive 获取 permalink，确保和固定链接规则一致
            $post = Typecho_Widget::widget('Widget_Archive@sync_' . $cid, 'pageSize=1&type=post', 'cid=' . $cid);
            
            if (!$post->have()) {
                throw new Exception(_t('文章不存在或未发布'));
            }

            $post->next();

            // 获取站点名称
            $siteName = $options->title;
            
            // 使用新的消息格式
            $content = "「{$siteName}」同步了一篇文章「{$post->title}」\n\n访问地址：{$post->permalink}";

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
        $options = Helper::options();
        $pluginOptions = $options->plugin('FediverseSync');
        $instance_type = $pluginOptions->instance_type;
        
        $instance = rtrim($instance, '/');
        
        // 根据实例类型选择不同的API端点和参数
        if ($instance_type === 'misskey') {
            $url = $instance . '/api/notes/create';
            
            // Misskey的可见性设置与Mastodon不同
            $visibility = 'public';
            switch ($pluginOptions->visibility) {
                case 'private':
                    $visibility = 'followers';
                    break;
                case 'unlisted':
                    $visibility = 'home';
                    break;
                default:
                    $visibility = 'public';
            }
            
            $data = array(
                'i' => $token,  // Misskey使用i参数传递访问令牌
                'text' => $content,
                'visibility' => $visibility
            );
            
            $headers = array(
                'Content-Type: application/json'
            );
        } else {
            // Mastodon/GoToSocial API
            $url = $instance . '/api/v1/statuses';
            
            $data = array(
                'status' => $content,
                'visibility' => $pluginOptions->visibility ?? 'public'
            );
            
            $headers = array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            );
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($data)
        ));
        
        // 设置超时
        if (!empty($pluginOptions->api_timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, intval($pluginOptions->api_timeout));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 204) || empty($response)) {
            throw new Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
        }
        
        // 处理Misskey和Mastodon的不同响应格式
        $responseData = json_decode($response, true);
        
        if ($instance_type === 'misskey') {
            // 如果是Misskey，转换为与Mastodon兼容的格式
            if (isset($responseData['createdNote'])) {
                $noteUrl = $instance . '/notes/' . $responseData['createdNote']['id'];
                return json_encode([
                    'id' => $responseData['createdNote']['id'],
                    'url' => $noteUrl
                ]);
            }
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
                // 只查基础数据，然后用 Widget_Archive 获取 permalink，保证格式和规则一致
                $postRow = $this->db->fetchRow($this->db->select()
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish'));

                if ($postRow) {
                    // 用 Widget_Archive 获取 permalink
                    $archive = Typecho_Widget::widget('Widget_Archive@sync_' . $cid, 'pageSize=1&type=post', 'cid=' . $cid);
                    if ($archive->have()) {
                        $archive->next();
                        $permalink = $archive->permalink;
                        $title = $archive->title;
                        $text = $archive->text;
                        $cidVal = $archive->cid;
                    } else {
                        $permalink = '';
                        $title = $postRow['title'] ?? '';
                        $text = $postRow['text'] ?? '';
                        $cidVal = $cid;
                    }

                    $response = $sync->postToFediverse([
                        'cid' => $cidVal,
                        'title' => $title,
                        'text' => $text,
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