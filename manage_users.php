<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? '';
// IMPORTANT: Only admins can manage users
if ($user_role !== 'admin') {
    header("location: dashboard.php");
    exit;
}

// --- Helper function for file uploads ---
function upload_user_file($file_input_name, $user_id, $existing_path = '') {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        $target_dir = "uploads/users/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        
        if (!empty($existing_path) && file_exists($existing_path)) {
            @unlink($existing_path);
        }
        
        $file_ext = strtolower(pathinfo(basename($_FILES[$file_input_name]["name"]), PATHINFO_EXTENSION));
        $new_file_name = "user_{$user_id}_{$file_input_name}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;

        if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
            return $target_file;
        }
    }
    return $existing_path;
}

// --- Helper for Document Thumbnails ---
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
$user_data = ['id' => '', 'username' => '', 'email' => '', 'role' => 'staff', 'branch_id' => '', 'address' => '', 'pan_no' => '', 'aadhaar_no' => '', 'is_active' => 1, 'photo_path' => '', 'pan_doc_path' => '', 'aadhaar_doc_path' => ''];

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    
    // Server-side validation for duplicates
    $is_duplicate = false;
    $duplicate_error_message = '';

    $stmt_check = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
    $stmt_check->bind_param("s", $username);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    if ($result_check->num_rows > 0) {
        $existing_user = $result_check->fetch_assoc();
        if ($existing_user['id'] != $id) {
            $is_duplicate = true;
            $duplicate_error_message = "This username is already taken.";
        }
    }
    $stmt_check->close();

    if (!$is_duplicate) {
        $stmt_check = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows > 0) {
            $existing_user = $result_check->fetch_assoc();
            if ($existing_user['id'] != $id) {
                $is_duplicate = true;
                $duplicate_error_message = "This email address is already registered.";
            }
        }
        $stmt_check->close();
    }

    if ($is_duplicate) {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $duplicate_error_message . '</div>';
        $user_data = $_POST;
        $user_data['id'] = $id;
        if ($id > 0) { $edit_mode = true; } else { $add_mode = true; }
    } else {
        // Proceed with saving
        $role = trim($_POST['role']);
        $branch_id = intval($_POST['branch_id']);
        $password = $_POST['password'];
        $address = trim($_POST['address']);
        $pan_no = trim($_POST['pan_no']);
        $aadhaar_no = trim($_POST['aadhaar_no']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id > 0) { // Update
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET username=?, email=?, role=?, branch_id=?, password=?, address=?, pan_no=?, aadhaar_no=?, is_active=? WHERE id=?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssissssii", $username, $email, $role, $branch_id, $hashed_password, $address, $pan_no, $aadhaar_no, $is_active, $id);
            } else {
                $sql = "UPDATE users SET username=?, email=?, role=?, branch_id=?, address=?, pan_no=?, aadhaar_no=?, is_active=? WHERE id=?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssisssii", $username, $email, $role, $branch_id, $address, $pan_no, $aadhaar_no, $is_active, $id);
            }
        } else { // Insert
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (username, password, email, role, branch_id, address, pan_no, aadhaar_no, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssisssi", $username, $hashed_password, $email, $role, $branch_id, $address, $pan_no, $aadhaar_no, $is_active);
        }

        if ($stmt->execute()) {
            $user_id = ($id > 0) ? $id : $stmt->insert_id;
            
            $photo_path = upload_user_file('photo', $user_id, $_POST['existing_photo_path'] ?? '');
            $pan_doc_path = upload_user_file('pan_doc', $user_id, $_POST['existing_pan_doc_path'] ?? '');
            $aadhaar_doc_path = upload_user_file('aadhaar_doc', $user_id, $_POST['existing_aadhaar_doc_path'] ?? '');

            $update_paths_sql = "UPDATE users SET photo_path=?, pan_doc_path=?, aadhaar_doc_path=? WHERE id=?";
            $update_stmt = $mysqli->prepare($update_paths_sql);
            $update_stmt->bind_param("sssi", $photo_path, $pan_doc_path, $aadhaar_doc_path, $user_id);
            $update_stmt->execute();
            $update_stmt->close();

            $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> User saved successfully!</div>';
            $add_mode = $edit_mode = false;
        } else {
            $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}


// Handle GET requests
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif ($_GET['action'] == 'view' && $id > 0) {
        $view_mode = true;
        $stmt = $mysqli->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) { $user_data = $result->fetch_assoc(); }
        $stmt->close();
    }
    elseif ($_GET['action'] == 'edit' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $user_data = $result->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    } elseif (($_GET['action'] == 'delete' || $_GET['action'] == 'reactivate') && $id > 0) {
        if ($id == 1) {
            $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-ban mr-2'></i> Cannot change the status of the main admin user.</div>";
        } else {
            $new_status = ($_GET['action'] == 'delete') ? 0 : 1;
            $action_word = ($new_status == 0) ? 'deactivated' : 'reactivated';
            $stmt = $mysqli->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $id);
            if($stmt->execute()){ $form_message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> User {$action_word} successfully.</div>"; }
            else { $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error updating user status.</div>"; }
            $stmt->close();
        }
    }
}

// Fetch users for the list view
$users_list = [];
if (!$edit_mode && !$add_mode && !$view_mode) {
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9;
    $offset = ($page - 1) * $records_per_page;
    
    // --- SEARCH LOGIC ---
    $search_term = trim($_GET['search'] ?? '');
    $where_clauses = [];
    $params = [];
    $types = "";
    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_clauses[] = "(u.username LIKE ? OR u.email LIKE ? OR b.name LIKE ?)";
        array_push($params, $like_term, $like_term, $like_term);
        $types .= "sss";
    }
    $where_sql = count($where_clauses) > 0 ? " WHERE " . implode(" AND ", $where_clauses) : "";

    // Get total records with filtering
    $total_records_sql = "SELECT COUNT(u.id) FROM users u LEFT JOIN branches b ON u.branch_id = b.id" . $where_sql;
    $stmt_count = $mysqli->prepare($total_records_sql);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    // Fetch users for the current page
    $sql = "SELECT u.id, u.username, u.email, u.role, u.is_active, u.photo_path, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id" . $where_sql . " ORDER BY u.username ASC LIMIT ? OFFSET ?";
    $params[] = $records_per_page;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";
    
    $stmt_list = $mysqli->prepare($sql);
    $stmt_list->bind_param($types, ...$params);
    $stmt_list->execute();
    $users_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
}
$branches = $mysqli->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        @media print {
            body * { visibility: hidden; } .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; } .no-print { display: none; }
        }
        [x-cloak] { display: none; } 
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
                                <i class="fas fa-users-cog opacity-80"></i> Manage Users
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
                        <h2 class="text-xl font-bold text-gray-800">User Profile</h2>
                        <div class="flex gap-2">
                            <button onclick="window.print()" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-print mr-2"></i> Print</button>
                            <a href="manage_users.php" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                        </div>
                    </div>
                    
                    <div class="p-8">
                        <div class="flex flex-col items-center justify-center mb-8 pb-6 border-b border-gray-100">
                            <img src="<?php echo htmlspecialchars($user_data['photo_path'] ?: 'https://placehold.co/200x200/e2e8f0/e2e8f0?text=No+Img'); ?>" alt="User Photo" class="w-32 h-32 rounded-full object-cover ring-4 ring-indigo-50 shadow-md mb-4">
                            <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($user_data['username']); ?></h2>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($user_data['email']); ?></p>
                            <div class="mt-4 flex gap-2">
                                <span class="px-3 py-1 text-xs font-bold uppercase tracking-wider rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars(ucfirst($user_data['role'])); ?></span>
                                <span class="px-3 py-1 text-xs font-bold uppercase tracking-wider rounded-full <?php echo $user_data['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $user_data['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8" x-data="{ activeTab: 'details' }">
                            <div class="md:col-span-2 bg-gray-50 rounded-lg p-1 mb-4 flex justify-center no-print">
                                <nav class="flex space-x-2" aria-label="Tabs">
                                    <button @click="activeTab = 'details'" :class="{'bg-white text-gray-900 shadow-sm': activeTab === 'details', 'text-gray-500 hover:text-gray-700': activeTab !== 'details'}" class="px-4 py-2 rounded-md text-sm font-medium transition-all">Details</button>
                                    <button @click="activeTab = 'documents'" :class="{'bg-white text-gray-900 shadow-sm': activeTab === 'documents', 'text-gray-500 hover:text-gray-700': activeTab !== 'documents'}" class="px-4 py-2 rounded-md text-sm font-medium transition-all">Documents</button>
                                </nav>
                            </div>

                            <div x-show="activeTab === 'details'" class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6">
                                <div class="p-4 rounded-lg border border-gray-100 bg-white">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Branch</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user_data['branch_name'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="p-4 rounded-lg border border-gray-100 bg-white">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">PAN Number</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user_data['pan_no'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="p-4 rounded-lg border border-gray-100 bg-white">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Aadhaar Number</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user_data['aadhaar_no'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="p-4 rounded-lg border border-gray-100 bg-white sm:col-span-2">
                                    <p class="text-xs font-bold text-gray-400 uppercase mb-1">Address</p>
                                    <p class="font-medium text-gray-800"><?php echo nl2br(htmlspecialchars($user_data['address'] ?: 'N/A')); ?></p>
                                </div>
                            </div>

                            <div x-show="activeTab === 'documents'" class="md:col-span-2 grid grid-cols-2 sm:grid-cols-3 gap-4" x-cloak>
                                <?php echo getDocumentThumbnail($user_data['pan_doc_path'] ?? null, 'PAN Card', 'fa-id-card'); ?>
                                <?php echo getDocumentThumbnail($user_data['aadhaar_doc_path'] ?? null, 'Aadhaar Card', 'fa-address-card'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php elseif ($edit_mode || $add_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-8 py-5 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-user-edit text-indigo-500"></i> <?php echo $edit_mode ? 'Edit User' : 'Add New User'; ?>
                        </h2>
                        <a href="manage_users.php" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition uppercase tracking-wide"><i class="fas fa-times mr-1"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $user_data['id']; ?>">
                        <input type="hidden" name="existing_photo_path" value="<?php echo htmlspecialchars($user_data['photo_path']); ?>">
                        <input type="hidden" name="existing_pan_doc_path" value="<?php echo htmlspecialchars($user_data['pan_doc_path']); ?>">
                        <input type="hidden" name="existing_aadhaar_doc_path" value="<?php echo htmlspecialchars($user_data['aadhaar_doc_path']); ?>">
                        
                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Login Credentials</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Username <span class="text-red-500">*</span></label><input type="text" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email <span class="text-red-500">*</span></label><input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Password <?php if($edit_mode) echo '<span class="text-gray-400 font-normal normal-case">(leave blank to keep)</span>'; else echo '<span class="text-red-500">*</span>'; ?></label><input type="password" name="password" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" <?php if(!$edit_mode) echo 'required'; ?>></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Role & Branch</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Role <span class="text-red-500">*</span></label>
                                    <select name="role" class="block w-full border-gray-300 rounded-lg text-sm bg-white" required>
                                        <option value="staff" <?php if($user_data['role'] == 'staff') echo 'selected'; ?>>Operation Staff</option>
                                        <option value="manager" <?php if($user_data['role'] == 'manager') echo 'selected'; ?>>Manager</option>
                                        <option value="admin" <?php if($user_data['role'] == 'admin') echo 'selected'; ?>>Super Admin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Branch <span class="text-red-500">*</span></label>
                                    <select name="branch_id" class="block w-full border-gray-300 rounded-lg text-sm bg-white" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach($branches as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>" <?php if($user_data['branch_id'] == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Personal Details</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Address</label><textarea name="address" rows="2" class="block w-full border-gray-300 rounded-lg text-sm"><?php echo htmlspecialchars($user_data['address']); ?></textarea></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN Number</label><input type="text" name="pan_no" value="<?php echo htmlspecialchars($user_data['pan_no']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar Number</label><input type="text" name="aadhaar_no" value="<?php echo htmlspecialchars($user_data['aadhaar_no']); ?>" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Documents & Photo</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Photo</label><input type="file" name="photo" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN Document</label><input type="file" name="pan_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar Document</label><input type="file" name="aadhaar_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"></div>
                            </div>
                        </fieldset>
                        
                        <div class="flex items-center pt-2"><input type="checkbox" name="is_active" value="1" <?php if($user_data['is_active']) echo 'checked'; ?> class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label class="ml-2 block text-sm font-bold text-gray-700">User is Active</label></div>
                        
                        <div class="flex justify-end pt-6 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-8 py-3 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update User' : 'Save User'; ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <?php else: ?>
                
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div class="w-full md:w-auto flex-1 flex flex-col md:flex-row gap-4">
                            <h2 class="text-2xl font-bold text-gray-800">System Users</h2>
                            <form method="GET" class="relative w-full md:max-w-md group">
                                <input type="text" name="search" placeholder="Search by name, email, branch..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 group-hover:bg-white transition-colors">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-hover:text-indigo-500 transition-colors"><i class="fas fa-search"></i></div>
                            </form>
                        </div>
                        <div class="flex gap-2">
                            <?php if(!empty($search_term)): ?>
                                <a href="manage_users.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-xl text-gray-600 font-bold text-sm hover:bg-gray-50 transition">Reset</a>
                            <?php endif; ?>
                            <a href="manage_users.php?action=add" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-md hover:bg-indigo-700 hover:shadow-lg transition transform hover:-translate-y-0.5">
                                <i class="fas fa-user-plus mr-2"></i> Add User
                            </a>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($users_list as $user): ?>
                        <div class="bg-white rounded-2xl shadow-sm hover:shadow-lg border border-gray-100 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-indigo-500 to-blue-600"></div>
                            <div class="p-6 flex-grow">
                                <div class="flex items-start space-x-4">
                                    <div class="h-14 w-14 rounded-full overflow-hidden border-2 border-indigo-100 bg-gray-50 flex-shrink-0">
                                        <img src="<?php echo htmlspecialchars($user['photo_path'] ?: 'https://placehold.co/100x100/e2e8f0/e2e8f0?text=NA'); ?>" alt="User" class="h-full w-full object-cover">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-lg font-bold text-gray-900 truncate leading-tight"><?php echo htmlspecialchars($user['username']); ?></h3>
                                        <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($user['email']); ?></p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide bg-blue-50 text-blue-700 border border-blue-100">
                                                <?php echo htmlspecialchars($user['role']); ?>
                                            </span>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide border <?php echo $user['is_active'] ? 'bg-green-50 text-green-700 border-green-100' : 'bg-red-50 text-red-700 border-red-100'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-gray-50">
                                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide mb-1">Branch</p>
                                    <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($user['branch_name'] ?? 'All Branches'); ?></p>
                                </div>
                            </div>
                            
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                <a href="manage_users.php?action=view&id=<?php echo $user['id']; ?>" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition">Profile</a>
                                <a href="manage_users.php?action=edit&id=<?php echo $user['id']; ?>" class="text-sm font-bold text-indigo-600 hover:text-indigo-800 transition">Edit</a>
                                <?php if ($user['id'] != 1): ?>
                                    <?php if ($user['is_active']): ?>
                                        <a href="manage_users.php?action=delete&id=<?php echo $user['id']; ?>" onclick="return confirm('Deactivate user?')" class="text-sm font-bold text-red-500 hover:text-red-700 transition">Deactivate</a>
                                    <?php else: ?>
                                        <a href="manage_users.php?action=reactivate&id=<?php echo $user['id']; ?>" onclick="return confirm('Reactivate user?')" class="text-sm font-bold text-green-600 hover:text-green-800 transition">Reactivate</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <span class="text-sm font-medium text-gray-500">
                            Showing <span class="font-bold text-gray-800"><?php echo $total_records > 0 ? ($offset + 1) : 0; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $records_per_page, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> users
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
    // --- Mobile Sidebar Toggle Logic ---
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
    });

    window.onload = function() {
        const loader = document.getElementById('page-loader');
        if (loader) { loader.style.display = 'none'; }
    };
    </script>
</body>
</html>