<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP is working!<br>";
echo "PHP Version: " . phpversion() . "<br>";

// Test MySQL connection
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    echo "MySQL connection: OK<br>";
} catch (PDOException $e) {
    echo "MySQL connection: FAILED - " . $e->getMessage() . "<br>";
}

// Check if Stripe library exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "Composer autoload: OK<br>";
} else {
    echo "Composer autoload: NOT FOUND<br>";
}
?>