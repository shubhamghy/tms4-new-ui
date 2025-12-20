<?php
session_start();
require_once "config.php";

// --- CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// --- POST: Add New Charge ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_charge'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $shipment_id_post = intval($_POST['shipment_id']);
    $payment_type = trim($_POST['payment_type']);
    $amount = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $created_by_id = $_SESSION['id'];

    $sql_insert = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, payment_date, created_by_id) VALUES (?, ?, ?, ?, ?)";
    if ($stmt_insert = $mysqli->prepare($sql_insert)) {
        $stmt_insert->bind_param("isdsi", $shipment_id_post, $payment_type, $amount, $payment_date, $created_by_id);
        if ($stmt_insert->execute()) {
            header("Location: view_shipment_details.php?id=" . $shipment_id_post . "&status=charge_added&tab=payments");
            exit;
        } else {
            echo "Error: " . $stmt_insert->error;
        }
        $stmt_insert->close();
    }
}

// --- POST: Add New Note ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_note'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $shipment_id_post = intval($_POST['shipment_id']);
    $note_content = trim($_POST['note_content']);
    $created_by_id = $_SESSION['id'];

    // Placeholder for Note Logic
    header("Location: view_shipment_details.php?id=" . $shipment_id_post . "&status=note_todo&tab=notes");
    exit;
}


if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("location: view_bookings.php");
    exit;
}

$shipment_id = intval($_GET['id']);
$default_tab = htmlspecialchars($_GET['tab'] ?? 'summary');

// Fetch main shipment data
$sql = "SELECT s.*, 
               p_consignor.name as consignor_name, p_consignor.address as consignor_address, p_consignor.gst_no as consignor_gst,
               p_consignee.name as consignee_name, p_consignee.address as consignee_address, p_consignee.gst_no as consignee_gst,
               b.name as broker_name,
               v.vehicle_number,
               d.name as driver_name, d.license_number as driver_license, d.contact_number as driver_contact,
               cd.description as consignment_description,
               u.username as created_by_user
        FROM shipments s
        LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
        LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
        LEFT JOIN brokers b ON s.broker_id = b.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN drivers d ON s.driver_id = d.id
        LEFT JOIN consignment_descriptions cd ON s.description_id = cd.id
        LEFT JOIN users u ON s.created_by_id = u.id
        WHERE s.id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$result = $stmt->get_result();
$shipment = $result->fetch_assoc();
$stmt->close();

if (!$shipment) {
    echo "Shipment not found.";
    exit;
}

// Fetch invoices
$invoices = [];
$invoice_sql = "SELECT * FROM shipment_invoices WHERE shipment_id = ?";
if ($invoice_stmt = $mysqli->prepare($invoice_sql)) {
    $invoice_stmt->bind_param("i", $shipment_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    $invoices = $invoice_result->fetch_all(MYSQLI_ASSOC);
    $invoice_stmt->close();
}

// Fetch tracking history
$tracking_history = [];
$tracking_sql = "SELECT th.*, u.username as updated_by_user FROM shipment_tracking th LEFT JOIN users u ON th.updated_by_id = u.id WHERE th.shipment_id = ? ORDER BY th.created_at ASC";
if ($tracking_stmt = $mysqli->prepare($tracking_sql)) {
    $tracking_stmt->bind_param("i", $shipment_id);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    $tracking_history = $tracking_result->fetch_all(MYSQLI_ASSOC);
    $tracking_stmt->close();
}

// --- SINGLE PAYMENT QUERY ---
$payments = [];
$other_charges = [];
$all_payments_from_db = [];

if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $payment_sql = "SELECT * FROM shipment_payments WHERE shipment_id = ?";
    if ($payment_stmt = $mysqli->prepare($payment_sql)) {
        $payment_stmt->bind_param("i", $shipment_id);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        $all_payments_from_db = $payment_result->fetch_all(MYSQLI_ASSOC);
        $payment_stmt->close();
    }
}

$main_summary_types = ['Billing Rate', 'Lorry Hire', 'Advance Cash', 'Advance Bank', 'Advance Diesel', 'Balance Payment', 'Damage Deduction', 'Shortage Deduction'];
foreach ($all_payments_from_db as $row) {
    $payments[$row['payment_type']] = $row;
    if (!in_array($row['payment_type'], $main_summary_types)) {
        $other_charges[] = $row;
    }
}
// --- END PAYMENT LOGIC ---

// --- PAYMENT CALCULATION ---
$lorry_hire = 0; $total_deductions = 0; $balance_amount = 0;
if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $lorry_hire = $payments['Lorry Hire']['amount'] ?? 0;
    $advance_cash = $payments['Advance Cash']['amount'] ?? 0;
    $advance_bank = $payments['Advance Bank']['amount'] ?? 0;
    $advance_diesel = $payments['Advance Diesel']['amount'] ?? 0;
    $balance_paid = $payments['Balance Payment']['amount'] ?? 0;
    $damage_deduction = $payments['Damage Deduction']['amount'] ?? 0;
    $shortage_deduction = $payments['Shortage Deduction']['amount'] ?? 0;
    
    $deduction_types = ['Advance Cash', 'Advance Bank', 'Advance Diesel', 'Labour Charge', 'Dala Charge', 'Lifting Charge'];
    foreach($deduction_types as $deduction_type) {
        $total_deductions += $payments[$deduction_type]['amount'] ?? 0;
    }
    $balance_amount = $lorry_hire - $total_deductions;
}
// --- END CALCULATION ---

// --- LEDGER DATA ---
$ledger_entries = [];
$ledger_sql = "SELECT *, payment_date as 'date', payment_type as 'description', amount, 'payment' as 'type' 
               FROM shipment_payments 
               WHERE shipment_id = ?
               ORDER BY payment_date ASC, created_at ASC";
if ($ledger_stmt = $mysqli->prepare($ledger_sql)) {
    $ledger_stmt->bind_param("i", $shipment_id);
    $ledger_stmt->execute();
    $ledger_result = $ledger_stmt->get_result();
    $ledger_entries = $ledger_result->fetch_all(MYSQLI_ASSOC);
    $ledger_stmt->close();
}

// --- NOTES DATA ---
$notes = [];

// Function to get status badge colors
function getStatusBadge($status) {
    $colors = [
        'Booked' => 'bg-blue-100 text-blue-800 border-blue-200', 
        'Billed' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'Pending Payment' => 'bg-amber-100 text-amber-800 border-amber-200', 
        'Reverify' => 'bg-orange-100 text-orange-800 border-orange-200',
        'In Transit' => 'bg-cyan-100 text-cyan-800 border-cyan-200', 
        'Reached' => 'bg-teal-100 text-teal-800 border-teal-200',
        'Delivered' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 
        'Completed' => 'bg-gray-100 text-gray-800 border-gray-200',
    ];
    return $colors[$status] ?? 'bg-gray-100 text-gray-800 border-gray-200';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment Details - <?php echo htmlspecialchars($shipment['consignment_no']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            body * { visibility: hidden; }
            #print-area, #print-area * { visibility: visible; }
            #print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
            .no-print { display: none !important; }
            /* Expand width on print */
            #print-area .lg\:grid-cols-3 { display: block; }
            #print-area .lg\:col-span-2 { width: 100%; margin-bottom: 20px; }
        }
        /* Style for active tab */
        .tab-button.active {
            color: #4f46e5;
            background-color: #eef2ff;
            border-bottom: 2px solid #4f46e5;
        }
    </style>
</head>
<body class="bg-gray-50 h-screen w-screen overflow-hidden">

<div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
    <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
</div>

<div class="flex h-full w-full bg-gray-50">
    
    <div class="flex-shrink-0 z-50">
         <?php include 'sidebar.php'; ?>
    </div>

    <div class="flex flex-col flex-1 h-full overflow-hidden relative">
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden"></div>
        
        <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white no-print">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                            <i class="fas fa-box-open opacity-80"></i> Shipment Details
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 space-y-6" id="print-area">
            
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="text-2xl font-bold text-gray-800">CN: <?php echo htmlspecialchars($shipment['consignment_no']); ?></h2>
                        <span class="px-3 py-1 text-xs font-bold rounded-full border uppercase tracking-wide <?php echo getStatusBadge($shipment['status']); ?>">
                            <?php echo htmlspecialchars($shipment['status']); ?>
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">
                        Booked on <span class="font-medium text-gray-700"><?php echo date("d M, Y", strtotime($shipment['consignment_date'])); ?></span> 
                        by <span class="font-medium text-gray-700"><?php echo htmlspecialchars($shipment['created_by_user']); ?></span>
                    </p>
                </div>
                <div class="flex gap-2 no-print">
                    <a href="view_bookings.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
                        <i class="fas fa-arrow-left mr-2"></i> Back
                    </a>
                    <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition">
                        <i class="fas fa-print mr-2"></i> Print
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 space-y-6" x-data="{ tab: '<?php echo $default_tab; ?>' }">
                    
                    <div class="bg-white p-1 rounded-xl shadow-sm border border-gray-100 no-print">
                        <nav class="flex space-x-1" aria-label="Tabs">
                            <button @click="tab = 'summary'" :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': tab === 'summary', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': tab !== 'summary' }" class="flex-1 py-2 px-3 rounded-lg text-sm font-bold transition-all">Summary</button>
                            <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                                <button @click="tab = 'payments'" :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': tab === 'payments', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': tab !== 'payments' }" class="flex-1 py-2 px-3 rounded-lg text-sm font-bold transition-all">Payments</button>
                                <button @click="tab = 'ledger'" :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': tab === 'ledger', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': tab !== 'ledger' }" class="flex-1 py-2 px-3 rounded-lg text-sm font-bold transition-all">Ledger</button>
                            <?php endif; ?>
                            <button @click="tab = 'notes'" :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': tab === 'notes', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': tab !== 'notes' }" class="flex-1 py-2 px-3 rounded-lg text-sm font-bold transition-all">Notes</button>
                        </nav>
                    </div>

                    <div x-show="tab === 'summary'" x-transition.opacity class="space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-gray-800 flex items-center"><i class="fas fa-users mr-3 text-indigo-500"></i> Party Details</h3>
                                <a href="booking.php?action=edit&id=<?php echo $shipment_id; ?>" class="no-print text-xs font-bold py-1.5 px-3 bg-white border border-gray-200 text-gray-600 rounded-lg hover:bg-indigo-50 hover:text-indigo-600 transition shadow-sm">
                                    <i class="fas fa-pencil-alt mr-1"></i> Edit
                                </a>
                            </div>
                            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="relative pl-4 border-l-4 border-indigo-200">
                                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Consignor</h4>
                                    <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($shipment['consignor_name']); ?></p>
                                    <p class="text-sm text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($shipment['consignor_address'])); ?></p>
                                    <p class="text-xs text-indigo-600 mt-2 font-medium">GST: <?php echo htmlspecialchars($shipment['consignor_gst'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="relative pl-4 border-l-4 border-blue-200">
                                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Consignee</h4>
                                    <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($shipment['consignee_name']); ?></p>
                                    <p class="text-sm text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($shipment['consignee_address'])); ?></p>
                                    <p class="text-xs text-blue-600 mt-2 font-medium">GST: <?php echo htmlspecialchars($shipment['consignee_gst'] ?? 'N/A'); ?></p>
                                </div>
                                <?php if($shipment['is_shipping_different']): ?>
                                <div class="md:col-span-2 bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                    <h4 class="text-xs font-bold text-yellow-700 uppercase tracking-wide mb-1"><i class="fas fa-map-marker-alt mr-1"></i> Shipping Address</h4>
                                    <p class="text-gray-900 font-bold"><?php echo htmlspecialchars($shipment['shipping_name']); ?></p>
                                    <p class="text-sm text-gray-600"><?php echo nl2br(htmlspecialchars($shipment['shipping_address'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="bg-gray-50 px-6 py-4 border-b border-gray-100">
                                <h3 class="text-lg font-bold text-gray-800 flex items-center"><i class="fas fa-file-invoice mr-3 text-indigo-500"></i> Linked Invoices</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-white"><tr><th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Invoice No.</th><th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Date</th><th class="py-3 px-6 text-right font-bold text-gray-500 uppercase">Amount</th><th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">E-Way Bill</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php if (empty($invoices)): ?>
                                            <tr><td colspan="4" class="py-6 px-6 text-gray-400 text-center italic">No invoice details linked.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($invoices as $invoice): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="py-3 px-6 font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_no']); ?></td>
                                                <td class="py-3 px-6 text-gray-600"><?php echo date("d-m-Y", strtotime($invoice['invoice_date'])); ?></td>
                                                <td class="py-3 px-6 text-right font-bold text-gray-800">₹<?php echo number_format($invoice['invoice_amount'], 2); ?></td>
                                                <td class="py-3 px-6 text-gray-600 font-mono"><?php echo htmlspecialchars($invoice['eway_bill_no'] ?? '-'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                    <div x-show="tab === 'payments'" x-transition.opacity class="space-y-6">
                        <div class="bg-gradient-to-br from-indigo-50 to-white p-6 rounded-xl shadow-sm border border-indigo-100">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-indigo-900 flex items-center"><i class="fas fa-coins mr-2"></i> Financial Snapshot</h3>
                                <a href="manage_payments.php?shipment_id=<?php echo $shipment_id; ?>" class="no-print text-xs font-bold py-1.5 px-3 bg-white border border-indigo-200 text-indigo-600 rounded-lg hover:bg-indigo-600 hover:text-white transition shadow-sm">
                                    Manage All Payments
                                </a>
                            </div>
                            <?php if (!empty($payments)): ?>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                                <div class="bg-white p-3 rounded-lg border border-gray-100 shadow-sm">
                                    <span class="block text-xs font-bold text-gray-400 uppercase">Party Rate</span>
                                    <span class="text-lg font-bold text-indigo-600">₹<?php echo number_format($payments['Billing Rate']['amount'] ?? 0, 2); ?></span>
                                </div>
                                <div class="bg-white p-3 rounded-lg border border-gray-100 shadow-sm">
                                    <span class="block text-xs font-bold text-gray-400 uppercase">Lorry Hire</span>
                                    <span class="text-lg font-bold text-gray-800">₹<?php echo number_format($lorry_hire, 2); ?></span>
                                </div>
                                <div class="bg-white p-3 rounded-lg border border-gray-100 shadow-sm">
                                    <span class="block text-xs font-bold text-gray-400 uppercase">Total Advances</span>
                                    <span class="text-lg font-bold text-orange-500">₹<?php echo number_format($total_deductions, 2); ?></span>
                                </div>
                                <div class="bg-indigo-600 p-3 rounded-lg border border-indigo-600 shadow-md text-white">
                                    <span class="block text-xs font-bold text-indigo-200 uppercase">Balance Paid</span>
                                    <span class="text-lg font-bold">₹<?php echo number_format($balance_paid, 2); ?></span>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4 text-gray-500 italic">No financial data available yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 no-print">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center"><i class="fas fa-plus-circle mr-2 text-green-500"></i> Add Miscellaneous Charge</h3>
                            <form method="POST" action="view_shipment_details.php?id=<?php echo $shipment_id; ?>&tab=payments">
                                <input type="hidden" name="shipment_id" value="<?php echo $shipment_id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                    <div>
                                        <label for="payment_type" class="block text-xs font-bold text-gray-500 uppercase mb-1">Charge Type</label>
                                        <select name="payment_type" id="payment_type" required class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                                            <option value="Detention Charge">Detention Charge</option>
                                            <option value="Loading Charge">Loading Charge</option>
                                            <option value="Unloading Charge">Unloading Charge</option>
                                            <option value="Labour Charge">Labour Charge</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="amount" class="block text-xs font-bold text-gray-500 uppercase mb-1">Amount (₹)</label>
                                        <input type="number" step="0.01" name="amount" id="amount" required class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label for="payment_date" class="block text-xs font-bold text-gray-500 uppercase mb-1">Date</label>
                                        <input type="date" name="payment_date" id="payment_date" value="<?php echo date('Y-m-d'); ?>" required class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                    <button type="submit" name="add_charge" class="w-full py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-green-600 hover:bg-green-700 transition">
                                        Add Charge
                                    </button>
                                </div>
                            </form>
                        </div>

                        <?php if (!empty($other_charges)): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="bg-gray-50 px-6 py-3 border-b border-gray-100 font-bold text-gray-700">Other Charges History</div>
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($other_charges as $charge): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="py-3 px-6 text-gray-800 font-medium"><?php echo htmlspecialchars($charge['payment_type']); ?></td>
                                            <td class="py-3 px-6 text-gray-500"><?php echo date("d M, Y", strtotime($charge['payment_date'])); ?></td>
                                            <td class="py-3 px-6 text-right font-bold text-gray-800">₹<?php echo number_format($charge['amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div x-show="tab === 'ledger'" x-transition.opacity>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="bg-gray-50 px-6 py-4 border-b border-gray-100">
                                <h3 class="text-lg font-bold text-gray-800 flex items-center"><i class="fas fa-book-open mr-3 text-indigo-500"></i> Shipment Ledger</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-white">
                                        <tr>
                                            <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Date</th>
                                            <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Description</th>
                                            <th class="py-3 px-6 text-right font-bold text-gray-500 uppercase">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php if (empty($ledger_entries)): ?>
                                            <tr><td colspan="3" class="py-8 text-center text-gray-400 italic">No ledger entries found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($ledger_entries as $entry): 
                                                $amount = $entry['amount'];
                                                $desc = $entry['description'];
                                                $color = 'text-gray-800';
                                                
                                                if ($desc == 'Lorry Hire') { $color = 'text-red-600 font-bold'; $amount = -$amount; }
                                                elseif (strpos($desc, 'Advance') !== false || strpos($desc, 'Payment') !== false) { $color = 'text-green-600 font-bold'; }
                                            ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="py-3 px-6 text-gray-600"><?php echo date("d-m-Y", strtotime($entry['date'])); ?></td>
                                                <td class="py-3 px-6 text-gray-800"><?php echo htmlspecialchars($desc); ?></td>
                                                <td class="py-3 px-6 text-right <?php echo $color; ?>"><?php echo number_format($amount, 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div x-show="tab === 'notes'" x-transition.opacity>
                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center"><i class="fas fa-sticky-note mr-2 text-yellow-500"></i> Internal Notes</h3>
                            
                            <form method="POST" action="view_shipment_details.php?id=<?php echo $shipment_id; ?>&tab=notes" class="mb-6 bg-yellow-50 p-4 rounded-lg border border-yellow-100 no-print">
                                <input type="hidden" name="shipment_id" value="<?php echo $shipment_id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <label for="note_content" class="block text-xs font-bold text-yellow-800 uppercase mb-2">New Note</label>
                                <textarea name="note_content" id="note_content" rows="3" required class="block w-full px-3 py-2 border border-yellow-300 rounded-lg shadow-sm focus:ring-yellow-500 focus:border-yellow-500 bg-white placeholder-yellow-300" placeholder="Type your note here..."></textarea>
                                <div class="mt-3 text-right">
                                    <button type="submit" name="add_note" class="py-2 px-4 bg-yellow-600 text-white text-sm font-bold rounded-lg hover:bg-yellow-700 shadow-sm transition">
                                        Save Note
                                    </button>
                                </div>
                            </form>
                            
                            <div class="space-y-4">
                                <?php if (empty($notes)): ?>
                                    <div class="text-center py-8 text-gray-400 italic bg-gray-50 rounded-lg border border-dashed border-gray-200">No notes added yet.</div>
                                <?php else: ?>
                                    <?php foreach ($notes as $note): ?>
                                    <div class="p-4 bg-white rounded-lg border border-gray-200 shadow-sm relative">
                                        <p class="text-gray-800 text-sm whitespace-pre-wrap"><?php echo htmlspecialchars($note['note_text']); ?></p>
                                        <div class="mt-2 pt-2 border-t border-gray-100 flex justify-between items-center text-xs text-gray-400">
                                            <span><i class="fas fa-user mr-1"></i> <?php echo htmlspecialchars($note['username']); ?></span>
                                            <span><?php echo date("d M, h:i A", strtotime($note['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (isset($_GET['status']) && $_GET['status'] == 'note_todo'): ?>
                                <div class="p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200">
                                    <strong>Dev Note:</strong> Notes functionality requires DB table creation.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                         <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-4">Route & Goods</h3>
                         
                         <div class="relative pl-4 border-l-2 border-indigo-200 mb-4">
                             <span class="text-xs text-gray-400 uppercase block">Origin</span>
                             <span class="text-base font-bold text-gray-900"><?php echo htmlspecialchars($shipment['origin']); ?></span>
                         </div>
                         <div class="relative pl-4 border-l-2 border-indigo-500 mb-6">
                             <span class="text-xs text-gray-400 uppercase block">Destination</span>
                             <span class="text-base font-bold text-gray-900"><?php echo htmlspecialchars($shipment['destination']); ?></span>
                         </div>
                         
                         <div class="bg-gray-50 p-3 rounded-lg border border-gray-200">
                             <p class="text-sm font-medium text-gray-800 mb-1"><?php echo htmlspecialchars($shipment['consignment_description']); ?></p>
                             <p class="text-xs text-gray-500 mb-2"><?php echo htmlspecialchars($shipment['quantity']); ?> <?php echo htmlspecialchars($shipment['package_type']); ?></p>
                             <div class="flex justify-between text-xs border-t pt-2 border-gray-200">
                                 <div><span class="text-gray-400">Net Wt:</span> <span class="font-bold text-gray-700"><?php echo htmlspecialchars($shipment['net_weight']); ?> <?php echo htmlspecialchars($shipment['net_weight_unit']); ?></span></div>
                                 <div><span class="text-gray-400">Chg Wt:</span> <span class="font-bold text-gray-700"><?php echo htmlspecialchars($shipment['chargeable_weight']); ?> <?php echo htmlspecialchars($shipment['chargeable_weight_unit']); ?></span></div>
                             </div>
                         </div>
                    </div>

                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                         <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-4">Transport</h3>
                         <div class="space-y-4">
                             <div class="flex justify-between items-center">
                                 <div>
                                     <p class="text-xs text-gray-400 uppercase">Vehicle</p>
                                     <p class="font-bold text-gray-800"><?php echo htmlspecialchars($shipment['vehicle_number']); ?></p>
                                 </div>
                                 <a href="manage_vehicles.php?action=details&id=<?php echo $shipment['vehicle_id']; ?>" class="text-indigo-600 hover:text-indigo-800 no-print"><i class="fas fa-external-link-alt"></i></a>
                             </div>
                             <div class="flex justify-between items-center">
                                 <div>
                                     <p class="text-xs text-gray-400 uppercase">Driver</p>
                                     <p class="font-bold text-gray-800"><?php echo htmlspecialchars($shipment['driver_name']); ?></p>
                                     <p class="text-xs text-gray-500"><?php echo htmlspecialchars($shipment['driver_contact']); ?></p>
                                 </div>
                                 <a href="manage_drivers.php?action=details&id=<?php echo $shipment['driver_id']; ?>" class="text-indigo-600 hover:text-indigo-800 no-print"><i class="fas fa-external-link-alt"></i></a>
                             </div>
                             <div>
                                 <p class="text-xs text-gray-400 uppercase">Broker</p>
                                 <p class="font-bold text-gray-800"><?php echo htmlspecialchars($shipment['broker_name'] ?? 'Direct / N/A'); ?></p>
                             </div>
                         </div>
                    </div>
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                         <div class="flex justify-between items-center border-b pb-2 mb-4">
                            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide">POD Status</h3>
                            <a href="manage_pod.php?shipment_id=<?php echo $shipment_id; ?>" class="no-print text-xs font-bold text-indigo-600 hover:underline">Manage</a>
                         </div>
                         <?php if ($shipment['pod_doc_path']): ?>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100 text-center">
                                <div class="inline-flex items-center justify-center w-10 h-10 bg-green-500 rounded-full text-white mb-2 shadow-sm">
                                    <i class="fas fa-check"></i>
                                </div>
                                <p class="text-sm font-bold text-green-800 mb-1">POD Received</p>
                                <p class="text-xs text-green-600 mb-3"><?php echo htmlspecialchars($shipment['pod_remarks']); ?></p>
                                <a href="<?php echo htmlspecialchars($shipment['pod_doc_path']); ?>" target="_blank" class="inline-block w-full py-2 bg-white border border-green-200 text-green-700 text-xs font-bold rounded hover:bg-green-50 transition">
                                    View Document
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                                <i class="fas fa-file-upload text-gray-300 text-2xl mb-2"></i>
                                <p class="text-xs text-gray-500">No POD uploaded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                         <div class="flex justify-between items-center border-b pb-2 mb-4">
                            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide">Timeline</h3>
                            <a href="update_tracking.php?shipment_id=<?php echo $shipment_id; ?>" class="no-print text-xs font-bold text-indigo-600 hover:underline">Update</a>
                         </div>
                         <div class="space-y-0 relative">
                            <div class="absolute left-2.5 top-2 bottom-2 w-0.5 bg-gray-200"></div>

                            <div class="relative pl-8 pb-6">
                                <div class="absolute left-0 top-1 w-5 h-5 bg-blue-500 rounded-full border-4 border-white shadow-sm z-10"></div>
                                <p class="text-sm font-bold text-gray-800">Booking Created</p>
                                <p class="text-xs text-gray-500"><?php echo date("d M Y", strtotime($shipment['consignment_date'])); ?></p>
                            </div>

                            <?php foreach ($tracking_history as $item): ?>
                            <div class="relative pl-8 pb-6">
                                <div class="absolute left-0 top-1 w-5 h-5 bg-indigo-500 rounded-full border-4 border-white shadow-sm z-10"></div>
                                <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($item['location']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo date("d M, h:i A", strtotime($item['created_at'])); ?></p>
                                <p class="text-xs text-gray-400 italic">By: <?php echo htmlspecialchars($item['updated_by_user']); ?></p>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($shipment['pod_doc_path']): ?>
                            <div class="relative pl-8">
                                <div class="absolute left-0 top-1 w-5 h-5 bg-green-500 rounded-full border-4 border-white shadow-sm z-10"></div>
                                <p class="text-sm font-bold text-green-700">Trip Completed</p>
                                <p class="text-xs text-green-600">POD Uploaded</p>
                            </div>
                            <?php endif; ?>
                         </div>
                    </div>
                </div>

            </div>
            
            <footer class="mt-8 text-center text-xs text-gray-400 no-print">
                &copy; <?php echo date('Y'); ?> TMS System. All rights reserved.
            </footer>
        </main>
    </div>
</div>

<script>
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) { loader.style.display = 'none'; }
    };

    // Mobile sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('close-sidebar-btn');

    function toggleSidebar() {
        if (sidebar && sidebarOverlay) {
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
        }
    }
    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
</script>
</body>
</html>