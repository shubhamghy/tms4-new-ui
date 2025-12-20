<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$form_message = "";

// --- Handle all form submissions ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $mysqli->begin_transaction();
    try {
        if ($action === 'add_tyre') {
            $tyre_number = trim($_POST['tyre_number']);
            $stmt_check = $mysqli->prepare("SELECT id FROM tyre_inventory WHERE tyre_number = ?");
            $stmt_check->bind_param("s", $tyre_number);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                throw new Exception("This Tyre Number is already in the inventory.");
            }
            $stmt_check->close();

            $sql = "INSERT INTO tyre_inventory (tyre_brand, tyre_model, tyre_number, purchase_date, purchase_cost, vendor_name) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssids", $_POST['tyre_brand'], $_POST['tyre_model'], $tyre_number, $_POST['purchase_date'], $_POST['purchase_cost'], $_POST['vendor_name']);
            if(!$stmt->execute()) throw new Exception($stmt->error);
            $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> New tyre added to inventory.</div>';
        
        } elseif ($action === 'delete_tyre') {
            $tyre_id = intval($_POST['tyre_id_to_delete']);
            if ($tyre_id > 0) {
                $stmt_check = $mysqli->prepare("SELECT status FROM tyre_inventory WHERE id = ?");
                $stmt_check->bind_param("i", $tyre_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $tyre_to_delete = $result_check->fetch_assoc();
                $stmt_check->close();

                if ($tyre_to_delete && $tyre_to_delete['status'] === 'Mounted') {
                    throw new Exception("Cannot delete a tyre that is currently mounted on a vehicle.");
                }

                $stmt_delete = $mysqli->prepare("DELETE FROM tyre_inventory WHERE id = ?");
                $stmt_delete->bind_param("i", $tyre_id);
                if(!$stmt_delete->execute()) throw new Exception($stmt_delete->error);
                $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-trash-alt mr-2"></i> Tyre deleted successfully.</div>';
            }
        
        } elseif ($action === 'mount_tyre') {
            // Odometer Validation
            $stmt_check_odo = $mysqli->prepare("SELECT MAX(unmount_odometer) as last_odo FROM vehicle_tyres WHERE vehicle_id = ?");
            $stmt_check_odo->bind_param("i", $_POST['vehicle_id']);
            $stmt_check_odo->execute();
            $last_odo = $stmt_check_odo->get_result()->fetch_assoc()['last_odo'] ?? 0;
            $stmt_check_odo->close();
            if (intval($_POST['mount_odometer']) < $last_odo) {
                throw new Exception("Mount odometer ({$_POST['mount_odometer']}) cannot be less than the vehicle's last recorded odometer ({$last_odo}).");
            }

            $sql_mount = "INSERT INTO vehicle_tyres (vehicle_id, tyre_id, position, mount_date, mount_odometer) VALUES (?, ?, ?, ?, ?)";
            $stmt_mount = $mysqli->prepare($sql_mount);
            $stmt_mount->bind_param("iissi", $_POST['vehicle_id'], $_POST['tyre_id'], $_POST['position'], $_POST['mount_date'], $_POST['mount_odometer']);
            if(!$stmt_mount->execute()) throw new Exception($stmt_mount->error);
            $sql_update_status = "UPDATE tyre_inventory SET status = 'Mounted' WHERE id = ?";
            $stmt_update = $mysqli->prepare($sql_update_status);
            $stmt_update->bind_param("i", $_POST['tyre_id']);
            if(!$stmt_update->execute()) throw new Exception($stmt_update->error);
            $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Tyre mounted successfully.</div>';
        
        } elseif ($action === 'add_and_mount_tyre') {
            // Odometer Validation
            $stmt_check_odo = $mysqli->prepare("SELECT MAX(unmount_odometer) as last_odo FROM vehicle_tyres WHERE vehicle_id = ?");
            $stmt_check_odo->bind_param("i", $_POST['vehicle_id']);
            $stmt_check_odo->execute();
            $last_odo = $stmt_check_odo->get_result()->fetch_assoc()['last_odo'] ?? 0;
            $stmt_check_odo->close();
            if (intval($_POST['mount_odometer']) < $last_odo) {
                throw new Exception("Mount odometer ({$_POST['mount_odometer']}) cannot be less than the vehicle's last recorded odometer ({$last_odo}).");
            }

            $tyre_number = trim($_POST['tyre_number']);
            $stmt_check = $mysqli->prepare("SELECT id FROM tyre_inventory WHERE tyre_number = ?");
            $stmt_check->bind_param("s", $tyre_number);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) throw new Exception("Tyre Number already exists.");
            $stmt_check->close();
            $sql_add = "INSERT INTO tyre_inventory (tyre_brand, tyre_model, tyre_number, purchase_date, purchase_cost, vendor_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Mounted')";
            $stmt_add = $mysqli->prepare($sql_add);
            $stmt_add->bind_param("sssids", $_POST['tyre_brand'], $_POST['tyre_model'], $tyre_number, $_POST['purchase_date'], $_POST['purchase_cost'], $_POST['vendor_name']);
            if(!$stmt_add->execute()) throw new Exception($stmt_add->error);
            $new_tyre_id = $stmt_add->insert_id;
            $sql_mount = "INSERT INTO vehicle_tyres (vehicle_id, tyre_id, position, mount_date, mount_odometer) VALUES (?, ?, ?, ?, ?)";
            $stmt_mount = $mysqli->prepare($sql_mount);
            $stmt_mount->bind_param("iissi", $_POST['vehicle_id'], $new_tyre_id, $_POST['position'], $_POST['mount_date'], $_POST['mount_odometer']);
            if(!$stmt_mount->execute()) throw new Exception($stmt_mount->error);
            $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> New tyre added and mounted successfully.</div>';
        
        } elseif ($action === 'unmount_tyre') {
            // Odometer Validation
            $stmt_check = $mysqli->prepare("SELECT mount_odometer FROM vehicle_tyres WHERE id = ?");
            $stmt_check->bind_param("i", $_POST['vehicle_tyre_id']);
            $stmt_check->execute();
            $mount_data = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();
            $mount_odometer = $mount_data['mount_odometer'] ?? 0;

            if (intval($_POST['unmount_odometer']) < $mount_odometer) {
                throw new Exception("Unmount odometer ({$_POST['unmount_odometer']}) cannot be less than mount odometer ({$mount_odometer}).");
            }

            $sql_unmount = "UPDATE vehicle_tyres SET unmount_date = ?, unmount_odometer = ?, unmount_reason = ? WHERE id = ?";
            $stmt_unmount = $mysqli->prepare($sql_unmount);
            $stmt_unmount->bind_param("sisi", $_POST['unmount_date'], $_POST['unmount_odometer'], $_POST['unmount_reason'], $_POST['vehicle_tyre_id']);
            if(!$stmt_unmount->execute()) throw new Exception($stmt_unmount->error);
            
            // If reason is Worn Out or Damaged, retire the tyre. Otherwise, return to stock.
            $new_status = 'In Stock';
            if (in_array($_POST['unmount_reason'], ['Worn Out', 'Damaged'])) {
                $new_status = 'Retired';
            }

            $sql_update_status = "UPDATE tyre_inventory SET status = ? WHERE id = ?";
            $stmt_update = $mysqli->prepare($sql_update_status);
            $stmt_update->bind_param("si", $new_status, $_POST['tyre_id_to_unmount']);
            if(!$stmt_update->execute()) throw new Exception($stmt_update->error);
            
            $form_message = '<div class="p-4 mb-6 text-sm text-amber-700 bg-amber-100 border-l-4 border-amber-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Tyre unmounted. Status set to ' . $new_status . '.</div>';
        
        } elseif ($action === 'retread_tyre') {
            $sql_retread = "INSERT INTO tyre_retreading (tyre_id, retread_date, cost, vendor_name, description) VALUES (?, ?, ?, ?, ?)";
            $stmt_retread = $mysqli->prepare($sql_retread);
            $stmt_retread->bind_param("isiss", $_POST['tyre_id'], $_POST['retread_date'], $_POST['retread_cost'], $_POST['retread_vendor'], $_POST['retread_description']);
            if(!$stmt_retread->execute()) throw new Exception($stmt_retread->error);
            $form_message = '<div class="p-4 mb-6 text-sm text-blue-700 bg-blue-100 border-l-4 border-blue-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Tyre retreading history added successfully.</div>';
        
        } elseif ($action === 'retire_tyre') {
            $tyre_id_to_retire = intval($_POST['tyre_id_to_retire']);
            // Only allow retiring tyres that are 'In Stock'
            $sql_retire = "UPDATE tyre_inventory SET status = 'Retired' WHERE id = ? AND status = 'In Stock'";
            $stmt_retire = $mysqli->prepare($sql_retire);
            $stmt_retire->bind_param("i", $tyre_id_to_retire);
            if(!$stmt_retire->execute()) throw new Exception($stmt_retire->error);
            $form_message = '<div class="p-4 mb-6 text-sm text-gray-700 bg-gray-100 border-l-4 border-gray-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Tyre marked as Retired.</div>';
        }

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $e->getMessage() . '</div>';
    }
}

// --- Pagination and Search Logic ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 9;
$offset = ($page - 1) * $records_per_page;
$search_term = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'All'); 

$where_clauses = [];
$params = [];
$types = "";

if (!empty($search_term)) {
    $where_clauses[] = "(ti.tyre_number LIKE ? OR v.vehicle_number LIKE ?)";
    $like_term = "%{$search_term}%";
    $params[] = $like_term;
    $params[] = $like_term;
    $types .= "ss";
}

if (!empty($status_filter) && $status_filter !== 'All') {
    $where_clauses[] = "ti.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = count($where_clauses) > 0 ? " WHERE " . implode(" AND ", $where_clauses) : "";

$query_base_from = "FROM tyre_inventory ti 
                    LEFT JOIN vehicle_tyres vt ON ti.id = vt.tyre_id AND vt.unmount_date IS NULL 
                    LEFT JOIN vehicles v ON vt.vehicle_id = v.id";

$count_sql = "SELECT COUNT(DISTINCT ti.id) " . $query_base_from . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_row()[0];
$total_pages = ceil($total_records / $records_per_page);
$stmt_count->close();


// --- Data Fetching for Views ---
$tyre_inventory_query = "
    SELECT 
        ti.*, 
        v.vehicle_number,
        (SELECT COUNT(*) FROM tyre_retreading tr WHERE tr.tyre_id = ti.id) as retread_count
    " . $query_base_from . "
    " . $where_sql . "
    GROUP BY ti.id
    ORDER BY ti.id DESC
    LIMIT ? OFFSET ?";

$list_params = $params;
$list_types = $types;
$list_params[] = $records_per_page;
$list_types .= "i";
$list_params[] = $offset;
$list_types .= "i";

$stmt_list = $mysqli->prepare($tyre_inventory_query);
if (!empty($list_types)) {
    $stmt_list->bind_param($list_types, ...$list_params);
}
$stmt_list->execute();
$tyre_inventory = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_list->close();

// Data for modals and selectors
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 AND ownership_type = 'Owned' ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);
$in_stock_tyres = $mysqli->query("SELECT id, tyre_brand, tyre_model, tyre_number FROM tyre_inventory WHERE status = 'In Stock' ORDER BY tyre_brand")->fetch_all(MYSQLI_ASSOC);
$selected_vehicle_id = intval($_GET['vehicle_id'] ?? 0);
$mounted_tyres = [];
if ($selected_vehicle_id > 0) {
    $sql = "SELECT vt.id, vt.position, vt.mount_date, vt.mount_odometer, ti.tyre_brand, ti.tyre_model, ti.tyre_number, ti.id as tyre_id 
            FROM vehicle_tyres vt JOIN tyre_inventory ti ON vt.tyre_id = ti.id 
            WHERE vt.vehicle_id = ? AND vt.unmount_date IS NULL";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $selected_vehicle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $mounted_tyres[$row['position']] = $row;
    }
    $stmt->close();
}

$axle_positions = ['Front-Left', 'Front-Right', 'Rear-Inner-Left', 'Rear-Outer-Left', 'Rear-Inner-Right', 'Rear-Outer-Right', 'Spare'];
function getStatusBadge($status) {
    $colors = ['In Stock' => 'bg-green-100 text-green-800 border-green-200', 'Mounted' => 'bg-blue-100 text-blue-800 border-blue-200', 'Retired' => 'bg-gray-100 text-gray-600 border-gray-200'];
    return $colors[$status] ?? 'bg-gray-100 text-gray-800';
}

// --- INITIAL TAB LOGIC (PHP DRIVEN) ---
// Force Layout tab if vehicle_id is present or tab parameter is explicitly 'layout'
$initial_tab = ($selected_vehicle_id > 0 || (isset($_GET['tab']) && $_GET['tab'] === 'layout')) ? 'layout' : 'inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyre Management - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 h-screen w-screen overflow-hidden">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
        <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
    </div>

    <div class="flex h-full w-full bg-gray-50" x-data="tyreApp('<?php echo $initial_tab; ?>')">
        
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
                                <i class="fas fa-dharmachakra opacity-80"></i> Tyre Management
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

            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 [--webkit-overflow-scrolling:touch]">
                <?php if(!empty($form_message)) echo $form_message; ?>
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-6 inline-flex">
                    <nav class="flex space-x-1" aria-label="Tabs">
                        <a href="#" @click.prevent="activeTab = 'inventory'" 
                           :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': activeTab === 'inventory', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'inventory' }" 
                           class="px-4 py-2 rounded-lg text-sm font-bold transition-all flex items-center">
                           <i class="fas fa-list mr-2"></i> Tyre Inventory
                        </a>
                        <a href="#" @click.prevent="activeTab = 'layout'" 
                           :class="{ 'bg-indigo-50 text-indigo-700 shadow-sm': activeTab === 'layout', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'layout' }" 
                           class="px-4 py-2 rounded-lg text-sm font-bold transition-all flex items-center">
                           <i class="fas fa-th mr-2"></i> Vehicle Tyre Layout
                        </a>
                    </nav>
                </div>
                
                <div x-show="activeTab === 'inventory'" x-cloak class="space-y-6" x-transition.opacity>
                    <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                        <h2 class="text-xl font-bold text-gray-800">Inventory Overview</h2>
                        <button @click="isAddTyreModalOpen = true" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Add New Tyre
                        </button>
                    </div>

                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                        <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
                            <input type="hidden" name="tab" value="inventory">
                            <div class="flex-grow w-full">
                                <label for="search" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Search Keyword</label>
                                <input type="text" id="search" name="search" placeholder="Tyre No or Vehicle No..." value="<?php echo htmlspecialchars($search_term); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                            </div>
                            <div class="w-full md:w-64">
                                <label for="status" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Status</label>
                                <select name="status" id="status" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                                    <option value="All" <?php if($status_filter == 'All') echo 'selected'; ?>>All Statuses</option>
                                    <option value="In Stock" <?php if($status_filter == 'In Stock') echo 'selected'; ?>>In Stock</option>
                                    <option value="Mounted" <?php if($status_filter == 'Mounted') echo 'selected'; ?>>Mounted</option>
                                    <option value="Retired" <?php if($status_filter == 'Retired') echo 'selected'; ?>>Retired</option>
                                </select>
                            </div>
                            <div class="flex gap-2 w-full md:w-auto">
                                <button type="submit" class="flex-1 md:flex-none px-6 py-2 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 shadow-sm transition"><i class="fas fa-filter mr-1"></i> Filter</button>
                                <a href="manage_tyres.php" class="flex-1 md:flex-none px-4 py-2 bg-white border border-gray-300 text-gray-700 font-bold rounded-lg hover:bg-gray-50 text-center transition">Reset</a>
                            </div>
                        </form>
                    </div>
                    
                    <div class="hidden xl:block bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                         <div class="overflow-x-auto">
                             <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Tyre Number</th>
                                        <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Brand & Model</th>
                                        <th class="px-6 py-3 text-left font-bold text-gray-500 uppercase tracking-wide">Status</th>
                                        <th class="px-6 py-3 text-center font-bold text-gray-500 uppercase tracking-wide">Retreads</th>
                                        <th class="px-6 py-3 text-center font-bold text-gray-500 uppercase tracking-wide">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php foreach($tyre_inventory as $tyre): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-4 font-bold text-gray-800"><?php echo htmlspecialchars($tyre['tyre_number']); ?></td>
                                        <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($tyre['tyre_brand'] . ' ' . $tyre['tyre_model']); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full border <?php echo getStatusBadge($tyre['status']); ?>"><?php echo htmlspecialchars($tyre['status']); ?></span>
                                            <?php if (!empty($tyre['vehicle_number'])): ?>
                                                <div class="text-xs text-indigo-600 mt-1 font-medium"><i class="fas fa-truck mr-1"></i> <?php echo htmlspecialchars($tyre['vehicle_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-center font-bold text-gray-600"><?php echo $tyre['retread_count']; ?></td>
                                        <td class="px-6 py-4 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button @click="openViewDetailsModal(<?php echo $tyre['id']; ?>)" class="text-indigo-600 hover:text-indigo-800 p-1" title="Details"><i class="fas fa-eye"></i></button>
                                                <button @click="openRetreadModal(<?php echo $tyre['id']; ?>, '<?php echo htmlspecialchars($tyre['tyre_number']); ?>')" class="text-blue-600 hover:text-blue-800 p-1" title="Retread"><i class="fas fa-recycle"></i></button>
                                                
                                                <?php if ($tyre['status'] === 'In Stock'): ?>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to retire this tyre?');" class="inline-block">
                                                        <input type="hidden" name="action" value="retire_tyre">
                                                        <input type="hidden" name="tyre_id_to_retire" value="<?php echo $tyre['id']; ?>">
                                                        <button type="submit" class="text-gray-500 hover:text-gray-700 p-1" title="Retire"><i class="fas fa-ban"></i></button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Permanently DELETE this tyre?');" class="inline-block">
                                                        <input type="hidden" name="action" value="delete_tyre">
                                                        <input type="hidden" name="tyre_id_to_delete" value="<?php echo $tyre['id']; ?>">
                                                        <button type="submit" class="text-red-500 hover:text-red-700 p-1" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                <?php elseif ($tyre['status'] !== 'Mounted'): ?>
                                                    <form method="POST" onsubmit="return confirm('Permanently DELETE this tyre?');" class="inline-block">
                                                        <input type="hidden" name="action" value="delete_tyre">
                                                        <input type="hidden" name="tyre_id_to_delete" value="<?php echo $tyre['id']; ?>">
                                                        <button type="submit" class="text-red-500 hover:text-red-700 p-1" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:hidden gap-6">
                         <?php foreach($tyre_inventory as $tyre): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-1 h-full bg-indigo-500"></div>
                                <div class="flex justify-between items-start mb-2 pl-3">
                                    <div>
                                        <h3 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars($tyre['tyre_number']); ?></h3>
                                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($tyre['tyre_brand'] . ' ' . $tyre['tyre_model']); ?></p>
                                    </div>
                                    <span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full border <?php echo getStatusBadge($tyre['status']); ?>"><?php echo htmlspecialchars($tyre['status']); ?></span>
                                </div>
                                
                                <?php if (!empty($tyre['vehicle_number'])): ?>
                                    <div class="pl-3 mb-3">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                            <i class="fas fa-truck mr-1.5"></i> <?php echo htmlspecialchars($tyre['vehicle_number']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-auto border-t border-gray-100 pt-3 flex justify-between items-center pl-3">
                                    <span class="text-xs font-bold text-gray-400 uppercase">Retreads: <span class="text-gray-800"><?php echo $tyre['retread_count']; ?></span></span>
                                    <div class="flex gap-3">
                                        <button @click="openViewDetailsModal(<?php echo $tyre['id']; ?>)" class="text-indigo-600 font-bold text-sm hover:underline">Details</button>
                                        <button @click="openRetreadModal(<?php echo $tyre['id']; ?>, '<?php echo htmlspecialchars($tyre['tyre_number']); ?>')" class="text-blue-600 font-bold text-sm hover:underline">Retread</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <div class="flex justify-center mt-6">
                        <nav class="inline-flex rounded-md shadow-sm">
                            <a href="<?php echo ($page > 1) ? '?page='.($page-1).'&search='.urlencode($search_term).'&status='.urlencode($status_filter) : '#'; ?>" class="px-4 py-2 text-sm font-medium bg-white border border-gray-300 rounded-l-lg hover:bg-gray-50 text-gray-700 <?php echo ($page <= 1) ? 'opacity-50 cursor-not-allowed' : ''; ?>">Prev</a>
                            
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search_term); ?>&status=<?php echo urlencode($status_filter); ?>" class="px-4 py-2 text-sm font-medium border-t border-b border-gray-300 hover:bg-gray-50 <?php echo ($p == $page) ? 'bg-indigo-50 text-indigo-600 font-bold border-indigo-200' : 'bg-white text-gray-700'; ?>"><?php echo $p; ?></a>
                            <?php endfor; ?>

                            <a href="<?php echo ($page < $total_pages) ? '?page='.($page+1).'&search='.urlencode($search_term).'&status='.urlencode($status_filter) : '#'; ?>" class="px-4 py-2 text-sm font-medium bg-white border border-gray-300 rounded-r-lg hover:bg-gray-50 text-gray-700 <?php echo ($page >= $total_pages) ? 'opacity-50 cursor-not-allowed' : ''; ?>">Next</a>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <div x-show="activeTab === 'layout'" x-cloak class="space-y-6" x-transition.opacity>
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 max-w-3xl mx-auto">
                        <form id="vehicle_layout_form" method="GET">
                             <input type="hidden" name="tab" value="layout">
                             <label for="vehicle_id_select" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Select Vehicle</label>
                             <select id="vehicle_id_select" name="vehicle_id" class="searchable-select block w-full">
                                <option>Select a vehicle to inspect...</option>
                                <?php foreach($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php if($selected_vehicle_id == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if($selected_vehicle_id > 0): ?>
                    <div class="bg-white p-6 md:p-8 rounded-xl shadow-md border border-gray-100">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center"><i class="fas fa-truck-monster mr-2 text-indigo-600"></i> Tyre Configuration</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach($axle_positions as $position): ?>
                                <div class="border border-gray-200 rounded-xl p-5 bg-gray-50/50 hover:border-indigo-200 transition-colors">
                                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3"><?php echo $position; ?></p>
                                    <?php if(isset($mounted_tyres[$position])): $tyre = $mounted_tyres[$position]; ?>
                                        <div class="space-y-2">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-circle-check text-green-500"></i>
                                                <span class="font-bold text-gray-900"><?php echo htmlspecialchars($tyre['tyre_number']); ?></span>
                                            </div>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($tyre['tyre_brand'] . ' ' . $tyre['tyre_model']); ?></p>
                                            <div class="text-xs bg-white p-2 rounded border border-gray-200 text-gray-600">
                                                Mounted: <strong><?php echo date('d-m-Y', strtotime($tyre['mount_date'])); ?></strong><br>
                                                Odo: <strong><?php echo $tyre['mount_odometer']; ?> km</strong>
                                            </div>
                                            <button @click="openUnmountModal(<?php echo htmlspecialchars(json_encode($tyre)); ?>)" class="w-full mt-2 py-1.5 px-3 bg-white border border-red-200 text-red-600 rounded-lg hover:bg-red-50 text-xs font-bold shadow-sm transition">
                                                Unmount Tyre
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex flex-col items-center justify-center py-4 border-2 border-dashed border-gray-300 rounded-lg">
                                            <p class="text-sm text-gray-400 font-medium mb-3">Empty Slot</p>
                                            <div class="flex flex-col gap-2 w-full px-4">
                                                <button @click="openMountModal('<?php echo $position; ?>')" class="w-full py-1.5 px-3 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100 text-xs font-bold transition">From Stock</button>
                                                <button @click="openAddAndMountModal('<?php echo $position; ?>')" class="w-full py-1.5 px-3 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 text-xs font-bold transition">New Purchase</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php include 'footer.php'; ?>
            </main>
        </div>

        <div x-show="isAddTyreModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
            <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isAddTyreModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full relative z-50 border border-gray-100">
                    <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fas fa-plus-circle"></i> Add New Tyre</h3>
                        <button @click="isAddTyreModalOpen = false" class="text-indigo-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="post" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="add_tyre">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Tyre Number*</label><input type="text" name="tyre_number" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Brand*</label><input type="text" name="tyre_brand" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Model</label><input type="text" name="tyre_model" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Purchase Date*</label><input type="date" name="purchase_date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cost (₹)*</label><input type="number" step="0.01" name="purchase_cost" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Vendor</label><input type="text" name="vendor_name" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                        </div>
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-indigo-700 transition">Save Tyre</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div x-show="isMountModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
            <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isMountModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full relative z-50">
                    <div class="bg-blue-600 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-white">Mount Tyre: <span x-text="selectedPosition"></span></h3>
                        <button @click="isMountModalOpen = false" class="text-blue-200 hover:text-white"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="post" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="mount_tyre">
                        <input type="hidden" name="vehicle_id" value="<?php echo $selected_vehicle_id; ?>">
                        <input type="hidden" name="position" :value="selectedPosition">
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Select Tyre (In Stock)</label>
                            <select name="tyre_id" class="searchable-select block w-full" required>
                                <option value="">Choose...</option>
                                <?php foreach($in_stock_tyres as $tyre): ?>
                                <option value="<?php echo $tyre['id']; ?>"><?php echo htmlspecialchars($tyre['tyre_number'] . ' (' . $tyre['tyre_brand'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Mount Date</label><input type="date" name="mount_date" value="<?php echo date('Y-m-d'); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Odometer (km)</label><input type="number" name="mount_odometer" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" required></div>
                        </div>
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-blue-700 transition">Confirm Mount</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div x-show="isAddAndMountModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
             <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isAddAndMountModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full relative z-50">
                    <div class="bg-green-600 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-white">Add & Mount: <span x-text="selectedPosition"></span></h3>
                        <button @click="isAddAndMountModalOpen = false" class="text-green-200 hover:text-white"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="post" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="add_and_mount_tyre">
                        <input type="hidden" name="vehicle_id" value="<?php echo $selected_vehicle_id; ?>">
                        <input type="hidden" name="position" :value="selectedPosition">
                        
                        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <p class="text-xs font-bold text-gray-500 uppercase mb-2">New Tyre Details</p>
                            <div class="grid grid-cols-2 gap-3">
                                <input type="text" name="tyre_number" placeholder="Tyre No." class="block w-full border-gray-300 rounded-md text-sm" required>
                                <input type="text" name="tyre_brand" placeholder="Brand" class="block w-full border-gray-300 rounded-md text-sm" required>
                                <input type="text" name="tyre_model" placeholder="Model" class="block w-full border-gray-300 rounded-md text-sm">
                                <input type="number" step="0.01" name="purchase_cost" placeholder="Cost" class="block w-full border-gray-300 rounded-md text-sm" required>
                                <input type="date" name="purchase_date" class="block w-full border-gray-300 rounded-md text-sm" required>
                                <input type="text" name="vendor_name" placeholder="Vendor" class="block w-full border-gray-300 rounded-md text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Mount Date</label><input type="date" name="mount_date" value="<?php echo date('Y-m-d'); ?>" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Odometer (km)</label><input type="number" name="mount_odometer" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                        </div>
                        
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-green-700 transition">Save & Mount</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div x-show="isUnmountModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
             <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isUnmountModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full relative z-50">
                    <div class="bg-red-600 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-white">Unmount Tyre</h3>
                        <button @click="isUnmountModalOpen = false" class="text-red-200 hover:text-white"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="post" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="unmount_tyre">
                        <input type="hidden" name="vehicle_tyre_id" :value="vehicleTyreToUnmount.id">
                        <input type="hidden" name="tyre_id_to_unmount" :value="vehicleTyreToUnmount.tyre_id">
                        
                        <div class="bg-red-50 p-3 rounded-lg border border-red-100">
                            <p class="text-sm text-red-800"><span class="font-bold">Tyre:</span> <span x-text="vehicleTyreToUnmount.tyre_number"></span></p>
                            <p class="text-sm text-red-800"><span class="font-bold">Position:</span> <span x-text="vehicleTyreToUnmount.position"></span></p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Unmount Date</label><input type="date" name="unmount_date" value="<?php echo date('Y-m-d'); ?>" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Odometer (km)</label><input type="number" name="unmount_odometer" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Reason</label>
                            <select name="unmount_reason" class="block w-full border-gray-300 rounded-lg text-sm" required>
                                <option value="">Select reason...</option>
                                <option value="Worn Out">Worn Out (Retire)</option>
                                <option value="Damaged">Damaged (Retire)</option>
                                <option value="Rotation">Rotation (To Stock)</option>
                                <option value="Puncture">Puncture (To Stock)</option>
                                <option value="Other">Other (To Stock)</option>
                            </select>
                        </div>
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="px-6 py-2 bg-red-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-red-700 transition">Confirm Unmount</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div x-show="isRetreadModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
             <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isRetreadModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg w-full relative z-50">
                    <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-white">Add Retreading Record</h3>
                        <button @click="isRetreadModalOpen = false" class="text-indigo-200 hover:text-white"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="post" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="retread_tyre">
                        <input type="hidden" name="tyre_id" :value="selectedTyre.id">
                        
                        <p class="text-sm font-bold text-gray-700 bg-gray-50 p-2 rounded border border-gray-200 text-center">Tyre: <span x-text="selectedTyre.number"></span></p>

                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Date</label><input type="date" name="retread_date" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cost (₹)</label><input type="number" step="0.01" name="retread_cost" class="block w-full border-gray-300 rounded-lg text-sm" required></div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Vendor</label>
                            <input type="text" name="retread_vendor" class="block w-full border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Description</label>
                            <textarea name="retread_description" rows="2" class="block w-full border-gray-300 rounded-lg text-sm"></textarea>
                        </div>
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-indigo-700 transition">Save Record</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div x-show="isViewDetailsModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
            <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="isViewDetailsModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-3xl w-full relative z-50 border border-gray-100">
                    <div class="p-8">
                        <div class="flex justify-between items-start border-b pb-4 mb-6">
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900" x-text="tyreDetails.details ? tyreDetails.details.tyre_number : 'Loading...'"></h3>
                                <p class="text-sm text-gray-500" x-text="tyreDetails.details ? (tyreDetails.details.tyre_brand + ' ' + tyreDetails.details.tyre_model) : ''"></p>
                            </div>
                            <button @click="isViewDetailsModalOpen = false" class="text-gray-400 hover:text-gray-600 text-2xl transition"><i class="fas fa-times"></i></button>
                        </div>
                        
                        <div x-show="isLoadingDetails" class="py-12 flex flex-col justify-center items-center text-gray-400">
                             <i class="fas fa-circle-notch fa-spin fa-2x mb-2"></i>
                             <p>Loading details...</p>
                        </div>

                        <div x-show="!isLoadingDetails && tyreDetails.details">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Purchase Date</p>
                                    <p class="font-bold text-gray-900" x-text="formatDate(tyreDetails.details.purchase_date)"></p>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Cost</p>
                                    <p class="font-bold text-gray-900" x-text="'₹' + parseFloat(tyreDetails.details.purchase_cost).toFixed(2)"></p>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Vendor</p>
                                    <p class="font-bold text-gray-900 truncate" x-text="tyreDetails.details.vendor_name || 'N/A'"></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8 bg-indigo-50 p-5 rounded-xl border border-indigo-100" x-if="tyreDetails.performance">
                                <div>
                                    <p class="text-xs font-bold text-indigo-400 uppercase mb-1">Total Distance</p>
                                    <p class="font-bold text-xl text-indigo-700" x-text="tyreDetails.performance.total_km.toLocaleString() + ' km'"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-indigo-400 uppercase mb-1">Lifetime Cost</p>
                                    <p class="font-bold text-xl text-indigo-700" x-text="'₹' + tyreDetails.performance.total_cost.toLocaleString()"></p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-indigo-400 uppercase mb-1">Cost Per KM</p>
                                    <p class="font-bold text-xl text-indigo-700" x-text="'₹' + tyreDetails.performance.cpk + ' /km'"></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <div>
                                    <h4 class="text-sm font-bold text-gray-800 uppercase border-b pb-2 mb-3">Mounting History</h4>
                                    <ul class="space-y-4 text-sm max-h-60 overflow-y-auto pr-2 custom-scrollbar" x-html="formatMountHistory(tyreDetails.mounts)"></ul>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-gray-800 uppercase border-b pb-2 mb-3">Retreading History</h4>
                                    <ul class="space-y-4 text-sm max-h-60 overflow-y-auto pr-2 custom-scrollbar" x-html="formatRetreadHistory(tyreDetails.retreads)"></ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // --- Sidebar Toggle Script ---
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('close-sidebar-btn');

        function toggleSidebar() {
            if (sidebarWrapper.classList.contains('hidden')) {
                // Open Sidebar
                sidebarWrapper.classList.remove('hidden');
                sidebarWrapper.classList.add('block');
                sidebarOverlay.classList.remove('hidden');
            } else {
                // Close Sidebar
                sidebarWrapper.classList.add('hidden');
                sidebarWrapper.classList.remove('block');
                sidebarOverlay.classList.add('hidden');
            }
        }

        if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
        if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
        if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }

        $(document).ready(function() {
            // --- Select2 Initialization with Fix ---
            $('#vehicle_id_select.searchable-select').select2({ width: '100%' })
                .on('select2:select', function(e) {
                    // Force submit parent form when selection is made
                    $(this).closest('form').submit();
                });
        });

        // --- Alpine.js logic ---
        function tyreApp(initialTab) {
            return {
                activeTab: initialTab,
                isAddTyreModalOpen: false, isMountModalOpen: false, isUnmountModalOpen: false,
                isRetreadModalOpen: false, isViewDetailsModalOpen: false, isAddAndMountModalOpen: false,
                selectedPosition: '', vehicleTyreToUnmount: {}, selectedTyre: { id: null, number: '' },
                tyreDetails: { details: {}, mounts:[], retreads:[], performance: { total_km: 0, total_cost: 0, cpk: 'N/A' } },
                isLoadingDetails: false,

                openMountModal(position) { this.selectedPosition = position; this.isMountModalOpen = true; this.initSelect2InModal(); },
                openAddAndMountModal(position) { this.selectedPosition = position; this.isAddAndMountModalOpen = true; },
                openUnmountModal(vehicleTyre) { this.vehicleTyreToUnmount = vehicleTyre; this.isUnmountModalOpen = true; },
                openRetreadModal(tyreId, tyreNumber) { this.selectedTyre.id = tyreId; this.selectedTyre.number = tyreNumber; this.isRetreadModalOpen = true; },
                
                openViewDetailsModal(tyreId) {
                    this.tyreDetails = { details: {}, mounts:[], retreads:[], performance: { total_km: 0, total_cost: 0, cpk: 'N/A' } };
                    this.isViewDetailsModalOpen = true;
                    this.isLoadingDetails = true;
                    
                    fetch(`ajax_get_tyre_details.php?tyre_id=${tyreId}`)
                        .then(response => response.json())
                        .then(data => {
                            this.isLoadingDetails = false;
                            if (data.error) {
                                alert(data.error);
                                this.isViewDetailsModalOpen = false;
                            } else {
                                this.tyreDetails = data;
                            }
                        })
                        .catch(error => {
                            this.isLoadingDetails = false;
                            console.error('Error fetching tyre details:', error);
                            alert('Failed to load tyre details.');
                            this.isViewDetailsModalOpen = false;
                        });
                },
                
                formatDate(dateString) {
                    if (!dateString || dateString === '0000-00-00') return 'N/A';
                    const date = new Date(dateString);
                    return isNaN(date.getTime()) ? 'Invalid Date' : date.toLocaleDateString('en-GB');
                },
                
                initSelect2InModal() { this.$nextTick(() => { $('[name="tyre_id"].searchable-select').select2({ dropdownParent: $('[x-show="isMountModalOpen"]'), width: '100%' }); }); },
                
                formatMountHistory(mounts) {
                    if (!mounts || mounts.length === 0) return '<li class="text-gray-400 italic">No mounting history.</li>';
                    return mounts.map(m => {
                        let unmountInfo = '<br><span class="text-green-600 font-bold text-xs bg-green-50 px-2 py-0.5 rounded">Current</span>';
                        if (m.unmount_date) {
                            let kmRun = (m.unmount_odometer && m.mount_odometer) ? ` <span class="font-bold text-indigo-600">(${m.unmount_odometer - m.mount_odometer} km)</span>` : '';
                            let reason = m.unmount_reason ? ` <span class="text-gray-500 italic text-xs">Reason: ${m.unmount_reason}</span>` : '';
                            unmountInfo = `<br><span class="text-gray-500 text-xs">Unmounted: ${new Date(m.unmount_date).toLocaleDateString('en-GB')}</span> ${kmRun}<br>${reason}`;
                        }
                        return `<li class="border-b border-gray-100 pb-3 last:border-0">
                                    <div class="flex justify-between">
                                        <strong>${new Date(m.mount_date).toLocaleDateString('en-GB')}</strong>
                                        <span class="text-gray-600 text-xs">${m.vehicle_number}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">${m.position}</div>
                                    ${unmountInfo}
                                </li>`;
                    }).join('');
                },
                
                formatRetreadHistory(retreads) { 
                    if (!retreads || retreads.length === 0) return '<li class="text-gray-400 italic">No retreading history.</li>'; 
                    return retreads.map(r => `
                        <li class="border-b border-gray-100 pb-3 last:border-0">
                            <div class="flex justify-between items-center">
                                <strong>${new Date(r.retread_date).toLocaleDateString('en-GB')}</strong>
                                <span class="text-indigo-600 font-bold">₹${parseFloat(r.cost).toFixed(2)}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">Vendor: ${r.vendor_name || 'N/A'}</div>
                        </li>`).join(''); 
                }
            }
        }
        
        window.onload = function() {
            const loader = document.getElementById('loader');
            if(loader) loader.style.display = 'none';
        };
    </script>
</body>
</html>