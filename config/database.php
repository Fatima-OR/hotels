<?php
class Database {
    private $host = 'localhost';
    private $dbname = 'atlass_hotels';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function __construct() {
        // You can initialize connection here if needed
    }

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->dbname . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
        }
        
        return $this->conn;
    }
}

// For backward compatibility with existing code
// This creates a global $pdo variable
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=atlass_hotels;charset=utf8mb4",
        "root",
        ""
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>