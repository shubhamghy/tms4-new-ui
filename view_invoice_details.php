<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_manage = in_array($user_role, ['admin', 'manager']);
$is_admin = ($user_role === 'admin');

if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id === 0) {
    die("Error: No invoice ID provided.");
}

$message = "";

// --- Handle Add Payment Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_payment'])) {
    $amount_received = $_POST['amount_received'];
    $tds_amount = $_POST['tds_amount'] ?? 0.00;
    $payment_date = $_POST['payment_date'];
    $payment_mode = $_POST['payment_mode'];
    $reference_no = $_POST['reference_no'];
    $remarks = $_POST['remarks'] ?? null;
    $received_by = $_SESSION['id'];

    if (!empty($amount_received) || !empty($tds_amount)) { // Allow 0 payment if TDS is present
        $mysqli->begin_transaction();
        try {
            // 1. Insert into invoice_payments
            $sql_pay = "INSERT INTO invoice_payments (invoice_id, payment_date, amount_received, tds_amount, payment_mode, reference_no, remarks, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_pay = $mysqli->prepare($sql_pay);
            $stmt_pay->bind_param("isddsssi", $invoice_id, $payment_date, $amount_received, $tds_amount, $payment_mode, $reference_no, $remarks, $received_by);
            $stmt_pay->execute();
            $stmt_pay->close();

            // 2. Update the invoice status (check against sum of amount_received AND tds_amount)
            $sql_total = "SELECT total_amount, (SELECT SUM(COALESCE(amount_received, 0)) + SUM(COALESCE(tds_amount, 0)) FROM invoice_payments WHERE invoice_id = ?) as total_paid FROM invoices WHERE id = ?";
            $stmt_total = $mysqli->prepare($sql_total);
            $stmt_total->bind_param("ii", $invoice_id, $invoice_id);
            $stmt_total->execute();
            $totals = $stmt_total->get_result()->fetch_assoc();
            $stmt_total->close();

            $total_amount = $totals['total_amount'];
            $total_paid = $totals['total_paid'] ?? 0;
            
            // Use round() for safe comparison of decimals
            $new_status = (round($total_paid, 2) >= round($total_amount, 2)) ? 'Paid' : 'Partially Paid';
            
            $sql_update = "UPDATE invoices SET status = ? WHERE id = ?";
            $stmt_update = $mysqli->prepare($sql_update);
            $stmt_update->bind_param("si", $new_status, $invoice_id);
            $stmt_update->execute();
            $stmt_update->close();

            $mysqli->commit();
            $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Payment added successfully.</div>";

        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error adding payment: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='p-4 mb-6 text-sm text-yellow-700 bg-yellow-100 border-l-4 border-yellow-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-triangle mr-2'></i> Please fill all required fields.</div>";
    }
}

// --- NEW: Handle Delete Payment Action ---
if (isset($_GET['action']) && $_GET['action'] === 'delete_payment' && isset($_GET['payment_id']) && $is_admin) {
    $payment_id_to_delete = intval($_GET['payment_id']);

    $mysqli->begin_transaction();
    try {
        // 1. Delete the payment
        $stmt_del_pay = $mysqli->prepare("DELETE FROM invoice_payments WHERE id = ? AND invoice_id = ?");
        $stmt_del_pay->bind_param("ii", $payment_id_to_delete, $invoice_id);
        $stmt_del_pay->execute();
        $stmt_del_pay->close();

        // 2. Recalculate and update the invoice status
        $sql_total = "SELECT total_amount, (SELECT SUM(COALESCE(amount_received, 0)) + SUM(COALESCE(tds_amount, 0)) FROM invoice_payments WHERE invoice_id = ?) as total_paid FROM invoices WHERE id = ?";
        $stmt_total = $mysqli->prepare($sql_total);
        $stmt_total->bind_param("ii", $invoice_id, $invoice_id);
        $stmt_total->execute();
        $totals = $stmt_total->get_result()->fetch_assoc();
        $stmt_total->close();

        $total_amount = $totals['total_amount'];
        $total_paid = $totals['total_paid'] ?? 0;

        // Determine new status (more robustly)
        $new_status = 'Unpaid'; // Default
        if (round($total_paid, 2) >= round($total_amount, 2)) {
            $new_status = 'Paid';
        } elseif ($total_paid > 0) {
            $new_status = 'Partially Paid';
        }
        
        $sql_update = "UPDATE invoices SET status = ? WHERE id = ?";
        $stmt_update = $mysqli->prepare($sql_update);
        $stmt_update->bind_param("si", $new_status, $invoice_id);
        $stmt_update->execute();
        $stmt_update->close();

        $mysqli->commit();
        $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Payment deleted successfully.</div>";

    } catch (Exception $e) {
        $mysqli->rollback();
        $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error deleting payment: " . $e->getMessage() . "</div>";
    }
}


// Handle Remove Item Action
if (isset($_GET['action']) && $_GET['action'] === 'remove_item' && isset($_GET['item_id']) && $is_admin) {
    $item_to_remove_id = intval($_GET['item_id']);
    
    $mysqli->begin_transaction();
    try {
        // Get amount to deduct
        $amount_sql = "SELECT amount FROM shipment_payments WHERE shipment_id = ? AND payment_type = 'Billing Rate'";
        $stmt_amount = $mysqli->prepare($amount_sql);
        $stmt_amount->bind_param("i", $item_to_remove_id);
        $stmt_amount->execute();
        $item_amount = $stmt_amount->get_result()->fetch_assoc()['amount'] ?? 0;
        $stmt_amount->close();

        // Delete from invoice_items
        $delete_sql = "DELETE FROM invoice_items WHERE invoice_id = ? AND shipment_id = ?";
        $stmt_delete = $mysqli->prepare($delete_sql);
        $stmt_delete->bind_param("ii", $invoice_id, $item_to_remove_id);
        $stmt_delete->execute();
        $stmt_delete->close();

        // Update invoice total
        $update_sql = "UPDATE invoices SET total_amount = total_amount - ? WHERE id = ?";
        $stmt_update = $mysqli->prepare($update_sql);
        $stmt_update->bind_param("di", $item_amount, $invoice_id);
        $stmt_update->execute();
        $stmt_update->close();

        $mysqli->commit();
        $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Shipment removed successfully.</div>";
    } catch (Exception $e) {
        $mysqli->rollback();
        $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error removing shipment: " . $e->getMessage() . "</div>";
    }
}


// Fetch Invoice Details
$sql_invoice = "SELECT i.*, p.name as consignor_name FROM invoices i JOIN parties p ON i.consignor_id = p.id WHERE i.id = ?";
$stmt_invoice = $mysqli->prepare($sql_invoice);
$stmt_invoice->bind_param("i", $invoice_id);
$stmt_invoice->execute();
$invoice = $stmt_invoice->get_result()->fetch_assoc();
$stmt_invoice->close();

if (!$invoice) {
    die("Error: Invoice not found.");
}

// Fetch Invoice Items (Shipments)
$sql_items = "SELECT s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, sp.amount 
              FROM invoice_items ii
              JOIN shipments s ON ii.shipment_id = s.id
              JOIN shipment_payments sp ON s.id = sp.shipment_id
              WHERE ii.invoice_id = ? AND sp.payment_type = 'Billing Rate'";
$stmt_items = $mysqli->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$invoice_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();

// Fetch Payment History
$sql_payments = "SELECT ip.*, u.username as received_by_user FROM invoice_payments ip JOIN users u ON ip.received_by = u.id WHERE ip.invoice_id = ? ORDER BY ip.payment_date DESC";
$stmt_payments = $mysqli->prepare($sql_payments);
$stmt_payments->bind_param("i", $invoice_id);
$stmt_payments->execute();
$payment_history = $stmt_payments->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_payments->close();

$total_paid = 0.00;
$total_tds = 0.00;
foreach ($payment_history as $payment) {
    $total_paid += $payment['amount_received'];
    $total_tds += $payment['tds_amount'];
}
$balance_due = $invoice['total_amount'] - ($total_paid + $total_tds);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Details - <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
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
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-file-invoice-dollar opacity-80"></i> Invoice Details
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
                <?php if(!empty($message)) echo $message; ?>
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div>
                            <h2 class="text-xl font-bold text-indigo-900"><?php echo htmlspecialchars($invoice['invoice_no']); ?></h2>
                            <p class="text-sm text-gray-500">Consignor: <?php echo htmlspecialchars($invoice['consignor_name']); ?></p>
                        </div>
                        <div class="flex gap-2">
                            <a href="print_invoice.php?id=<?php echo $invoice_id; ?>" target="_blank" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-bold text-gray-700 bg-white hover:bg-gray-50 transition">
                                <i class="fas fa-print mr-2"></i> Print
                            </a>
                            <a href="view_invoices.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">
                                <i class="fas fa-arrow-left mr-2"></i> Back
                            </a>
                        </div>
                    </div>
                    
                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                            <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide">Invoice Date</span>
                            <span class="text-lg font-semibold text-gray-800"><?php echo date("d M, Y", strtotime($invoice['invoice_date'])); ?></span>
                        </div>
                        <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                            <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide">Total Amount</span>
                            <span class="text-lg font-bold text-gray-900">₹<?php echo htmlspecialchars(number_format($invoice['total_amount'], 2)); ?></span>
                        </div>
                        <div class="p-4 rounded-lg bg-emerald-50 border border-emerald-200">
                            <span class="block text-xs font-bold text-emerald-600 uppercase tracking-wide">Paid + TDS</span>
                            <span class="text-lg font-bold text-emerald-700">₹<?php echo htmlspecialchars(number_format($total_paid + $total_tds, 2)); ?></span>
                        </div>
                        <div class="p-4 rounded-lg bg-red-50 border border-red-200">
                            <span class="block text-xs font-bold text-red-600 uppercase tracking-wide">Balance Due</span>
                            <span class="text-lg font-bold text-red-700">₹<?php echo htmlspecialchars(number_format($balance_due, 2)); ?></span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-truck text-indigo-500"></i> Included Shipments</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">LR No.</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Route</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Amount</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php foreach ($invoice_items as $item): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">
                                        <a href="view_shipment_details.php?id=<?php echo $item['id']; ?>" class="hover:underline"><?php echo htmlspecialchars($item['consignment_no']); ?></a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date("d-m-Y", strtotime($item['consignment_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($item['origin'] . ' → ' . $item['destination']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium">₹<?php echo htmlspecialchars(number_format($item['amount'], 2)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <?php if ($is_admin): ?>
                                        <a href="view_invoice_details.php?action=remove_item&id=<?php echo $invoice_id; ?>&item_id=<?php echo $item['id']; ?>" class="text-red-500 hover:text-red-700 transition" onclick="return confirm('Are you sure you want to remove this shipment?');" title="Remove">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($invoice_items)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-gray-500 italic">No shipments found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden h-fit">
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-plus-circle text-green-500"></i> Record Payment</h3>
                        </div>
                        <div class="p-6">
                            <form id="payment-form" method="POST" action="view_invoice_details.php?id=<?php echo $invoice_id; ?>" data-total-amount="<?php echo htmlspecialchars($invoice['total_amount']); ?>">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Received Amount</label>
                                        <div class="relative">
                                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500 font-bold">₹</span>
                                            <input type="number" step="0.01" name="amount_received" id="amount_received" class="pl-8 block w-full py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500" placeholder="0.00">
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">TDS %</label>
                                            <select id="tds_percent" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500 bg-white">
                                                <option value="0">0%</option>
                                                <option value="1">1%</option>
                                                <option value="2">2%</option>
                                                <option value="5">5%</option>
                                                <option value="10">10%</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">TDS Amount</label>
                                            <input type="number" step="0.01" name="tds_amount" id="tds_amount" value="0.00" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500">
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Payment Date</label>
                                        <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500" required>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Mode</label>
                                        <select name="payment_mode" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500 bg-white" required>
                                            <option>Bank Transfer</option><option>Cheque</option><option>Cash</option><option>UPI</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Reference No</label>
                                        <input type="text" name="reference_no" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500" placeholder="Cheque / Trans ID">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Remarks</label>
                                        <textarea name="remarks" rows="2" class="block w-full py-2 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500"></textarea>
                                    </div>
                                    
                                    <button type="submit" name="add_payment" class="w-full py-2.5 px-4 border border-transparent rounded-lg shadow-md text-sm font-bold text-white bg-green-600 hover:bg-green-700 transition transform hover:-translate-y-0.5">
                                        Add Payment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden h-fit">
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-history text-indigo-500"></i> Payment History</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Mode</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Reference</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Amount</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">TDS</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    <?php if (empty($payment_history)): ?>
                                        <tr><td colspan="6" class="text-center py-8 text-gray-500 italic">No payments recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($payment_history as $payment): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date("d-m-Y", strtotime($payment['payment_date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($payment['payment_mode']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono"><?php echo htmlspecialchars($payment['reference_no']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium">₹<?php echo htmlspecialchars(number_format($payment['amount_received'], 2)); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₹<?php echo htmlspecialchars(number_format($payment['tds_amount'], 2)); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <?php if ($is_admin): ?>
                                                    <a href="view_invoice_details.php?action=delete_payment&id=<?php echo $invoice_id; ?>&payment_id=<?php echo $payment['id']; ?>" class="text-red-500 hover:text-red-700 transition" onclick="return confirm('Are you sure you want to delete this payment?');" title="Delete">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <script>
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) { loader.style.display = 'none'; }
    };

    // Sidebar Logic
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

    // TDS Calculation Logic
    document.addEventListener('DOMContentLoaded', function() {
        const paymentForm = document.getElementById('payment-form');
        const tdsPercentSelect = document.getElementById('tds_percent');
        const tdsAmountInput = document.getElementById('tds_amount');

        // Get the total invoice amount from the form's data attribute
        const totalAmount = parseFloat(paymentForm.dataset.totalAmount) || 0;

        tdsPercentSelect.addEventListener('change', function() {
            const percent = parseFloat(this.value) || 0;
            if (percent > 0) {
                const tdsCalculated = (totalAmount * percent) / 100;
                tdsAmountInput.value = tdsCalculated.toFixed(2);
            } else {
                tdsAmountInput.value = '0.00';
            }
        });
    });
    </script>
</body>
</html>