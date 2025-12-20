<?php
// --- STEP 1: ADD THIS AT THE VERY TOP ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- Data Fetching & Calculation ---
$aging_data = [];
$totals = [
    'balance' => 0, 'current' => 0, '30_days' => 0, '60_days' => 0, '90_days' => 0, '90_plus_days' => 0
];

// MODIFIED: Select credit_limit from parties table
$sql = "
    SELECT 
        p.id as party_id,
        p.name as consignor_name,
        p.credit_limit, 
        i.id as invoice_id,
        i.invoice_no,
        i.invoice_date,
        i.total_amount,
        COALESCE((SELECT SUM(amount_received) FROM invoice_payments WHERE invoice_id = i.id), 0) as paid_amount,
        DATEDIFF(CURDATE(), i.invoice_date) as age_days
    FROM invoices i
    JOIN parties p ON i.consignor_id = p.id
    WHERE i.status IN ('Generated', 'Partially Paid', 'Unpaid') AND i.total_amount > COALESCE((SELECT SUM(amount_received) FROM invoice_payments WHERE invoice_id = i.id), 0)
    ORDER BY p.name, i.invoice_date ASC
";

$result = $mysqli->query($sql);

if (!$result) {
    die("SQL Error: " . $mysqli->error);
}

// Process the results 
while ($row = $result->fetch_assoc()) {
    $balance = $row['total_amount'] - $row['paid_amount'];
    
    // Initialize party if not exists
    if (!isset($aging_data[$row['party_id']])) {
        $aging_data[$row['party_id']] = [
            'name' => $row['consignor_name'],
            'credit_limit' => $row['credit_limit'], // NEW FIELD
            'invoices' => [],
            'total_balance' => 0
        ];
    }
    
    // Categorize into aging buckets
    $invoice_details = [
        'invoice_no' => $row['invoice_no'], 'invoice_date' => $row['invoice_date'], 'total_amount' => $row['total_amount'],
        'paid_amount' => $row['paid_amount'], 'balance' => $balance, 'age_days' => $row['age_days'],
        'current' => 0, '30_days' => 0, '60_days' => 0, '90_days' => 0, '90_plus_days' => 0
    ];
    
    // Original aging logic remains sound
    if ($row['age_days'] <= 30) {
        $invoice_details['current'] = $balance;
        $totals['current'] += $balance;
    } elseif ($row['age_days'] <= 60) {
        $invoice_details['30_days'] = $balance;
        $totals['30_days'] += $balance;
    } elseif ($row['age_days'] <= 90) {
        $invoice_details['60_days'] = $balance;
        $totals['60_days'] += $balance;
    } elseif ($row['age_days'] <= 120) {
        $invoice_details['90_days'] = $balance;
        $totals['90_days'] += $balance;
    } else {
        $invoice_details['90_plus_days'] = $balance;
        $totals['90_plus_days'] += $balance;
    }
    
    $aging_data[$row['party_id']]['invoices'][] = $invoice_details;
    $aging_data[$row['party_id']]['total_balance'] += $balance;
    $totals['balance'] += $balance;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/R Aging Report - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            body * { visibility: hidden; } 
            .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; } 
            .no-print { display: none !important; }
            /* Ensure background colors print */
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white no-print">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-chart-bar opacity-80"></i> A/R Aging Report
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
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
                    <div class="bg-gray-50/50 px-6 py-6 border-b border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Accounts Receivable Aging</h2>
                            <p class="text-sm text-gray-500 mt-1">Status as of <span class="font-bold text-gray-700"><?php echo date("F j, Y"); ?></span></p>
                        </div>
                        <div class="flex gap-2 no-print">
                            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
                                <i class="fas fa-print mr-2"></i> Print Report
                            </button>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Customer Name</th>
                                    <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Credit Limit</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide">Total Due</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide text-green-600">Current (1-30)</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide text-yellow-600">31-60 Days</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide text-orange-600">61-90 Days</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide text-red-500">91-120 Days</th>
                                    <th class="px-6 py-3 text-right font-bold text-gray-500 uppercase tracking-wide text-red-700">120+ Days</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php if(empty($aging_data)): ?>
                                    <tr><td colspan="8" class="text-center py-12 text-gray-400 italic">No outstanding receivables found. Great job!</td></tr>
                                <?php else: ?>
                                    <?php foreach ($aging_data as $party_id => $data): 
                                        $limit_exceeded = $data['credit_limit'] > 0 && $data['total_balance'] > $data['credit_limit'];
                                        $row_bg = $limit_exceeded ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50';
                                        $name_color = $limit_exceeded ? 'text-red-800' : 'text-gray-900';
                                    ?>
                                        <tr class="<?php echo $row_bg; ?> transition-colors group">
                                            <td class="px-6 py-4 whitespace-nowrap max-w-[200px]">
                                                <div class="flex items-center gap-2">
                                                    <a href="accounts_ledger.php?entity_id=<?php echo $party_id; ?>&entity_type=party" 
                                                       class="font-bold <?php echo $name_color; ?> hover:underline truncate block" 
                                                       title="<?php echo htmlspecialchars($data['name']); ?>">
                                                        <?php echo htmlspecialchars($data['name']); ?>
                                                    </a>
                                                    <?php if($limit_exceeded): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-800 no-print border border-red-200 flex-shrink-0">Over Limit</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-gray-500">
                                                <?php echo ($data['credit_limit'] > 0) ? '₹' . number_format($data['credit_limit'], 2) : '<span class="text-gray-400">-</span>'; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right font-bold text-gray-900">
                                                ₹<?php echo number_format($data['total_balance'], 2); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-600 group-hover:text-gray-900">
                                                <?php 
                                                    $val = array_sum(array_column($data['invoices'], 'current'));
                                                    echo $val > 0 ? number_format($val, 2) : '-'; 
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-600 group-hover:text-gray-900">
                                                <?php 
                                                    $val = array_sum(array_column($data['invoices'], '30_days'));
                                                    echo $val > 0 ? '<span class="text-yellow-700 font-medium">'.number_format($val, 2).'</span>' : '-'; 
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-600 group-hover:text-gray-900">
                                                <?php 
                                                    $val = array_sum(array_column($data['invoices'], '60_days'));
                                                    echo $val > 0 ? '<span class="text-orange-600 font-medium">'.number_format($val, 2).'</span>' : '-'; 
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-600 group-hover:text-gray-900">
                                                <?php 
                                                    $val = array_sum(array_column($data['invoices'], '90_days'));
                                                    echo $val > 0 ? '<span class="text-red-600 font-bold">'.number_format($val, 2).'</span>' : '-'; 
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-600 group-hover:text-gray-900">
                                                <?php 
                                                    $val = array_sum(array_column($data['invoices'], '90_plus_days'));
                                                    echo $val > 0 ? '<span class="text-red-700 font-extrabold">'.number_format($val, 2).'</span>' : '-'; 
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-gray-100 border-t border-gray-200">
                                <tr class="text-right">
                                    <td class="px-6 py-4 text-left text-sm font-bold text-gray-700 uppercase" colspan="2">Grand Total</td>
                                    <td class="px-6 py-4 text-sm font-bold text-gray-900">₹<?php echo number_format($totals['balance'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-green-700">₹<?php echo number_format($totals['current'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-yellow-700">₹<?php echo number_format($totals['30_days'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-orange-700">₹<?php echo number_format($totals['60_days'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-red-600">₹<?php echo number_format($totals['90_days'], 2); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-red-800">₹<?php echo number_format($totals['90_plus_days'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
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
    </script>
</body>
</html>