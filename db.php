<?php

class DB {
    private static $instance = null;
    private $connection;

    private function __construct($host, $user, $password, $database) {
        $this->connection = new mysqli($host, $user, $password, $database);
        if ($this->connection->connect_error) {
            error_log("DB error: " . $this->connection->connect_error);
        }
    }

    public static function getInstance($host, $user, $password, $database) {
        if (self::$instance === null || !self::$instance->isConnected()) {
            self::$instance = new self($host, $user, $password, $database);
        }
        return self::$instance;
    }

    public function isConnected() {
        return $this->connection && $this->connection->ping();
    }

    public function closeConnection() {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function query($sql, $params = [], $types = "") {
        if (!$this->isConnected()) {
            throw new Exception("Database connection is not active.");
        }

        $stmt = $this->connection->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }

    public function execute($sql, $params = [], $types = "") {
        if (!$this->isConnected()) {
            throw new Exception("Database connection is not active.");
        }

        $stmt = $this->connection->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize a singleton.");
    }
}
?>
