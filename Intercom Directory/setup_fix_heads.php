<?php
require_once 'conn.php';

echo "<h2>Fixing Head User Links...</h2>";

// First, show current state
$sql = "SELECT n.number_id, n.numbers, n.head, n.head_user_id, u.username as head_username 
        FROM numbers n 
        LEFT JOIN users u ON n.head_user_id = u.user_id";
$result = $conn->query($sql);

echo "<h3>Current Head Assignments:</h3>";
echo "<table border='1' cellpadding='8' cellspacing='0'>";
echo "<tr><th>Number ID</th><th>Number</th><th>Head Name</th><th>Head User ID</th><th>Head Username</th></tr>";
while($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['number_id'] . "</td>";
    echo "<td>" . $row['numbers'] . "</td>";
    echo "<td>" . $row['head'] . "</td>";
    echo "<td>" . $row['head_user_id'] . "</td>";
    echo "<td>" . $row['head_username'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Fix the head_user_id links
echo "<h3>Fixing links...</h3>";

// Method 1: Try to match by full_name
$sql = "UPDATE numbers n 
        JOIN users u ON n.head = u.full_name 
        SET n.head_user_id = u.user_id 
        WHERE n.head_user_id IS NULL OR n.head_user_id = 0";
$conn->query($sql);
echo "✓ Updated by full_name: " . $conn->affected_rows . " rows<br>";

// Method 2: Try to match by username
$sql = "UPDATE numbers n 
        JOIN users u ON n.head = u.username 
        SET n.head_user_id = u.user_id 
        WHERE n.head_user_id IS NULL OR n.head_user_id = 0";
$conn->query($sql);
echo "✓ Updated by username: " . $conn->affected_rows . " rows<br>";

// Method 3: Manual fixes based on your data
$manual_fixes = [
    [2, 2],  // number_id 2 -> head_user_id 2 (admin/JDLT)
    [4, 2],  // number_id 4 -> head_user_id 2 (JDLT)
    [5, 2],  // number_id 5 -> head_user_id 2 (JDLT)
    [6, 2],  // number_id 6 -> head_user_id 2 (JDLT)
];

foreach ($manual_fixes as $fix) {
    $number_id = $fix[0];
    $head_user_id = $fix[1];
    $sql = "UPDATE numbers SET head_user_id = $head_user_id WHERE number_id = $number_id";
    $conn->query($sql);
    echo "✓ Manually fixed number $number_id to head_user_id $head_user_id<br>";
}

// Show final state
$sql = "SELECT n.number_id, n.numbers, n.head, n.head_user_id, u.username as head_username 
        FROM numbers n 
        LEFT JOIN users u ON n.head_user_id = u.user_id";
$result = $conn->query($sql);

echo "<h3>Final Head Assignments:</h3>";
echo "<table border='1' cellpadding='8' cellspacing='0'>";
echo "<tr><th>Number ID</th><th>Number</th><th>Head Name</th><th>Head User ID</th><th>Head Username</th></tr>";
while($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['number_id'] . "</td>";
    echo "<td>" . $row['numbers'] . "</td>";
    echo "<td>" . $row['head'] . "</td>";
    echo "<td>" . $row['head_user_id'] . "</td>";
    echo "<td>" . $row['head_username'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Done!</h3>";
echo "<p><a href='numpage.php?id=2'>Test Number 2</a></p>";
?>
