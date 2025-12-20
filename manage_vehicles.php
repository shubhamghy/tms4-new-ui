<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? '';
$can_manage = in_array($user_role, ['admin', 'manager']);
$is_admin = ($user_role === 'admin');

// --- Helper function for file uploads ---
function upload_vehicle_file($file_input_name, $vehicle_id, $existing_path = '') {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        $target_dir = "uploads/vehicles/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        
        if (!empty($existing_path) && file_exists($existing_path)) {
            @unlink($existing_path);
        }
        
        $file_ext = strtolower(pathinfo(basename($_FILES[$file_input_name]["name"]), PATHINFO_EXTENSION));
        $new_file_name = "vehicle_{$vehicle_id}_{$file_input_name}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;

        if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
            return $target_file;
        }
    }
    return $existing_path;
}

$form_message = "";
$edit_mode = false;
$add_mode = false;
$view_mode = false;
$vehicle_data = [];

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Use null coalescing for optional fields
    $registration_date = !empty($_POST['registration_date']) ? $_POST['registration_date'] : null;
    $fitness_expiry = !empty($_POST['fitness_expiry']) ? $_POST['fitness_expiry'] : null;
    $insurance_expiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $tax_expiry = !empty($_POST['tax_expiry']) ? $_POST['tax_expiry'] : null;
    $puc_expiry = !empty($_POST['puc_expiry']) ? $_POST['puc_expiry'] : null;
    $permit_expiry = !empty($_POST['permit_expiry']) ? $_POST['permit_expiry'] : null;
    $driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;

    if ($id > 0) { // Update
        $rc_doc_path = upload_vehicle_file('rc_doc_path', $id, $_POST['existing_rc_doc_path'] ?? '');
        $insurance_doc_path = upload_vehicle_file('insurance_doc_path', $id, $_POST['existing_insurance_doc_path'] ?? '');
        $permit_doc_path = upload_vehicle_file('permit_doc_path', $id, $_POST['existing_permit_doc_path'] ?? '');
        
        $sql = "UPDATE vehicles SET vehicle_number=?, vehicle_type=?, ownership_type=?, owner_name=?, owner_contact=?, driver_id=?, registration_date=?, fitness_expiry=?, insurance_expiry=?, tax_expiry=?, puc_expiry=?, permit_expiry=?, permit_details=?, rc_doc_path=?, insurance_doc_path=?, permit_doc_path=?, is_active=? WHERE id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssissssssssssii", $_POST['vehicle_number'], $_POST['vehicle_type'], $_POST['ownership_type'], $_POST['owner_name'], $_POST['owner_contact'], $driver_id, $registration_date, $fitness_expiry, $insurance_expiry, $tax_expiry, $puc_expiry, $permit_expiry, $_POST['permit_details'], $rc_doc_path, $insurance_doc_path, $permit_doc_path, $is_active, $id);
    } else { // Insert
        $sql = "INSERT INTO vehicles (vehicle_number, vehicle_type, ownership_type, owner_name, owner_contact, driver_id, registration_date, fitness_expiry, insurance_expiry, tax_expiry, puc_expiry, permit_expiry, permit_details, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssisssssssi", $_POST['vehicle_number'], $_POST['vehicle_type'], $_POST['ownership_type'], $_POST['owner_name'], $_POST['owner_contact'], $driver_id, $registration_date, $fitness_expiry, $insurance_expiry, $tax_expiry, $puc_expiry, $permit_expiry, $_POST['permit_details'], $is_active);
    }

    if ($stmt->execute()) {
        $vehicle_id = ($id > 0) ? $id : $stmt->insert_id;
        // Handle file uploads for new vehicle
        if ($id == 0) {
            $rc_doc_path = upload_vehicle_file('rc_doc_path', $vehicle_id);
            $insurance_doc_path = upload_vehicle_file('insurance_doc_path', $vehicle_id);
            $permit_doc_path = upload_vehicle_file('permit_doc_path', $vehicle_id);
            $mysqli->query("UPDATE vehicles SET rc_doc_path = '{$mysqli->real_escape_string($rc_doc_path)}', insurance_doc_path = '{$mysqli->real_escape_string($insurance_doc_path)}', permit_doc_path = '{$mysqli->real_escape_string($permit_doc_path)}' WHERE id = $vehicle_id");
        }
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Vehicle saved successfully!</div>';
        $add_mode = $edit_mode = false;
    } else {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}


// Handle GET requests
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif (($_GET['action'] == 'view' || $_GET['action'] == 'edit') && $id > 0) {
        $stmt = $mysqli->prepare("SELECT v.*, d.name as driver_name FROM vehicles v LEFT JOIN drivers d ON v.driver_id = d.id WHERE v.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $vehicle_data = $result->fetch_assoc();
            if ($_GET['action'] == 'view') { $view_mode = true; }
            if ($_GET['action'] == 'edit') { $edit_mode = true; }
        }
        $stmt->close();
    } elseif (($_GET['action'] == 'delete' || $_GET['action'] == 'reactivate') && $id > 0 && $is_admin) {
        $new_status = ($_GET['action'] == 'delete') ? 0 : 1;
        $action_word = ($new_status == 0) ? 'deactivated' : 'reactivated';
        $stmt = $mysqli->prepare("UPDATE vehicles SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $id);
        if($stmt->execute()){ 
            $form_message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Vehicle {$action_word} successfully.</div>"; 
        } else { 
            $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error updating vehicle status.</div>"; 
        }
        $stmt->close();
    }
}

// Data fetching for lists/dropdowns
$vehicles_list = [];
$drivers = $mysqli->query("SELECT id, name FROM drivers WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

if (!$add_mode && !$edit_mode && !$view_mode) {
    // --- Pagination Logic ---
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9; // Grid view
    $offset = ($page - 1) * $records_per_page;
    
    // --- Search Logic ---
    $search_term = trim($_GET['search'] ?? '');
    $where_sql = "";
    $params = [];
    $types = "";
    
    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        // Search by vehicle number, type, or owner name
        $where_sql = " WHERE (v.vehicle_number LIKE ? OR v.vehicle_type LIKE ? OR v.owner_name LIKE ?)";
        $params = [$like_term, $like_term, $like_term];
        $types = "sss";
    }

    // Get total records with filtering
    $count_sql = "SELECT COUNT(v.id) FROM vehicles v" . $where_sql;
    $stmt_count = $mysqli->prepare($count_sql);
    if (!empty($params)) { $stmt_count->bind_param($types, ...$params); }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    // Fetch paginated vehicles
    $list_sql = "SELECT v.*, d.name as driver_name FROM vehicles v LEFT JOIN drivers d ON v.driver_id = d.id" . $where_sql . " ORDER BY v.id DESC LIMIT ? OFFSET ?";
    $params[] = $records_per_page; $types .= "i";
    $params[] = $offset; $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    if(!empty($types)) {
        // Use call_user_func_array for robust dynamic binding
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    }
    $stmt_list->execute();
    $vehicles_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vehicles - TMS</title>
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
                                <i class="fas fa-truck-moving opacity-80"></i> Manage Vehicles
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

                <?php if ($view_mode && $vehicle_data): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-4xl mx-auto">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($vehicle_data['vehicle_number'] ?? ''); ?></h2>
                        <a href="manage_vehicles.php" class="py-2 px-4 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"><i class="fas fa-arrow-left mr-2"></i>Back</a>
                    </div>
                    <div class="p-6">
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                            <div class="bg-indigo-50 p-4 rounded-lg">
                                <p class="text-xs font-bold text-gray-500 uppercase mb-1">Type</p>
                                <p class="font-bold text-indigo-900 text-lg"><?php echo htmlspecialchars($vehicle_data['vehicle_type'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="bg-indigo-50 p-4 rounded-lg">
                                <p class="text-xs font-bold text-gray-500 uppercase mb-1">Ownership</p>
                                <p class="font-bold text-indigo-900 text-lg"><?php echo htmlspecialchars($vehicle_data['ownership_type'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <p class="text-xs font-bold text-gray-400 uppercase mb-1">Owner Name</p>
                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($vehicle_data['owner_name'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <p class="text-xs font-bold text-gray-400 uppercase mb-1">Owner Contact</p>
                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($vehicle_data['owner_contact'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <p class="text-xs font-bold text-gray-400 uppercase mb-1">Assigned Driver</p>
                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($vehicle_data['driver_name'] ?? 'None'); ?></p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <p class="text-xs font-bold text-gray-400 uppercase mb-1">Registration Date</p>
                                <p class="font-medium text-gray-800"><?php echo $vehicle_data['registration_date'] ? date("d-m-Y", strtotime($vehicle_data['registration_date'])) : 'N/A'; ?></p>
                            </div>
                        </div>
                        
                        <h3 class="text-sm font-bold text-gray-800 uppercase mt-8 mb-4 border-b pb-2">Document Expiry & Files</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <?php 
                                $docs = [
                                    'Fitness' => ['date' => $vehicle_data['fitness_expiry'], 'file' => ''],
                                    'Insurance' => ['date' => $vehicle_data['insurance_expiry'], 'file' => $vehicle_data['insurance_doc_path']],
                                    'Permit' => ['date' => $vehicle_data['permit_expiry'], 'file' => $vehicle_data['permit_doc_path']],
                                    'Tax' => ['date' => $vehicle_data['tax_expiry'], 'file' => ''],
                                    'PUC' => ['date' => $vehicle_data['puc_expiry'], 'file' => ''],
                                    'RC' => ['date' => '', 'file' => $vehicle_data['rc_doc_path']]
                                ];
                                foreach($docs as $label => $data):
                                    $is_expired = !empty($data['date']) && strtotime($data['date']) < time();
                                    $date_display = !empty($data['date']) ? date("d-m-Y", strtotime($data['date'])) : 'N/A';
                                    $status_color = $is_expired ? 'text-red-600 bg-red-50' : 'text-gray-700 bg-gray-50';
                            ?>
                            <div class="p-3 rounded-lg border border-gray-100 <?php echo $status_color; ?>">
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-xs font-bold uppercase"><?php echo $label; ?></span>
                                    <?php if(!empty($data['file'])): ?>
                                        <a href="<?php echo htmlspecialchars($data['file']); ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800"><i class="fas fa-file-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                                <p class="font-medium"><?php echo $date_display; ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php elseif ($add_mode || $edit_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-edit text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Vehicle' : 'Add New Vehicle'; ?>
                        </h2>
                        <a href="manage_vehicles.php" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="p-6 md:p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $vehicle_data['id'] ?? ''; ?>">
                        
                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Vehicle & Owner Info</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Vehicle Number <span class="text-red-500">*</span></label><input type="text" name="vehicle_number" value="<?php echo htmlspecialchars($vehicle_data['vehicle_number'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Vehicle Type</label><input type="text" name="vehicle_type" value="<?php echo htmlspecialchars($vehicle_data['vehicle_type'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Ownership</label>
                                    <div class="flex space-x-4">
                                        <label class="flex items-center"><input type="radio" name="ownership_type" value="Owned" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300" <?php if (($vehicle_data['ownership_type'] ?? 'Hired') == 'Owned') echo 'checked'; ?>><span class="ml-2 text-sm text-gray-700">Owned</span></label>
                                        <label class="flex items-center"><input type="radio" name="ownership_type" value="Hired" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300" <?php if (($vehicle_data['ownership_type'] ?? 'Hired') == 'Hired') echo 'checked'; ?>><span class="ml-2 text-sm text-gray-700">Hired</span></label>
                                    </div>
                                </div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Owner Name</label><input type="text" name="owner_name" value="<?php echo htmlspecialchars($vehicle_data['owner_name'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Owner Contact</label><input type="text" name="owner_contact" value="<?php echo htmlspecialchars($vehicle_data['owner_contact'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Assigned Driver</label><select name="driver_id" class="searchable-select block w-full"><option value="">Select Driver</option><?php foreach($drivers as $d): ?><option value="<?php echo $d['id']; ?>" <?php if(($vehicle_data['driver_id'] ?? '') == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?></select></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Document Expiry</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Registration Date</label><input type="date" name="registration_date" value="<?php echo htmlspecialchars($vehicle_data['registration_date'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Insurance Expiry</label><input type="date" name="insurance_expiry" value="<?php echo htmlspecialchars($vehicle_data['insurance_expiry'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Tax Expiry</label><input type="date" name="tax_expiry" value="<?php echo htmlspecialchars($vehicle_data['tax_expiry'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Fitness Expiry</label><input type="date" name="fitness_expiry" value="<?php echo htmlspecialchars($vehicle_data['fitness_expiry'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Permit Expiry</label><input type="date" name="permit_expiry" value="<?php echo htmlspecialchars($vehicle_data['permit_expiry'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PUC Expiry</label><input type="date" name="puc_expiry" value="<?php echo htmlspecialchars($vehicle_data['puc_expiry'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div class="md:col-span-3"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Permit Details</label><textarea name="permit_details" rows="2" class="block w-full border-gray-300 rounded-lg text-sm"><?php echo htmlspecialchars($vehicle_data['permit_details'] ?? ''); ?></textarea></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Upload Documents</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">RC Document</label><input type="file" name="rc_doc" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_rc_doc_path" value="<?php echo htmlspecialchars($vehicle_data['rc_doc_path'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Insurance Doc</label><input type="file" name="insurance_doc" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_insurance_doc_path" value="<?php echo htmlspecialchars($vehicle_data['insurance_doc_path'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Permit Doc</label><input type="file" name="permit_doc" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_permit_doc_path" value="<?php echo htmlspecialchars($vehicle_data['permit_doc_path'] ?? ''); ?>"></div>
                            </div>
                        </fieldset>
                        
                        <div class="flex items-center"><input type="checkbox" name="is_active" value="1" <?php if($vehicle_data['is_active'] ?? 1) echo 'checked'; ?> class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label class="ml-2 block text-sm font-bold text-gray-700">Is Active</label></div>
                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> Save Vehicle
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                
                <div class="space-y-6">
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <form method="GET" class="w-full md:w-auto flex-1 flex gap-2">
                            <div class="relative w-full md:max-w-md">
                                <input type="text" name="search" placeholder="Search vehicle no, type, owner..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                            </div>
                            <button type="submit" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition"><i class="fas fa-arrow-right"></i></button>
                            <a href="manage_vehicles.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 shadow-sm transition">Reset</a>
                        </form>
                        <a href="manage_vehicles.php?action=add" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Add Vehicle
                        </a>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($vehicles_list as $vehicle): ?>
                        <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                            <div class="p-6 flex-grow">
                                <div class="flex justify-between items-start mb-4">
                                    <h3 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></h3>
                                    <div class="flex items-center space-x-2">
                                        <span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full <?php echo $vehicle['ownership_type'] == 'Owned' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800'; ?>"><?php echo htmlspecialchars($vehicle['ownership_type']); ?></span>
                                        <?php echo $vehicle['is_active'] ? '<span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full bg-green-100 text-green-800">Active</span>' : '<span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full bg-red-100 text-red-800">Inactive</span>'; ?>
                                    </div>
                                </div>
                                
                                <p class="text-sm text-indigo-600 font-medium mb-3"><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></p>
                                
                                <div class="space-y-2 text-sm text-gray-600">
                                    <p class="flex items-center"><i class="fas fa-user-tie w-5 text-gray-400"></i> Owner: <span class="font-medium ml-1"><?php echo htmlspecialchars($vehicle['owner_name']); ?></span></p>
                                    <p class="flex items-center"><i class="fas fa-id-card w-5 text-gray-400"></i> Driver: <span class="font-medium ml-1"><?php echo htmlspecialchars($vehicle['driver_name'] ?? 'N/A'); ?></span></p>
                                </div>

                                <?php 
                                    $expiries = ['Insurance' => 'insurance_expiry', 'Tax' => 'tax_expiry', 'Fitness' => 'fitness_expiry', 'Permit' => 'permit_expiry'];
                                    $expiring_docs = [];
                                    $expiring_soon = strtotime('+30 days');
                                    foreach($expiries as $doc => $col) {
                                        if (!empty($vehicle[$col]) && strtotime($vehicle[$col]) < $expiring_soon) {
                                            $expiring_docs[] = $doc;
                                        }
                                    }
                                    if (!empty($expiring_docs)): 
                                ?>
                                <div class="mt-4 p-2 bg-red-50 rounded border border-red-100 text-xs text-red-700 font-semibold">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Expiring: <?php echo implode(', ', $expiring_docs); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                <a href="?action=view&id=<?php echo $vehicle['id']; ?>" class="text-sm font-bold text-gray-600 hover:text-indigo-600 transition">Details</a>
                                <a href="?action=edit&id=<?php echo $vehicle['id']; ?>" class="text-sm font-bold text-indigo-600 hover:text-indigo-800 transition">Edit</a>
                                <?php if($is_admin): ?>
                                    <?php if ($vehicle['is_active']): ?>
                                        <a href="?action=delete&id=<?php echo $vehicle['id']; ?>" onclick="return confirm('Deactivate this vehicle?')" class="text-sm font-bold text-red-600 hover:text-red-800 transition">Deactivate</a>
                                    <?php else: ?>
                                        <a href="?action=reactivate&id=<?php echo $vehicle['id']; ?>" onclick="return confirm('Reactivate this vehicle?')" class="text-sm font-bold text-yellow-600 hover:text-yellow-800 transition">Reactivate</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
    });

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>