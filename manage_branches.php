<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin') {
    header("location: dashboard.php");
    exit;
}

// Helper functions (upload_branch_file, getDocumentThumbnail) remain the same...
function upload_branch_file($file_input_name, $branch_id, $existing_path = '') {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        $target_dir = "uploads/branches/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        if (!empty($existing_path) && file_exists($existing_path)) { @unlink($existing_path); }
        $file_ext = strtolower(pathinfo(basename($_FILES[$file_input_name]["name"]), PATHINFO_EXTENSION));
        $new_file_name = "branch_{$branch_id}_{$file_input_name}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;
        if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
            return $target_file;
        }
    }
    return $existing_path;
}

function getDocumentThumbnail($path, $label, $icon_class = 'fa-file-alt') {
    if (!empty($path) && file_exists($path)) {
        $file_info = pathinfo($path);
        $is_image = in_array(strtolower($file_info['extension']), ['jpg', 'jpeg', 'png', 'gif']);
        $html = '<div class="group relative block w-full rounded-lg border border-gray-300 p-2 text-center hover:border-indigo-400 bg-gray-50 hover:bg-indigo-50 transition">';
        $html .= '<p class="text-xs font-bold text-gray-500 uppercase mb-2">'.$label.'</p>';
        if ($is_image) {
            $html .= '<a href="'.htmlspecialchars($path).'" target="_blank"><img src="'.htmlspecialchars($path).'" alt="'.$label.'" class="h-20 w-full object-contain rounded mb-1"></a>';
        } else {
            $html .= '<a href="'.htmlspecialchars($path).'" target="_blank" class="flex flex-col items-center justify-center py-4"><i class="fas '.$icon_class.' text-3xl text-indigo-500 mb-1"></i><span class="text-xs text-indigo-600 font-medium">View File</span></a>';
        }
        $html .= '</div>';
        return $html;
    }
    return '<div class="rounded-lg border border-dashed border-gray-300 p-4 text-center bg-gray-50"><p class="text-xs font-bold text-gray-400 uppercase mb-1">'.$label.'</p><span class="text-xs text-gray-400 italic">Not Uploaded</span></div>';
}

$form_message = "";
$edit_mode = false;
$view_mode = false;
$add_mode = false;
$branch_data = ['id' => '', 'name' => '', 'address' => '', 'city' => '', 'state' => '', 'country' => '', 'contact_number' => '', 'contact_number_2' => '', 'email' => '', 'website' => '', 'gst_no' => '', 'food_license_no' => '', 'trade_license_path' => '', 'is_active' => 1];
$associated_users = [];

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $country_id = intval($_POST['country'] ?? 0);
    $state_id = intval($_POST['state'] ?? 0);
    $city_id = intval($_POST['city'] ?? 0);
    
    $country_name = '';
    if ($country_id > 0) { $country_name = $mysqli->query("SELECT name FROM countries WHERE id = $country_id")->fetch_assoc()['name'] ?? ''; }
    $state_name = '';
    if ($state_id > 0) { $state_name = $mysqli->query("SELECT name FROM states WHERE id = $state_id")->fetch_assoc()['name'] ?? ''; }
    $city_name = '';
    if ($city_id > 0) { $city_name = $mysqli->query("SELECT name FROM cities WHERE id = $city_id")->fetch_assoc()['name'] ?? ''; }

    if ($id > 0) { // Update
        $trade_license_path = upload_branch_file('trade_license_doc', $id, $_POST['existing_trade_license_path']);
        $sql = "UPDATE branches SET name=?, address=?, city=?, state=?, country=?, contact_number=?, contact_number_2=?, email=?, website=?, gst_no=?, food_license_no=?, trade_license_path=?, is_active=? WHERE id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssssssssssssii", $name, $_POST['address'], $city_name, $state_name, $country_name, $_POST['contact_number'], $_POST['contact_number_2'], $_POST['email'], $_POST['website'], $_POST['gst_no'], $_POST['food_license_no'], $trade_license_path, $is_active, $id);
    } else { // Insert
        $sql = "INSERT INTO branches (name, address, city, state, country, contact_number, contact_number_2, email, website, gst_no, food_license_no, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssssssssi", $name, $_POST['address'], $city_name, $state_name, $country_name, $_POST['contact_number'], $_POST['contact_number_2'], $_POST['email'], $_POST['website'], $_POST['gst_no'], $_POST['food_license_no'], $is_active);
    }

    if ($stmt->execute()) {
        $branch_id = ($id > 0) ? $id : $stmt->insert_id;
        if ($id == 0) {
            $trade_license_path = upload_branch_file('trade_license_doc', $branch_id);
            if ($trade_license_path) {
                $mysqli->query("UPDATE branches SET trade_license_path = '{$mysqli->real_escape_string($trade_license_path)}' WHERE id = $branch_id");
            }
        }
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Branch saved successfully!</div>';
        $add_mode = $edit_mode = false;
    } else {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error saving branch.</div>';
    }
    $stmt->close();
}

// Handle GET requests
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif ($_GET['action'] == 'view' && $id > 0) {
        $view_mode = true;
        $stmt = $mysqli->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) { $branch_data = $result->fetch_assoc(); }
        $stmt->close();
        
        $stmt_users = $mysqli->prepare("SELECT username, email, role FROM users WHERE branch_id = ?");
        $stmt_users->bind_param("i", $id);
        $stmt_users->execute();
        $associated_users = $stmt_users->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_users->close();
    } elseif ($_GET['action'] == 'edit' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $branch_data = $result->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    } elseif (($_GET['action'] == 'delete' || $_GET['action'] == 'reactivate') && $id > 0) {
        $new_status = ($_GET['action'] == 'delete') ? 0 : 1;
        $action_word = ($new_status == 0) ? 'deactivated' : 'reactivated';
        $stmt = $mysqli->prepare("UPDATE branches SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $id);
        if($stmt->execute()){ $form_message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Branch {$action_word} successfully.</div>"; }
        else { $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error: Cannot deactivate a branch with active users. Please reassign users first.</div>"; }
        $stmt->close();
    }
}

// Data fetching for lists/dropdowns
$branches_list = [];
$countries = [];
if ($add_mode || $edit_mode) {
    $countries = $mysqli->query("SELECT * FROM countries ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
} elseif (!$view_mode) {
    // --- CORRECTED DATA FETCHING LOGIC ---
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9; // Grid view
    $offset = ($page - 1) * $records_per_page;
    
    $search_term = trim($_GET['search'] ?? '');
    $where_sql = "";
    $params = [];
    $types = "";
    
    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_sql = " WHERE (name LIKE ? OR city LIKE ? OR state LIKE ? OR gst_no LIKE ?)";
        $params = [$like_term, $like_term, $like_term, $like_term];
        $types = "ssss";
    }

    // Get total records with filtering
    $total_records_sql = "SELECT COUNT(*) FROM branches" . $where_sql;
    $stmt_count = $mysqli->prepare($total_records_sql);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);
    
    // Fetch branches for the current page
    $list_sql = "SELECT * FROM branches" . $where_sql . " ORDER BY name ASC LIMIT ? OFFSET ?";
    
    $params[] = $records_per_page;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    
    // Use call_user_func_array for robust binding
    if (!empty($types)) {
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    }
    
    $stmt_list->execute();
    $result = $stmt_list->get_result();
    if ($result) {
        $branches_list = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_list->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Branches - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        @media print {
            body * { visibility: hidden; } .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; } .no-print { display: none; }
        }
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
    </style>
</head>
<body class="bg-gray-50 h-screen w-screen overflow-hidden">
    <div id="page-loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
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
                                <i class="fas fa-network-wired opacity-80"></i> Manage Branches
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

                <?php if ($view_mode): ?>
                
                 <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-4xl mx-auto print-area">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center no-print">
                        <h2 class="text-xl font-bold text-gray-800">Branch Details</h2>
                        <div class="flex gap-2">
                            <button onclick="window.print()" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-print mr-2"></i> Print</button>
                            <a href="manage_branches.php" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                        </div>
                    </div>
                    <div class="p-8">
                        <div class="flex items-center mb-8 pb-6 border-b border-gray-100">
                            <div class="h-16 w-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-2xl font-bold mr-4 uppercase">
                                <?php echo substr($branch_data['name'], 0, 1); ?>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($branch_data['name']); ?></h1>
                                <p class="text-sm text-gray-500 flex items-center mt-1">
                                    <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($branch_data['city'] . ', ' . $branch_data['state']); ?>
                                </p>
                            </div>
                            <div class="ml-auto text-right">
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $branch_data['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $branch_data['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Contact Information</h3>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between"><dt class="text-gray-500">Phone 1:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['contact_number'] ?: 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Phone 2:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['contact_number_2'] ?: 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Email:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['email'] ?: 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Website:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['website'] ?: 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Address:</dt><dd class="font-medium text-right max-w-xs"><?php echo nl2br(htmlspecialchars($branch_data['address'] ?? 'N/A')); ?></dd></div>
                                </dl>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Registration & Documents</h3>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between"><dt class="text-gray-500">GST No:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['gst_no'] ?: 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">FSSAI No:</dt><dd class="font-medium"><?php echo htmlspecialchars($branch_data['food_license_no'] ?: 'N/A'); ?></dd></div>
                                </dl>
                                <div class="mt-4">
                                     <?php echo getDocumentThumbnail($branch_data['trade_license_path'], 'Trade License', 'fa-file-contract'); ?>
                                </div>
                            </div>
                        </div>

                        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Associated Users</h3>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Username</th><th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Email</th><th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Role</th></tr></thead>
                                <tbody class="bg-white divide-y divide-gray-200"><?php foreach ($associated_users as $user): ?><tr><td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td></tr><?php endforeach; if(empty($associated_users)): ?><tr><td colspan="3" class="text-center py-4 text-gray-400 italic">No users assigned.</td></tr><?php endif; ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php elseif ($add_mode || $edit_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-8 py-5 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-building text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Branch' : 'Register New Branch'; ?>
                        </h2>
                        <a href="manage_branches.php" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition uppercase tracking-wide"><i class="fas fa-times mr-1"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $branch_data['id']; ?>">
                        <input type="hidden" name="existing_trade_license_path" value="<?php echo htmlspecialchars($branch_data['trade_license_path'] ?? ''); ?>">
                        
                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Basic Information</legend>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 pt-2">
                                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Branch Name <span class="text-red-500">*</span></label><input type="text" name="name" value="<?php echo htmlspecialchars($branch_data['name']); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Country</label><select name="country" id="country" class="searchable-select block w-full"><option value="">Select...</option><?php foreach($countries as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">State</label><select name="state" id="state" class="searchable-select block w-full"></select></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">City</label><select name="city" id="city" class="searchable-select block w-full"></select></div>
                                <div class="md:col-span-4"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Full Address</label><textarea name="address" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"><?php echo htmlspecialchars($branch_data['address']); ?></textarea></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Contact Details</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Primary Phone</label><input type="text" name="contact_number" value="<?php echo htmlspecialchars($branch_data['contact_number']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Secondary Phone</label><input type="text" name="contact_number_2" value="<?php echo htmlspecialchars($branch_data['contact_number_2']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email Address</label><input type="email" name="email" value="<?php echo htmlspecialchars($branch_data['email']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Website</label><input type="text" name="website" value="<?php echo htmlspecialchars($branch_data['website']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Licenses & Documents</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">GST Number</label><input type="text" name="gst_no" value="<?php echo htmlspecialchars($branch_data['gst_no']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">FSSAI Number</label><input type="text" name="food_license_no" value="<?php echo htmlspecialchars($branch_data['food_license_no']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Trade License Doc</label><input type="file" name="trade_license_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"></div>
                            </div>
                        </fieldset>

                        <div class="flex items-center pt-2"><input type="checkbox" name="is_active" value="1" <?php if($branch_data['is_active']) echo 'checked'; ?> class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label class="ml-2 block text-sm font-bold text-gray-700">Branch is Active</label></div>
                        
                        <div class="flex justify-end pt-6 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-8 py-3 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Branch' : 'Save Branch'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div class="w-full md:w-auto flex-1 flex flex-col md:flex-row gap-4">
                            <h2 class="text-2xl font-bold text-gray-800">Branch Network</h2>
                            <form method="GET" class="relative w-full md:max-w-md group">
                                <input type="text" name="search" placeholder="Search by name, city, gst..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 group-hover:bg-white transition-colors">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-hover:text-indigo-500 transition-colors"><i class="fas fa-search"></i></div>
                            </form>
                        </div>
                        <div class="flex gap-2">
                            <?php if(!empty($search_term)): ?>
                                <a href="manage_branches.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-xl text-gray-600 font-bold text-sm hover:bg-gray-50 transition">Reset</a>
                            <?php endif; ?>
                            <a href="manage_branches.php?action=add" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-md hover:bg-indigo-700 hover:shadow-lg transition transform hover:-translate-y-0.5">
                                <i class="fas fa-plus mr-2"></i> Add Branch
                            </a>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($branches_list as $branch): ?>
                        <div class="bg-white rounded-2xl shadow-sm hover:shadow-lg border border-gray-100 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-indigo-500 to-blue-600"></div>
                            <div class="p-6 flex-grow">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-lg uppercase">
                                            <?php echo substr($branch['name'], 0, 1); ?>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-900 leading-tight"><?php echo htmlspecialchars($branch['name']); ?></h3>
                                            <span class="text-xs text-gray-500 flex items-center mt-1"><i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($branch['city'] . ', ' . $branch['state']); ?></span>
                                        </div>
                                    </div>
                                    <?php echo $branch['is_active'] ? '<span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-50 text-green-700 border border-green-100">Active</span>' : '<span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-100">Inactive</span>'; ?>
                                </div>
                                <div class="space-y-2 text-sm text-gray-600 mt-4 border-t border-gray-50 pt-4">
                                    <p class="flex items-center"><i class="fas fa-phone w-5 text-gray-400"></i> <?php echo htmlspecialchars($branch['contact_number'] ?: 'N/A'); ?></p>
                                    <p class="flex items-center"><i class="fas fa-envelope w-5 text-gray-400"></i> <?php echo htmlspecialchars($branch['email'] ?: 'N/A'); ?></p>
                                </div>
                            </div>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                                <a href="manage_branches.php?action=view&id=<?php echo $branch['id']; ?>" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition">View Details</a>
                                <div class="flex gap-3">
                                    <a href="manage_branches.php?action=edit&id=<?php echo $branch['id']; ?>" class="text-indigo-600 hover:text-indigo-800 transition" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if ($branch['is_active']): ?>
                                        <a href="manage_branches.php?action=delete&id=<?php echo $branch['id']; ?>" onclick="return confirm('Deactivate this branch?');" class="text-red-500 hover:text-red-700 transition" title="Deactivate"><i class="fas fa-ban"></i></a>
                                    <?php else: ?>
                                        <a href="manage_branches.php?action=reactivate&id=<?php echo $branch['id']; ?>" onclick="return confirm('Reactivate this branch?');" class="text-green-600 hover:text-green-800 transition" title="Reactivate"><i class="fas fa-check-circle"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <span class="text-sm font-medium text-gray-500">
                            Showing <span class="font-bold text-gray-800"><?php echo $total_records > 0 ? ($offset + 1) : 0; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $records_per_page, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> branches
                        </span>
                        <div class="flex gap-2">
                            <?php 
                                $query_params = $_GET; 
                                unset($query_params['page']); 
                                $base_url = '?' . http_build_query($query_params); 
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $base_url . '&page=' . ($page - 1); ?>" class="px-4 py-2 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Previous</a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo $base_url . '&page=' . ($page + 1); ?>" class="px-4 py-2 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">Next</a>
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
    // --- Mobile Sidebar Toggle ---
    document.addEventListener('DOMContentLoaded', () => {
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
        
        // --- Location Dropdown Logic ---
        $('.searchable-select').select2({ width: '100%' });
        const branchData = <?php echo json_encode($branch_data); ?>;

        async function fetchStates(countryId, selectedStateName = '') {
            $('#state').html('<option value="">Loading...</option>').prop('disabled', true);
            $('#city').html('<option value="">Select State First</option>').prop('disabled', true).trigger('change.select2');
            if (!countryId) {
                $('#state').html('<option value="">Select Country First</option>').prop('disabled',false).trigger('change.select2');
                return;
            }
            try {
                const response = await fetch(`get_locations.php?get=states&country_id=${countryId}`);
                const states = await response.json();
                $('#state').html('<option value="">Select State</option>').prop('disabled', false);
                let selectedStateId = null;
                states.forEach(state => {
                    const option = new Option(state.name, state.id);
                    $('#state').append(option);
                    if (state.name === selectedStateName) { selectedStateId = state.id; }
                });
                if(selectedStateId) { $('#state').val(selectedStateId).trigger('change'); } 
                else { $('#state').trigger('change.select2'); }
            } catch (error) { console.error("Error fetching states:", error); }
        }

        async function fetchCities(stateId, selectedCityName = '') {
            $('#city').html('<option value="">Loading...</option>').prop('disabled', true);
            if (!stateId) {
                $('#city').html('<option value="">Select State First</option>').prop('disabled',false).trigger('change.select2');
                return;
            }
            try {
                const response = await fetch(`get_locations.php?get=cities&state_id=${stateId}`);
                const cities = await response.json();
                $('#city').html('<option value="">Select City</option>').prop('disabled', false);
                let selectedCityId = null;
                cities.forEach(city => {
                    const option = new Option(city.name, city.id);
                    $('#city').append(option);
                    if (city.name === selectedCityName) { selectedCityId = city.id; }
                });
                if(selectedCityId) { $('#city').val(selectedCityId).trigger('change.select2'); } 
                else { $('#city').trigger('change.select2'); }
            } catch (error) { console.error("Error fetching cities:", error); }
        }

        $('#country').on('change', function() { fetchStates($(this).val()); });
        $('#state').on('change', function() {
            const targetCity = (branchData.state === $('#state option:selected').text()) ? branchData.city : '';
            fetchCities($(this).val(), targetCity);
        });
        
        if (branchData && branchData.id && branchData.country) {
            let initialCountryId = Array.from(document.getElementById('country').options).find(opt => opt.text === branchData.country)?.value;
            if (initialCountryId) {
                 $('#country').val(initialCountryId).trigger('change.select2');
                 fetchStates(initialCountryId, branchData.state);
            }
        } else {
            $('#country').val('1').trigger('change'); // Default to India if new
        }
    });

    window.onload = function() {
        const loader = document.getElementById('page-loader');
        if (loader) { loader.style.display = 'none'; }
    };
    </script>
</body>
</html>