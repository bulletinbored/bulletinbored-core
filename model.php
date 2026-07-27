<?php
// Base Model Class

abstract class Model {
    protected $db;
    protected $table;
    protected $primaryKey = 'id';

    public function __construct($db, $table = null) {
        $this->db = $db;
        $this->table = $table ?? $this->inferTableName();
    }

    private function inferTableName() {
        $className = get_class($this);
        $prefix = $this->db->getConfig()['db_table_prefix'] ?? '';
        return $prefix . strtolower(str_replace('Model', '', $className));
    }

    public function findAll($conditions = [], $orderBy = null, $limit = null) {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "$key = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        if ($orderBy) {
            $sql .= " ORDER BY $orderBy";
        }

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        return $this->db->query($sql, $params);
    }

    public function find($id) {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?", [$id]);
    }

    public function create($data) {
        $keys = array_keys($data);
        $placeholders = array_fill(0, count($keys), '?');
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $this->db->execute($sql, array_values($data));
        return $this->db->lastInsertId();
    }

    public function update($id, $data) {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE {$this->primaryKey} = ?";
        return $this->db->execute($sql, $params);
    }

    public function delete($id) {
        return $this->db->execute("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?", [$id]);
    }
}