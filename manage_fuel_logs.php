<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$form_message = "";
$edit_mode = false;
$add_mode = false;
$log_data = ['id' => '', 'vehicle_id' => '', 'log_date' => date('Y-m-d'), 'odometer_reading' => '', 'fuel_quantity' => '', 'fuel_rate' => '', 'fuel_station' => '', 'filled_by_driver_id' => null];
$expense_categories = ['Fuel', 'Maintenance', 'Toll', 'Salary', 'Office Rent', 'Utilities', 'Repair', 'Other'];

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $vehicle_id = intval($_POST['vehicle_id']);
    $log_date = $_POST['log_date'];
    $odometer = intval($_POST['odometer_reading']);
    $quantity = (float)$_POST['fuel_quantity'];
    $rate = (float)$_POST['fuel_rate'];
    $total_cost = $quantity * $rate;
    $station = trim($_POST['fuel_station']);
    $driver_id = !empty($_POST['filled_by_driver_id']) ? intval($_POST['filled_by_driver_id']) : null;
    $branch_id = $_SESSION['branch_id'];
    $created_by = $_SESSION['id'];

    if ($id > 0) { // Update
        $sql = "UPDATE fuel_logs SET vehicle_id=?, log_date=?, odometer_reading=?, fuel_quantity=?, fuel_rate=?, total_cost=?, fuel_station=?, filled_by_driver_id=? WHERE id=? AND branch_id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isiddsdiii", $vehicle_id, $log_date, $odometer, $quantity, $rate, $total_cost, $station, $driver_id, $id, $branch_id);
    } else { // Insert
        $sql = "INSERT INTO fuel_logs (vehicle_id, log_date, odometer_reading, fuel_quantity, fuel_rate, total_cost, fuel_station, filled_by_driver_id, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isiddsdiii", $vehicle_id, $log_date, $odometer, $quantity, $rate, $total_cost, $station, $driver_id, $branch_id, $created_by);
    }

    if ($stmt->execute()) {
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Fuel log saved successfully!</div>';
        $add_mode = $edit_mode = false;
    } else {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// Handle GET Actions
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif ($_GET['action'] == 'edit' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM fuel_logs WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $log_data = $result->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    }
}

// Data Fetching for Lists/Dropdowns
$fuel_logs = [];
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);
$drivers = $mysqli->query("SELECT id, name FROM drivers WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

if (!$add_mode && !$edit_mode) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9; // Changed to 9 for grid view
    $offset = ($page - 1) * $records_per_page;
    $search_term = trim($_GET['search'] ?? '');
    
    // Base WHERE clause for branch security
    $where_sql = " WHERE f.branch_id = ?";
    $params = [$_SESSION['branch_id']];
    $types = "i";

    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_sql .= " AND (v.vehicle_number LIKE ? OR d.name LIKE ?)";
        array_push($params, $like_term, $like_term);
        $types .= "ss";
    }

    // Get total records with filtering
    $count_sql = "SELECT COUNT(f.id) FROM fuel_logs f LEFT JOIN vehicles v ON f.vehicle_id = v.id LEFT JOIN drivers d ON f.filled_by_driver_id = d.id" . $where_sql;
    $stmt_count = $mysqli->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    // Fetch logs for the current page
    $list_sql = "SELECT f.*, v.vehicle_number, d.name as driver_name 
                 FROM fuel_logs f 
                 JOIN vehicles v ON f.vehicle_id = v.id 
                 LEFT JOIN drivers d ON f.filled_by_driver_id = d.id" . $where_sql . " 
                 ORDER BY f.log_date DESC, f.id DESC LIMIT ? OFFSET ?";
    
    $params[] = $records_per_page;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    // Use call_user_func_array for robust binding
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    
    $stmt_list->execute();
    $result = $stmt_list->get_result();
    if ($result) {
        $fuel_logs = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_list->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Fuel Logs - TMS</title>
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
                                <i class="fas fa-gas-pump opacity-80"></i> Fuel Logs
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

                <?php if ($add_mode || $edit_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-4xl mx-auto">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-edit text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Fuel Log' : 'Add New Fuel Log'; ?>
                        </h2>
                        <a href="manage_fuel_logs.php" class="text-sm font-medium text-gray-600 hover:text-gray-900"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" class="p-6 md:p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $log_data['id']; ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle <span class="text-red-500">*</span></label>
                                <select name="vehicle_id" class="searchable-select block w-full" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach($vehicles as $v): ?>
                                    <option value="<?php echo $v['id']; ?>" <?php if($log_data['vehicle_id'] == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
                                <input type="date" name="log_date" value="<?php echo htmlspecialchars($log_data['log_date']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Odometer Reading <span class="text-red-500">*</span></label>
                                <input type="number" name="odometer_reading" value="<?php echo htmlspecialchars($log_data['odometer_reading']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Quantity (Ltr) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" name="fuel_quantity" value="<?php echo htmlspecialchars($log_data['fuel_quantity']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Rate (per Ltr) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" name="fuel_rate" value="<?php echo htmlspecialchars($log_data['fuel_rate']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filled by (Driver)</label>
                                <select name="filled_by_driver_id" class="searchable-select block w-full">
                                    <option value="">Select Driver</option>
                                    <?php foreach($drivers as $d): ?>
                                    <option value="<?php echo $d['id']; ?>" <?php if($log_data['filled_by_driver_id'] == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Station</label>
                                <input type="text" name="fuel_station" value="<?php echo htmlspecialchars($log_data['fuel_station']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Log' : 'Save Log'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                <div class="space-y-6">
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <form method="GET" action="manage_fuel_logs.php" class="w-full md:w-auto flex-1 flex gap-2">
                            <div class="relative w-full md:max-w-md">
                                <input type="text" name="search" placeholder="Search vehicle or driver..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                            </div>
                            <button type="submit" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition"><i class="fas fa-arrow-right"></i></button>
                            <?php if(!empty($search_term)): ?>
                                <a href="manage_fuel_logs.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 shadow-sm transition">Reset</a>
                            <?php endif; ?>
                        </form>
                        <a href="manage_fuel_logs.php?action=add" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Add Fuel Log
                        </a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php if (empty($fuel_logs)): ?>
                            <div class="md:col-span-2 xl:col-span-3 flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-dashed border-gray-300 text-gray-400">
                                <i class="fas fa-gas-pump fa-3x mb-4 opacity-50"></i>
                                <p class="text-lg font-medium">No fuel logs found.</p>
                                <p class="text-sm">Click 'Add Fuel Log' to get started.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($fuel_logs as $log): ?>
                            <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                                <div class="p-6 flex-grow">
                                    <div class="flex justify-between items-start mb-4">
                                        <h3 class="text-lg font-bold text-indigo-900"><?php echo htmlspecialchars($log['vehicle_number']); ?></h3>
                                        <span class="text-xs font-medium text-gray-400"><?php echo date("d M, Y", strtotime($log['log_date'])); ?></span>
                                    </div>
                                    
                                    <div class="flex items-baseline mb-4">
                                        <span class="text-2xl font-bold text-gray-900">₹<?php echo number_format($log['total_cost'], 2); ?></span>
                                    </div>
                                    
                                    <div class="space-y-2 text-sm text-gray-600">
                                        <div class="flex justify-between"><span class="text-gray-400">Quantity:</span> <span class="font-medium"><?php echo htmlspecialchars($log['fuel_quantity']); ?> Ltr</span></div>
                                        <div class="flex justify-between"><span class="text-gray-400">Rate:</span> <span class="font-medium">₹<?php echo htmlspecialchars($log['fuel_rate']); ?>/Ltr</span></div>
                                        <div class="flex justify-between"><span class="text-gray-400">Odometer:</span> <span class="font-medium"><?php echo htmlspecialchars($log['odometer_reading']); ?> km</span></div>
                                        <div class="flex justify-between"><span class="text-gray-400">Driver:</span> <span class="font-medium truncate"><?php echo htmlspecialchars($log['driver_name'] ?? 'N/A'); ?></span></div>
                                    </div>
                                </div>
                                
                                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                    <a href="manage_fuel_logs.php?action=edit&id=<?php echo $log['id']; ?>" class="text-sm font-bold text-indigo-600 hover:text-indigo-800 transition"><i class="fas fa-edit mr-1"></i> Edit</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <span class="text-sm text-gray-600">
                            Showing <span class="font-bold text-gray-800"><?php echo $total_records > 0 ? ($offset + 1) : 0; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $records_per_page, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> results
                        </span>
                        <div class="flex gap-1">
                             <?php 
                                $query_params = [];
                                if (!empty($search_term)) { $query_params['search'] = $search_term; }
                            ?>
                            <?php if ($page > 1): ?>
                                <?php $query_params['page'] = $page - 1; ?>
                                <a href="?<?php echo http_build_query($query_params); ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Previous</a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <?php $query_params['page'] = $page + 1; ?>
                                <a href="?<?php echo http_build_query($query_params); ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>
    <script>
    $(document).ready(function() {
        $('.searchable-select').select2({ width: '100%' });
        
        // --- Sidebar Toggle Logic ---
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('close-sidebar-btn');

        function toggleSidebar() {
            if (sidebarWrapper.classList.contains('hidden')) {
                sidebarWrapper.classList.remove('hidden');
                sidebarWrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.remove('hidden');
            } else {
                sidebarWrapper.classList.add('hidden');
                sidebarWrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.add('hidden');
            }
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
    });

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>