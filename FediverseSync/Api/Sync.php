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
            $instance_url = rtrim($options->instance_url, '/');
            $api_url = $instance_url . '/api/v1/statuses';

            // 准备发送的数据
            $summary = $this->getPostSummary($contents);
            $message = "## {$contents['title']}\n\n{$summary}\n\n🔗 阅读全文: {$contents['permalink']}";

            $post_data = [
                'status' => $message,
                'visibility' => $options->visibility
            ];

            // 初始化 curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $options->access_token,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: FediverseSync/1.0'
            ]);

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
                throw new Exception('Failed to post to Fediverse. HTTP Code: ' . $http_code);
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

        } catch (Exception $e) {
            error_log('FediverseSync Error: ' . $e->getMessage());
            if ($options->debug_mode == '1') {
                error_log('FediverseSync Error Stack: ' . $e->getTraceAsString());
            }
            return null;
        }
    }

    /**
     * 获取文章摘要
     * 
     * @param array $contents 文章内容
     * @return string
     */
    private function getPostSummary($contents)
    {
        $options = Helper::options()->plugin('FediverseSync');
        $summary_length = intval($options->summary_length ?? 140);

        // 移除 HTML 标签和 Markdown 标记
        $text = strip_tags($contents['text']);
        $text = preg_replace('/<!--.*?-->/s', '', $text); // 移除注释
        $text = str_replace(['<!--markdown-->', "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text); // 将多个空白字符替换为单个空格

        // 截取指定长度
        if (function_exists('mb_substr')) {
            $summary = mb_substr($text, 0, $summary_length, 'UTF-8');
        } else {
            $summary = substr($text, 0, $summary_length);
        }

        // 如果文本被截断，添加省略号
        if (strlen($text) > $summary_length) {
            $summary .= '...';
        }

        return $summary;
    }
}