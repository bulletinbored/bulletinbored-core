<?php
// User Model

class UserModel extends Model {
    public function findByUsername($username) {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE username = ?", [$username]);
    }

    public function findByEmail($email) {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE email = ?", [$email]);
    }

    public function isAdmin($userId) {
        $user = $this->find($userId);
        return $user && $user['role'] === 'admin';
    }
}

// Thread Model
class ThreadModel extends Model {
    public function getThreadsPaginated($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        return $this->db->query(
            "SELECT t.*, u.username as author FROM {$this->table} t 
            LEFT JOIN {$this->table}_users u ON t.user_id = u.id 
            ORDER BY t.created_at DESC LIMIT $offset, $perPage"
        );
    }
}

// Post Model
class PostModel extends Model {
    public function getPostsForThread($threadId) {
        return $this->db->query(
            "SELECT p.*, u.username as author FROM {$this->table} p 
            LEFT JOIN {$this->table}_users u ON p.user_id = u.id 
            WHERE thread_id = ? ORDER BY created_at ASC", 
            [$threadId]
        );
    }
}

// Category Model
class CategoryModel extends Model {
    public function getWithThreadCount() {
        return $this->db->query(
            "SELECT c.*, COUNT(t.id) as thread_count FROM {$this->table} c 
            LEFT JOIN {$this->table}_threads t ON c.id = t.category_id 
            GROUP BY c.id ORDER BY c.position"
        );
    }
}