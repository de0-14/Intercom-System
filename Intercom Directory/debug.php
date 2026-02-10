<?php
require_once 'conn.php';

echo "<h2>Debugging Division Issue</h2>";

// 1. First, check what numbers we have
echo "<h3>1. Numbers in Database:</h3>";
$sql = "SELECT number_id, numbers, division_id, department_id, unit_id, office_id, status FROM numbers";
$stmt = sqlsrv_query($conn, $sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Number</th><th>Div ID</th><th>Dept ID</th><th>Unit ID</th><th>Office ID</th><th>Status</th></tr>";
if($stmt) {
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['number_id']}</td>";
        echo "<td>{$row['numbers']}</td>";
        echo "<td>" . ($row['division_id'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['department_id'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['unit_id'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['office_id'] ?? 'NULL') . "</td>";
        echo "<td>{$row['status']}</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
}
echo "</table>";

// 2. Check divisions table
echo "<h3>2. Divisions in Database:</h3>";
$sql = "SELECT division_id, division_name, status FROM divisions ORDER BY division_id";
$stmt = sqlsrv_query($conn, $sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Status</th></tr>";
if($stmt) {
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['division_id']}</td>";
        echo "<td>{$row['division_name']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
}
echo "</table>";

// 3. Check specific division IDs mentioned in numbers (3, 4, 5)
echo "<h3>3. Checking Specific Division IDs (3, 4, 5):</h3>";
$sql = "SELECT division_id, division_name, status FROM divisions WHERE division_id IN (3, 4, 5)";
$stmt = sqlsrv_query($conn, $sql);

if($stmt) {
    $found = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $found[] = $row['division_id'];
        echo "<p>Found Division ID {$row['division_id']}: {$row['division_name']} (Status: {$row['status']})</p>";
    }
    
    $missing = array_diff([3, 4, 5], $found);
    if(!empty($missing)) {
        echo "<p style='color: red;'>Missing Division IDs: " . implode(', ', $missing) . "</p>";
    }
    sqlsrv_free_stmt($stmt);
}

// 4. Test the JOIN query
echo "<h3>4. Testing JOIN Query:</h3>";
$sql = "SELECT n.number_id, n.division_id, d.division_name, d.status as div_status
        FROM numbers n
        LEFT JOIN divisions d ON n.division_id = d.division_id
        WHERE n.division_id IS NOT NULL";

$stmt = sqlsrv_query($conn, $sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Number ID</th><th>Division ID</th><th>Division Name</th><th>Div Status</th></tr>";
if($stmt) {
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['number_id']}</td>";
        echo "<td>{$row['division_id']}</td>";
        echo "<td>" . ($row['division_name'] ?? 'NULL/Not Found') . "</td>";
        echo "<td>" . ($row['div_status'] ?? 'NULL/Not Found') . "</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
}
echo "</table>";

// 5. Test the full getAllContactNumbers logic
echo "<h3>5. Testing getAllContactNumbers Logic:</h3>";

// Simulate the logic from getAllContactNumbers
$sql = "SELECT 
            n.number_id,
            n.numbers as contact_number,
            n.division_id,
            d.division_name,
            d.status as division_status,
            CASE 
                WHEN n.division_id IS NOT NULL THEN 'Division'
                ELSE 'Unknown'
            END as unit_type
        FROM numbers n
        LEFT JOIN divisions d ON n.division_id = d.division_id
        WHERE n.division_id IS NOT NULL";

$stmt = sqlsrv_query($conn, $sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Number ID</th><th>Contact Number</th><th>Div ID</th><th>Division Name</th><th>Div Status</th><th>Unit Type</th></tr>";
if($stmt) {
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['number_id']}</td>";
        echo "<td>{$row['contact_number']}</td>";
        echo "<td>{$row['division_id']}</td>";
        echo "<td>" . ($row['division_name'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['division_status'] ?? 'NULL') . "</td>";
        echo "<td>{$row['unit_type']}</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
}
echo "</table>";

// 6. Check database structure
echo "<h3>6. Database Structure Check:</h3>";
echo "<h4>Numbers Table Structure:</h4>";
$sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'numbers' 
        AND COLUMN_NAME LIKE '%division%' 
        OR COLUMN_NAME LIKE '%department%' 
        OR COLUMN_NAME LIKE '%unit%' 
        OR COLUMN_NAME LIKE '%office%'";
$stmt = sqlsrv_query($conn, $sql);

if($stmt) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Column</th><th>Data Type</th><th>Nullable</th></tr>";
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['COLUMN_NAME']}</td>";
        echo "<td>{$row['DATA_TYPE']}</td>";
        echo "<td>{$row['IS_NULLABLE']}</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
    echo "</table>";
}

// 7. Check if there are any data type mismatches
echo "<h4>Data Type Issues:</h4>";
$sql = "SELECT 
            n.number_id,
            n.division_id,
            CASE 
                WHEN ISNUMERIC(n.division_id) = 1 THEN 'Numeric'
                ELSE 'Non-numeric'
            END as div_id_type,
            d.division_id as div_table_id
        FROM numbers n
        LEFT JOIN divisions d ON CAST(n.division_id AS VARCHAR) = CAST(d.division_id AS VARCHAR)
        WHERE n.division_id IS NOT NULL";

$stmt = sqlsrv_query($conn, $sql);
if($stmt) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Number ID</th><th>Division ID (numbers)</th><th>Type</th><th>Division ID (divisions)</th></tr>";
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['number_id']}</td>";
        echo "<td>{$row['division_id']}</td>";
        echo "<td>{$row['div_id_type']}</td>";
        echo "<td>" . ($row['div_table_id'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    sqlsrv_free_stmt($stmt);
    echo "</table>";
}

echo "<hr><h3>Summary:</h3>";
echo "<p>Based on the debug output above:</p>";
echo "<ol>";
echo "<li>Check if division IDs in numbers table match IDs in divisions table</li>";
echo "<li>Check if division records exist for IDs 3, 4, 5</li>";
echo "<li>Check if there are data type mismatches (e.g., string vs integer)</li>";
echo "<li>Check if the JOIN is working correctly</li>";
echo "</ol>";

echo "<p><a href='index.php'>← Back to Contact Directory</a></p>";
?>