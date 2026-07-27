<?php
// Forum Controller - Handles threads and posts

require_once __DIR__ . '/model.php';

class ForumController {
    private $container;
    private $db;
    private $userModel;
    private $threadModel;
    private $postModel;
    private $categoryModel;

    public function __construct($container) {
        $this->container = $container;
        $this->db = $container->get('db');
        $this->userModel = new UserModel($this->db);
        $this->threadModel = new ThreadModel($this->db);
        $this->postModel = new PostModel($this->db);
        $this->categoryModel = new CategoryModel($this->db);
    }

    public function index() {
        $threads = $this->threadModel->findAll([], 'created_at DESC', 20);
        $categories = $this->categoryModel->findAll();
        
        $view = new View('forum/index');
        $view->assign('threads', $threads);
        $view->assign('categories', $categories);
        echo $view->render();
    }

    public function viewThread($id) {
        $thread = $this->threadModel->find($id);
        if (!$thread) {
            http_response_code(404);
            echo "Thread not found";
            return;
        }
        
        $posts = $this->postModel->findAll(['thread_id' => $id], 'created_at ASC');
        
        $view = new View('forum/thread');
        $view->assign('thread', $thread);
        $view->assign('posts', $posts);
        echo $view->render();
    }

    public function createThread() {
        session_start();
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo "Login required";
            return;
        }

        $data = [
            'category_id' => $_POST['category_id'] ?? 1,
            'user_id' => $_SESSION['user_id'],
            'title' => $_POST['title'] ?? '',
            'content' => $_POST['content'] ?? '',
            'status' => 'open'
        ];
        
        $threadId = $this->threadModel->create($data);
        header('Location: /thread/' . $threadId);
    }

    public function createReply() {
        session_start();
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo "Login required";
            return;
        }

        $data = [
            'thread_id' => $_POST['thread_id'] ?? 0,
            'user_id' => $_SESSION['user_id'],
            'content' => $_POST['content'] ?? '',
            'status' => 'visible'
        ];
        
        $this->postModel->create($data);
        header('Location: /thread/' . $_POST['thread_id']);
    }

    public function adminPanel() {
        session_start();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo "Admin access required";
            return;
        }

        $pendingThreads = $this->threadModel->findAll(['status' => 'pending'], 'created_at DESC');
        $pendingPosts = $this->postModel->findAll(['status' => 'pending'], 'created_at DESC');
        
        $view = new View('admin/panel');
        $view->assign('pending_threads', $pendingThreads);
        $view->assign('pending_posts', $pendingPosts);
        echo $view->render();
    }

    public function moderateContent() {
        session_start();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            echo "Admin access required";
            return;
        }

        $action = $_POST['action'] ?? '';
        $targetType = $_POST['target_type'] ?? '';
        $targetId = $_POST['target_id'] ?? 0;
        
        $model = $targetType === 'thread' ? $this->threadModel : $this->postModel;
        
        switch ($action) {
            case 'approve':
                $model->update($targetId, ['status' => 'visible']);
                break;
            case 'delete':
                $model->delete($targetId);
                break;
        }
        
        header('Location: /admin');
    }
}