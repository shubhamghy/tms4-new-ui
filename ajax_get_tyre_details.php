<?php
session_start();
require_once "config.php";

// Access Control
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access Denied.']);
    exit;
}

header('Content-Type: application/json');
$tyre_id = isset($_GET['tyre_id']) ? intval($_GET['tyre_id']) : 0;

if ($tyre_id <= 0) {
    echo json_encode(['error' => 'Invalid Tyre ID.']);
    exit;
}

$response = [
    'details' => null,
    'mounts' => [],
    'retreads' => [],
    'performance' => [
        'total_km' => 0,
        'total_cost' => 0,
        'cpk' => 'N/A'
    ]
];

try {
    // 1. Fetch Tyre Details
    $stmt_details = $mysqli->prepare("SELECT * FROM tyre_inventory WHERE id = ?");
    $stmt_details->bind_param("i", $tyre_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    $tyre_details = $result_details->fetch_assoc();
    $stmt_details->close();

    if (!$tyre_details) {
        throw new Exception("Tyre not found.");
    }
    $response['details'] = $tyre_details;

    // 2. Fetch Mount History
    $mount_history_sql = "SELECT vt.*, v.vehicle_number 
                          FROM vehicle_tyres vt 
                          JOIN vehicles v ON vt.vehicle_id = v.id 
                          WHERE vt.tyre_id = ? 
                          ORDER BY vt.mount_date DESC";
    $stmt_mounts = $mysqli->prepare($mount_history_sql);
    $stmt_mounts->bind_param("i", $tyre_id);
    $stmt_mounts->execute();
    $mount_history = $stmt_mounts->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_mounts->close();
    $response['mounts'] = $mount_history;

    // 3. Fetch Retread History
    $retread_history_sql = "SELECT * FROM tyre_retreading WHERE tyre_id = ? ORDER BY retread_date DESC";
    $stmt_retreads = $mysqli->prepare($retread_history_sql);
    $stmt_retreads->bind_param("i", $tyre_id);
    $stmt_retreads->execute();
    $retread_history = $stmt_retreads->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_retreads->close();
    $response['retreads'] = $retread_history;

    // 4. Calculate Performance Metrics
    $total_cost = (float)$tyre_details['purchase_cost'];
    $total_km = 0;

    foreach ($retread_history as $retread) {
        $total_cost += (float)$retread['cost'];
    }

    $is_mounted = false;
    $last_mount_odo = 0;
    $last_vehicle_id = 0;

    foreach ($mount_history as $mount) {
        if (!empty($mount['unmount_odometer']) && !empty($mount['mount_odometer'])) {
            $total_km += (int)$mount['unmount_odometer'] - (int)$mount['mount_odometer'];
        }
        if (empty($mount['unmount_date'])) {
            $is_mounted = true;
            $last_mount_odo = (int)$mount['mount_odometer'];
            $last_vehicle_id = (int)$mount['vehicle_id'];
        }
    }

    // ---
    // TODO: For more accurate "live" KM calculation, you need the vehicle's CURRENT odometer.
    // It's recommended to add a `current_odometer` column to your `vehicles` table.
    // You would then fetch it here if $is_mounted is true.
    /*
    if ($is_mounted && $last_vehicle_id > 0) {
        $stmt_vehicle_odo = $mysqli->prepare("SELECT current_odometer FROM vehicles WHERE id = ?");
        $stmt_vehicle_odo->bind_param("i", $last_vehicle_id);
        $stmt_vehicle_odo->execute();
        $current_odo = $stmt_vehicle_odo->get_result()->fetch_assoc()['current_odometer'];
        $stmt_vehicle_odo->close();

        if ($current_odo > $last_mount_odo) {
            $total_km += $current_odo - $last_mount_odo;
        }
    }
    */
    // ---

    $response['performance']['total_cost'] = $total_cost;
    $response['performance']['total_km'] = $total_km;
    $response['performance']['cpk'] = ($total_km > 0) ? number_format($total_cost / $total_km, 2) : '0.00';

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>