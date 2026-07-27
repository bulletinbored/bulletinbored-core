<?php
// Auth Controller - Handles user authentication

class AuthController {
    private $container;
    private $db;
    private $userModel;

    public function __construct($container) {
        $this->container = $container;
        $this->db = $container->get('db');
        $this->userModel = new UserModel($this->db);
    }

    public function login() {
        $view = new View('auth/login');
        echo $view->render();
    }

    public function authenticate() {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $user = $this->userModel->findByUsername($username);
        
        if ($user && password_verify($password, $user['password'])) {
            session_start();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            header('Location: /');
        } else {
            $view = new View('auth/login');
            $view->assign('error', 'Invalid credentials');
            echo $view->render();
        }
    }

    public function register() {
        $view = new View('auth/register');
        echo $view->render();
    }

    public function createAccount() {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (!$username || !$email || !$password) {
            $view = new View('auth/register');
            $view->assign('error', 'All fields required');
            echo $view->render();
            return;
        }
        
        $data = [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user'
        ];
        
        $userId = $this->userModel->create($data);
        header('Location: /login');
    }

    public function logout() {
        session_start();
        session_destroy();
        header('Location: /');
    }
}