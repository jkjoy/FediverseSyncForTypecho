<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 模板工具类
 * 用于处理同步内容模板的变量替换
 */
class FediverseSync_Utils_Template
{
    /**
     * 解析模板变量
     * 
     * @param string $template 模板字符串
     * @param array $data 替换数据
     * @return string 解析后的内容
     */
    public static function parse($template, $data)
    {
        $variables = [
            '{title}' => $data['title'] ?? '',
            '{permalink}' => $data['permalink'] ?? '',
            '{content}' => $data['content'] ?? '',
            '{author}' => $data['author'] ?? '',
            '{created}' => $data['created'] ?? '',
            '{site_name}' => $data['site_name'] ?? ''
        ];

        return str_replace(array_keys($variables), array_values($variables), $template);
    }

    /**
     * 处理文章内容（去除HTML标签，限制长度）
     * 
     * @param string $content 原始内容
     * @param int $length 限制长度（0表示不限制）
     * @return string 处理后的内容
     */
    public static function processContent($content, $length = 500)
    {
        // 去除HTML标签
        $text = strip_tags($content);
        
        // 去除多余的空白字符
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // 限制长度
        if ($length > 0 && mb_strlen($text) > $length) {
            $text = mb_substr($text, 0, $length) . '...';
        }
        
        return $text;
    }

    /**
     * 获取默认模板
     * 
     * @return string 默认模板
     */
    public static function getDefaultTemplate()
    {
        return "「{title}」\n\n{permalink}\n\nFrom「{site_name}」";
    }

    /**
     * 验证模板变量
     * 
     * @param string $template 模板字符串
     * @return array 无效的变量列表
     */
    public static function validateTemplate($template)
    {
        $validVariables = ['{title}', '{permalink}', '{content}', '{author}', '{created}', '{site_name}'];
        
        // 提取模板中的所有变量
        preg_match_all('/\{[^}]+\}/', $template, $matches);
        $templateVariables = $matches[0];
        
        $invalidVariables = [];
        foreach ($templateVariables as $variable) {
            if (!in_array($variable, $validVariables)) {
                $invalidVariables[] = $variable;
            }
        }
        
        return $invalidVariables;
    }

    /**
     * 获取模板变量说明
     * 
     * @return array 变量说明数组
     */
    public static function getVariableDescriptions()
    {
        return [
            '{title}' => '文章标题',
            '{permalink}' => '文章链接',
            '{content}' => '文章内容',
            '{author}' => '作者名称',
            '{created}' => '发布时间',
            '{site_name}' => '站点名称'
        ];
    }
}