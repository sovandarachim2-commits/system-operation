<?php
require_once 'scanner/config.php';

echo "Checking return_items table structure:\n";
$result = $conn->query('DESCRIBE return_items');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
}

echo "\n\nChecking if status column exists:\n";
$result = $conn->query("SHOW COLUMNS FROM return_items LIKE 'status'");
if ($result->num_rows > 0) {
    echo "Status column exists\n";
} else {
    echo "Status column does NOT exist - need to add it\n";
}

$conn->close();
?>
