<?php
session_start();
require_once "config.php";

// Basic security checks
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
$response = ['success' => false, 'message' => 'Invalid type specified.'];
$created_by_branch_id = $_SESSION['branch_id'] ?? null;

switch ($type) {
    case 'party':
        if (empty($_POST['name']) || empty($_POST['address'])) {
            $response['message'] = 'Name and Address are required.';
            echo json_encode($response); exit;
        }
        $sql = "INSERT INTO parties (name, address, city, gst_no, party_type, is_active, branch_id) VALUES (?, ?, ?, ?, ?, 1, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssi", $_POST['name'], $_POST['address'], $_POST['city'], $_POST['gst_no'], $_POST['party_type'], $created_by_branch_id);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['name'], 'address' => $_POST['address'], 'party_type' => $_POST['party_type'], 'branch_id' => $created_by_branch_id];
        } else { $response['message'] = 'Database error: ' . $stmt->error; }
        $stmt->close();
        break;

    case 'broker':
        if (empty($_POST['name'])) { $response['message'] = 'Broker Name is required.'; echo json_encode($response); exit; }
        $sql = "INSERT INTO brokers (name, address, contact_number, branch_id) VALUES (?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssi", $_POST['name'], $_POST['address'], $_POST['contact_number'], $created_by_branch_id);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['name']];
        } else { $response['message'] = 'Database error: ' . $stmt->error; }
        $stmt->close();
        break;

    case 'vehicle':
        if (empty($_POST['vehicle_number'])) { $response['message'] = 'Vehicle Number is required.'; echo json_encode($response); exit; }
        // ✅ MODIFIED: Added 'branch_id' to the INSERT statement.
        $sql = "INSERT INTO vehicles (vehicle_number, branch_id) VALUES (?, ?)";
        $stmt = $mysqli->prepare($sql);
        // ✅ MODIFIED: Added the branch ID to the bind_param call.
        $stmt->bind_param("si", $_POST['vehicle_number'], $created_by_branch_id);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['vehicle_number']];
        } else { $response['message'] = 'Database error. Vehicle number might already exist.'; }
        $stmt->close();
        break;

    case 'driver':
        if (empty($_POST['name']) || empty($_POST['license_number'])) { $response['message'] = 'Driver Name and License are required.'; echo json_encode($response); exit; }
        // ✅ MODIFIED: Added 'branch_id' to the INSERT statement.
        $sql = "INSERT INTO drivers (name, license_number, branch_id) VALUES (?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        // ✅ MODIFIED: Added the branch ID to the bind_param call.
        $stmt->bind_param("ssi", $_POST['name'], $_POST['license_number'], $created_by_branch_id);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['name']];
        } else { $response['message'] = 'Database error. License number might already exist.'; }
        $stmt->close();
        break;

    case 'city':
        if (empty($_POST['name']) || empty($_POST['state_id'])) {
            $response['message'] = 'City Name and State are required.';
            echo json_encode($response); exit;
        }
        $sql = "INSERT INTO cities (name, state_id) VALUES (?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("si", $_POST['name'], $_POST['state_id']);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['name']];
        } else { $response['message'] = 'Database error: ' . $stmt->error; }
        $stmt->close();
        break;
    
    case 'description':
         if (empty($_POST['description'])) {
            $response['message'] = 'Description is required.';
            echo json_encode($response); exit;
        }
        $sql = "INSERT INTO consignment_descriptions (description) VALUES (?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $_POST['description']);
        if ($stmt->execute()) {
            $last_id = $stmt->insert_id;
            $response = ['success' => true, 'id' => $last_id, 'name' => $_POST['description']];
        } else { $response['message'] = 'Database error: ' . $stmt->error; }
        $stmt->close();
        break;
}

echo json_encode($response);
exit;
?>