<?php
require_once 'constants.php';

class Database {
    private $conn;
    private  $instance = null;

    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public  function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance->conn;
    }

    public static function beginTransaction() {
        $conn = self::getInstance();
        return $conn->beginTransaction();
    }

    public static function commit() {
        $conn = self::getInstance();
        return $conn->commit();
    }

    public static function rollback() {
        $conn = self::getInstance();
        return $conn->rollBack();
    }
}

// Helper function for database operations
class DBHelper {
    public  function insert($table, $data) {
        $conn = new Database::getInstance();
        
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        $stmt->execute();
        return $conn->lastInsertId();
    }

    public static function update($table, $data, $where) {
        $conn = Database::getInstance();
        
        $setClause = [];
        foreach ($data as $key => $value) {
            $setClause[] = "$key = :$key";
        }
        
        $whereClause = [];
        $whereParams = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "$key = :where_$key";
            $whereParams[":where_$key"] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $setClause) . 
               " WHERE " . implode(' AND ', $whereClause);
        
        $stmt = $conn->prepare($sql);
        
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        return $stmt->execute();
    }

    public static function select($table, $columns = '*', $where = [], $order = '', $limit = '') {
        $conn = Database::getInstance();
        
        $columnStr = is_array($columns) ? implode(', ', $columns) : $columns;
        $sql = "SELECT $columnStr FROM $table";
        
        if (!empty($where)) {
            $whereClause = [];
            foreach ($where as $key => $value) {
                $whereClause[] = "$key = :$key";
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        if (!empty($order)) {
            $sql .= " ORDER BY $order";
        }
        
        if (!empty($limit)) {
            $sql .= " LIMIT $limit";
        }
        
        $stmt = $conn->prepare($sql);
        
        foreach ($where as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function selectOne($table, $columns = '*', $where = []) {
        $result = self::select($table, $columns, $where, '', 1);
        return $result ? $result[0] : null;
    }

    public static function delete($table, $where) {
        $conn = Database::getInstance();
        
        $whereClause = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "$key = :$key";
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $whereClause);
        $stmt = $conn->prepare($sql);
        
        foreach ($where as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        return $stmt->execute();
    }
}
?>