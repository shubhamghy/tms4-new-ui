<?php
require_once "config.php";
header('Content-Type: application/json');

$vehicle_id = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : 0;
$response = ['driver_id' => null];

if ($vehicle_id > 0) {
    $stmt = $mysqli->prepare("SELECT driver_id FROM vehicles WHERE id = ?");
    $stmt->bind_param("i", $vehicle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['driver_id']) {
            $response['driver_id'] = $row['driver_id'];
        }
    }
    $stmt->close();
}

echo json_encode($response);
$mysqli->close();
?>
