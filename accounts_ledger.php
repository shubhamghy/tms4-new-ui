<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

session_start();
require_once "config.php";

// Access Control
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- Filter Handling ---
$entity_id = isset($_GET['entity_id']) ? intval($_GET['entity_id']) : 0;
$entity_type = isset($_GET['entity_type']) ? $_GET['entity_type'] : '';
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$entity_details = null;
$transactions = [];
$opening_balance = 0;
$closing_balance = 0;
$ledger_direction = 'party'; 

// Fetch dropdown data
$parties_list = $mysqli->query("SELECT id, name FROM parties ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$brokers_list = $mysqli->query("SELECT id, name FROM brokers ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$vehicles_list = $mysqli->query("SELECT id, vehicle_number as name FROM vehicles ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);

if ($entity_id > 0 && !empty($entity_type)) {

    if ($entity_type === 'party') {
        $ledger_direction = 'party';
        $stmt = $mysqli->prepare("SELECT id, name, credit_limit FROM parties WHERE id = ?");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $entity_details = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $ob_debit_sql = "SELECT COALESCE(SUM(total_amount), 0) as total_debits FROM invoices WHERE consignor_id = ? AND invoice_date < ?";
        $stmt_ob_d = $mysqli->prepare($ob_debit_sql);
        $stmt_ob_d->bind_param("is", $entity_id, $start_date);
        $stmt_ob_d->execute();
        $total_debits_before = $stmt_ob_d->get_result()->fetch_assoc()['total_debits'] ?? 0;
        $stmt_ob_d->close();

        $ob_credit_sql = "SELECT COALESCE(SUM(p.amount_received), 0) as total_credits FROM invoice_payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.consignor_id = ? AND p.payment_date < ?";
        $stmt_ob_c = $mysqli->prepare($ob_credit_sql);
        $stmt_ob_c->bind_param("is", $entity_id, $start_date);
        $stmt_ob_c->execute();
        $total_credits_before = $stmt_ob_c->get_result()->fetch_assoc()['total_credits'] ?? 0;
        $stmt_ob_c->close();
        
        $opening_balance = $total_debits_before - $total_credits_before;
        
        $sql = "(SELECT invoice_date AS date, CONCAT('Invoice No: ', invoice_no) as particulars, total_amount as debit, 0 as credit
                FROM invoices WHERE consignor_id = ? AND invoice_date BETWEEN ? AND ?)
                UNION ALL
                (SELECT p.payment_date AS date, CONCAT('Payment Received (', p.payment_mode, IF(p.reference_no IS NULL, '', CONCAT(' Ref: ', p.reference_no)), ')') as particulars, 0 as debit, p.amount_received as credit
                FROM invoice_payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.consignor_id = ? AND p.payment_date BETWEEN ? AND ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ississ", $entity_id, $start_date, $end_date, $entity_id, $start_date, $end_date);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

    } elseif ($entity_type === 'broker') {
        $ledger_direction = 'broker/vehicle';
        $stmt = $mysqli->prepare("SELECT id, name FROM brokers WHERE id = ?");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $entity_details = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $ob_credit_sql = "SELECT COALESCE(SUM(p.amount), 0) as total_credits FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.broker_id = ? AND p.payment_type = 'Lorry Hire' AND s.consignment_date < ?";
        $stmt_ob_c = $mysqli->prepare($ob_credit_sql);
        $stmt_ob_c->bind_param("is", $entity_id, $start_date);
        $stmt_ob_c->execute();
        $total_credits_before = $stmt_ob_c->get_result()->fetch_assoc()['total_credits'] ?? 0;
        $stmt_ob_c->close();

        $ob_debit_sql = "
            SELECT COALESCE(SUM(t.amount), 0) as total_debits FROM (
                SELECT amount FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.broker_id = ? AND p.payment_type IN ('Advance Cash', 'Advance Diesel', 'Labour Charge', 'Dala Charge', 'Damage Deduction', 'Shortage Deduction', 'Balance Payment') AND COALESCE(p.payment_date, s.consignment_date) < ?
                UNION ALL SELECT amount FROM expenses e JOIN shipments s ON e.shipment_id = s.id WHERE s.broker_id = ? AND e.expense_date < ?
            ) t";

        $stmt_ob_d = $mysqli->prepare($ob_debit_sql);
        $stmt_ob_d->bind_param("isis", $entity_id, $start_date, $entity_id, $start_date);
        $stmt_ob_d->execute();
        $total_debits_before = $stmt_ob_d->get_result()->fetch_assoc()['total_debits'] ?? 0;
        $stmt_ob_d->close();
        
        $opening_balance = $total_debits_before - $total_credits_before;
        
        $sql = "(SELECT s.consignment_date as date, CONCAT('Lorry Hire for CN: ', s.consignment_no) as particulars, 0 as debit, p.amount as credit
                FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.broker_id = ? AND p.payment_type = 'Lorry Hire' AND s.consignment_date BETWEEN ? AND ?)
                UNION ALL
                (SELECT COALESCE(p.payment_date, s.consignment_date) as date, CONCAT(p.payment_type, ' for CN: ', s.consignment_no) as particulars, p.amount as debit, 0 as credit
                FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.broker_id = ? AND p.payment_type IN ('Advance Cash', 'Advance Diesel', 'Labour Charge', 'Dala Charge', 'Damage Deduction', 'Shortage Deduction', 'Balance Payment') AND COALESCE(p.payment_date, s.consignment_date) BETWEEN ? AND ?)
                UNION ALL
                (SELECT e.expense_date as date, CONCAT('Expense Entry: ', e.category, ' for CN: ', s.consignment_no) as particulars, e.amount as debit, 0 as credit
                FROM expenses e JOIN shipments s ON e.shipment_id = s.id WHERE s.broker_id = ? AND e.expense_date BETWEEN ? AND ? AND e.shipment_id IS NOT NULL)";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issississ", 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date
        );
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();


    } elseif ($entity_type === 'vehicle') {
        $ledger_direction = 'broker/vehicle';
        $stmt = $mysqli->prepare("SELECT id, vehicle_number as name FROM vehicles WHERE id = ?");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $entity_details = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $ob_rev_sql = "SELECT COALESCE(SUM(p.amount), 0) as total_rev FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.vehicle_id = ? AND p.payment_type = 'Lorry Hire' AND s.consignment_date < ?";
        $stmt_ob_r = $mysqli->prepare($ob_rev_sql);
        $stmt_ob_r->bind_param("is", $entity_id, $start_date);
        $stmt_ob_r->execute();
        $total_rev_before = $stmt_ob_r->get_result()->fetch_assoc()['total_rev'] ?? 0;
        $stmt_ob_r->close();

        $ob_exp_sql = "
            SELECT COALESCE(SUM(t.amount), 0) as total_exp FROM (
                SELECT amount FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.vehicle_id = ? AND p.payment_type IN ('Advance Cash', 'Advance Diesel', 'Labour Charge', 'Dala Charge') AND COALESCE(p.payment_date, s.consignment_date) < ?
                UNION ALL SELECT total_cost as amount FROM fuel_logs WHERE vehicle_id = ? AND log_date < ?
                UNION ALL SELECT service_cost as amount FROM maintenance_logs WHERE vehicle_id = ? AND service_date < ?
                UNION ALL SELECT amount FROM expenses WHERE vehicle_id = ? AND expense_date < ?
            ) t";
        $stmt_ob_e = $mysqli->prepare($ob_exp_sql);
        $stmt_ob_e->bind_param("isississ", $entity_id, $start_date, $entity_id, $start_date, $entity_id, $start_date, $entity_id, $start_date);
        $stmt_ob_e->execute();
        $total_exp_before = $stmt_ob_e->get_result()->fetch_assoc()['total_exp'] ?? 0;
        $stmt_ob_e->close();

        $opening_balance = $total_rev_before - $total_exp_before;
        
        $sql = "
            (SELECT s.consignment_date as date, CONCAT('Lorry Hire Revenue for CN: ', s.consignment_no) as particulars, 0 as debit, p.amount as credit
            FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.vehicle_id = ? AND p.payment_type = 'Lorry Hire' AND s.consignment_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT COALESCE(p.payment_date, s.consignment_date) as date, CONCAT('Trip Advance/Expense: ', p.payment_type, ' for CN: ', s.consignment_no) as particulars, p.amount as debit, 0 as credit
            FROM shipment_payments p JOIN shipments s ON p.shipment_id = s.id WHERE s.vehicle_id = ? AND p.payment_type IN ('Advance Cash', 'Advance Diesel', 'Labour Charge', 'Dala Charge') AND COALESCE(p.payment_date, s.consignment_date) BETWEEN ? AND ?)
            UNION ALL
            (SELECT log_date as date, CONCAT('Fuel Expense at ', fuel_station) as particulars, total_cost as debit, 0 as credit
            FROM fuel_logs WHERE vehicle_id = ? AND log_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT service_date as date, CONCAT('Maintenance: ', service_type) as particulars, service_cost as debit, 0 as credit
            FROM maintenance_logs WHERE vehicle_id = ? AND service_date BETWEEN ? AND ?)
            UNION ALL
            (SELECT expense_date as date, CONCAT('General Expense: ', category, ' (', paid_to, ')') as particulars, amount as debit, 0 as credit
            FROM expenses WHERE vehicle_id = ? AND expense_date BETWEEN ? AND ?)
        ";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isssisssisssiss", 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date, 
            $entity_id, $start_date, $end_date
        );
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    // --- Post-processing ---
    if (abs($opening_balance) > 0.01 || $entity_type === 'vehicle') { 
        $ob_entry = [
            'date' => $start_date, 
            'particulars' => 'Opening Balance', 
            'debit' => ($ledger_direction === 'party' || $entity_type === 'broker') ? ($opening_balance >= 0 ? $opening_balance : 0) : ($opening_balance < 0 ? abs($opening_balance) : 0),
            'credit' => ($ledger_direction === 'party' || $entity_type === 'broker') ? ($opening_balance < 0 ? abs($opening_balance) : 0) : ($opening_balance >= 0 ? $opening_balance : 0),
            'balance' => $opening_balance
        ];
        array_unshift($transactions, $ob_entry);
    }
    
    if (!empty($transactions)) {
        usort($transactions, function($a, $b) { 
            if ($a['particulars'] === 'Opening Balance') return -1;
            if ($b['particulars'] === 'Opening Balance') return 1;
            $dateA = strtotime($a['date']); $dateB = strtotime($b['date']);
            if ($dateA == $dateB) { return 0; }
            return $dateA - $dateB;
        });

        $balance = $opening_balance;
        
        foreach ($transactions as $i => &$t) {
            if ($t['particulars'] === 'Opening Balance') {
                $t['balance'] = $opening_balance;
                continue;
            }

            if ($ledger_direction === 'party' || $entity_type === 'broker') {
                $balance = $balance + ($t['debit'] - $t['credit']);
            } elseif ($entity_type === 'vehicle') {
                $balance = $balance + ($t['credit'] - $t['debit']);
            }
            $t['balance'] = $balance;
        }
        unset($t);
        
        $closing_balance = $balance;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Ledger - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.5rem; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
        @media print { .no-print { display: none; } }
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
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white no-print">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-calculator opacity-80"></i> Accounts Ledger
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
                
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 no-print">
                    <form id="ledger-form" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
                        <div class="md:col-span-2">
                            <label for="entity_select" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Select Entity</label>
                            <select id="entity_select" class="searchable-select block w-full">
                                <option value="">-- Choose... --</option>
                                <optgroup label="Customers (Parties)">
                                    <?php foreach($parties_list as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" data-type="party" <?php if($entity_id == $item['id'] && $entity_type == 'party') echo 'selected'; ?>><?php echo htmlspecialchars($item['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Vendors (Brokers)">
                                    <?php foreach($brokers_list as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" data-type="broker" <?php if($entity_id == $item['id'] && $entity_type == 'broker') echo 'selected'; ?>><?php echo htmlspecialchars($item['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Assets (Vehicles)">
                                    <?php foreach($vehicles_list as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" data-type="vehicle" <?php if($entity_id == $item['id'] && $entity_type == 'vehicle') echo 'selected'; ?>><?php echo htmlspecialchars($item['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label for="start_date" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Start Date</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        </div>
                        <div>
                            <label for="end_date" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">End Date</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        </div>
                        <input type="hidden" name="entity_id" id="entity_id_hidden">
                        <input type="hidden" name="entity_type" id="entity_type_hidden">
                        <button type="submit" class="w-full md:col-span-4 inline-flex justify-center items-center py-2.5 px-6 border border-transparent rounded-lg shadow-md text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-search mr-2"></i> View Ledger
                        </button>
                    </form>
                </div>

                <?php if ($entity_id > 0 && $entity_details): ?>
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden print-area">
                    <div class="bg-gray-50 px-6 py-6 border-b border-gray-100 flex flex-col md:flex-row justify-between items-start gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($entity_details['name']); ?></h2>
                            <p class="text-sm text-gray-500 mt-1">Statement for <strong><?php echo date('d-M-Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($end_date)); ?></strong></p>
                            <?php if ($entity_type === 'party' && !empty($entity_details['credit_limit'])): ?>
                            <p class="text-xs text-indigo-600 mt-2 font-medium bg-indigo-50 inline-block px-2 py-1 rounded">Credit Limit: ₹<?php echo number_format($entity_details['credit_limit'], 2); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col items-end gap-3">
                            <div class="flex gap-2 no-print">
                                <button onclick="window.print()" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-bold rounded-lg shadow-sm hover:bg-gray-50 transition"><i class="fas fa-print mr-2"></i>Print</button>
                                <a href="download_ledger.php?entity_id=<?php echo $entity_id; ?>&entity_type=<?php echo $entity_type; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm font-bold rounded-lg shadow-sm hover:bg-green-100 transition"><i class="fas fa-download mr-2"></i>CSV</a>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">
                                    <?php echo ($entity_type === 'vehicle') ? 'Net Profit / Loss' : 'Closing Balance'; ?>
                                </p>
                                <p class="text-3xl font-bold <?php echo ($closing_balance >= 0 ? ($entity_type === 'vehicle' ? 'text-green-600' : 'text-red-600') : ($entity_type === 'vehicle' ? 'text-red-600' : 'text-green-600')); ?>">
                                    ₹<?php echo number_format(abs($closing_balance), 2); ?> 
                                    <span class="text-sm font-medium text-gray-500">
                                        <?php 
                                            if ($entity_type === 'vehicle') echo $closing_balance >= 0 ? '(Profit)' : '(Loss)';
                                            else echo $closing_balance >= 0 ? 'Dr' : 'Cr';
                                        ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-white">
                                <tr>
                                    <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Date</th>
                                    <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Particulars</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide"><?php echo ($entity_type === 'vehicle') ? 'Expense' : 'Debit'; ?></th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide"><?php echo ($entity_type === 'vehicle') ? 'Revenue' : 'Credit'; ?></th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="5" class="text-center py-10 text-gray-400 italic">No transactions found for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $t): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-3 text-gray-500 whitespace-nowrap"><?php echo $t['particulars'] === 'Opening Balance' ? '' : date('d-m-Y', strtotime($t['date'])); ?></td>
                                        <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($t['particulars']); ?></td>
                                        <td class="px-6 py-3 text-right font-medium text-red-600"><?php echo $t['debit'] > 0 ? '₹'.number_format($t['debit'], 2) : '-'; ?></td>
                                        <td class="px-6 py-3 text-right font-medium text-green-600"><?php echo $t['credit'] > 0 ? '₹'.number_format($t['credit'], 2) : '-'; ?></td>
                                        <td class="px-6 py-3 text-right font-bold text-gray-700">
                                            ₹<?php echo number_format(abs($t['balance']), 2); ?> 
                                            <span class="text-xs font-normal text-gray-400">
                                                <?php 
                                                    if ($entity_type === 'vehicle') echo $t['balance'] >= 0 ? 'P' : 'L';
                                                    else echo $t['balance'] >= 0 ? 'Dr' : 'Cr';
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>
    <script>
    $(document).ready(function() {
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

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

        // Select2 & Filter Logic
        $('.searchable-select').select2({ width: '100%' });

        $('#entity_select').on('change', function() {
            const selectedOption = $(this).find('option:selected');
            $('#entity_id_hidden').val(selectedOption.val());
            $('#entity_type_hidden').val(selectedOption.data('type'));
        });

        const selectedOption = $('#entity_select').find('option:selected');
        if (selectedOption.val()) {
            $('#entity_id_hidden').val(selectedOption.val());
            $('#entity_type_hidden').val(selectedOption.data('type'));
        }
    });

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>