<?php
class Security {
    
    /**
     * Generate CSRF Token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF Token
     */
    public static function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Sanitize input data - handles both strings and arrays
     */
    public static function sanitizeInput($data) {
        // Check if data is null or empty
        if ($data === null || $data === '') {
            return $data;
        }
        
        // Handle arrays recursively
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitized[$key] = self::sanitizeInput($value);
            }
            return $sanitized;
        }
        
        // Handle strings
        if (is_string($data)) {
            $data = trim($data);
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
            return $data;
        }
        
        // Return other data types as-is (numbers, booleans, etc.)
        return $data;
    }
    
    /**
     * Prepare and execute SQL statement
     */
    public static function prepareAndExecute($pdo, $sql, $params = []) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("SQL Error: " . $e->getMessage());
            throw new Exception("Database operation failed");
        }
    }
    
    /**
     * Hash password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate random string
     */
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Validate email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number (basic validation)
     */
    public static function validatePhone($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        // Check if it's between 8 and 15 digits
        return strlen($phone) >= 8 && strlen($phone) <= 15;
    }
    
    /**
     * Rate limiting (simple implementation)
     */
    public static function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 300) {
        $key = 'rate_limit_' . $identifier;
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        }
        
        $data = $_SESSION[$key];
        
        // Reset if time window has passed
        if (time() - $data['first_attempt'] > $timeWindow) {
            $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
            return true;
        }
        
        // Check if limit exceeded
        if ($data['count'] >= $maxAttempts) {
            return false;
        }
        
        // Increment counter
        $_SESSION[$key]['count']++;
        return true;
    }
    
    /**
     * Clean and validate input for specific types
     */
    public static function validateInput($data, $type = 'string', $options = []) {
        $data = self::sanitizeInput($data);
        
        switch ($type) {
            case 'email':
                return self::validateEmail($data) ? $data : false;
                
            case 'phone':
                return self::validatePhone($data) ? $data : false;
                
            case 'number':
                return is_numeric($data) ? (float)$data : false;
                
            case 'integer':
                return filter_var($data, FILTER_VALIDATE_INT) !== false ? (int)$data : false;
                
            case 'date':
                $date = DateTime::createFromFormat('Y-m-d', $data);
                return $date && $date->format('Y-m-d') === $data ? $data : false;
                
            case 'string':
            default:
                $maxLength = $options['max_length'] ?? 255;
                return strlen($data) <= $maxLength ? $data : false;
        }
    }
    
    /**
     * Generate secure random token
     */
    public static function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Check if request is POST
     */
    public static function isPost() {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    /**
     * Check if request is GET
     */
    public static function isGet() {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }
    
    /**
     * Redirect to URL
     */
    public static function redirect($url) {
        header("Location: $url");
        exit;
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
?>