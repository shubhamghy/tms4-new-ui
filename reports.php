<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$is_admin = $_SESSION['role'] === 'admin';

// --- Filter Handling ---
$today = new DateTime();
$thirty_days_ago = (new DateTime())->sub(new DateInterval('P30D'));

$filter_start_date = $_GET['start_date'] ?? $thirty_days_ago->format('Y-m-d');
$filter_end_date = $_GET['end_date'] ?? $today->format('Y-m-d');
$filter_branch_id = $is_admin ? ($_GET['branch_id'] ?? '') : $_SESSION['branch_id'];

// --- Data Fetching ---
$report_data = [];
$total_revenue = 0;
$total_shipment_expenses = 0;
$total_gross_profit = 0;

// Query 1: Get profit from individual shipments
$sql_shipments = "
    SELECT 
        s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, br.name as branch_name,
        COALESCE((SELECT sp.amount FROM shipment_payments sp WHERE sp.shipment_id = s.id AND sp.payment_type = 'Billing Rate'), 0) AS income,
        COALESCE((SELECT sp.amount FROM shipment_payments sp WHERE sp.shipment_id = s.id AND sp.payment_type = 'Lorry Hire'), 0) AS lorry_hire,
        COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.shipment_id = s.id), 0) AS other_expenses
    FROM shipments s
    LEFT JOIN branches br ON s.branch_id = br.id
";

$where_clauses = [];
$params = [];
$types = "";

$where_clauses[] = "s.consignment_date BETWEEN ? AND ?";
$params[] = $filter_start_date;
$params[] = $filter_end_date;
$types .= "ss";

$branch_filter_sql = "";
if ($is_admin) {
    if (!empty($filter_branch_id)) {
        $where_clauses[] = "s.branch_id = ?";
        $params[] = $filter_branch_id;
        $types .= "i";
        $branch_filter_sql = " AND branch_id = " . intval($filter_branch_id);
    }
} else {
    $where_clauses[] = "s.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
    $types .= "i";
    $branch_filter_sql = " AND branch_id = " . intval($_SESSION['branch_id']);
}

if (!empty($where_clauses)) {
    $sql_shipments .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql_shipments .= " ORDER BY s.consignment_date DESC";

$stmt = $mysqli->prepare($sql_shipments);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['total_expenses'] = $row['lorry_hire'] + $row['other_expenses'];
    $row['profit_loss'] = $row['income'] - $row['total_expenses'];
    $report_data[] = $row;
    $total_revenue += $row['income'];
    $total_shipment_expenses += $row['total_expenses'];
    $total_gross_profit += $row['profit_loss'];
}
$stmt->close();

// --- Expenses Calculation ---
$total_op_expenses = 0;
$total_salary_expenses = 0;

$op_expense_sql = "SELECT category, SUM(amount) as total FROM expenses WHERE shipment_id IS NULL AND expense_date BETWEEN ? AND ? {$branch_filter_sql} GROUP BY category";
$stmt_op = $mysqli->prepare($op_expense_sql);
$stmt_op->bind_param("ss", $filter_start_date, $filter_end_date);
$stmt_op->execute();
$result_op = $stmt_op->get_result();
while($row_op = $result_op->fetch_assoc()) {
    if ($row_op['category'] === 'Salary') {
        $total_salary_expenses += $row_op['total'];
    } else {
        $total_op_expenses += $row_op['total'];
    }
}
$stmt_op->close();

// --- Final Calculation: Net Profit ---
$total_expenses = $total_shipment_expenses + $total_op_expenses + $total_salary_expenses;
$net_profit = $total_revenue - $total_expenses;

$branches = [];
if ($is_admin) {
    $branches = $mysqli->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Report - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
        @media print {
            body * { visibility: hidden; } .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; } .no-print { display: none !important; }
        }
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
                                <i class="fas fa-chart-line opacity-80"></i> Profit & Loss Report
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
                    <form id="filter-form" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 items-end">
                        <div>
                            <label for="start_date" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Start Date</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        </div>
                        <div>
                            <label for="end_date" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">End Date</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        </div>
                        <?php if ($is_admin): ?>
                        <div>
                            <label for="branch_id" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Branch</label>
                            <select name="branch_id" id="branch_id" class="searchable-select block w-full">
                                <option value="">All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>" <?php if ($filter_branch_id == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex gap-2 lg:col-span-2">
                            <button type="submit" class="flex-1 inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-lg shadow-md text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">Filter Report</button>
                            <a href="reports.php" class="inline-flex justify-center items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Reset</a>
                            <a href="#" id="download-btn" class="inline-flex justify-center items-center px-4 py-2 border border-green-200 rounded-lg shadow-sm text-sm font-bold text-green-700 bg-green-50 hover:bg-green-100 transition"><i class="fas fa-file-csv mr-2"></i> CSV</a>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6 print-area">
                    <div class="bg-gradient-to-br from-green-500 to-green-600 text-white p-5 rounded-xl shadow-lg flex flex-col justify-between">
                        <div class="text-green-100 text-xs font-bold uppercase tracking-wider mb-2">Total Revenue</div>
                        <div class="text-3xl font-bold">₹<?php echo number_format($total_revenue, 2); ?></div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-red-400 to-red-500 text-white p-5 rounded-xl shadow-lg flex flex-col justify-between">
                        <div class="text-red-100 text-xs font-bold uppercase tracking-wider mb-2">Trip Expenses</div>
                        <div class="text-3xl font-bold">₹<?php echo number_format($total_shipment_expenses, 2); ?></div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-orange-400 to-orange-500 text-white p-5 rounded-xl shadow-lg flex flex-col justify-between">
                        <div class="text-orange-100 text-xs font-bold uppercase tracking-wider mb-2">Salary Cost</div>
                        <div class="text-3xl font-bold">₹<?php echo number_format($total_salary_expenses, 2); ?></div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-pink-500 to-rose-500 text-white p-5 rounded-xl shadow-lg flex flex-col justify-between">
                        <div class="text-pink-100 text-xs font-bold uppercase tracking-wider mb-2">Operational Exp</div>
                        <div class="text-3xl font-bold">₹<?php echo number_format($total_op_expenses, 2); ?></div>
                    </div>
                    
                    <div class="bg-gradient-to-br <?php echo $net_profit >= 0 ? 'from-blue-600 to-indigo-600' : 'from-gray-700 to-gray-800'; ?> text-white p-5 rounded-xl shadow-lg flex flex-col justify-between relative overflow-hidden md:col-span-2 lg:col-span-1">
                        <div class="relative z-10">
                            <div class="text-blue-100 text-xs font-bold uppercase tracking-wider mb-2">Net Profit / Loss</div>
                            <div class="text-3xl font-bold">₹<?php echo number_format($net_profit, 2); ?></div>
                        </div>
                        <div class="absolute right-[-10px] bottom-[-10px] text-white opacity-10 text-6xl"><i class="fas fa-balance-scale"></i></div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print-area">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-list-alt text-indigo-500"></i> Shipment P&L Breakdown</h3>
                        <button onclick="window.print()" class="no-print text-sm text-gray-500 hover:text-gray-800"><i class="fas fa-print mr-1"></i> Print Report</button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">LR No.</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Branch</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Income</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Lorry Hire</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Other Exp.</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Gross P/L</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100 text-sm">
                                <?php if (empty($report_data)): ?>
                                    <tr><td colspan="7" class="text-center py-10 text-gray-400 italic">No shipment data found for the selected filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-3 font-medium text-indigo-600"><a href="view_shipment_details.php?id=<?php echo $row['id']; ?>" class="hover:underline"><?php echo htmlspecialchars($row['consignment_no']); ?></a></td>
                                        <td class="px-6 py-3 text-gray-500"><?php echo date('d-m-Y', strtotime($row['consignment_date'])); ?></td>
                                        <td class="px-6 py-3 text-gray-500"><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                        <td class="px-6 py-3 text-right text-green-600 font-medium">₹<?php echo number_format($row['income'], 2); ?></td>
                                        <td class="px-6 py-3 text-right text-red-500">₹<?php echo number_format($row['lorry_hire'], 2); ?></td>
                                        <td class="px-6 py-3 text-right text-orange-500">₹<?php echo number_format($row['other_expenses'], 2); ?></td>
                                        <td class="px-6 py-3 text-right font-bold <?php echo $row['profit_loss'] >= 0 ? 'text-green-700' : 'text-red-700'; ?>">₹<?php echo number_format($row['profit_loss'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
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
        
        // Select2
        $('.searchable-select').select2({ width: '100%' });

        // Download handler
        $('#download-btn').on('click', function(e) {
            e.preventDefault();
            const form = $('#filter-form');
            const params = form.serialize();
            window.location.href = 'download_report.php?' + params;
        });
    });

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>