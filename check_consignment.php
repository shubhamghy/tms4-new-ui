<?php
session_start();
require_once "config.php";

// Set the content type to JSON for the response
header('Content-Type: application/json');

// Security check: ensure the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403); // Forbidden
    echo json_encode(['exists' => false, 'message' => 'Access denied.']);
    exit;
}

// Get the consignment number and the optional ID of the shipment being edited
$consignment_no = trim($_GET['consignment_no'] ?? '');
$current_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($consignment_no)) {
    echo json_encode(['exists' => false]);
    exit;
}

// Prepare a secure SQL statement to check if the CN exists on ANOTHER record
$sql = "SELECT id FROM shipments WHERE consignment_no = ? AND id != ?";

if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param("si", $consignment_no, $current_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // If rows are found, the CN exists on a different record
        echo json_encode(['exists' => true]);
    } else {
        // If no rows are found, the CN is available
        echo json_encode(['exists' => false]);
    }

    $stmt->close();
} else {
    http_response_code(500); // Internal Server Error
    echo json_encode(['exists' => false, 'message' => 'Database query failed.']);
}

$mysqli->close();
?>