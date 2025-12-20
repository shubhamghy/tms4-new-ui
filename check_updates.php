<?php
session_start();
require_once "config.php";

header('Content-Type: application/json');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_GET['shipment_id'])) {
    echo json_encode(['error' => 'Unauthorized or missing parameters']);
    exit;
}

$shipment_id = intval($_GET['shipment_id']);
$today = date('Y-m-d');

$sql = "SELECT COUNT(id) as count FROM shipment_tracking WHERE shipment_id = ? AND DATE(created_at) = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("is", $shipment_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode(['count' => $row['count'] ?? 0]);
$stmt->close();
?>

