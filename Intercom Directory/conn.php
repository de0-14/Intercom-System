<?php
session_start();
$host = "localhost";
$user = "root";
$password = "";
$database = "drmc_intercom";

$conn = mysqli_connect($host, $user, $password, $database);
if(!$conn) {
    die("Connection Failed: ".mysqli_connect_error());
}
    
$role_names = [
    1=>"Admin",
    2=>"MCC",
    3=>"Division Head",
    4=>"Department Head",
    5=>"Unit Head",
    6=>"Office Head",
    7=>"Staff"
];

function getDivisions($conn, $activeOnly = true) {
    $sql = "SELECT * FROM divisions";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY division_name";
    $result = mysqli_query($conn, $sql);
    $divisions = [];
    while($row = mysqli_fetch_assoc($result)) {
        $divisions[] = $row;
    }
    return $divisions;
}

function getDepartmentsByDivision($conn, $division_id, $activeOnly = true) {
    $division_id = mysqli_real_escape_string($conn, $division_id);
    $sql = "SELECT * FROM departments WHERE division_id = $division_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY department_name";
    $result = mysqli_query($conn, $sql);
    $departments = [];
    while($row = mysqli_fetch_assoc($result)) {
        $departments[] = $row;
    }
    return $departments;
}

function getUnitsByDepartment($conn, $department_id, $activeOnly = true) {
    $department_id = mysqli_real_escape_string($conn, $department_id);
    $sql = "SELECT * FROM units WHERE department_id = $department_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY unit_name";
    $result = mysqli_query($conn, $sql);
    $units = [];
    while($row = mysqli_fetch_assoc($result)) {
        $units[] = $row;
    }
    return $units;
}

function getOfficesByUnit($conn, $unit_id, $activeOnly = true) {
    $unit_id = mysqli_real_escape_string($conn, $unit_id);
    $sql = "SELECT * FROM offices WHERE unit_id = $unit_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY office_name";
    $result = mysqli_query($conn, $sql);
    $offices = [];
    while($row = mysqli_fetch_assoc($result)) {
        $offices[] = $row;
    }
    return $offices;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role_id']) && intval($_SESSION['role_id']) == 1;
}

function getUserName() {
    if(isset($_SESSION['username'])) {
        return htmlspecialchars($_SESSION['username']);
    } else {
        return 'WALA NI GANA';
    }
}

function getDepartments($conn, $activeOnly = true) {
    $sql = "SELECT * FROM departments";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY department_name";
    $result = $conn->query($sql);
    $departments = [];
    while($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    return $departments;
}

function getUnits($conn, $activeOnly = true) {
    $sql = "SELECT * FROM units";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY unit_name";
    $result = $conn->query($sql);
    $units = [];
    while($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    return $units;
}

function updateUserActivity($conn, $userId) {
    $currentTime = time();
    $sql = "UPDATE users SET last_activity = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $currentTime, $userId);
    $stmt->execute();
}

function getOnlineAdmins($conn) {
    $timeout = 300;
    $onlineTime = time() - $timeout;
    
    $sql = "SELECT COUNT(*) as online_count FROM users 
            WHERE role_id = 1 
            AND last_activity > ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $onlineTime);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['online_count'] ?? 0;
}

function updateAllUsersActivity($conn) {
    if (isset($_SESSION['user_id'])) {
        updateUserActivity($conn, $_SESSION['user_id']);
    }
}

function getHeadUserId($conn, $number_id) {
    $sql = "SELECT head_user_id FROM numbers WHERE number_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $number_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['head_user_id'] ?? null;
}

function debugHeadInfo($conn, $number_id, $current_user_id) {
    $head_user_id = getHeadUserId($conn, $number_id);
    $head_name = getHeadName($conn, $number_id);
    
    echo "<div style='background:#f0f0f0; padding:10px; margin:10px 0; border:1px solid #ccc;'>";
    echo "<strong>DEBUG INFO:</strong><br>";
    echo "Number ID: $number_id<br>";
    echo "Current User ID: $current_user_id<br>";
    echo "Head Name: $head_name<br>";
    echo "Head User ID: $head_user_id<br>";
    echo "Is Current User Head?: " . ($head_user_id == $current_user_id ? 'YES' : 'NO') . "<br>";
    echo "</div>";
    
    return $head_user_id;
}


function getHeadName($conn, $number_id) {
    $sql = "SELECT head FROM numbers WHERE number_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $number_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['head'] ?? null;
}

// Add to conn.php after your existing functions
function updateConversationActivity($conn, $conversation_id) {
    $sql = "UPDATE conversations SET last_activity = NOW() WHERE conversation_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
}

function archiveInactiveConversations($conn, $inactive_minutes = 5) {
    $conn->begin_transaction();
    
    try {
        // Get conversations inactive for X minutes and not already archived
        $inactive_time = date('Y-m-d H:i:s', strtotime("-$inactive_minutes minutes"));
        
        $get_inactive_stmt = $conn->prepare("
            SELECT conversation_id 
            FROM conversations 
            WHERE is_archived = FALSE 
            AND last_activity < ? 
            AND conversation_id IN (
                SELECT DISTINCT conversation_id FROM messages
            )
        ");
        $get_inactive_stmt->bind_param("s", $inactive_time);
        $get_inactive_stmt->execute();
        $inactive_result = $get_inactive_stmt->get_result();
        
        $archived_count = 0;
        
        while ($conv = $inactive_result->fetch_assoc()) {
            $conversation_id = $conv['conversation_id'];
            
            // Archive messages
            $archive_messages = $conn->prepare("
                INSERT INTO messages_archive 
                SELECT * FROM messages 
                WHERE conversation_id = ? 
                AND is_archived = FALSE
            ");
            $archive_messages->bind_param("i", $conversation_id);
            $archive_messages->execute();
            $messages_archived = $conn->affected_rows;
            $archive_messages->close();
            
            if ($messages_archived > 0) {
                // Mark messages as archived in main table
                $mark_messages = $conn->prepare("
                    UPDATE messages 
                    SET is_archived = TRUE, 
                        archived_at = NOW() 
                    WHERE conversation_id = ? 
                    AND is_archived = FALSE
                ");
                $mark_messages->bind_param("i", $conversation_id);
                $mark_messages->execute();
                $mark_messages->close();
                
                // Archive conversation
                $archive_conversation = $conn->prepare("
                    INSERT INTO conversations_archive 
                    SELECT * FROM conversations 
                    WHERE conversation_id = ?
                ");
                $archive_conversation->bind_param("i", $conversation_id);
                $archive_conversation->execute();
                $archive_conversation->close();
                
                // Mark conversation as archived
                $mark_conv = $conn->prepare("
                    UPDATE conversations 
                    SET is_archived = TRUE, 
                        archived_at = NOW() 
                    WHERE conversation_id = ?
                ");
                $mark_conv->bind_param("i", $conversation_id);
                $mark_conv->execute();
                $mark_conv->close();
                
                $archived_count++;
            }
        }
        
        $get_inactive_stmt->close();
        
        $conn->commit();
        
        // Cleanup: Remove fully archived conversations after successful archiving
        if ($archived_count > 0) {
            $cleanup_stmt = $conn->prepare("
                DELETE FROM conversations 
                WHERE is_archived = TRUE 
                AND archived_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $cleanup_stmt->execute();
            $cleanup_stmt->close();
        }
        
        return $archived_count;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Archive error: " . $e->getMessage());
        return 0;
    }
}

function checkAndArchiveInactive($conn, $user_id, $number_id, $selected_conversation_id = null) {
    $archive_count = 0;
    
    try {
        // Archive conversations inactive for more than 5 minutes
        $archive_time = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Get conversations to archive (excluding the currently selected one if any)
        $sql = "SELECT conversation_id FROM conversations 
                WHERE number_id = ? 
                AND last_activity < ? 
                AND is_archived = 0";
        
        $params = [$number_id, $archive_time];
        $types = "is";
        
        if ($selected_conversation_id) {
            $sql .= " AND conversation_id != ?";
            $params[] = $selected_conversation_id;
            $types .= "i";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conversations_to_archive = [];
        while ($row = $result->fetch_assoc()) {
            $conversations_to_archive[] = $row['conversation_id'];
        }
        $stmt->close();
        
        // Archive each conversation
        foreach ($conversations_to_archive as $conv_id) {
            // Copy conversation to archive table
            $archive_conv_sql = "
                INSERT INTO conversations_archive 
                SELECT * FROM conversations 
                WHERE conversation_id = ?
            ";
            $stmt1 = $conn->prepare($archive_conv_sql);
            $stmt1->bind_param("i", $conv_id);
            $stmt1->execute();
            $stmt1->close();
            
            // Copy messages to archive table
            $archive_msg_sql = "
                INSERT INTO messages_archive 
                SELECT * FROM messages 
                WHERE conversation_id = ?
            ";
            $stmt2 = $conn->prepare($archive_msg_sql);
            $stmt2->bind_param("i", $conv_id);
            $stmt2->execute();
            $stmt2->close();
            
            // Mark as archived in original tables
            $mark_archived_sql = "
                UPDATE conversations SET is_archived = 1, archived_at = NOW() 
                WHERE conversation_id = ?
            ";
            $stmt3 = $conn->prepare($mark_archived_sql);
            $stmt3->bind_param("i", $conv_id);
            $stmt3->execute();
            $stmt3->close();
            
            $mark_msg_archived_sql = "
                UPDATE messages SET is_archived = 1, archived_at = NOW() 
                WHERE conversation_id = ?
            ";
            $stmt4 = $conn->prepare($mark_msg_archived_sql);
            $stmt4->bind_param("i", $conv_id);
            $stmt4->execute();
            $stmt4->close();
            
            $archive_count++;
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
    } catch (Exception $e) {
        // Rollback on error
        mysqli_rollback($conn);
        error_log("Archive error: " . $e->getMessage());
        return 0;
    }
    
    return $archive_count;
}

function restoreArchivedConversation($conn, $conversation_id, $number_id, $user_id) {
    try {
        // Check if user has permission (must be head or the original initiator)
        $check_sql = "
            SELECT c.*, n.head_user_id 
            FROM conversations_archive c
            JOIN numbers n ON c.number_id = n.number_id
            WHERE c.conversation_id = ? AND c.number_id = ?
        ";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("ii", $conversation_id, $number_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ["success" => false, "error" => "Conversation not found"];
        }
        
        $conv = $result->fetch_assoc();
        $stmt->close();
        
        // Check permissions: user must be head or original initiator
        $is_head = ($conv['head_user_id'] == $user_id);
        $is_initiator = ($conv['initiated_by'] == $user_id);
        
        if (!$is_head && !$is_initiator) {
            return ["success" => false, "error" => "Permission denied"];
        }
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Copy conversation back to active table
        $restore_conv_sql = "
            INSERT INTO conversations 
            SELECT * FROM conversations_archive 
            WHERE conversation_id = ?
        ";
        $stmt1 = $conn->prepare($restore_conv_sql);
        $stmt1->bind_param("i", $conversation_id);
        if (!$stmt1->execute()) {
            throw new Exception("Failed to restore conversation: " . $stmt1->error);
        }
        $stmt1->close();
        
        // Copy messages back to active table
        $restore_msg_sql = "
            INSERT INTO messages 
            SELECT * FROM messages_archive 
            WHERE conversation_id = ?
        ";
        $stmt2 = $conn->prepare($restore_msg_sql);
        $stmt2->bind_param("i", $conversation_id);
        if (!$stmt2->execute()) {
            throw new Exception("Failed to restore messages: " . $stmt2->error);
        }
        $stmt2->close();
        
        // Update conversation to be active
        $update_conv_sql = "
            UPDATE conversations 
            SET is_archived = 0, archived_at = NULL, last_activity = NOW() 
            WHERE conversation_id = ?
        ";
        $stmt3 = $conn->prepare($update_conv_sql);
        $stmt3->bind_param("i", $conversation_id);
        if (!$stmt3->execute()) {
            throw new Exception("Failed to update conversation: " . $stmt3->error);
        }
        $stmt3->close();
        
        // Update messages to be active
        $update_msg_sql = "
            UPDATE messages 
            SET is_archived = 0, archived_at = NULL 
            WHERE conversation_id = ?
        ";
        $stmt4 = $conn->prepare($update_msg_sql);
        $stmt4->bind_param("i", $conversation_id);
        if (!$stmt4->execute()) {
            throw new Exception("Failed to update messages: " . $stmt4->error);
        }
        $stmt4->close();
        
        // Delete from archive tables
        $delete_msg_archive_sql = "DELETE FROM messages_archive WHERE conversation_id = ?";
        $stmt5 = $conn->prepare($delete_msg_archive_sql);
        $stmt5->bind_param("i", $conversation_id);
        $stmt5->execute();
        $stmt5->close();
        
        $delete_conv_archive_sql = "DELETE FROM conversations_archive WHERE conversation_id = ?";
        $stmt6 = $conn->prepare($delete_conv_archive_sql);
        $stmt6->bind_param("i", $conversation_id);
        $stmt6->execute();
        $stmt6->close();
        
        // Commit transaction
        mysqli_commit($conn);
        
        return ["success" => true, "message" => "Conversation restored successfully"];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Restore error: " . $e->getMessage());
        return ["success" => false, "error" => $e->getMessage()];
    }
}

function getArchivedMessages($conn, $conversation_id, $user_id) {
    try {
        // Check if user has permission to view archived messages
        $check_sql = "
            SELECT c.*, n.head_user_id, u.username as initiator_name, u.full_name as initiator_full_name
            FROM conversations_archive c
            JOIN numbers n ON c.number_id = n.number_id
            JOIN users u ON c.initiated_by = u.user_id
            WHERE c.conversation_id = ?
        ";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ["success" => false, "error" => "Conversation not found"];
        }
        
        $conv = $result->fetch_assoc();
        $stmt->close();
        
        // Check permissions: user must be head or original initiator
        $is_head = ($conv['head_user_id'] == $user_id);
        $is_initiator = ($conv['initiated_by'] == $user_id);
        
        if (!$is_head && !$is_initiator) {
            return ["success" => false, "error" => "Permission denied"];
        }
        
        // Get messages
        $messages_sql = "
            SELECT m.*, u.username as sender_name 
            FROM messages_archive m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ";
        $stmt2 = $conn->prepare($messages_sql);
        $stmt2->bind_param("i", $conversation_id);
        $stmt2->execute();
        $messages_result = $stmt2->get_result();
        
        $messages = [];
        while ($row = $messages_result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt2->close();
        
        return [
            "success" => true,
            "messages" => $messages,
            "conversation_info" => $conv
        ];
        
    } catch (Exception $e) {
        error_log("Get archived messages error: " . $e->getMessage());
        return ["success" => false, "error" => $e->getMessage()];
    }
}

function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = [
        'y' => 'year',
        'm' => 'month',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
function archiveInactiveConversationsAuto($conn) {
    // Archive conversations inactive for 5+ minutes
    $five_minutes_ago = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    $archived_count = 0;
    
    // Get conversations with no activity for 5+ minutes
    $sql = "SELECT c.conversation_id, c.number_id 
            FROM conversations c 
            WHERE c.is_archived = 0 
            AND c.last_activity < ? 
            AND EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.conversation_id)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $five_minutes_ago);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $conv_id = $row['conversation_id'];
        $number_id = $row['number_id'];
        
        try {
            $conn->begin_transaction();
            
            // 1. Archive conversation
            $archive_conv = $conn->prepare("
                INSERT INTO conversations_archive 
                SELECT * FROM conversations 
                WHERE conversation_id = ?
            ");
            $archive_conv->bind_param("i", $conv_id);
            $archive_conv->execute();
            $archive_conv->close();
            
            // 2. Archive messages
            $archive_msgs = $conn->prepare("
                INSERT INTO messages_archive 
                SELECT * FROM messages 
                WHERE conversation_id = ?
            ");
            $archive_msgs->bind_param("i", $conv_id);
            $archive_msgs->execute();
            $archive_msgs->close();
            
            // 3. Mark conversation as archived
            $mark_conv = $conn->prepare("
                UPDATE conversations 
                SET is_archived = 1, archived_at = NOW() 
                WHERE conversation_id = ?
            ");
            $mark_conv->bind_param("i", $conv_id);
            $mark_conv->execute();
            $mark_conv->close();
            
            // 4. Mark messages as archived
            $mark_msgs = $conn->prepare("
                UPDATE messages 
                SET is_archived = 1 
                WHERE conversation_id = ?
            ");
            $mark_msgs->bind_param("i", $conv_id);
            $mark_msgs->execute();
            $mark_msgs->close();
            
            $conn->commit();
            $archived_count++;
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Archive error for conversation $conv_id: " . $e->getMessage());
        }
    }
    
    $stmt->close();
    return $archived_count;
}
?>