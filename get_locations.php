<?php
// ✅ ADDED: Start the session and check if the user is logged in
session_start();
require_once "config.php";

// Security check: Only allow logged-in users to access this data
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

header('Content-Type: application/json');

$response = [];
$get = $_GET['get'] ?? '';

if ($get === 'states' && isset($_GET['country_id'])) {
    $country_id = intval($_GET['country_id']);
    $sql = "SELECT id, name FROM states WHERE country_id = ? ORDER BY name ASC";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $country_id);
        $stmt->execute();
        $result = $stmt->get_result();
        // ✅ IMPROVED: Using fetch_all() is more concise than a while loop
        $response = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} elseif ($get === 'cities' && isset($_GET['state_id'])) {
    $state_id = intval($_GET['state_id']);
    $sql = "SELECT id, name FROM cities WHERE state_id = ? ORDER BY name ASC";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $state_id);
        $stmt->execute();
        $result = $stmt->get_result();
        // ✅ IMPROVED: Using fetch_all()
        $response = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

echo json_encode($response);
$mysqli->close();
?>