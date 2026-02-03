<?php
require_once 'conn.php';

echo "<h2>Fixing Chat System Database Structure...</h2>";

// 1. Make sure head_user_id column exists and has proper constraints
$sql = "SHOW COLUMNS FROM numbers LIKE 'head_user_id'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE numbers ADD COLUMN head_user_id INT NULL AFTER head";
    if ($conn->query($sql)) {
        echo "✓ Added head_user_id column to numbers table<br>";
    } else {
        echo "✗ Error adding head_user_id column: " . $conn->error . "<br>";
    }
} else {
    echo "✓ head_user_id column already exists<br>";
}

// 2. Remove existing foreign key constraints that might interfere
$sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = 'drmc_intercom' 
        AND TABLE_NAME = 'numbers' 
        AND CONSTRAINT_NAME IN ('numbers_ibfk_5', 'userasasd')";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $constraint_name = $row['CONSTRAINT_NAME'];
        $sql = "ALTER TABLE numbers DROP FOREIGN KEY $constraint_name";
        if ($conn->query($sql)) {
            echo "✓ Removed constraint: $constraint_name<br>";
        } else {
            echo "✗ Error removing constraint: " . $conn->error . "<br>";
        }
    }
}

// 3. Add proper foreign key for head_user_id
$sql = "ALTER TABLE numbers 
        ADD CONSTRAINT fk_numbers_head_user_id 
        FOREIGN KEY (head_user_id) REFERENCES users(user_id) 
        ON DELETE SET NULL";
if ($conn->query($sql)) {
    echo "✓ Added foreign key for head_user_id<br>";
} else {
    echo "✗ Error adding foreign key: " . $conn->error . "<br>";
}

// 4. Make sure conversations table exists
$sql = "CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    number_id INT NOT NULL,
    initiated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (number_id) REFERENCES numbers(number_id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_number_id (number_id),
    INDEX idx_initiated_by (initiated_by),
    UNIQUE KEY unique_user_conversation (number_id, initiated_by)
)";
if ($conn->query($sql)) {
    echo "✓ conversations table created/verified<br>";
} else {
    echo "✗ Error creating conversations table: " . $conn->error . "<br>";
}

// 5. Check and add missing columns to messages table
$columns_to_check = [
    'conversation_id' => "INT NULL",
    'receiver_id' => "INT NULL",
    'is_head_reply' => "TINYINT(1) DEFAULT 0"
];

foreach ($columns_to_check as $column_name => $column_type) {
    $sql = "SHOW COLUMNS FROM messages LIKE '$column_name'";
    $result = $conn->query($sql);
    if ($result->num_rows == 0) {
        $sql = "ALTER TABLE messages ADD COLUMN $column_name $column_type";
        if ($conn->query($sql)) {
            echo "✓ Added $column_name column to messages table<br>";
        } else {
            echo "✗ Error adding $column_name column: " . $conn->error . "<br>";
        }
    } else {
        echo "✓ $column_name column already exists<br>";
    }
}

// 6. Add foreign keys to messages table
$foreign_keys = [
    'fk_messages_conversation_id' => "ADD CONSTRAINT fk_messages_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE",
    'fk_messages_receiver_id' => "ADD CONSTRAINT fk_messages_receiver_id FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE"
];

foreach ($foreign_keys as $key_name => $sql_command) {
    $check_sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                  WHERE CONSTRAINT_SCHEMA = 'drmc_intercom' 
                  AND TABLE_NAME = 'messages' 
                  AND CONSTRAINT_NAME = '$key_name'";
    $result = $conn->query($check_sql);
    if ($result->num_rows == 0) {
        $sql = "ALTER TABLE messages $sql_command";
        if ($conn->query($sql)) {
            echo "✓ Added $key_name foreign key<br>";
        } else {
            echo "✗ Error adding $key_name: " . $conn->error . "<br>";
        }
    } else {
        echo "✓ $key_name already exists<br>";
    }
}

// 7. Update existing data to connect head_user_id properly
$sql = "UPDATE numbers n 
        JOIN users u ON n.head = u.full_name OR n.head = u.username 
        SET n.head_user_id = u.user_id 
        WHERE n.head_user_id IS NULL";
if ($conn->query($sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✓ Updated $affected_rows number records with head_user_id<br>";
} else {
    echo "✗ Error updating head_user_id: " . $conn->error . "<br>";
}

// 8. Verify the structure
echo "<h3>Verification Results:</h3>";

$tables = ['numbers', 'conversations', 'messages', 'users'];
foreach ($tables as $table) {
    echo "<h4>$table table structure:</h4>";
    $sql = "SHOW CREATE TABLE $table";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<pre style='background:#f5f5f5;padding:10px;border:1px solid #ddd;overflow:auto;'>";
        echo htmlspecialchars($row['Create Table']);
        echo "</pre>";
    }
}

echo "<h3>Setup Complete!</h3>";
echo "<p>You can now use the chat system in numpage.php</p>";
echo '<p><a href="numpage.php?id=2">Test the chat system with number ID 2</a></p>';
echo '<p><a href="homepage.php">Go to Homepage</a></p>';
?>