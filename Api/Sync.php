<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class FediverseSync_Api_Sync
{
    private $http;

    public function __construct()
    {
        $this->http = new FediverseSync_Utils_Http();
    }

    /**
     * 发送文章到Fediverse平台
     * 
     * @param array $contents 文章内容
     * @return array|null
     */
    public function postToFediverse($contents)
    {
        try {
            $options = Helper::options()->plugin('FediverseSync');
            $instance_type = $options->instance_type;
            $instance_url = rtrim($options->instance_url, '/');
            
            // 获取站点名称
            $siteName = FediverseSync_Utils_Template::decodeHtmlEntities(Helper::options()->title);
            
            // 使用模板工具类处理内容（是否包含原文由模板是否包含 {content} 决定）
            $template = $options->content_template ?? FediverseSync_Utils_Template::getDefaultTemplate();
            if (empty($template)) {
                $template = FediverseSync_Utils_Template::getDefaultTemplate();
            }

            // 获取文章内容
            $postContent = '';
            $rawContent = '';
            if (strpos($template, '{content}') !== false) {
                $contentLength = intval($options->content_length ?? 500);
                $rawContent = $contents['text'] ?? ($contents['content'] ?? '');
                if ($rawContent === '' && !empty($contents['cid'])) {
                    try {
                        $db = Typecho_Db::get();
                        $row = $db->fetchRow($db->select('text')->from('table.contents')->where('cid = ?', $contents['cid'])->limit(1));
                        $rawContent = $row['text'] ?? '';
                    } catch (Exception $e) {
                        // ignore and fall back to empty
                    }
                }
                $postContent = FediverseSync_Utils_Template::processMarkdownContent($rawContent, $contentLength);
            }

            // 获取作者信息
            $author = FediverseSync_Utils_Template::decodeHtmlEntities($contents['author'] ?? ($contents['authorName'] ?? ''));
            if ($author === '' && !empty($contents['cid'])) {
                try {
                    $db = Typecho_Db::get();
                    $row = $db->fetchRow($db->select('table.users.screenName')
                        ->from('table.contents')
                        ->join('table.users', 'table.contents.authorId = table.users.uid')
                        ->where('table.contents.cid = ?', $contents['cid'])
                        ->limit(1));
                    $author = FediverseSync_Utils_Template::decodeHtmlEntities($row['screenName'] ?? '');
                } catch (Exception $e) {
                    // ignore and fall back to empty
                }
            }

            $templateData = [
                'title' => FediverseSync_Utils_Template::decodeHtmlEntities($contents['title']),
                'permalink' => $contents['permalink'],
                'content' => $postContent,
                'author' => $author,
                'created' => date('Y-m-d H:i', $contents['created']),
                'site_name' => $siteName
            ];
            
            $message = FediverseSync_Utils_Template::parse($template, $templateData);

            // Mastodon/GoToSocial：避免超长导致同步失败，优先缩短 {content}
            if ($instance_type !== 'misskey') {
                $maxCharacters = 500;
                if (mb_strlen($message) > $maxCharacters) {
                    if (strpos($template, '{content}') !== false) {
                        $baseMessage = FediverseSync_Utils_Template::parse($template, array_merge($templateData, ['content' => '']));
                        $available = $maxCharacters - mb_strlen($baseMessage);
                        if ($available > 0) {
                            $templateData['content'] = FediverseSync_Utils_Template::processMarkdownContent($rawContent ?? '', $available);
                            $message = FediverseSync_Utils_Template::parse($template, $templateData);
                        }
                    }
                    if (mb_strlen($message) > $maxCharacters) {
                        $message = FediverseSync_Utils_Template::truncate($message, $maxCharacters, '...');
                    }
                }
            }

            // 根据实例类型调用不同的API
            if ($instance_type === 'misskey') {
                return $this->postToMisskey($instance_url, $options->access_token, $message, $options, $contents);
            } else {
                // Mastodon/GoToSocial API (兼容)
                return $this->postToMastodon($instance_url, $options->access_token, $message, $options, $contents);
            }

        } catch (Exception $e) {
            error_log('FediverseSync Error: ' . $e->getMessage());
            if ($options->debug_mode == '1') {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
            return null;
        }
    }
    
    /**
     * 发送文章到Mastodon/GoToSocial实例
     * 
     * @param string $instance_url 实例URL
     * @param string $access_token 访问令牌
     * @param string $message 消息内容
     * @param object $options 插件选项
     * @param array $contents 文章内容
     * @return array|null
     */
    private function postToMastodon($instance_url, $access_token, $message, $options, $contents)
    {
        $api_url = $instance_url . '/api/v1/statuses';
        
        $post_data = [
            'status' => $message,
            'visibility' => $options->visibility
        ];

        // 初始化 curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/json',
            'User-Agent: FediverseSync/1.6.4'
        ]);
        
        // 设置超时
        if (!empty($options->api_timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, intval($options->api_timeout));
        }

        // 执行请求并获取响应
        $response_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 解析响应
        $response = json_decode($response_json, true);

        // 调试输出
        if ($options->debug_mode == '1') {
            error_log('FediverseSync Debug - API Response: ' . print_r($response, true));
            error_log('FediverseSync Debug - HTTP Code: ' . $http_code);
        }

        // 检查响应
        if ($http_code !== 200 || !$response) {
            throw new Exception('Failed to post to Mastodon. HTTP Code: ' . $http_code);
        }

        // 保存绑定关系
        if (isset($response['id']) && isset($response['url'])) {
            $binding = new FediverseSync_Models_Binding();
            $binding->saveBinding([
                'post_id' => $contents['cid'],
                'toot_id' => $response['id'],
                'instance_url' => $instance_url,
                'toot_url' => $response['url']
            ]);

            if ($options->debug_mode == '1') {
                error_log('FediverseSync: Toot posted successfully. URL: ' . $response['url']);
            }
        }

        return $response;
    }
    
    /**
     * 发送文章到Misskey实例
     * 
     * @param string $instance_url 实例URL
     * @param string $access_token 访问令牌
     * @param string $message 消息内容
     * @param object $options 插件选项
     * @param array $contents 文章内容
     * @return array|null
     */
    private function postToMisskey($instance_url, $access_token, $message, $options, $contents)
    {
        $api_url = $instance_url . '/api/notes/create';
        
        // Misskey的可见性设置与Mastodon不同
        $visibility = 'public';
        switch ($options->visibility) {
            case 'private':
                $visibility = 'followers';
                break;
            case 'unlisted':
                $visibility = 'home';
                break;
            default:
                $visibility = 'public';
        }
        
        $post_data = [
            'i' => $access_token,
            'text' => $message,
            'visibility' => $visibility
        ];

        // 初始化 curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FediverseSync/1.6.4'
        ]);
        
        // 设置超时
        if (!empty($options->api_timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, intval($options->api_timeout));
        }

        // 执行请求并获取响应
        $response_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 解析响应
        $response = json_decode($response_json, true);

        // 调试输出
        if ($options->debug_mode == '1') {
            error_log('FediverseSync Debug - Misskey API Response: ' . print_r($response, true));
            error_log('FediverseSync Debug - HTTP Code: ' . $http_code);
        }

        // 检查响应
        if ($http_code !== 200 && $http_code !== 204 || !$response) {
            throw new Exception('Failed to post to Misskey. HTTP Code: ' . $http_code);
        }

        // 构建Note URL
        $note_url = '';
        if (isset($response['createdNote']['id'])) {
            // 假设Misskey实例的note URL结构
            $note_url = $instance_url . '/notes/' . $response['createdNote']['id'];
            
            // 保存绑定关系
            $binding = new FediverseSync_Models_Binding();
            $binding->saveBinding([
                'post_id' => $contents['cid'],
                'toot_id' => $response['createdNote']['id'],
                'instance_url' => $instance_url,
                'toot_url' => $note_url
            ]);

            if ($options->debug_mode == '1') {
                error_log('FediverseSync: Misskey note posted successfully. ID: ' . $response['createdNote']['id']);
            }
        }

        // 将Misskey响应转换为与Mastodon兼容的格式
        return [
            'id' => $response['createdNote']['id'] ?? '',
            'url' => $note_url
        ];
    }
}
