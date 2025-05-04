<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class FediverseSync_Utils_Http
{
    /**
     * 发送GET请求
     */
    public function get($url, $headers = [])
    {
        $options = Helper::options()->plugin('FediverseSync');
        $instance_type = $options->instance_type;
        
        if ($instance_type === 'misskey') {
            // Misskey API 使用POST请求模拟GET（并使用'i'参数传递令牌）
            $data = ['i' => $options->access_token];
            return $this->post($url, $data, $headers);
        } else {
            // Mastodon/GoToSocial API
            $defaultHeaders = [
                'Authorization: Bearer ' . $options->access_token,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: TypechoFediverseSync/1.4.0'
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            
            // 设置超时
            if (!empty($options->api_timeout)) {
                curl_setopt($ch, CURLOPT_TIMEOUT, intval($options->api_timeout));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return json_decode($response, true);
            }

            return null;
        }
    }

    /**
     * 发送POST请求
     */
    public function post($url, $data, $headers = [])
    {
        $options = Helper::options()->plugin('FediverseSync');
        $instance_type = $options->instance_type;
        
        if ($instance_type === 'misskey') {
            // Misskey 不使用Authorization头，而是将令牌作为i参数
            if (!isset($data['i']) && !empty($options->access_token)) {
                $data['i'] = $options->access_token;
            }
            
            $defaultHeaders = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: TypechoFediverseSync/1.4.0'
            ];
        } else {
            // Mastodon/GoToSocial API
            $defaultHeaders = [
                'Authorization: Bearer ' . $options->access_token,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: TypechoFediverseSync/1.4.0'
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        
        // 设置超时
        if (!empty($options->api_timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, intval($options->api_timeout));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Misskey API可能返回204
        if ($httpCode === 200 || ($instance_type === 'misskey' && $httpCode === 204)) {
            return json_decode($response, true);
        }

        return null;
    }
}