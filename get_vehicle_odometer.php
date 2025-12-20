<?php
session_start();
require_once "config.php";

// Basic security: Ensure user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$vehicle_id = intval($_GET['vehicle_id'] ?? 0);
// --- FIX: Read the exclude_id parameter sent from the edit form ---
$exclude_id = intval($_GET['exclude_id'] ?? 0);

if ($vehicle_id > 0) {
    
    // --- FIX: Modify query to exclude the current log ID ---
    $sql = "SELECT MAX(odometer_reading) as last_odometer FROM maintenance_logs WHERE vehicle_id = ?";
    $params = [$vehicle_id];
    $types = "i";
    
    if ($exclude_id > 0) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }
    // --- END FIX ---
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Return the last odometer reading, or 0 if none is found
    echo json_encode(['last_odometer' => $row['last_odometer'] ?? 0]);
} else {
    echo json_encode(['last_odometer' => 0]);
}
exit;
?>