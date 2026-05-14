<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test MySQL connection directly
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'sams_db';

try {
    // Try to connect
    $conn = new mysqli($host, $user, $pass);
    
    if ($conn->connect_error) {
        echo "CONNECTION FAILED: " . $conn->connect_error . "\n";
        exit(1);
    }
    
    echo "✓ MySQL Connection Successful\n";
    
    // Check if sams_db exists
    $result = $conn->query("SHOW DATABASES LIKE 'sams_db'");
    if ($result && $result->num_rows > 0) {
        echo "✓ Database 'sams_db' EXISTS\n";
        
        // Now check tables
        $conn->select_db($dbname);
        $result = $conn->query("SHOW TABLES");
        $table_count = $result->num_rows;
        echo "✓ Database has $table_count tables\n";
        
        // Check users table
        $result = $conn->query("SELECT COUNT(*) as cnt FROM users");
        $row = $result->fetch_assoc();
        echo "✓ Users table has " . $row['cnt'] . " records\n";
        
    } else {
        echo "✗ Database 'sams_db' NOT FOUND\n";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>
