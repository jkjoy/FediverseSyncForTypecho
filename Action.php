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

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
    }

    protected function init()
    {
        parent::init();
        $this->security = Typecho_Widget::widget('Widget_Security');
    }

    public function action()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            throw new Typecho_Widget_Exception(_t('未登录'));
        }
        
        if (!$user->pass('administrator', true)) {
            throw new Typecho_Widget_Exception(_t('权限不足'));
        }

        if ($this->request->is('do=sync')) {
            $this->security->protect();
            $this->sync();
        }
    }

    public function sync()
    {
        $cids = $this->request->getArray('cid');
        if (empty($cids)) {
            $this->widget('Widget_Notice')->set(_t('请选择要同步的文章'), 'error');
            $this->response->goBack();
            return;
        }

        $options = Helper::options();
        $pluginOptions = $options->plugin('FediverseSync');
        
        if (empty($pluginOptions->instance_url) || empty($pluginOptions->access_token)) {
            $this->widget('Widget_Notice')->set(_t('请先配置 Fediverse 实例地址和访问令牌'), 'error');
            $this->response->goBack();
            return;
        }

        $successes = $failures = 0;
        foreach ($cids as $cid) {
            try {
                // 获取文章信息
                $post = $this->db->fetchRow($this->db->select()
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish')
                    ->limit(1));

                if ($post) {
                    // 用 Widget_Archive 获取 permalink
                    $archive = Typecho_Widget::widget('Widget_Archive@sync_' . $cid, 'pageSize=1&type=post', 'cid=' . $cid);
                    if ($archive->have()) {
                        $archive->next();
                        
                        // 获取站点名称
                        $siteName = $options->title;
                        
                        // 获取文章内容
                        $postContent = '';
                        if ($pluginOptions->show_content == '1') {
                            $contentLength = intval($pluginOptions->content_length ?? 500);
                            $postContent = FediverseSync_Utils_Template::processContent($archive->content ?? '', $contentLength);
                        }

                        // 获取作者信息
                        $author = $archive->author->screenName ?? '';

                        // 使用模板工具类处理内容
                        $template = $pluginOptions->content_template ?? FediverseSync_Utils_Template::getDefaultTemplate();
                        
                        $templateData = [
                            'title' => $archive->title,
                            'permalink' => $archive->permalink,
                            'content' => $postContent,
                            'author' => $author,
                            'created' => date('Y-m-d H:i', $archive->created),
                            'site_name' => $siteName
                        ];
                        
                        $content = FediverseSync_Utils_Template::parse($template, $templateData);

                        // 如果启用了显示内容且内容不为空，但模板中没有包含content变量，则在消息末尾添加内容
                        if ($pluginOptions->show_content == '1' && !empty($postContent) && strpos($template, '{content}') === false) {
                            $content .= "\n\n" . $postContent;
                        }

                        // 发送到 Fediverse
                        $response = $this->postToFediverse($pluginOptions->instance_url, $pluginOptions->access_token, $content);
                        $tootData = json_decode($response, true);

                        if ($pluginOptions->instance_type === 'misskey') {
                            if (isset($tootData['createdNote']['id'])) {
                                $tootData = [
                                    'id' => $tootData['createdNote']['id'],
                                    'url' => $pluginOptions->instance_url . '/notes/' . $tootData['createdNote']['id']
                                ];
                            }
                        }

                        if (!isset($tootData['id']) || !isset($tootData['url'])) {
                            throw new Exception(_t('发送到 Fediverse 失败：无效的响应'));
                        }

                        // 更新或插入绑定关系
                        $binding = $this->db->fetchRow($this->db->select()
                            ->from('table.fediverse_bindings')
                            ->where('post_id = ?', $cid)
                            ->limit(1));

                        $data = [
                            'post_id' => $cid,
                            'toot_id' => $tootData['id'],
                            'toot_url' => $tootData['url'],
                            'instance_url' => rtrim($pluginOptions->instance_url, '/')
                        ];

                        if ($binding) {
                            $this->db->query($this->db->update('table.fediverse_bindings')
                                ->rows($data)
                                ->where('post_id = ?', $cid));
                        } else {
                            $this->db->query($this->db->insert('table.fediverse_bindings')
                                ->rows($data));
                        }

                        $successes++;
                    }
                }
            } catch (Exception $e) {
                FediverseSync_Plugin::log($cid, 'sync', 'error', '手动同步失败：' . $e->getMessage());
                $failures++;
            }
        }

        // 设置提示消息
        $msg = '';
        if ($successes > 0) {
            $msg .= _t('成功同步 %d 篇文章。', $successes);
        }
        if ($failures > 0) {
            $msg .= _t('同步失败 %d 篇文章。', $failures);
        }

        // 返回面板页面
        $this->widget('Widget_Notice')->set($msg, $failures > 0 ? 'error' : 'success');
        $this->response->goBack();
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
                'Content-Type: application/json',
                'Accept: */*',
                'User-Agent: FediverseSync/1.5.3'
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

        return $response;
    }
}