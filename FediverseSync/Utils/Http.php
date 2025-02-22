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
        $defaultHeaders = [
            'Authorization: Bearer ' . $options->access_token,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: TypechoFediverseSync/1.1.2'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }

    /**
     * 发送POST请求
     */
    public function post($url, $data, $headers = [])
    {
        $options = Helper::options()->plugin('FediverseSync');
        $defaultHeaders = [
            'Authorization: Bearer ' . $options->access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TypechoFediverseSync/1.1.2'
        ];

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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }
}