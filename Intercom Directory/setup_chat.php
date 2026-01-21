<?php
// setup_chat.php - Run this once to set up chat tables
require_once 'conn.php';

echo "<h2>Setting up Chat Tables...</h2>";

// Create conversations table
$sql = "CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    number_id INT NOT NULL,
    initiated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (number_id) REFERENCES numbers(number_id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_number_id (number_id),
    INDEX idx_initiated_by (initiated_by)
)";

if ($conn->query($sql)) {
    echo "✓ conversations table created/verified<br>";
} else {
    echo "✗ Error creating conversations table: " . $conn->error . "<br>";
}

// Add head_user_id column
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

// Add foreign key for head_user_id
$sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = 'drmc_intercom' 
        AND TABLE_NAME = 'numbers' 
        AND CONSTRAINT_NAME = 'fk_numbers_head_user_id'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE numbers 
            ADD CONSTRAINT fk_numbers_head_user_id 
            FOREIGN KEY (head_user_id) REFERENCES users(user_id) 
            ON DELETE SET NULL";
    if ($conn->query($sql)) {
        echo "✓ Added foreign key for head_user_id<br>";
    } else {
        echo "✗ Error adding foreign key: " . $conn->error . "<br>";
    }
} else {
    echo "✓ Foreign key already exists<br>";
}

// Add conversation_id column to messages
$sql = "SHOW COLUMNS FROM messages LIKE 'conversation_id'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE messages ADD COLUMN conversation_id INT NULL";
    if ($conn->query($sql)) {
        echo "✓ Added conversation_id column to messages table<br>";
    } else {
        echo "✗ Error adding conversation_id column: " . $conn->error . "<br>";
    }
} else {
    echo "✓ conversation_id column already exists<br>";
}

// Add is_head_reply column
$sql = "SHOW COLUMNS FROM messages LIKE 'is_head_reply'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE messages ADD COLUMN is_head_reply TINYINT(1) DEFAULT 0";
    if ($conn->query($sql)) {
        echo "✓ Added is_head_reply column to messages table<br>";
    } else {
        echo "✗ Error adding is_head_reply column: " . $conn->error . "<br>";
    }
} else {
    echo "✓ is_head_reply column already exists<br>";
}

// Add receiver_id column
$sql = "SHOW COLUMNS FROM messages LIKE 'receiver_id'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE messages ADD COLUMN receiver_id INT NULL";
    if ($conn->query($sql)) {
        echo "✓ Added receiver_id column to messages table<br>";
    } else {
        echo "✗ Error adding receiver_id column: " . $conn->error . "<br>";
    }
} else {
    echo "✓ receiver_id column already exists<br>";
}

echo "<h3>Setup Complete!</h3>";
echo "<p>You can now use the chat system in numpage.php</p>";
echo '<p><a href="numpage.php?id=1">Test the chat system</a> (change id=1 to an existing number ID)</p>';
?>