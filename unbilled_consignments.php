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

$consignor_id = isset($_GET['consignor_id']) ? intval($_GET['consignor_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$unbilled_shipments = [];

if ($consignor_id > 0 && !empty($date_from) && !empty($date_to)) {
    // Find shipments that are billed/completed but not yet on an invoice
    $sql = "SELECT s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, sp.amount as billing_rate
            FROM shipments s
            JOIN shipment_payments sp ON s.id = sp.shipment_id
            LEFT JOIN invoice_items ii ON s.id = ii.shipment_id
            WHERE s.consignor_id = ? 
            AND s.consignment_date BETWEEN ? AND ?
            AND sp.payment_type = 'Billing Rate'
            AND ii.id IS NULL
            ORDER BY s.consignment_date ASC";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("iss", $consignor_id, $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $unbilled_shipments[] = $row;
        }
        $stmt->close();
    }
}

// Fetch consignors for the filter dropdown
$consignors = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignor', 'Both') AND is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unbilled Consignments Report - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        /* Select2 Customization to match Tailwind */
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.5rem; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
        <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
    </div>

    <div class="flex h-screen bg-gray-50 overflow-hidden">
        
        <div class="md:hidden fixed inset-0 z-40 hidden transition-opacity duration-300 bg-gray-900 bg-opacity-50" id="sidebar-overlay"></div>
        
        <div id="sidebar-wrapper" class="z-50 md:relative md:z-0 md:block hidden h-full shadow-xl">
             <?php include 'sidebar.php'; ?>
        </div>

        <div class="flex flex-col flex-1 h-full overflow-hidden relative">
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-chart-line opacity-80"></i> Unbilled Report
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

            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 space-y-6">
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex items-center">
                        <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-filter text-indigo-500"></i> Filter Criteria
                        </h2>
                    </div>
                    <div class="p-6">
                        <form method="GET">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
                                <div class="col-span-1 md:col-span-2">
                                    <label for="consignor_id" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Select Consignor</label>
                                    <select name="consignor_id" id="consignor_id" class="searchable-select block w-full" required>
                                        <option value="">Select Consignor</option>
                                        <?php foreach($consignors as $c): ?><option value="<?php echo $c['id']; ?>" <?php if($consignor_id == $c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="date_from" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">From Date</label>
                                    <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                                <div>
                                    <label for="date_to" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">To Date</label>
                                    <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                            </div>
                            <div class="mt-6 flex justify-end">
                                <button type="submit" class="inline-flex items-center px-6 py-2.5 border border-transparent rounded-lg shadow-md text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                                    <i class="fas fa-search mr-2"></i> Generate Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($unbilled_shipments)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-list-alt text-indigo-500"></i> Report Results
                        </h2>
                        <span class="bg-indigo-100 text-indigo-700 text-xs font-bold px-3 py-1 rounded-full border border-indigo-200"><?php echo count($unbilled_shipments); ?> Records Found</span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">LR No.</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide">Route</th>
                                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($unbilled_shipments as $shipment): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600"><?php echo htmlspecialchars($shipment['consignment_no']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date("d-m-Y", strtotime($shipment['consignment_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($shipment['origin'] . ' → ' . $shipment['destination']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">₹<?php echo htmlspecialchars(number_format($shipment['billing_rate'], 2)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php elseif ($_SERVER["REQUEST_METHOD"] == "GET" && !empty($consignor_id)): ?>
                <div class="bg-white p-12 rounded-xl shadow-sm border border-gray-100 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-50 mb-4">
                        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-800">All Clear!</h3>
                    <p class="text-gray-500 mt-2">No unbilled shipments found for the selected criteria.</p>
                </div>
                <?php endif; ?>
                
                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.searchable-select').select2();

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
        });

        // Hide Loader
        window.onload = function() {
            const loader = document.getElementById('loader');
            if (loader) {
                loader.style.display = 'none';
            }
        };
    </script>
</body>
</html>