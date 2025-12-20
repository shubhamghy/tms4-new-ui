<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- MODIFICATION: Updated File Upload Helper ---
function process_maintenance_upload($file_key_name, $log_id, $existing_path = null) {
    $target_dir = "uploads/maintenance/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }

    // Check if a file was uploaded for this key
    if (isset($_FILES[$file_key_name]) && $_FILES[$file_key_name]['error'] == 0) {
        $file = $_FILES[$file_key_name];
        $tmp_name = $file['tmp_name'];
        $file_ext = strtolower(pathinfo(basename($file["name"]), PATHINFO_EXTENSION));
        $new_file_name = "log_{$log_id}_{$file_key_name}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;
        $file_type = mime_content_type($tmp_name);

        $upload_success = false;

        // Check if GD library functions exist before trying to compress
        $can_compress = function_exists('imagecreatefromjpeg') && function_exists('imagejpeg') && 
                        function_exists('imagecreatefrompng') && function_exists('imagepng') && 
                        function_exists('imagecreatefromgif');

        if ($can_compress && in_array($file_type, ['image/jpeg', 'image/png', 'image/gif'])) {
            $image = null;
            if ($file_type == 'image/jpeg') {
                $image = @imagecreatefromjpeg($tmp_name);
            } elseif ($file_type == 'image/png') {
                $image = @imagecreatefrompng($tmp_name);
            } elseif ($file_type == 'image/gif') {
                $image = @imagecreatefromgif($tmp_name);
            }

            if ($image) {
                if ($file_type == 'image/jpeg') {
                    $upload_success = imagejpeg($image, $target_file, 75); // 75% quality
                } elseif ($file_type == 'image/png') {
                    imagealphablending($image, false); // Keep transparency
                    imagesavealpha($image, true);
                    $upload_success = imagepng($image, $target_file, 6); // Compression level 0-9
                } else {
                    $upload_success = move_uploaded_file($tmp_name, $target_file);
                }
                imagedestroy($image);
            } else {
                $upload_success = move_uploaded_file($tmp_name, $target_file);
            }
        } else {
            $upload_success = move_uploaded_file($tmp_name, $target_file);
        }
        
        if ($upload_success) {
            if (!empty($existing_path) && file_exists($existing_path) && $existing_path != $target_file) {
                @unlink($existing_path);
            }
            return $target_file;
        } else {
            return $existing_path;
        }
    }

    // Handle file deletion request
    if (isset($_POST['delete_file_' . $file_key_name]) && !empty($existing_path)) {
        if (file_exists($existing_path)) {
            @unlink($existing_path);
        }
        return null; // Return null to remove it from the array
    }

    return $existing_path;
}


// --- Helper function for status badges ---
function getStatusBadge($days_diff) {
    if ($days_diff === null) {
        return ''; 
    }
    if ($days_diff < 0) {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">Overdue (' . abs($days_diff) . ' days)</span>';
    } elseif ($days_diff <= 30) {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">Due in ' . $days_diff . ' days</span>';
    } else {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">Scheduled</span>';
    }
}


$form_message = "";
$edit_mode = false;
$add_mode = false;
$log_data = ['id' => '', 'vehicle_id' => '', 'service_date' => date('Y-m-d'), 'service_type' => '', 'odometer_reading' => '', 'service_cost' => '', 'vendor_name' => '', 'description' => '', 'next_service_date' => null, 'invoice_doc_paths' => '[]', 'tyre_number' => null];

$service_types_result = $mysqli->query("SELECT name FROM maintenance_service_types WHERE is_active = 1 ORDER BY name ASC");
$service_types = $service_types_result->fetch_all(MYSQLI_ASSOC);


// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $vehicle_id = intval($_POST['vehicle_id']);
    $service_date = $_POST['service_date'];
    $service_type = trim($_POST['service_type']);
    $odometer = intval($_POST['odometer_reading']);
    $cost = (float)$_POST['service_cost'];
    $vendor = trim($_POST['vendor_name']);
    $description = trim($_POST['description']);
    $next_service_date = !empty($_POST['next_service_date']) ? $_POST['next_service_date'] : null;
    $branch_id = $_SESSION['branch_id'];
    $created_by = $_SESSION['id'];
    $tyre_number = ($service_type == 'Tyre Replacement') ? trim($_POST['tyre_number']) : null;
    
    $final_paths = [];
    $log_id_for_upload = $id;

    if ($id > 0) { // Update
        $log_id_for_upload = $id;
        $current_paths = json_decode($_POST['existing_invoice_doc_paths_json'] ?? '[]', true);

        $final_paths[0] = process_maintenance_upload('invoice_doc_1', $log_id_for_upload, $current_paths[0] ?? null);
        $final_paths[1] = process_maintenance_upload('invoice_doc_2', $log_id_for_upload, $current_paths[1] ?? null);
        $final_paths[2] = process_maintenance_upload('invoice_doc_3', $log_id_for_upload, $current_paths[2] ?? null);
        $final_paths[3] = process_maintenance_upload('invoice_doc_4', $log_id_for_upload, $current_paths[3] ?? null);

        $paths_to_save = json_encode(array_values(array_filter($final_paths)));

        $sql = "UPDATE maintenance_logs SET vehicle_id=?, service_date=?, service_type=?, odometer_reading=?, service_cost=?, vendor_name=?, description=?, next_service_date=?, invoice_doc_paths=?, tyre_number=? WHERE id=? AND branch_id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issidsssssii", $vehicle_id, $service_date, $service_type, $odometer, $cost, $vendor, $description, $next_service_date, $paths_to_save, $tyre_number, $id, $branch_id);
    
    } else { // Insert
        $sql = "INSERT INTO maintenance_logs (vehicle_id, service_date, service_type, odometer_reading, service_cost, vendor_name, description, next_service_date, branch_id, created_by, tyre_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issidsssiss", $vehicle_id, $service_date, $service_type, $odometer, $cost, $vendor, $description, $next_service_date, $branch_id, $created_by, $tyre_number);
    }

    if ($stmt->execute()) {
        $log_id = ($id > 0) ? $id : $stmt->insert_id;

        if ($id == 0) { 
            $final_paths[0] = process_maintenance_upload('invoice_doc_1', $log_id, null);
            $final_paths[1] = process_maintenance_upload('invoice_doc_2', $log_id, null);
            $final_paths[2] = process_maintenance_upload('invoice_doc_3', $log_id, null);
            $final_paths[3] = process_maintenance_upload('invoice_doc_4', $log_id, null);
            
            $paths_to_save = json_encode(array_values(array_filter($final_paths)));
            if (!empty($paths_to_save) && $paths_to_save != '[]') {
                $mysqli->query("UPDATE maintenance_logs SET invoice_doc_paths = '{$mysqli->real_escape_string($paths_to_save)}' WHERE id = $log_id");
            }
        }

        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Maintenance log saved successfully!</div>';
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
        $stmt = $mysqli->prepare("SELECT * FROM maintenance_logs WHERE id = ?");
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

// Data Fetching
$maintenance_logs = [];
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);

// Dashboard Stats
$stats = ['cost_month' => 0, 'overdue' => 0, 'due_soon' => 0];
if (!$add_mode && !$edit_mode) {
    $branch_id = $_SESSION['branch_id'];
    $first_day_month = date('Y-m-01');
    $last_day_month = date('Y-m-t');
    
    $cost_result = $mysqli->query("SELECT SUM(service_cost) as total_cost FROM maintenance_logs WHERE branch_id = $branch_id AND service_date BETWEEN '$first_day_month' AND '$last_day_month'");
    $stats['cost_month'] = $cost_result->fetch_assoc()['total_cost'] ?? 0;

    $overdue_result = $mysqli->query("SELECT COUNT(id) as count FROM maintenance_logs WHERE branch_id = $branch_id AND next_service_date < CURDATE()");
    $stats['overdue'] = $overdue_result->fetch_assoc()['count'] ?? 0;
    
    $due_soon_result = $mysqli->query("SELECT COUNT(id) as count FROM maintenance_logs WHERE branch_id = $branch_id AND next_service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $stats['due_soon'] = $due_soon_result->fetch_assoc()['count'] ?? 0;
}

if (!$add_mode && !$edit_mode) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9; // Grid view
    $offset = ($page - 1) * $records_per_page;
    $search_term = trim($_GET['search'] ?? '');
    
    $where_sql = " WHERE m.branch_id = ?";
    $params = [$_SESSION['branch_id']];
    $types = "i";

    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_sql .= " AND (v.vehicle_number LIKE ? OR m.service_type LIKE ? OR m.vendor_name LIKE ?)";
        array_push($params, $like_term, $like_term, $like_term);
        $types .= "sss";
    }

    $count_sql = "SELECT COUNT(m.id) FROM maintenance_logs m LEFT JOIN vehicles v ON m.vehicle_id = v.id" . $where_sql;
    $stmt_count = $mysqli->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    $list_sql = "SELECT m.*, v.vehicle_number, DATEDIFF(m.next_service_date, CURDATE()) as days_diff 
                 FROM maintenance_logs m 
                 JOIN vehicles v ON m.vehicle_id = v.id" . $where_sql . " 
                 ORDER BY m.service_date DESC, m.id DESC LIMIT ? OFFSET ?";
    $params[] = $records_per_page; $types .= "i";
    $params[] = $offset; $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    
    $stmt_list->execute();
    $maintenance_logs = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
    
    // Build pagination URL
    $query_params = $_GET;
    unset($query_params['page']);
    $pagination_url = http_build_query($query_params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Maintenance - TMS</title>
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
        .file-slot { background-color: #f9fafb; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.75rem; margin-top: 0.25rem; }
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
                                <i class="fas fa-tools opacity-80"></i> Manage Maintenance
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
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-wrench text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Maintenance Log' : 'Add New Maintenance Log'; ?>
                        </h2>
                        <a href="manage_maintenance.php" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" class="p-6 md:p-8 space-y-8" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?php echo $log_data['id']; ?>">
                        <input type="hidden" name="existing_invoice_doc_paths_json" value="<?php echo htmlspecialchars($log_data['invoice_doc_paths'] ?? '[]'); ?>">
                        <?php $existing_paths = json_decode($log_data['invoice_doc_paths'] ?? '[]', true); ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle <span class="text-red-500">*</span></label>
                                <select id="vehicle_id" name="vehicle_id" class="searchable-select block w-full" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach($vehicles as $v): ?>
                                    <option value="<?php echo $v['id']; ?>" <?php if($log_data['vehicle_id'] == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Service Date <span class="text-red-500">*</span></label>
                                <input type="date" name="service_date" value="<?php echo htmlspecialchars($log_data['service_date']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Service Type <span class="text-red-500">*</span></label>
                                <select id="service_type" name="service_type" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white" required>
                                    <option value="">Select Type</option>
                                    <?php foreach($service_types as $type): ?>
                                    <option value="<?php echo $type['name']; ?>" <?php if($log_data['service_type'] == $type['name']) echo 'selected'; ?>><?php echo $type['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Odometer Reading</label>
                                <input type="number" id="odometer_reading" name="odometer_reading" value="<?php echo htmlspecialchars($log_data['odometer_reading']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" min="0" placeholder="e.g., 125000">
                                <p id="odometer_warning" class="text-xs text-red-600 mt-1 hidden">Warning: Odometer is less than last entry.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Service Cost <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" name="service_cost" value="<?php echo htmlspecialchars($log_data['service_cost']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Vendor / Garage</label>
                                <input type="text" name="vendor_name" value="<?php echo htmlspecialchars($log_data['vendor_name']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea name="description" rows="2" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($log_data['description']); ?></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Next Service Due</label>
                                <input type="date" name="next_service_date" value="<?php echo htmlspecialchars($log_data['next_service_date']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div id="tyre_number_field" class="conditional-field" <?php if($log_data['service_type'] == 'Tyre Replacement') echo 'style="display:block;"'; ?>>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tyre Number / Position</label>
                                <input type="text" name="tyre_number" value="<?php echo htmlspecialchars($log_data['tyre_number'] ?? ''); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h4 class="text-sm font-bold text-gray-700 uppercase mb-4">Service Invoices (Max 4)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <?php for ($i = 1; $i <= 4; $i++): 
                                    $existing_file_path = $existing_paths[$i - 1] ?? null;
                                ?>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Invoice/File <?php echo $i; ?></label>
                                    <div class="file-slot bg-white border border-gray-300 rounded-lg p-3">
                                        <input type="file" name="invoice_doc_<?php echo $i; ?>" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"/>
                                        <?php if (!empty($existing_file_path)): ?>
                                        <div class="mt-2 flex items-center justify-between">
                                            <a href="<?php echo htmlspecialchars($existing_file_path); ?>" target="_blank" class="text-indigo-600 hover:underline text-sm font-medium flex items-center">
                                                <i class="fas fa-file-alt mr-2"></i> View File
                                            </a>
                                            <div class="flex items-center">
                                                <input type="checkbox" name="delete_file_invoice_doc_<?php echo $i; ?>" id="delete_file_<?php echo $i; ?>" class="mr-2 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                                <label for="delete_file_<?php echo $i; ?>" class="text-xs text-red-600 font-bold cursor-pointer">Delete</label>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent rounded-lg shadow-md text-base font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Record' : 'Save Record'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                
                <div class="space-y-6">
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center">
                            <div class="p-3 rounded-full bg-indigo-50 text-indigo-600 mr-4"><i class="fas fa-wallet text-2xl"></i></div>
                            <div>
                                <p class="text-sm text-gray-500 font-bold uppercase">Cost This Month</p>
                                <p class="text-2xl font-bold text-gray-800">₹<?php echo number_format($stats['cost_month'], 2); ?></p>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center">
                            <div class="p-3 rounded-full bg-red-50 text-red-600 mr-4"><i class="fas fa-exclamation-circle text-2xl"></i></div>
                            <div>
                                <p class="text-sm text-gray-500 font-bold uppercase">Services Overdue</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $stats['overdue']; ?></p>
                            </div>
                        </div>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center">
                            <div class="p-3 rounded-full bg-yellow-50 text-yellow-600 mr-4"><i class="fas fa-clock text-2xl"></i></div>
                            <div>
                                <p class="text-sm text-gray-500 font-bold uppercase">Due Soon</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $stats['due_soon']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <form method="GET" action="manage_maintenance.php" class="w-full md:w-auto flex-1 flex gap-2">
                            <div class="relative w-full md:max-w-md">
                                <input type="text" name="search" placeholder="Search vehicle, service type..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                            </div>
                            <button type="submit" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition"><i class="fas fa-arrow-right"></i></button>
                            <?php if(!empty($search_term)): ?>
                                <a href="manage_maintenance.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 shadow-sm transition">Reset</a>
                            <?php endif; ?>
                        </form>
                        <a href="manage_maintenance.php?action=add" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Log Service
                        </a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php if (empty($maintenance_logs)): ?>
                            <div class="md:col-span-2 xl:col-span-3 flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-dashed border-gray-300 text-gray-400">
                                <i class="fas fa-tools fa-3x mb-4 opacity-50"></i>
                                <p class="text-lg font-medium">No maintenance records found.</p>
                                <p class="text-sm">Click 'Log Service' to add a new record.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($maintenance_logs as $log): ?>
                            <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                                <div class="p-5 flex-grow">
                                    <div class="flex justify-between items-start mb-3">
                                        <h3 class="text-lg font-bold text-indigo-900"><?php echo htmlspecialchars($log['vehicle_number'] ?? 'N/A'); ?></h3>
                                        <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-md border border-gray-200"><?php echo date("d M, Y", strtotime($log['service_date'])); ?></span>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-100">
                                            <?php echo htmlspecialchars($log['service_type']); ?>
                                        </span>
                                    </div>

                                    <div class="space-y-2 text-sm text-gray-600 mb-4">
                                        <div class="flex justify-between"><p class="text-gray-500">Cost:</p><p class="font-bold text-lg">₹<?php echo number_format($log['service_cost'] ?? 0, 2); ?></p></div>
                                        <div class="flex justify-between"><p class="text-gray-500">Odometer:</p><p><?php echo htmlspecialchars($log['odometer_reading']); ?> km</p></div>
                                        <div class="flex justify-between"><p class="text-gray-500">Vendor:</p><p class="font-medium truncate"><?php echo htmlspecialchars($log['vendor_name'] ?? 'N/A'); ?></p></div>
                                        
                                        <?php if($log['next_service_date']): ?>
                                        <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between items-center">
                                            <p class="text-xs font-bold text-gray-400 uppercase">Next Due</p>
                                            <div class="text-right">
                                                <span class="font-semibold block"><?php echo date("d M, Y", strtotime($log['next_service_date'])); ?></span>
                                                <?php echo getStatusBadge($log['days_diff']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php 
                                    $invoice_paths = json_decode($log['invoice_doc_paths'] ?? '[]', true);
                                    if (!empty($invoice_paths)): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-100">
                                        <p class="text-xs font-bold text-gray-400 uppercase mb-2">Attachments</p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($invoice_paths as $index => $path): ?>
                                                <a href="<?php echo htmlspecialchars($path); ?>" target="_blank" class="inline-flex items-center px-2 py-1 bg-gray-50 border border-gray-200 rounded text-xs font-medium text-indigo-600 hover:bg-indigo-50">
                                                    <i class="fas fa-paperclip mr-1"></i> File <?php echo $index + 1; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                                    <a href="manage_maintenance.php?action=edit&id=<?php echo $log['id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-medium text-sm transition">Edit</a>
                                    <a href="manage_maintenance.php?action=delete&id=<?php echo $log['id']; ?>" class="text-red-600 hover:text-red-800 font-medium text-sm transition" onclick="return confirm('Delete this record permanently?');">Delete</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                        
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <span class="text-sm text-gray-600">
                            Showing page <span class="font-bold text-gray-800"><?php echo $page; ?></span> of <span class="font-bold text-gray-800"><?php echo $total_pages; ?></span>
                        </span>
                        <div class="flex gap-1">
                            <?php if($page > 1): ?>
                                <a href="?page=<?php echo $page-1; ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Previous</a>
                            <?php endif; ?>
                            <?php if($page < $total_pages): ?>
                                <a href="?page=<?php echo $page+1; ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Next</a>
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

        if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
        if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
        if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
        
        $('.searchable-select').select2({ width: '100%' });

        // Odometer Validation
        var last_odometer = 0;
        
        function fetchOdometer(vehicle_id) {
            if(vehicle_id) {
                // Pass exclude_id if in edit mode
                var exclude_id = <?php echo $edit_mode ? ($log_data['id'] ?? 0) : 0; ?>;
                $.get('get_vehicle_odometer.php?vehicle_id=' + vehicle_id + '&exclude_id=' + exclude_id, function(data) {
                    last_odometer = parseInt(data.last_odometer) || 0;
                    $('#odometer_reading').attr('min', last_odometer);
                    if(last_odometer > 0) {
                        $('#odometer_reading').attr('placeholder', 'Must be >= ' + last_odometer);
                    } else {
                        $('#odometer_reading').attr('placeholder', 'e.g., 125000');
                    }
                }, 'json');
            } else {
                last_odometer = 0;
                $('#odometer_reading').attr('min', 0);
                $('#odometer_reading').attr('placeholder', 'e.g., 125000');
            }
        }

        $('#vehicle_id').on('change', function() {
            fetchOdometer($(this).val());
        });
        
        // Trigger change on load if a vehicle is already selected (for edit mode)
        if ($('#vehicle_id').val()) {
             fetchOdometer($('#vehicle_id').val());
        }

        $('#odometer_reading').on('input', function() {
            var current_odometer = parseInt($(this).val()) || 0;
            if (current_odometer > 0 && current_odometer < last_odometer) {
                $('#odometer_warning').removeClass('hidden');
            } else {
                $('#odometer_warning').addClass('hidden');
            }
        });

        // Conditional Fields
        $('#service_type').on('change', function() {
            if ($(this).val() == 'Tyre Replacement') {
                $('#tyre_number_field').slideDown();
            } else {
                $('#tyre_number_field').slideUp();
            }
        });
        // Trigger on page load for edit mode
        $('#service_type').trigger('change');

    });
    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>