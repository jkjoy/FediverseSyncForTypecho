<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class FediverseSync_Api_Comment extends Typecho_Widget
{
    public function action()
    {
        // 验证请求
        $this->security->protect();
        
        if (!$this->request->isGet()) {
            $this->response->setStatus(405);
            $this->response->throwJson([
                'error' => 'Method not allowed'
            ]);
        }

        $post_id = $this->request->get('post_id');
        if (!$post_id) {
            $this->response->setStatus(400);
            $this->response->throwJson([
                'error' => 'Missing post_id parameter'
            ]);
        }

        // 获取评论
        $commentModel = new FediverseSync_Models_Comment();
        $comments = $commentModel->getComments($post_id);

        $this->response->throwJson([
            'success' => true,
            'comments' => $comments
        ]);
    }
}