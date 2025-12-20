<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "config.php";

// --- Role-based access control ---
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$can_manage = in_array($user_role, ['admin', 'manager']);

// --- Helper function for file uploads ---
function upload_broker_file($file_input_name, $broker_id, $existing_path = '') {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        $target_dir = "uploads/brokers/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        
        $file_ext = strtolower(pathinfo(basename($_FILES[$file_input_name]["name"]), PATHINFO_EXTENSION));
        $new_file_name = "broker_{$broker_id}_{$file_input_name}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;

        if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
            if (!empty($existing_path) && file_exists($existing_path)) {
                @unlink($existing_path);
            }
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

// --- Page State Management ---
$form_message = "";
$edit_mode = false;
$view_mode = false;
$add_mode = false;
$broker_data = [
    'id' => '', 'name' => '', 'address' => '', 'city' => '', 'state' => '', 'contact_person' => '', 'contact_number' => '',
    'gst_no' => '', 'pan_no' => '', 'aadhaar_no' => '', 'bank_account_no' => '', 'bank_ifsc_code' => '', 'is_active' => 1,
    'pan_doc_path' => '', 'gst_doc_path' => '', 'bank_doc_path' => '', 'aadhaar_doc_path' => '',
    'visibility_type' => 'global', 'branch_id' => null
];

// --- Form Processing for Add/Edit ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $visibility_type = $_POST['visibility_type'] ?? 'global';
    $branch_id = null;
    if ($visibility_type === 'local') {
        $branch_id = $is_admin ? (intval($_POST['branch_id']) ?: null) : $_SESSION['branch_id'];
    }

    if ($_POST['action'] == 'edit' && $can_manage && $id > 0) {
        $sql = "UPDATE brokers SET name=?, address=?, city=?, state=?, contact_person=?, contact_number=?, gst_no=?, pan_no=?, aadhaar_no=?, bank_account_no=?, bank_ifsc_code=?, is_active=?, pan_doc_path=?, gst_doc_path=?, bank_doc_path=?, aadhaar_doc_path=?, visibility_type=?, branch_id=? WHERE id=?";
        if ($stmt = $mysqli->prepare($sql)) {
            $pan_path = upload_broker_file('pan_doc', $id, $_POST['existing_pan_doc_path']);
            $gst_path = upload_broker_file('gst_doc', $id, $_POST['existing_gst_doc_path']);
            $bank_path = upload_broker_file('bank_doc', $id, $_POST['existing_bank_doc_path']);
            $aadhaar_path = upload_broker_file('aadhaar_doc', $id, $_POST['existing_aadhaar_doc_path']);
            
            $stmt->bind_param("sssssssssssisssssii", $name, $_POST['address'], $_POST['city'], $_POST['state'], $_POST['contact_person'], $_POST['contact_number'], $_POST['gst_no'], $_POST['pan_no'], $_POST['aadhaar_no'], $_POST['bank_account_no'], $_POST['bank_ifsc_code'], $is_active, $pan_path, $gst_path, $bank_path, $aadhaar_path, $visibility_type, $branch_id, $id);
            if ($stmt->execute()) {
                $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Broker updated successfully!</div>';
            } else {
                $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error updating broker.</div>';
            }
            $stmt->close();
        }
    } elseif ($_POST['action'] == 'add') {
        $sql = "INSERT INTO brokers (name, address, city, state, contact_person, contact_number, gst_no, pan_no, aadhaar_no, bank_account_no, bank_ifsc_code, is_active, visibility_type, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("sssssssssssisi", $name, $_POST['address'], $_POST['city'], $_POST['state'], $_POST['contact_person'], $_POST['contact_number'], $_POST['gst_no'], $_POST['pan_no'], $_POST['aadhaar_no'], $_POST['bank_account_no'], $_POST['bank_ifsc_code'], $is_active, $visibility_type, $branch_id);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $pan_path = upload_broker_file('pan_doc', $new_id);
                $gst_path = upload_broker_file('gst_doc', $new_id);
                $bank_path = upload_broker_file('bank_doc', $new_id);
                $aadhaar_path = upload_broker_file('aadhaar_doc', $new_id);
                
                $update_sql = "UPDATE brokers SET pan_doc_path=?, gst_doc_path=?, bank_doc_path=?, aadhaar_doc_path=? WHERE id=?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("ssssi", $pan_path, $gst_path, $bank_path, $aadhaar_path, $new_id);
                $update_stmt->execute();
                $update_stmt->close();
                $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> New broker added successfully!</div>';
            } else {
                $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error adding broker: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Handle GET requests for actions like delete/reactivate
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = intval($_GET['id'] ?? 0);
    if ($action == 'add') { $add_mode = true; }
    elseif (($action == 'view' || $action == 'edit') && $id > 0) {
        if ($action == 'edit' && !$can_manage) { header("location: manage_brokers.php?message=denied"); exit; }
        $stmt = $mysqli->prepare("SELECT * FROM brokers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $broker_data = $result->fetch_assoc();
            if ($action == 'view') $view_mode = true;
            if ($action == 'edit') $edit_mode = true;
        }
        $stmt->close();
    } elseif ($action == 'delete' && $id > 0 && $is_admin) {
        $stmt = $mysqli->prepare("UPDATE brokers SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()){ $form_message = "<div class='p-4 mb-6 text-sm text-yellow-700 bg-yellow-100 border-l-4 border-yellow-500 rounded shadow-sm flex items-center'><i class='fas fa-ban mr-2'></i> Broker deactivated successfully.</div>"; }
        else { $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error deactivating broker.</div>"; }
        $stmt->close();
    } elseif ($action == 'reactivate' && $id > 0 && $is_admin) {
        $stmt = $mysqli->prepare("UPDATE brokers SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()){ $form_message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Broker reactivated successfully.</div>"; }
        else { $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error reactivating broker.</div>"; }
        $stmt->close();
    }
}

// Fetch data for lists / dropdowns
$brokers_list = [];
$branches = [];
if ($edit_mode || $add_mode) {
    if ($is_admin) {
        $branches = $mysqli->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    }
} elseif (!$view_mode) {
    // --- CORRECTED DATA FETCHING FOR LIST VIEW ---
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9;
    $offset = ($page - 1) * $records_per_page;

    $base_sql = "FROM brokers b LEFT JOIN branches br ON b.branch_id = br.id";
    $where_sql = "";
    $params = [];
    $types = "";

    if (!$is_admin) {
        $user_branch_id = $_SESSION['branch_id'] ?? 0;
        $where_sql = " WHERE (b.visibility_type = 'global' OR (b.visibility_type = 'local' AND b.branch_id = ?))";
        $params[] = $user_branch_id;
        $types .= "i";
    }

    // Search functionality
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = trim($_GET['search']);
        $where_sql .= (empty($where_sql) ? " WHERE " : " AND ") . "(b.name LIKE ? OR b.city LIKE ? OR b.contact_person LIKE ?)";
        $like_search = "%$search%";
        $params[] = $like_search;
        $params[] = $like_search;
        $params[] = $like_search;
        $types .= "sss";
    }

    // Get total records with filtering
    $total_records_sql = "SELECT COUNT(b.id) " . $base_sql . $where_sql;
    $stmt_count = $mysqli->prepare($total_records_sql);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    
    $total_pages = ceil($total_records / $records_per_page);

    // Fetch brokers for the current page with filtering
    $list_sql = "SELECT b.*, br.name as branch_name " . $base_sql . $where_sql . " ORDER BY b.name ASC LIMIT ? OFFSET ?";
    $stmt_list = $mysqli->prepare($list_sql);
    
    // Append pagination params to the existing params and types
    $params[] = $records_per_page;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";

    if(!empty($types)) {
        $stmt_list->bind_param($types, ...$params);
    }
    $stmt_list->execute();
    $result = $stmt_list->get_result();
    if ($result) {
        $brokers_list = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_list->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Brokers - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        @media print {
            body * { visibility: hidden; } .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; } .no-print { display: none; }
        }
        [x-cloak] { display: none !important; }
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
                                <i class="fas fa-users-cog opacity-80"></i> Manage Brokers
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
                        <h2 class="text-xl font-bold text-gray-800">Broker Details</h2>
                        <div class="flex gap-2">
                            <button onclick="window.print()" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-print mr-2"></i> Print</button>
                            <a href="manage_brokers.php" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                        </div>
                    </div>
                    <div class="p-8">
                        <div class="flex items-center mb-8 pb-6 border-b border-gray-100">
                            <div class="h-16 w-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-2xl font-bold mr-4 uppercase">
                                <?php echo substr($broker_data['name'], 0, 1); ?>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($broker_data['name']); ?></h1>
                                <p class="text-sm text-gray-500 flex items-center mt-1">
                                    <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($broker_data['city'] . ', ' . $broker_data['state']); ?>
                                </p>
                            </div>
                            <div class="ml-auto text-right">
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $broker_data['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $broker_data['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Contact Information</h3>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between"><dt class="text-gray-500">Contact Person:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['contact_person'] ?? 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Phone:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['contact_number'] ?? 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Address:</dt><dd class="font-medium text-right max-w-xs"><?php echo nl2br(htmlspecialchars($broker_data['address'] ?? 'N/A')); ?></dd></div>
                                </dl>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Financial & Tax</h3>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between"><dt class="text-gray-500">GST No:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['gst_no'] ?? 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">PAN No:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['pan_no'] ?? 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Bank Account:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['bank_account_no'] ?? 'N/A'); ?></dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">IFSC Code:</dt><dd class="font-medium"><?php echo htmlspecialchars($broker_data['bank_ifsc_code'] ?? 'N/A'); ?></dd></div>
                                </dl>
                            </div>
                        </div>

                        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide border-b pb-2 mb-4">Documents</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php echo getDocumentThumbnail($broker_data['pan_doc_path'], 'PAN Card', 'fa-id-card'); ?>
                            <?php echo getDocumentThumbnail($broker_data['gst_doc_path'], 'GST Cert', 'fa-file-invoice'); ?>
                            <?php echo getDocumentThumbnail($broker_data['aadhaar_doc_path'], 'Aadhaar', 'fa-fingerprint'); ?>
                            <?php echo getDocumentThumbnail($broker_data['bank_doc_path'], 'Bank Proof', 'fa-university'); ?>
                        </div>
                    </div>
                </div>

                <?php elseif ($edit_mode || $add_mode): ?>
                 <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-8 py-5 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-user-edit text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Broker Profile' : 'Register New Broker'; ?>
                        </h2>
                        <a href="manage_brokers.php" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition uppercase tracking-wide"><i class="fas fa-times mr-1"></i> Cancel</a>
                    </div>
                    
                    <form action="manage_brokers.php" method="post" enctype="multipart/form-data" class="p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $broker_data['id']; ?>">
                        <input type="hidden" name="action" value="<?php echo $edit_mode ? 'edit' : 'add'; ?>">
                        
                        <div>
                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-5">Basic Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Broker Name <span class="text-red-500">*</span></label><input type="text" name="name" value="<?php echo htmlspecialchars($broker_data['name'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Contact Person</label><input type="text" name="contact_person" value="<?php echo htmlspecialchars($broker_data['contact_person'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Phone Number</label><input type="text" name="contact_number" value="<?php echo htmlspecialchars($broker_data['contact_number'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div class="md:col-span-3"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Full Address</label><textarea name="address" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"><?php echo htmlspecialchars($broker_data['address'] ?? ''); ?></textarea></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">City</label><input type="text" name="city" value="<?php echo htmlspecialchars($broker_data['city'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">State</label><input type="text" name="state" value="<?php echo htmlspecialchars($broker_data['state'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-5">Financial Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">GST No.</label><input type="text" name="gst_no" value="<?php echo htmlspecialchars($broker_data['gst_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN No.</label><input type="text" name="pan_no" value="<?php echo htmlspecialchars($broker_data['pan_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar No.</label><input type="text" name="aadhaar_no" value="<?php echo htmlspecialchars($broker_data['aadhaar_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bank Account</label><input type="text" name="bank_account_no" value="<?php echo htmlspecialchars($broker_data['bank_account_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">IFSC Code</label><input type="text" name="bank_ifsc_code" value="<?php echo htmlspecialchars($broker_data['bank_ifsc_code'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-5">Documents Upload</h3>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN Card</label><input type="file" name="pan_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_pan_doc_path" value="<?php echo htmlspecialchars($broker_data['pan_doc_path'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">GST Cert</label><input type="file" name="gst_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_gst_doc_path" value="<?php echo htmlspecialchars($broker_data['gst_doc_path'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bank Proof</label><input type="file" name="bank_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_bank_doc_path" value="<?php echo htmlspecialchars($broker_data['bank_doc_path'] ?? ''); ?>"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar</label><input type="file" name="aadhaar_doc" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_aadhaar_doc_path" value="<?php echo htmlspecialchars($broker_data['aadhaar_doc_path'] ?? ''); ?>"></div>
                            </div>
                        </div>

                        <div class="flex items-center pt-2"><input type="checkbox" name="is_active" value="1" <?php if($broker_data['is_active']) echo 'checked'; ?> class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label class="ml-2 block text-sm font-bold text-gray-700">Broker is Active</label></div>
                        
                        <div class="flex justify-end pt-6 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-8 py-3 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Broker' : 'Save Broker'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div class="w-full md:w-auto flex-1 flex flex-col md:flex-row gap-4">
                            <h2 class="text-2xl font-bold text-gray-800">Broker Directory</h2>
                            <form method="GET" class="relative w-full md:max-w-md group">
                                <input type="text" name="search" placeholder="Search brokers..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 group-hover:bg-white transition-colors">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-hover:text-indigo-500 transition-colors"><i class="fas fa-search"></i></div>
                            </form>
                        </div>
                        <div class="flex gap-2">
                            <?php if(!empty($_GET['search'])): ?>
                                <a href="manage_brokers.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-xl text-gray-600 font-bold text-sm hover:bg-gray-50 transition">Reset</a>
                            <?php endif; ?>
                            <a href="manage_brokers.php?action=add" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-md hover:bg-indigo-700 hover:shadow-lg transition transform hover:-translate-y-0.5">
                                <i class="fas fa-plus mr-2"></i> Add Broker
                            </a>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($brokers_list as $broker): ?>
                        <div class="bg-white rounded-2xl shadow-sm hover:shadow-lg border border-gray-100 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-indigo-500 to-blue-600"></div>
                            <div class="p-6 flex-grow">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-lg">
                                            <?php echo substr($broker['name'], 0, 1); ?>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-900 leading-tight"><?php echo htmlspecialchars($broker['name']); ?></h3>
                                            <span class="text-xs text-gray-500 flex items-center mt-1"><i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($broker['city'] . ', ' . $broker['state']); ?></span>
                                        </div>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?php echo $broker['is_active'] ? 'bg-green-50 text-green-700 border-green-100' : 'bg-red-50 text-red-700 border-red-100'; ?>">
                                        <?php echo $broker['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="space-y-2 text-sm text-gray-600 mt-4 border-t border-gray-50 pt-4">
                                    <p class="flex items-center"><i class="fas fa-user-circle w-5 text-gray-400"></i> <?php echo htmlspecialchars($broker['contact_person'] ?: 'N/A'); ?></p>
                                    <p class="flex items-center"><i class="fas fa-phone w-5 text-gray-400"></i> <?php echo htmlspecialchars($broker['contact_number'] ?: 'N/A'); ?></p>
                                </div>
                            </div>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                                <a href="manage_brokers.php?action=view&id=<?php echo $broker['id']; ?>" class="text-sm font-bold text-gray-500 hover:text-gray-800 transition">View Details</a>
                                <div class="flex gap-3">
                                    <?php if ($can_manage): ?>
                                    <a href="manage_brokers.php?action=edit&id=<?php echo $broker['id']; ?>" class="text-indigo-600 hover:text-indigo-800 transition" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if($is_admin): ?>
                                        <?php if ($broker['is_active']): ?>
                                            <a href="manage_brokers.php?action=delete&id=<?php echo $broker['id']; ?>" onclick="return confirm('Deactivate this broker?')" class="text-red-500 hover:text-red-700 transition" title="Deactivate"><i class="fas fa-ban"></i></a>
                                        <?php else: ?>
                                            <a href="manage_brokers.php?action=reactivate&id=<?php echo $broker['id']; ?>" onclick="return confirm('Reactivate this broker?')" class="text-green-600 hover:text-green-800 transition" title="Reactivate"><i class="fas fa-check-circle"></i></a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <span class="text-sm font-medium text-gray-500">
                            Showing <span class="font-bold text-gray-800"><?php echo $total_records > 0 ? ($offset + 1) : 0; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $records_per_page, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> brokers
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
        const loader = document.getElementById('loader');
        if (loader) { loader.style.display = 'none'; }
    };
    </script>
</body>
</html>