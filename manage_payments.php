<?php
session_start();
require_once "config.php";

// 1. Authentication & Authorization
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$current_branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
$can_manage = in_array($user_role, ['admin', 'manager']);

if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}

// 2. Global Branch Filter
$branch_sql_filter = "";
$branch_param_type = "";
$branch_param_value = null;

if ($user_role !== 'admin' && $current_branch_id > 0) {
    $branch_sql_filter = " AND s.branch_id = ?";
    $branch_param_type = "i"; 
    $branch_param_value = $current_branch_id;
}

// ===================================================================================
// --- AI PREDICTION ENGINE ---
// ===================================================================================
if (isset($_GET['action']) && $_GET['action'] === 'predict_rates' && isset($_GET['shipment_id'])) {
    header('Content-Type: application/json');
    $current_id = intval($_GET['shipment_id']);

    $stmt_ctx = $mysqli->prepare("SELECT consignor_id, consignee_id, origin, destination, chargeable_weight, chargeable_weight_unit FROM shipments WHERE id = ?");
    $stmt_ctx->bind_param("i", $current_id);
    $stmt_ctx->execute();
    $ctx = $stmt_ctx->get_result()->fetch_assoc();
    $stmt_ctx->close();

    if (!$ctx) { echo json_encode(['success' => false, 'message' => 'Context not found']); exit; }

    function normalizeToKg($weight, $unit) {
        $weight = (float)$weight;
        $unit = strtolower(trim($unit));
        if ($unit === 'ton' || $unit === 'tons' || $unit === 'mt') return $weight * 1000;
        if ($unit === 'quintal' || $unit === 'quintals') return $weight * 100;
        if ($unit === 'kg' || $unit === 'kgs') return $weight;
        return 0; 
    }

    $target_unit = $ctx['chargeable_weight_unit'];
    $target_kg = normalizeToKg($ctx['chargeable_weight'], $target_unit);
    $is_ftl = (strtolower($target_unit) === 'ftl');

    $sql_history = "
        SELECT sp.payment_type, sp.rate, sp.amount, sp.billing_method, s.consignment_date, s.consignee_id, s.chargeable_weight, s.chargeable_weight_unit
        FROM shipment_payments sp
        JOIN shipments s ON sp.shipment_id = s.id
        WHERE s.consignor_id = ? 
        AND s.origin = ? 
        AND s.destination = ? 
        AND s.payment_entry_status = 'Done'
        AND s.id != ?
        ORDER BY s.consignment_date DESC 
        LIMIT 100
    ";

    $stmt_hist = $mysqli->prepare($sql_history);
    $stmt_hist->bind_param("issi", $ctx['consignor_id'], $ctx['origin'], $ctx['destination'], $current_id);
    $stmt_hist->execute();
    $result = $stmt_hist->get_result();
    
    $candidates = [];

    while ($row = $result->fetch_assoc()) {
        $score = 0;
        $hist_unit = $row['chargeable_weight_unit'];
        $hist_is_ftl = (strtolower($hist_unit) === 'ftl');
        
        if ($is_ftl) {
            if (!$hist_is_ftl) continue; 
            $score += 30; 
        } else {
            if ($hist_is_ftl) continue;
            $hist_kg = normalizeToKg($row['chargeable_weight'], $hist_unit);
            $min_w = $target_kg * 0.7;
            $max_w = $target_kg * 1.3;

            if ($hist_kg >= $min_w && $hist_kg <= $max_w) {
                $score += 20; 
            } else {
                continue; 
            }
        }

        if ($row['consignee_id'] == $ctx['consignee_id']) $score += 30;

        $days_old = (time() - strtotime($row['consignment_date'])) / (60 * 60 * 24);
        if ($days_old < 30) $score += 10;
        elseif ($days_old < 90) $score += 5;
        else $score += 1;

        $type = $row['payment_type'];
        if ($type === 'Advance Cash') {
            $key = (string)((float)$row['amount']);
            if (!isset($candidates[$type][$key])) $candidates[$type][$key] = ['score' => 0, 'count' => 0, 'amount' => $row['amount']];
            $candidates[$type][$key]['score'] += $score;
        } else {
            $key = $row['rate'] . '|' . $row['billing_method'];
            if (!isset($candidates[$type][$key])) $candidates[$type][$key] = ['score' => 0, 'count' => 0, 'data' => $row];
            $candidates[$type][$key]['score'] += $score;
        }
    }
    $stmt_hist->close();

    function pickWinner($array, $is_amount = false) {
        if (empty($array)) return null;
        usort($array, function($a, $b) { return $b['score'] - $a['score']; });
        return $is_amount ? $array[0]['amount'] : $array[0]['data'];
    }

    $best_party = pickWinner($candidates['Billing Rate'] ?? []);
    $best_vehicle = pickWinner($candidates['Lorry Hire'] ?? []);
    $best_advance = pickWinner($candidates['Advance Cash'] ?? [], true);

    echo json_encode([
        'success' => true,
        'party' => $best_party,
        'vehicle' => $best_vehicle,
        'advance_cash' => $best_advance,
        'message' => 'Rates predicted using Consignee & Unit Normalization.'
    ]);
    exit;
}
// ===================================================================================

$form_message = "";
$edit_mode = false;
$shipment_data = [];
$payment_data = [];

// 3. Handle POST Request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['shipment_id'])) {
    $shipment_id = intval($_POST['shipment_id']);
    $created_by_id = $_SESSION['id'];
    $payment_date = date('Y-m-d');

    $mysqli->begin_transaction();
    try {
        $delete_stmt = $mysqli->prepare("DELETE FROM shipment_payments WHERE shipment_id = ?");
        $delete_stmt->bind_param("i", $shipment_id);
        if (!$delete_stmt->execute()) { throw new Exception("Error clearing old payment data."); }
        $delete_stmt->close();

        $payment_sql = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, billing_method, rate, payment_date, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $payment_stmt = $mysqli->prepare($payment_sql);
        $charge_sql = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, payment_date, created_by_id) VALUES (?, ?, ?, ?, ?)";
        $charge_stmt = $mysqli->prepare($charge_sql);

        // A. Save Party Billing
        $payment_type_billing = 'Billing Rate';
        $party_billing_method = $_POST['party_billing_method'];
        $party_rate = (float)$_POST['party_rate'];
        $party_total_billing = (float)$_POST['party_total_billing'];
        $payment_stmt->bind_param("isdsdsi", $shipment_id, $payment_type_billing, $party_total_billing, $party_billing_method, $party_rate, $payment_date, $created_by_id);
        $payment_stmt->execute();

        // B. Save Lorry Hire
        $payment_type_hire = 'Lorry Hire';
        $vehicle_billing_method = $_POST['vehicle_billing_method'];
        $vehicle_rate = (float)$_POST['vehicle_rate'];
        $vehicle_total_hire = (float)$_POST['vehicle_total_hire'];
        $payment_stmt->bind_param("isdsdsi", $shipment_id, $payment_type_hire, $vehicle_total_hire, $vehicle_billing_method, $vehicle_rate, $payment_date, $created_by_id);
        $payment_stmt->execute();
        $payment_stmt->close();

        // C. Save Other Charges
        $other_charges = [
            'Advance Cash' => $_POST['advance_cash'], 
            'Advance Diesel' => $_POST['advance_diesel'], 
            'Labour Charge' => $_POST['labour_charge'], 
            'Dala Charge' => $_POST['dala_charge'], 
            'Lifting Charge' => $_POST['lifting_charge']
        ];

        foreach ($other_charges as $type => $amount) {
            if (!empty($amount)) {
                $amount_decimal = (float)$amount;
                $charge_stmt->bind_param("isdsi", $shipment_id, $type, $amount_decimal, $payment_date, $created_by_id);
                $charge_stmt->execute();
            }
        }
        $charge_stmt->close();

        // D. Update Shipment Status
        $update_sql = "UPDATE shipments SET payment_entry_status='Done' WHERE id=?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $shipment_id);
        $update_stmt->execute();
        $update_stmt->close();

        $mysqli->commit();

        // --- FIND NEXT PENDING SHIPMENT ---
        $next_shipment = null;
        $sql_next = "SELECT id, consignment_no FROM shipments s WHERE s.payment_entry_status != 'Done' $branch_sql_filter ORDER BY s.consignment_date ASC, s.id ASC LIMIT 1";
        $stmt_next = $mysqli->prepare($sql_next);
        if ($branch_param_value !== null) {
            $stmt_next->bind_param($branch_param_type, $branch_param_value);
        }
        $stmt_next->execute();
        $res_next = $stmt_next->get_result();
        if ($res_next->num_rows > 0) {
            $next_shipment = $res_next->fetch_assoc();
        }
        $stmt_next->close();

        // Added ID 'system-message' to identify presence of message
        $form_message = '<div id="system-message" class="p-6 mb-6 bg-green-50 border border-green-200 rounded-xl shadow-sm flex flex-col md:flex-row items-center justify-between gap-4 animate-fade-in-down">';
        $form_message .= '<div class="flex items-center gap-3"><div class="bg-green-100 p-2 rounded-full"><i class="fas fa-check text-green-600 text-xl"></i></div>';
        $form_message .= '<div><h4 class="text-green-800 font-bold">Payment Details Saved!</h4><p class="text-green-600 text-sm">Entry has been updated successfully.</p></div></div>';
        
        $form_message .= '<div class="flex gap-3">';
        $form_message .= '<a href="manage_payments.php" class="px-4 py-2 border border-green-300 text-green-700 font-medium rounded-lg hover:bg-green-100 transition">Back to List</a>';
        
        if ($next_shipment) {
            $form_message .= '<a href="manage_payments.php?action=edit&id='.$next_shipment['id'].'" id="next-btn" class="px-5 py-2 bg-green-600 text-white font-bold rounded-lg shadow-md hover:bg-green-700 transition flex items-center gap-2 transform hover:-translate-y-0.5"><i class="fas fa-arrow-right"></i> Process Next: '.$next_shipment['consignment_no'].' (Alt+N)</a>';
        } else {
            $form_message .= '<span class="px-4 py-2 bg-gray-100 text-gray-500 rounded-lg border border-gray-200 cursor-default">All Pending Cleared!</span>';
        }
        $form_message .= '</div></div>';

        $edit_mode = false;

    } catch (Exception $e) {
        $mysqli->rollback();
        // Added ID 'system-message'
        $form_message = '<div id="system-message" class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $e->getMessage() . '</div>';
    }
}

// 4. Handle Edit Mode
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_mode = true;
    $shipment_id = intval($_GET['id']);
    
    $sql = "SELECT s.*, consignor.name AS consignor_name, consignee.name AS consignee_name, v.vehicle_number, d.name as driver_name 
            FROM shipments s 
            JOIN parties consignor ON s.consignor_id = consignor.id 
            JOIN parties consignee ON s.consignee_id = consignee.id 
            LEFT JOIN vehicles v ON s.vehicle_id = v.id 
            LEFT JOIN drivers d ON s.driver_id = d.id 
            WHERE s.id = ? $branch_sql_filter";
    
    if ($stmt = $mysqli->prepare($sql)) {
        if ($branch_param_value !== null) {
            $stmt->bind_param("i" . $branch_param_type, $shipment_id, $branch_param_value);
        } else {
            $stmt->bind_param("i", $shipment_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $shipment_data = $result->fetch_assoc();
            $payment_result = $mysqli->query("SELECT payment_type, amount, billing_method, rate FROM shipment_payments WHERE shipment_id = $shipment_id");
            while($row = $payment_result->fetch_assoc()){
                $payment_data[$row['payment_type']] = $row;
            }
        } else {
            $edit_mode = false; 
            $form_message = '<div id="system-message" class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50">Shipment not found.</div>';
        }
        $stmt->close();
    }
}

// 5. Pagination & Fetching
$records_per_page = 10;
$search_reverify = isset($_GET['search_reverify']) ? trim($_GET['search_reverify']) : '';
$search_pending = isset($_GET['search_pending']) ? trim($_GET['search_pending']) : '';
$search_done = isset($_GET['search_done']) ? trim($_GET['search_done']) : '';

$page_reverify = isset($_GET['page_reverify']) ? (int)$_GET['page_reverify'] : 1;
$page_pending = isset($_GET['page_pending']) ? (int)$_GET['page_pending'] : 1;
$page_done = isset($_GET['page_done']) ? (int)$_GET['page_done'] : 1;

function getPaginatedData($mysqli, $status, $search_term, $page, $per_page, $branch_filter, $branch_val, $branch_type) {
    $offset = ($page - 1) * $per_page;
    $search_param = "%{$search_term}%";
    
    $sql_count = "SELECT COUNT(*) FROM shipments s JOIN parties p ON s.consignor_id = p.id WHERE s.payment_entry_status = ? AND s.consignment_no LIKE ? $branch_filter";
    $stmt_count = $mysqli->prepare($sql_count);
    if ($branch_val !== null) { $stmt_count->bind_param("ss" . $branch_type, $status, $search_param, $branch_val); } 
    else { $stmt_count->bind_param("ss", $status, $search_param); }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    
    $sql_data = "SELECT s.id, s.consignment_no, s.consignment_date, p.name as consignor_name, s.origin, s.destination 
                 FROM shipments s 
                 JOIN parties p ON s.consignor_id = p.id 
                 WHERE s.payment_entry_status = ? AND s.consignment_no LIKE ? $branch_filter 
                 ORDER BY s.consignment_date DESC LIMIT ?, ?";
    $stmt_data = $mysqli->prepare($sql_data);
    if ($branch_val !== null) { $stmt_data->bind_param("ss" . $branch_type . "ii", $status, $search_param, $branch_val, $offset, $per_page); } 
    else { $stmt_data->bind_param("ssii", $status, $search_param, $offset, $per_page); }
    $stmt_data->execute();
    $data = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_data->close();

    return ['data' => $data, 'total_pages' => ceil($total_records / $per_page), 'current_page' => $page];
}

$reverify_list = []; $pending_list = []; $done_list = [];
if (!$edit_mode) {
    $reverify_list = getPaginatedData($mysqli, 'Reverify', $search_reverify, $page_reverify, $records_per_page, $branch_sql_filter, $branch_param_value, $branch_param_type);
    $pending_list = getPaginatedData($mysqli, 'Pending', $search_pending, $page_pending, $records_per_page, $branch_sql_filter, $branch_param_value, $branch_param_type);
    $done_list = getPaginatedData($mysqli, 'Done', $search_done, $page_done, $records_per_page, $branch_sql_filter, $branch_param_value, $branch_param_type);
}
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { font-family: 'Inter', sans-serif; height: 100%; overflow: hidden; } 
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in-down { animation: fadeInDown 0.5s ease-out; }
    </style>
</head>
<body class="bg-gray-50">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center backdrop-blur-sm">
        <div class="flex flex-col items-center">
            <div class="fas fa-circle-notch fa-spin fa-3x text-indigo-600 mb-4"></div>
            <p class="text-gray-500 font-medium">Loading TMS...</p>
        </div>
    </div>

    <div class="flex h-screen bg-gray-50 overflow-hidden">
        
        <div class="md:hidden fixed inset-0 z-40 hidden transition-opacity duration-300 bg-gray-900 bg-opacity-50" id="sidebar-overlay"></div>
        <div id="sidebar-wrapper" class="z-50 md:relative md:z-0 md:block hidden h-full shadow-xl">
             <?php include 'sidebar.php'; ?>
        </div>
        
        <div class="flex flex-col flex-1 min-w-0 bg-gray-50 relative w-full">
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-file-invoice-dollar opacity-80"></i> Manage Payments
                            </h1>
                        </div>
                        
                        <div class="flex items-center gap-4">
                             <span class="text-indigo-100 text-sm hidden md:inline-block bg-white/10 px-3 py-1 rounded-full border border-white/10">
                                <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?>
                             </span>
                            <a href="logout.php" class="text-indigo-200 hover:text-white hover:bg-white/10 p-2 rounded-full transition-colors" title="Logout">
                                <i class="fas fa-sign-out-alt fa-lg"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <main class="flex-1 overflow-y-auto p-4 md:p-8 w-full bg-gray-50">
                <?php if(!empty($form_message)) echo $form_message; ?>

                <?php if ($edit_mode): ?>
                <div class="max-w-7xl mx-auto space-y-6">
                    
                    <div class="bg-indigo-900 rounded-xl shadow-lg p-6 text-white flex flex-col md:flex-row justify-between items-center relative overflow-hidden">
                        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-32 h-32 bg-white opacity-5 rounded-full"></div>
                        <div class="z-10">
                            <p class="text-indigo-200 text-xs font-bold uppercase tracking-wider mb-1">Consignment Number</p>
                            <h1 class="text-4xl font-extrabold tracking-tight"><?php echo htmlspecialchars($shipment_data['consignment_no']); ?></h1>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-indigo-100 overflow-hidden">
                        <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100">
                            <h2 class="text-lg font-bold text-indigo-800 flex items-center gap-2">
                                <i class="fas fa-info-circle text-indigo-500"></i> Shipment Details
                            </h2>
                        </div>
                        
                        <div id="summary-details" 
                             data-weight-val="<?php echo htmlspecialchars($shipment_data['chargeable_weight']); ?>" 
                             data-weight-unit="<?php echo htmlspecialchars($shipment_data['chargeable_weight_unit']); ?>"
                             class="p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-y-6 gap-x-8 text-sm">
                            
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Date</span> <span class="text-gray-900 font-medium"><?php echo date("d-m-Y", strtotime($shipment_data['consignment_date'])); ?></span></div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Vehicle</span> <span class="text-gray-900 font-medium"><i class="fas fa-truck text-gray-400 mr-1"></i> <?php echo htmlspecialchars($shipment_data['vehicle_number'] ?? 'N/A'); ?></span></div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Driver</span> <span class="text-gray-900 font-medium"><i class="fas fa-id-card text-gray-400 mr-1"></i> <?php echo htmlspecialchars($shipment_data['driver_name'] ?? 'N/A'); ?></span></div>
                            <div class="sm:col-span-2 md:col-span-3 lg:col-span-2 space-y-1">
                                <span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Route</span>
                                <div class="flex items-center gap-2 text-gray-900 font-medium bg-gray-50 px-3 py-1 rounded-md border border-gray-100 inline-flex">
                                    <span class="text-indigo-600"><?php echo htmlspecialchars($shipment_data['origin']); ?></span>
                                    <i class="fas fa-arrow-right text-xs text-gray-400"></i>
                                    <span class="text-indigo-600"><?php echo htmlspecialchars($shipment_data['destination']); ?></span>
                                </div>
                            </div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Consignor</span> <span class="text-gray-900 font-medium truncate block"><?php echo htmlspecialchars($shipment_data['consignor_name']); ?></span></div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Consignee</span> <span class="text-gray-900 font-medium truncate block"><?php echo htmlspecialchars($shipment_data['consignee_name']); ?></span></div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Net Weight</span> <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($shipment_data['net_weight'] . ' ' . $shipment_data['net_weight_unit']); ?></span></div>
                            <div class="space-y-1"><span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Chargeable Wt</span> <span class="text-gray-900 font-bold text-indigo-600"><?php echo htmlspecialchars($shipment_data['chargeable_weight'] . ' ' . $shipment_data['chargeable_weight_unit']); ?></span></div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                        <div class="bg-gray-50/50 px-6 py-4 border-b border-gray-200">
                             <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-edit mr-2 text-gray-500"></i>Enter Payment Details</h2>
                        </div>
                        
                        <form method="POST" id="paymentForm" class="p-6 md:p-8 space-y-8">
                            <input type="hidden" name="shipment_id" value="<?php echo $shipment_data['id']; ?>">
                            
                            <div class="relative pl-4 md:pl-0 ai-target-section">
                                <div class="absolute left-0 top-0 bottom-0 w-1 bg-indigo-500 rounded-r md:hidden"></div>
                                <h3 class="text-base font-semibold text-indigo-900 mb-4 flex items-center gap-2">
                                    <span class="bg-indigo-100 text-indigo-700 w-8 h-8 rounded-full flex items-center justify-center text-sm">1</span> Party Billing
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="group">
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Billing Method</label>
                                        <select id="party_billing_method" name="party_billing_method" class="block w-full pl-3 pr-10 py-2.5 text-base border-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 rounded-lg shadow-sm bg-gray-50 hover:bg-white" required>
                                            <option value="Fixed" <?php if(($payment_data['Billing Rate']['billing_method'] ?? '') == 'Fixed') echo 'selected'; ?>>Fixed Amount</option>
                                            <option value="Kg" <?php if(($payment_data['Billing Rate']['billing_method'] ?? '') == 'Kg') echo 'selected'; ?>>Per Kg</option>
                                            <option value="Quintal" <?php if(($payment_data['Billing Rate']['billing_method'] ?? '') == 'Quintal') echo 'selected'; ?>>Per Quintal</option>
                                            <option value="Ton" <?php if(($payment_data['Billing Rate']['billing_method'] ?? '') == 'Ton') echo 'selected'; ?>>Per Ton</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Rate</label>
                                        <input type="number" step="0.01" id="party_rate" name="party_rate" value="<?php echo htmlspecialchars($payment_data['Billing Rate']['rate'] ?? ''); ?>" class="pl-3 block w-full py-2.5 border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm transition-all duration-500" placeholder="0.00" required>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Total Billing</label>
                                        <input type="number" step="0.01" id="party_total_billing" name="party_total_billing" value="<?php echo htmlspecialchars($payment_data['Billing Rate']['amount'] ?? ''); ?>" class="pl-3 block w-full py-2.5 border-gray-200 bg-indigo-50 text-indigo-700 font-bold rounded-lg cursor-not-allowed" readonly tabindex="-1">
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="border-gray-100">

                            <div class="relative pl-4 md:pl-0 ai-target-section">
                                <div class="absolute left-0 top-0 bottom-0 w-1 bg-blue-500 rounded-r md:hidden"></div>
                                <h3 class="text-base font-semibold text-blue-900 mb-4 flex items-center gap-2">
                                    <span class="bg-blue-100 text-blue-700 w-8 h-8 rounded-full flex items-center justify-center text-sm">2</span> Vehicle Hire
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Billing Method</label>
                                        <select id="vehicle_billing_method" name="vehicle_billing_method" class="block w-full py-2.5 border-gray-300 focus:ring-blue-500 focus:border-blue-500 rounded-lg shadow-sm bg-gray-50 hover:bg-white" required>
                                            <option value="Fixed" <?php if(($payment_data['Lorry Hire']['billing_method'] ?? '') == 'Fixed') echo 'selected'; ?>>Fixed Amount</option>
                                            <option value="Kg" <?php if(($payment_data['Lorry Hire']['billing_method'] ?? '') == 'Kg') echo 'selected'; ?>>Per Kg</option>
                                            <option value="Quintal" <?php if(($payment_data['Lorry Hire']['billing_method'] ?? '') == 'Quintal') echo 'selected'; ?>>Per Quintal</option>
                                            <option value="Ton" <?php if(($payment_data['Lorry Hire']['billing_method'] ?? '') == 'Ton') echo 'selected'; ?>>Per Ton</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Rate</label>
                                        <input type="number" step="0.01" id="vehicle_rate" name="vehicle_rate" value="<?php echo htmlspecialchars($payment_data['Lorry Hire']['rate'] ?? ''); ?>" class="pl-3 block w-full py-2.5 border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 shadow-sm transition-all duration-500" placeholder="0.00" required>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase tracking-wide">Total Lorry Hire</label>
                                        <input type="number" step="0.01" id="vehicle_total_hire" name="vehicle_total_hire" value="<?php echo htmlspecialchars($payment_data['Lorry Hire']['amount'] ?? ''); ?>" class="pl-3 block w-full py-2.5 border-gray-200 bg-blue-50 text-blue-700 font-bold rounded-lg cursor-not-allowed" readonly tabindex="-1">
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="border-gray-100">

                            <div class="relative pl-4 md:pl-0">
                                <div class="absolute left-0 top-0 bottom-0 w-1 bg-gray-500 rounded-r md:hidden"></div>
                                <h3 class="text-base font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <span class="bg-gray-100 text-gray-600 w-8 h-8 rounded-full flex items-center justify-center text-sm">3</span> Charges & Advances
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                                    <div class="space-y-1">
                                        <label class="text-xs text-gray-500 uppercase tracking-wide">Adv. Cash</label>
                                        <input type="number" step="0.01" id="advance_cash" name="advance_cash" value="<?php echo htmlspecialchars($payment_data['Advance Cash']['amount'] ?? ''); ?>" class="balance-calc w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm transition-all duration-500" placeholder="0">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs text-gray-500 uppercase tracking-wide">Adv. Diesel</label>
                                        <input type="number" step="0.01" name="advance_diesel" value="<?php echo htmlspecialchars($payment_data['Advance Diesel']['amount'] ?? ''); ?>" class="balance-calc w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="0">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs text-gray-500 uppercase tracking-wide">Labour</label>
                                        <input type="number" step="0.01" name="labour_charge" value="<?php echo htmlspecialchars($payment_data['Labour Charge']['amount'] ?? ''); ?>" class="balance-calc w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="0">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs text-gray-500 uppercase tracking-wide">Dala</label>
                                        <input type="number" step="0.01" name="dala_charge" value="<?php echo htmlspecialchars($payment_data['Dala Charge']['amount'] ?? ''); ?>" class="balance-calc w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="0">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs text-gray-500 uppercase tracking-wide">Lifting</label>
                                        <input type="number" step="0.01" name="lifting_charge" value="<?php echo htmlspecialchars($payment_data['Lifting Charge']['amount'] ?? ''); ?>" class="balance-calc w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="0">
                                    </div>
                                </div>
                                
                                <div class="mt-8 flex justify-end">
                                    <div class="bg-gray-900 rounded-lg p-4 w-full md:w-80 shadow-lg text-white">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="text-gray-400 text-sm">Net Balance Payable</span>
                                            <i class="fas fa-wallet text-gray-500"></i>
                                        </div>
                                        <div class="relative">
                                            <span class="absolute left-0 top-1/2 -translate-y-1/2 text-gray-400 text-xl font-light">₹</span>
                                            <input type="number" step="0.01" id="balance_amount" class="w-full bg-transparent border-none text-right text-3xl font-bold text-white focus:ring-0 p-0" readonly value="0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-6 border-t border-gray-100 flex flex-col sm:flex-row justify-end gap-3">
                                <a href="manage_payments.php" class="px-6 py-3 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition shadow-sm text-center">Cancel</a>
                                <button type="submit" id="save-btn" class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white font-bold rounded-lg hover:from-indigo-700 hover:to-blue-700 shadow-lg hover:shadow-xl transition transform hover:-translate-y-0.5 text-center">
                                    <i class="fas fa-save mr-2"></i> Save Payment Details (Alt+S)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="space-y-10 max-w-7xl mx-auto">
                    <?php 
                    $sections = [
                        ['title' => 'Re-verification Required', 'icon' => 'fa-exclamation-circle', 'theme' => 'amber', 'list_data' => $reverify_list, 'search_key' => 'search_reverify', 'search_val' => $search_reverify, 'page_key' => 'page_reverify', 'action_text' => 'Verify Now', 'header_gradient' => 'from-amber-500 to-orange-500'],
                        ['title' => 'Pending Entries', 'icon' => 'fa-clock', 'theme' => 'blue', 'list_data' => $pending_list, 'search_key' => 'search_pending', 'search_val' => $search_pending, 'page_key' => 'page_pending', 'action_text' => 'Add Payment', 'header_gradient' => 'from-blue-600 to-indigo-600'],
                        ['title' => 'Completed Payments', 'icon' => 'fa-check-circle', 'theme' => 'emerald', 'list_data' => $done_list, 'search_key' => 'search_done', 'search_val' => $search_done, 'page_key' => 'page_done', 'action_text' => 'View Details', 'header_gradient' => 'from-emerald-600 to-teal-600']
                    ];
                    foreach ($sections as $sec): $theme = $sec['theme']; ?>
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                        <div class="bg-gradient-to-r <?php echo $sec['header_gradient']; ?> px-6 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
                            <h2 class="text-lg font-bold text-white flex items-center gap-2"><i class="fas <?php echo $sec['icon']; ?> opacity-90"></i> <?php echo $sec['title']; ?></h2>
                            <form method="GET" class="relative w-full md:w-auto">
                                <?php foreach($_GET as $key => $val): if(!in_array($key, [$sec['search_key'], $sec['page_key'], 'action', 'id'])): ?><input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"><?php endif; endforeach; ?>
                                <div class="relative"><input type="text" name="<?php echo $sec['search_key']; ?>" value="<?php echo htmlspecialchars($sec['search_val']); ?>" placeholder="Search by LR No..." class="w-full md:w-64 pl-10 pr-4 py-2 rounded-full border-none focus:ring-2 focus:ring-white/50 bg-white/20 text-white placeholder-white/70 text-sm backdrop-blur-sm"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-white/70"></i></div><button type="submit" class="absolute right-1 top-1 bottom-1 bg-white text-<?php echo $theme; ?>-600 px-3 rounded-full text-xs font-bold hover:bg-gray-100 transition shadow-sm">Go</button></div>
                            </form>
                        </div>
                        <div class="overflow-x-auto w-full"><table class="min-w-full divide-y divide-gray-100"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">LR Number</th><th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th><th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Route</th><th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th></tr></thead><tbody class="bg-white divide-y divide-gray-100"><?php foreach ($sec['list_data']['data'] as $row): ?><tr class="hover:bg-<?php echo $theme; ?>-50 transition-colors group"><td class="px-6 py-4 whitespace-nowrap"><span class="text-sm font-bold text-gray-800 group-hover:text-<?php echo $theme; ?>-700 transition-colors"><?php echo htmlspecialchars($row['consignment_no']); ?></span></td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><i class="far fa-calendar-alt mr-1 text-gray-400"></i><?php echo date("d M, Y", strtotime($row['consignment_date'])); ?></td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><div class="flex items-center gap-2"><span><?php echo htmlspecialchars($row['origin']); ?></span><i class="fas fa-long-arrow-alt-right text-gray-300"></i><span><?php echo htmlspecialchars($row['destination']); ?></span></div></td><td class="px-6 py-4 whitespace-nowrap text-right text-sm"><a href="?action=edit&id=<?php echo $row['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-<?php echo $theme; ?>-700 bg-<?php echo $theme; ?>-100 hover:bg-<?php echo $theme; ?>-200 transition-colors"><?php echo $sec['action_text']; ?> <i class="fas fa-chevron-right ml-1"></i></a></td></tr><?php endforeach; ?></tbody></table></div>
                        <?php if($sec['list_data']['total_pages'] > 1): ?><div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-end gap-1"><?php $total_p = $sec['list_data']['total_pages']; $current_p = $sec['list_data']['current_page']; for ($i = max(1, $current_p - 2); $i <= min($total_p, $current_p + 2); $i++): $params = $_GET; unset($params['action'], $params['id']); $params[$sec['page_key']] = $i; ?><a href="?<?php echo http_build_query($params); ?>" class="<?php echo ($i == $current_p) ? "bg-{$theme}-600 text-white border-{$theme}-600 shadow-md transform scale-105" : "bg-white text-gray-600 border-gray-200 hover:bg-gray-100 hover:text-{$theme}-600"; ?> px-3 py-1 border rounded-md text-xs font-bold transition-all duration-200"><?php echo $i; ?></a><?php endfor; ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>
    
    <?php if($edit_mode): ?>
    <button type="button" id="btn-auto-fill" onclick="triggerAutoFill(<?php echo $shipment_data['id']; ?>)" class="fixed bottom-8 right-8 z-50 flex items-center justify-center w-16 h-16 bg-gradient-to-r from-purple-600 to-indigo-600 rounded-full shadow-2xl hover:scale-110 transition-transform duration-200 group focus:outline-none focus:ring-4 focus:ring-indigo-300">
        <i class="fas fa-magic text-yellow-300 text-2xl group-hover:animate-pulse"></i>
        <span class="absolute right-20 bg-gray-900 text-white text-xs px-3 py-1.5 rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-300 shadow-lg">Auto-Fill Rates (Alt+A)</span>
    </button>
    <?php endif; ?>

    <div id="ai-toast" class="fixed top-24 right-5 bg-indigo-900 text-white px-6 py-3 rounded-lg shadow-xl border border-indigo-500/50 flex items-center gap-3 transform -translate-y-40 opacity-0 transition-all duration-500 z-50">
        <div class="bg-indigo-700 p-2 rounded-full"><i class="fas fa-robot text-yellow-300"></i></div>
        <div><h4 class="font-bold text-sm">Prediction Success</h4><p class="text-xs text-indigo-200">Historical rates applied.</p></div>
    </div>

    <script>
    // --- HOTKEYS HANDLING ---
    document.addEventListener('keydown', function(event) {
        if (event.altKey && event.key === 's') { event.preventDefault(); const saveBtn = document.getElementById('save-btn'); if (saveBtn) saveBtn.click(); }
        if (event.altKey && event.key === 'a') { event.preventDefault(); const autoBtn = document.getElementById('btn-auto-fill'); if (autoBtn) autoBtn.click(); }
        if (event.altKey && event.key === 'n') { event.preventDefault(); const nextBtn = document.getElementById('next-btn'); if (nextBtn) nextBtn.click(); }
    });

    // --- AUTO FOCUS ON LOAD ---
    window.addEventListener('load', function() {
        const hasMessage = document.getElementById('system-message');
        const partyRateInput = document.getElementById('party_rate');
        if (partyRateInput && !hasMessage) { partyRateInput.focus(); }
    });

    function triggerAutoFill(shipmentId) {
        const btn = document.getElementById('btn-auto-fill');
        const icon = btn.querySelector('i');
        const originalIcon = icon.className;
        icon.className = 'fas fa-circle-notch fa-spin text-white text-2xl';
        btn.classList.add('opacity-75', 'cursor-not-allowed');

        fetch(`manage_payments.php?action=predict_rates&shipment_id=${shipmentId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.party) {
                        const method = document.getElementById('party_billing_method');
                        const rate = document.getElementById('party_rate');
                        Array.from(method.options).forEach(opt => { if (opt.value === data.party.billing_method) opt.selected = true; });
                        rate.value = parseFloat(data.party.rate).toFixed(2);
                        method.dispatchEvent(new Event('change'));
                        rate.classList.add('bg-green-50', 'text-green-700', 'border-green-300'); setTimeout(() => rate.classList.remove('bg-green-50', 'text-green-700', 'border-green-300'), 1500);
                    }
                    if (data.vehicle) {
                        const method = document.getElementById('vehicle_billing_method');
                        const rate = document.getElementById('vehicle_rate');
                        Array.from(method.options).forEach(opt => { if (opt.value === data.vehicle.billing_method) opt.selected = true; });
                        rate.value = parseFloat(data.vehicle.rate).toFixed(2);
                        method.dispatchEvent(new Event('change'));
                        rate.classList.add('bg-blue-50', 'text-blue-700', 'border-blue-300'); setTimeout(() => rate.classList.remove('bg-blue-50', 'text-blue-700', 'border-blue-300'), 1500);
                    }
                    if (data.advance_cash) {
                        const advCash = document.getElementById('advance_cash');
                        if (advCash) {
                            advCash.value = parseFloat(data.advance_cash).toFixed(2);
                            advCash.dispatchEvent(new Event('input'));
                            advCash.classList.add('bg-yellow-50', 'text-yellow-700', 'border-yellow-300');
                            setTimeout(() => advCash.classList.remove('bg-yellow-50', 'text-yellow-700', 'border-yellow-300'), 1500);
                        }
                    }

                    const toast = document.getElementById('ai-toast');
                    toast.classList.remove('-translate-y-40', 'opacity-0');
                    setTimeout(() => toast.classList.add('-translate-y-40', 'opacity-0'), 3000);
                } else { alert('AI Analysis: ' + data.message); }
            })
            .catch(err => { console.error(err); alert('Could not connect to Prediction Engine.'); })
            .finally(() => { icon.className = originalIcon; btn.classList.remove('opacity-75', 'cursor-not-allowed'); });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarClose = document.getElementById('close-sidebar-btn');

        function toggleSidebar() {
            if (sidebarWrapper.classList.contains('hidden')) { sidebarWrapper.classList.remove('hidden'); sidebarWrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0'); sidebarOverlay.classList.remove('hidden'); } 
            else { sidebarWrapper.classList.add('hidden'); sidebarWrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0'); sidebarOverlay.classList.add('hidden'); }
        }
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);

        const summaryDetails = document.getElementById('summary-details');
        if (summaryDetails) {
            const weightVal = parseFloat(summaryDetails.dataset.weightVal);
            const weightUnit = summaryDetails.dataset.weightUnit;
            
            let chargeableWeightKg = 0;
            const u = weightUnit.toLowerCase();
            if(u === 'ton' || u === 'tons' || u === 'mt') chargeableWeightKg = weightVal * 1000;
            else if(u === 'quintal' || u === 'quintals') chargeableWeightKg = weightVal * 100;
            else if(u === 'kg' || u === 'kgs') chargeableWeightKg = weightVal;
            else chargeableWeightKg = 0; 

            const partyMethod = document.getElementById('party_billing_method');
            const partyRate = document.getElementById('party_rate');
            const partyTotal = document.getElementById('party_total_billing');
            const vehicleMethod = document.getElementById('vehicle_billing_method');
            const vehicleRate = document.getElementById('vehicle_rate');
            const vehicleTotal = document.getElementById('vehicle_total_hire');
            const balanceInputs = document.querySelectorAll('.balance-calc');
            const balanceField = document.getElementById('balance_amount');

            function calculateTotal(method, rate) {
                rate = parseFloat(rate) || 0;
                if(method === 'Fixed') return rate;
                if(method === 'Kg') return rate * chargeableWeightKg;
                if(method === 'Quintal') return rate * (chargeableWeightKg / 100);
                if(method === 'Ton') return rate * (chargeableWeightKg / 1000);
                return rate; 
            }

            function updateParty() { partyTotal.value = calculateTotal(partyMethod.value, partyRate.value).toFixed(2); }
            function updateVehicle() { vehicleTotal.value = calculateTotal(vehicleMethod.value, vehicleRate.value).toFixed(2); calculateBalance(); }
            function calculateBalance() {
                const hire = parseFloat(vehicleTotal.value) || 0;
                let deductions = 0;
                balanceInputs.forEach(input => deductions += (parseFloat(input.value) || 0));
                balanceField.value = (hire - deductions).toFixed(2);
            }

            partyMethod.addEventListener('change', updateParty);
            partyRate.addEventListener('input', updateParty);
            vehicleMethod.addEventListener('change', updateVehicle);
            vehicleRate.addEventListener('input', updateVehicle);
            balanceInputs.forEach(input => input.addEventListener('input', calculateBalance));
            updateParty(); updateVehicle();
        }
    });

    window.onload = () => { const loader = document.getElementById('loader'); if(loader) { loader.style.opacity = '0'; setTimeout(() => loader.style.display = 'none', 300); } };
    </script>
</body>
</html>