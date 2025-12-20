<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_manage = in_array($user_role, ['admin', 'manager']);

if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}

// Fetch Company Details
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();

$form_message = "";
$settle_mode = false;
$view_details_mode = false;
$shipment_data = [];
$payment_summary = [];
$balance_amount = 0;
$lorry_hire = 0;
$total_advances = 0;

// Handle Form Submission for Settling Payment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['shipment_id'])) {
    $shipment_id = intval($_POST['shipment_id']);
    $payment_date = $_POST['payment_date'];
    $payment_mode = $_POST['payment_mode'];
    $transaction_ref = trim($_POST['remarks']); // Remarks field now used for ref
    $amount_paid = (float)$_POST['balance_amount'];
    $damage_deduction = (float)($_POST['damage_deduction'] ?? 0);
    $shortage_deduction = (float)($_POST['shortage_deduction'] ?? 0);
    $created_by_id = $_SESSION['id'];

    $mysqli->begin_transaction();
    try {
        // Insert deduction records if they exist
        $deduction_sql = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, payment_date, created_by_id) VALUES (?, ?, ?, ?, ?)";
        $deduction_stmt = $mysqli->prepare($deduction_sql);
        if ($damage_deduction > 0) {
            $type = 'Damage Deduction';
            $deduction_stmt->bind_param("isdsi", $shipment_id, $type, $damage_deduction, $payment_date, $created_by_id);
            if (!$deduction_stmt->execute()) { throw new Exception("Error saving damage deduction."); }
        }
        if ($shortage_deduction > 0) {
            $type = 'Shortage Deduction';
            $deduction_stmt->bind_param("isdsi", $shipment_id, $type, $shortage_deduction, $payment_date, $created_by_id);
            if (!$deduction_stmt->execute()) { throw new Exception("Error saving shortage deduction."); }
        }
        $deduction_stmt->close();

        // Insert the final settlement record
        $sql_payment = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, payment_date, remarks, created_by_id) VALUES (?, 'Balance Payment', ?, ?, ?, ?)";
        $stmt_payment = $mysqli->prepare($sql_payment);
        $stmt_payment->bind_param("idssi", $shipment_id, $amount_paid, $payment_date, $transaction_ref, $created_by_id);
        if (!$stmt_payment->execute()) { throw new Exception("Error saving final payment record."); }
        $stmt_payment->close();

        // Update the vehicle payment status in the shipments table
        $sql_update = "UPDATE shipments SET vehicle_payment_status = 'Paid' WHERE id = ?";
        $stmt_update = $mysqli->prepare($sql_update);
        $stmt_update->bind_param("i", $shipment_id);
        if (!$stmt_update->execute()) { throw new Exception("Error updating shipment status."); }
        $stmt_update->close();

        $mysqli->commit();
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Vehicle payment settled successfully!</div>';

    } catch (Exception $e) {
        $mysqli->rollback();
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $e->getMessage() . '</div>';
    }
}


// Handle GET request for viewing or settling
if (isset($_GET['action']) && isset($_GET['id'])) {
    $shipment_id = intval($_GET['id']);
    $sql = "";
    
    if ($_GET['action'] == 'settle') {
        $settle_mode = true;
        $sql = "SELECT s.id, s.consignment_no, s.pod_doc_path, v.vehicle_number, b.name as broker_name 
                FROM shipments s 
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                LEFT JOIN brokers b ON s.broker_id = b.id
                WHERE s.id = ? AND s.status = 'Completed' AND s.vehicle_payment_status = 'Pending'";
    } elseif ($_GET['action'] == 'view_details') {
        $view_details_mode = true;
         $sql = "SELECT s.id, s.consignment_no, s.pod_doc_path, s.pod_remarks, v.vehicle_number, b.name as broker_name 
                FROM shipments s 
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                LEFT JOIN brokers b ON s.broker_id = b.id
                WHERE s.id = ? AND s.vehicle_payment_status = 'Paid'";
    }

    if (!empty($sql)) {
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("i", $shipment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows == 1) {
                $shipment_data = $result->fetch_assoc();
                
                // Fetch and calculate payment summary for both modes
                $payment_result = $mysqli->query("SELECT payment_type, amount, payment_date, remarks FROM shipment_payments WHERE shipment_id = $shipment_id");
                while($row = $payment_result->fetch_assoc()){
                    $payment_summary[$row['payment_type']] = $row;
                }
                
                $lorry_hire = (float)($payment_summary['Lorry Hire']['amount'] ?? 0);
                $total_advances = (float)($payment_summary['Advance Cash']['amount'] ?? 0) + (float)($payment_summary['Advance Diesel']['amount'] ?? 0) + (float)($payment_summary['Labour Charge']['amount'] ?? 0) + (float)($payment_summary['Dala Charge']['amount'] ?? 0) + (float)($payment_summary['Lifting Charge']['amount'] ?? 0);
                $balance_amount = $lorry_hire - $total_advances;

            } else {
                $settle_mode = false;
                $view_details_mode = false;
                $form_message = '<div class="p-4 mb-6 text-sm text-yellow-700 bg-yellow-100 border-l-4 border-yellow-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-triangle mr-2"></i> Shipment not found or status has changed.</div>';
            }
        }
    }
}

// --- Pagination & Data Fetching for Lists ---
$records_per_page = 10;

// Pending Settlements Pagination
$page_pending = isset($_GET['page_pending']) && is_numeric($_GET['page_pending']) ? (int)$_GET['page_pending'] : 1;
$offset_pending = ($page_pending - 1) * $records_per_page;
$total_pending_res = $mysqli->query("SELECT COUNT(*) FROM shipments WHERE status = 'Completed' AND vehicle_payment_status = 'Pending'");
$total_pending = $total_pending_res->fetch_row()[0];
$total_pages_pending = ceil($total_pending / $records_per_page);

$pending_settlements = [];
if (!$settle_mode && !$view_details_mode) {
    $sql_pending = "SELECT s.id, s.consignment_no, v.vehicle_number, b.name as broker_name
            FROM shipments s
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            LEFT JOIN brokers b ON s.broker_id = b.id
            WHERE s.status = 'Completed' AND s.vehicle_payment_status = 'Pending'
            ORDER BY s.consignment_date DESC LIMIT ?, ?";
    if ($stmt = $mysqli->prepare($sql_pending)) {
        $stmt->bind_param("ii", $offset_pending, $records_per_page);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pending_settlements[] = $row;
        }
        $stmt->close();
    }
}

// Settled Payments Pagination
$page_settled = isset($_GET['page_settled']) && is_numeric($_GET['page_settled']) ? (int)$_GET['page_settled'] : 1;
$offset_settled = ($page_settled - 1) * $records_per_page;
$total_settled_res = $mysqli->query("SELECT COUNT(*) FROM shipments WHERE vehicle_payment_status = 'Paid'");
$total_settled = $total_settled_res->fetch_row()[0];
$total_pages_settled = ceil($total_settled / $records_per_page);

$settled_payments = [];
if (!$settle_mode && !$view_details_mode) {
    $sql_settled = "SELECT s.id, s.consignment_no, v.vehicle_number, b.name as broker_name
            FROM shipments s
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            LEFT JOIN brokers b ON s.broker_id = b.id
            WHERE s.vehicle_payment_status = 'Paid'
            ORDER BY s.consignment_date DESC LIMIT ?, ?";
    if ($stmt = $mysqli->prepare($sql_settled)) {
        $stmt->bind_param("ii", $offset_settled, $records_per_page);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $settled_payments[] = $row;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Settlements - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        @media print {
            body * { visibility: hidden; }
            .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 1rem; }
            .no-print { display: none; }
        }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-gray-50 h-screen w-screen overflow-hidden">
    
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
        <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
    </div>

    <div class="flex h-full w-full bg-gray-50">
        
        <div class="md:hidden fixed inset-0 z-40 hidden transition-opacity duration-300 bg-gray-900 bg-opacity-50" id="sidebar-overlay"></div>
        
        <div id="sidebar-wrapper" class="z-50 md:relative md:z-0 md:block hidden h-full shadow-xl">
             <?php include 'sidebar.php'; ?>
        </div>

        <div class="flex flex-col flex-1 h-full overflow-hidden relative">
            <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden"></div>
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-file-invoice-dollar opacity-80"></i> Vehicle Settlements
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

            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6">
                <?php if(!empty($form_message)) echo $form_message; ?>

                <?php if ($settle_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-wallet text-indigo-500"></i> Settle Vehicle Payment
                        </h2>
                        <span class="bg-indigo-100 text-indigo-700 text-xs font-bold px-3 py-1 rounded-full border border-indigo-200">
                            LR No: <?php echo htmlspecialchars($shipment_data['consignment_no']); ?>
                        </span>
                    </div>
                    
                    <div class="p-6 md:p-8">
                        <div class="bg-blue-50/50 rounded-xl p-6 border border-blue-100 mb-8">
                            <h3 class="text-sm font-bold text-blue-800 uppercase tracking-wide mb-4 border-b border-blue-200 pb-2">Payment Breakdown</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-sm">
                                <div>
                                    <span class="block text-xs font-semibold text-gray-500 uppercase">Lorry Hire</span>
                                    <span class="text-lg font-bold text-gray-900">₹<?php echo number_format($lorry_hire, 2); ?></span>
                                </div>
                                <div>
                                    <span class="block text-xs font-semibold text-gray-500 uppercase">Total Advances</span>
                                    <span class="text-lg font-bold text-red-600">- ₹<?php echo number_format($total_advances, 2); ?></span>
                                </div>
                                <div>
                                    <span class="block text-xs font-semibold text-gray-500 uppercase">Current Balance</span>
                                    <span class="text-lg font-bold text-indigo-600">₹<?php echo number_format($balance_amount, 2); ?></span>
                                </div>
                                 <div class="flex items-end">
                                     <?php if(!empty($shipment_data['pod_doc_path'])): ?>
                                     <a href="<?php echo htmlspecialchars($shipment_data['pod_doc_path']); ?>" target="_blank" class="inline-flex items-center text-sm font-bold text-blue-600 hover:text-blue-800 hover:underline">
                                         <i class="fas fa-file-pdf mr-2"></i> View POD Doc
                                     </a>
                                     <?php else: ?>
                                     <span class="text-gray-400 italic text-sm">No POD Uploaded</span>
                                     <?php endif; ?>
                                 </div>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="shipment_id" value="<?php echo $shipment_data['id']; ?>">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Damage Deduction</label>
                                    <input type="number" step="0.01" name="damage_deduction" id="damage_deduction" class="balance-calc block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="0.00">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Shortage Deduction</label>
                                    <input type="number" step="0.01" name="shortage_deduction" id="shortage_deduction" class="balance-calc block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="0.00">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Final Balance to Pay</label>
                                    <input type="number" step="0.01" id="balance_amount" name="balance_amount" value="<?php echo $balance_amount; ?>" class="block w-full px-3 py-2 border border-gray-200 bg-gray-100 rounded-lg text-indigo-700 font-bold shadow-sm cursor-not-allowed" readonly>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Payment Date</label>
                                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Payment Mode</label>
                                    <select name="payment_mode" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition bg-white">
                                        <option>Bank Transfer</option><option>Cash</option><option>Cheque</option><option>UPI</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Transaction Ref / Remarks</label>
                                    <input type="text" name="remarks" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition" placeholder="Optional">
                                </div>
                            </div>
                            
                            <div class="mt-8 flex justify-end gap-3 pt-6 border-t border-gray-100">
                                <a href="vehicle_settlements.php" class="px-6 py-2.5 border border-gray-300 rounded-lg shadow-sm text-sm font-bold text-gray-700 bg-white hover:bg-gray-50 transition">Cancel</a>
                                <button type="submit" class="px-8 py-2.5 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700 shadow-md transition transform hover:-translate-y-0.5">
                                    <i class="fas fa-check-circle mr-2"></i> Mark as Paid
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php elseif ($view_details_mode): ?>
                
                <div class="bg-white p-8 md:p-12 rounded-xl shadow-md max-w-4xl mx-auto print-area" id="settlement-details">
                    <div class="flex justify-between items-start border-b-2 border-gray-800 pb-6 mb-8">
                        <div>
                            <?php if(!empty($company_details['logo_path'])): ?>
                                <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Company Logo" class="h-16 object-contain mb-2">
                            <?php endif; ?>
                            <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($company_details['name'] ?? 'TMS'); ?></h1>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($company_details['address'] ?? ''); ?></p>
                        </div>
                        <div class="text-right">
                            <h2 class="text-xl font-bold text-indigo-900 uppercase tracking-wider">Settlement Receipt</h2>
                            <p class="text-sm text-gray-500 mt-1">Date: <?php echo date("d M, Y"); ?></p>
                        </div>
                    </div>

                    <div class="no-print flex justify-between items-center mb-8 bg-gray-50 p-3 rounded-lg border border-gray-200">
                        <span class="text-sm font-bold text-gray-600 uppercase">Actions:</span>
                        <div class="flex gap-2">
                             <button onclick="window.print()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg shadow-sm hover:bg-gray-50 text-sm font-medium transition"><i class="fas fa-print mr-2"></i> Print</button>
                             <button id="download-pdf-btn" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg shadow-sm hover:bg-gray-50 text-sm font-medium transition"><i class="fas fa-file-pdf mr-2"></i> Download PDF</button>
                             <a href="vehicle_settlements.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg shadow-sm hover:bg-indigo-700 text-sm font-medium transition"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6 mb-8 text-sm">
                         <div class="p-4 bg-gray-50 rounded-lg border border-gray-100">
                             <strong class="block text-gray-500 text-xs uppercase mb-1">Consignment Details</strong>
                             <div class="text-gray-800"><span class="font-bold">LR No:</span> <?php echo htmlspecialchars($shipment_data['consignment_no']); ?></div>
                             <div class="text-gray-800"><span class="font-bold">Vehicle:</span> <?php echo htmlspecialchars($shipment_data['vehicle_number']); ?></div>
                             <div class="text-gray-800"><span class="font-bold">Broker:</span> <?php echo htmlspecialchars($shipment_data['broker_name'] ?? 'N/A'); ?></div>
                         </div>
                         <div class="p-4 bg-gray-50 rounded-lg border border-gray-100">
                             <strong class="block text-gray-500 text-xs uppercase mb-1">POD Info</strong>
                             <div class="text-gray-800 mb-1"><span class="font-bold">Status:</span> <span class="text-green-600 font-bold">Settled</span></div>
                             <div class="text-gray-800"><span class="font-bold">Remarks:</span> <?php echo htmlspecialchars($shipment_data['pod_remarks'] ?? 'None'); ?></div>
                         </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="font-bold text-gray-800 uppercase tracking-wide mb-4">Payment Calculation</h3>
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-bold text-gray-600 uppercase border-b border-gray-200">Description</th>
                                        <th class="px-4 py-2 text-right font-bold text-gray-600 uppercase border-b border-gray-200">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr><td class="px-4 py-2 text-gray-700">Lorry Hire (Agreed)</td><td class="px-4 py-2 text-right font-medium text-gray-900"><?php echo number_format($payment_summary['Lorry Hire']['amount'] ?? 0, 2); ?></td></tr>
                                    <tr><td class="px-4 py-2 text-gray-700">Less: Advance Cash</td><td class="px-4 py-2 text-right text-red-600">-<?php echo number_format($payment_summary['Advance Cash']['amount'] ?? 0, 2); ?></td></tr>
                                    <tr><td class="px-4 py-2 text-gray-700">Less: Advance Diesel</td><td class="px-4 py-2 text-right text-red-600">-<?php echo number_format($payment_summary['Advance Diesel']['amount'] ?? 0, 2); ?></td></tr>
                                    <tr><td class="px-4 py-2 text-gray-700">Less: Other Charges (Labour/Dala)</td><td class="px-4 py-2 text-right text-red-600">-<?php echo number_format(($payment_summary['Labour Charge']['amount'] ?? 0) + ($payment_summary['Dala Charge']['amount'] ?? 0), 2); ?></td></tr>
                                    <?php if(!empty($payment_summary['Damage Deduction']['amount'])): ?>
                                    <tr><td class="px-4 py-2 text-gray-700">Less: Damage Deduction</td><td class="px-4 py-2 text-right text-red-600">-<?php echo number_format($payment_summary['Damage Deduction']['amount'], 2); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if(!empty($payment_summary['Shortage Deduction']['amount'])): ?>
                                    <tr><td class="px-4 py-2 text-gray-700">Less: Shortage Deduction</td><td class="px-4 py-2 text-right text-red-600">-<?php echo number_format($payment_summary['Shortage Deduction']['amount'], 2); ?></td></tr>
                                    <?php endif; ?>
                                    <tr class="bg-gray-50">
                                        <td class="px-4 py-3 font-bold text-gray-800 text-base">Net Amount Paid</td>
                                        <td class="px-4 py-3 text-right font-bold text-indigo-700 text-lg">₹<?php echo number_format($payment_summary['Balance Payment']['amount'] ?? 0, 2); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-6 text-sm text-gray-600 grid grid-cols-2 gap-4">
                            <div><span class="font-bold">Payment Date:</span> <?php echo date("d-m-Y", strtotime($payment_summary['Balance Payment']['payment_date'] ?? '')); ?></div>
                            <div><span class="font-bold">Reference:</span> <?php echo htmlspecialchars($payment_summary['Balance Payment']['remarks'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-amber-50/50 px-6 py-4 border-b border-amber-100 flex items-center justify-between">
                            <h2 class="text-lg font-bold text-amber-900 flex items-center gap-2">
                                <i class="fas fa-clock text-amber-500"></i> Pending Settlements
                            </h2>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">LR No.</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Vehicle</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    <?php foreach ($pending_settlements as $shipment): ?>
                                    <tr class="hover:bg-amber-50/30 transition">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-800"><?php echo htmlspecialchars($shipment['consignment_no']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($shipment['vehicle_number']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="vehicle_settlements.php?action=settle&id=<?php echo $shipment['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-bold rounded-md text-amber-700 bg-amber-100 hover:bg-amber-200 transition">
                                                Settle <i class="fas fa-arrow-right ml-1"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($pending_settlements)): ?><tr><td colspan="3" class="text-center py-8 text-gray-400 italic">No pending settlements.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if($total_pages_pending > 1): ?>
                        <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-1">
                            <?php for ($i = 1; $i <= $total_pages_pending; $i++): ?>
                                <a href="?page_pending=<?php echo $i; ?>" class="px-3 py-1 text-xs font-bold rounded-md border <?php echo $i == $page_pending ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-green-50/50 px-6 py-4 border-b border-green-100 flex items-center justify-between">
                            <h2 class="text-lg font-bold text-green-900 flex items-center gap-2">
                                <i class="fas fa-check-circle text-green-500"></i> Completed Settlements
                            </h2>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">LR No.</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Vehicle</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    <?php foreach ($settled_payments as $shipment): ?>
                                    <tr class="hover:bg-green-50/30 transition">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-800"><?php echo htmlspecialchars($shipment['consignment_no']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($shipment['vehicle_number']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="vehicle_settlements.php?action=view_details&id=<?php echo $shipment['id']; ?>" class="text-gray-400 hover:text-indigo-600 transition" title="View Receipt">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($settled_payments)): ?><tr><td colspan="3" class="text-center py-8 text-gray-400 italic">No history found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if($total_pages_settled > 1): ?>
                        <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-1">
                            <?php for ($i = 1; $i <= $total_pages_settled; $i++): ?>
                                <a href="?page_settled=<?php echo $i; ?>" class="px-3 py-1 text-xs font-bold rounded-md border <?php echo $i == $page_settled ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            // --- Sidebar Toggle Logic ---
            const sidebarWrapper = document.getElementById('sidebar-wrapper');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebarClose = document.getElementById('close-sidebar-btn');

            function toggleSidebar() {
                if (sidebarWrapper.classList.contains('hidden')) {
                    // Open Sidebar
                    sidebarWrapper.classList.remove('hidden');
                    sidebarWrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
                    sidebarOverlay.classList.remove('hidden');
                } else {
                    // Close Sidebar
                    sidebarWrapper.classList.add('hidden');
                    sidebarWrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
                    sidebarOverlay.classList.add('hidden');
                }
            }

            if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
            if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
            if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }

            // Loader
            const loader = document.getElementById('loader');
            if (loader) { loader.style.display = 'none'; }

            // Balance Calculation
            if (document.getElementById('balance_amount')) {
                const initialBalance = parseFloat(document.getElementById('balance_amount').value) || 0;
                const balanceInputs = document.querySelectorAll('.balance-calc');
                const balanceAmountField = document.getElementById('balance_amount');

                function calculateBalance() {
                    let totalDeductions = 0;
                    balanceInputs.forEach(input => {
                        totalDeductions += parseFloat(input.value) || 0;
                    });
                    const finalBalance = initialBalance - totalDeductions;
                    balanceAmountField.value = finalBalance.toFixed(2);
                }

                balanceInputs.forEach(input => {
                    input.addEventListener('keyup', calculateBalance);
                    input.addEventListener('change', calculateBalance);
                });
            }

            // PDF Generation
            if (document.getElementById('download-pdf-btn')) {
                document.getElementById('download-pdf-btn').addEventListener('click', function () {
                    const element = document.getElementById('settlement-details');
                    const lrNumber = "<?php echo htmlspecialchars($shipment_data['consignment_no'] ?? 'settlement'); ?>";
                    
                    html2canvas(element, { scale: 2 }).then(canvas => {
                        const imgData = canvas.toDataURL('image/png');
                        const { jsPDF } = window.jspdf;
                        
                        const pdf = new jsPDF('p', 'mm', 'a4');
                        const pdfWidth = pdf.internal.pageSize.getWidth();
                        const pdfHeight = pdf.internal.pageSize.getHeight();
                        
                        const canvasWidth = canvas.width;
                        const canvasHeight = canvas.height;
                        const canvasAspectRatio = canvasWidth / canvasHeight;
                        
                        let imgWidth = pdfWidth - 20;
                        let imgHeight = imgWidth / canvasAspectRatio;

                        if (imgHeight > pdfHeight - 20) {
                            imgHeight = pdfHeight - 20;
                            imgWidth = imgHeight * canvasAspectRatio;
                        }
                        
                        const x = (pdfWidth - imgWidth) / 2;
                        const y = 10;

                        pdf.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);
                        pdf.save(`Settlement-${lrNumber}.pdf`);
                    });
                });
            }
        });
    </script>
</body>
</html>