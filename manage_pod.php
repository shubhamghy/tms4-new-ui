<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$form_message = "";

// --- Image Compression Function ---
function compressImage($source, $destination, $quality) {
    $info = getimagesize($source);
    if (!$info) return false;

    $image = null;
    $mime = $info['mime'];
    if ($mime == 'image/jpeg' || $mime == 'image/jpg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
    } elseif ($mime == 'image/png') {
        $image = imagecreatefrompng($source);
        $png_quality = floor(($quality / 100) * 9);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagepng($image, $destination, $png_quality);
    } else { return false; }
    
    if ($image) { imagedestroy($image); return true; }
    return false;
}


// --- Corrected POD Upload Handling ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['pod_shipment_id'])) {
    $shipment_id = intval($_POST['pod_shipment_id']);
    $remarks = trim($_POST['pod_remarks']);
    $pod_doc_path = null;
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $max_file_size = 5 * 1024 * 1024; // 5 MB

    try {
        if (!isset($_FILES['pod_doc']) || !is_uploaded_file($_FILES['pod_doc']['tmp_name'])) {
            throw new Exception("No file was uploaded. Please select a document.");
        }

        if ($_FILES['pod_doc']['error'] !== UPLOAD_ERR_OK) { throw new Exception("File upload error code: " . $_FILES['pod_doc']['error']); }
        
        $file_info = pathinfo($_FILES["pod_doc"]["name"]);
        $file_ext = strtolower($file_info['extension'] ?? '');
        $temp_file = $_FILES["pod_doc"]["tmp_name"];
        $file_size = $_FILES["pod_doc"]["size"];

        if ($file_size > $max_file_size) { throw new Exception("File size exceeds the 5MB limit."); }
        if (!in_array($file_ext, $allowed_extensions)) { throw new Exception("Invalid file type. Only JPG, PNG, and PDF are allowed."); }

        $target_dir = "uploads/pod/";
        if (!is_dir($target_dir)) { if (!mkdir($target_dir, 0755, true)) { throw new Exception("Failed to create the POD uploads directory."); } }

        $file_name = "pod_{$shipment_id}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $file_name;
        $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png']);
        
        if ($is_image && function_exists('imagecreatefromjpeg')) {
            if (!compressImage($temp_file, $target_file, 75)) {
                 if (!move_uploaded_file($temp_file, $target_file)) { throw new Exception("Failed to move the uploaded file (compression fallback)."); }
            }
            $pod_doc_path = $target_file;
        } else {
            if (!move_uploaded_file($temp_file, $target_file)) { throw new Exception("Failed to move the uploaded file."); }
            $pod_doc_path = $target_file;
        }

        if ($pod_doc_path) {
            $sql = "UPDATE shipments SET status = 'Completed', pod_doc_path = ?, pod_remarks = ? WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssi", $pod_doc_path, $remarks, $shipment_id);
            if (!$stmt->execute()) { throw new Exception("Error updating shipment record: " . $stmt->error); }
            $stmt->close();
            $form_message = '<div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 border border-green-200 shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> POD uploaded and trip marked as completed!</div>';
        } else { throw new Exception("File path was not set correctly."); }

    } catch (Exception $e) {
        $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 border border-red-200 shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $e->getMessage() . '</div>';
    }
}

// --- Function to Render Cards for Pending PODs ---
function renderPodCardList($shipments, $total_pages, $current_page, $page_param_name) {
    if (empty($shipments)) {
        echo '<div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 mb-4 text-gray-300">
                    <i class="fas fa-inbox fa-2x"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900">No pending PODs</h3>
                <p class="mt-1 text-gray-500 max-w-sm mx-auto">There are no shipments awaiting Proof of Delivery at this moment.</p>
              </div>';
        return;
    }
    
    echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">';
    foreach ($shipments as $shipment) {
        $consignment_no = htmlspecialchars($shipment['consignment_no']);
        $destination = htmlspecialchars($shipment['destination']);
        $consignee_name = htmlspecialchars($shipment['consignee_name']);
        $delivery_date = htmlspecialchars(date("d M Y, h:i A", strtotime($shipment['delivery_date'])));

        echo <<<HTML
        <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col relative overflow-hidden group">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-green-400 to-green-600"></div>
            
            <div class="p-6 flex-1">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-green-50 text-green-700 border border-green-100 uppercase tracking-wide mb-1">
                            Pending POD
                        </span>
                        <a href="view_shipment_details.php?id={$shipment['id']}" class="block text-lg font-bold text-gray-900 hover:text-green-600 transition-colors">{$consignment_no}</a>
                        <p class="text-xs text-gray-500 mt-1 flex items-center"><i class="fas fa-map-marker-alt mr-1 text-red-400"></i> {$destination}</p>
                    </div>
                    <div class="bg-green-50 p-2 rounded-full text-green-600">
                        <i class="fas fa-truck-loading"></i>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-3 border border-gray-100 mb-4 space-y-2">
                    <p class="text-sm text-gray-700"><span class="text-xs font-bold text-gray-400 uppercase block mb-0.5">Consignee</span> {$consignee_name}</p>
                    <p class="text-sm text-gray-700"><span class="text-xs font-bold text-gray-400 uppercase block mb-0.5">Delivered On</span> <i class="far fa-clock text-green-500 mr-1"></i> {$delivery_date}</p>
                </div>

                <button @click="openModal(\$event)" 
                        data-id="{$shipment['id']}" 
                        data-cn="{$consignment_no}"
                        data-consignee="{$consignee_name}"
                        data-delivery-date="{$delivery_date}"
                        class="w-full flex items-center justify-center text-sm font-bold bg-green-600 text-white px-4 py-2.5 rounded-lg hover:bg-green-700 transition shadow-sm hover:shadow-md transform hover:-translate-y-0.5">
                    <i class="fas fa-upload mr-2"></i> Upload Document
                </button>
            </div>
        </div>
HTML;
    }
    echo '</div>';

    // Pagination
    $base_query = $_GET;
    unset($base_query[$page_param_name]); 
    
    echo '<div class="mt-8 flex justify-end">';
    if ($total_pages > 1) {
        if ($current_page > 1) {
            $prev_page_query = http_build_query(array_merge($base_query, [$page_param_name => $current_page - 1]));
            echo "<a href='?{$prev_page_query}' class='px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 mr-2 transition shadow-sm'><i class='fas fa-arrow-left mr-1'></i> Prev</a>";
        }
        if ($current_page < $total_pages) {
            $next_page_query = http_build_query(array_merge($base_query, [$page_param_name => $current_page + 1]));
            echo "<a href='?{$next_page_query}' class='px-4 py-2 text-sm font-medium text-white bg-green-600 border border-transparent rounded-lg hover:bg-green-700 transition shadow-md'>Next <i class='fas fa-arrow-right ml-1'></i></a>";
        }
    }
    echo '</div>';
}

// --- Data Fetching ---
$limit = 6;
$branch_filter_sql = "";
$user_role = $_SESSION['role'] ?? null;
if ($user_role !== 'admin' && !empty($_SESSION['branch_id'])) {
    $user_branch_id = intval($_SESSION['branch_id']);
    $branch_filter_sql = " AND s.branch_id = $user_branch_id";
}


// Pending PODs
$page_pending = isset($_GET['page_pending']) ? (int)$_GET['page_pending'] : 1;
$offset_pending = ($page_pending - 1) * $limit;

$search_pending = trim($_GET['search_pending'] ?? '');
$filter_where_pending = "";
$pending_params = [];
$pending_types = "";

if (!empty($search_pending)) {
    $like_term = "%{$search_pending}%";
    $filter_where_pending = " AND (s.consignment_no LIKE ? OR v.vehicle_number LIKE ?)";
    $pending_params = [$like_term, $like_term];
    $pending_types = "ss";
}

$pending_pod_base_sql = "FROM shipments s
                        JOIN parties p ON s.consignee_id = p.id
                        JOIN shipment_tracking st ON s.id = st.shipment_id
                        LEFT JOIN vehicles v ON s.vehicle_id = v.id
                        WHERE s.status = 'Delivered' 
                        AND st.id = (SELECT MAX(id) FROM shipment_tracking WHERE shipment_id = s.id)
                        {$branch_filter_sql}
                        {$filter_where_pending}";


$total_pending_sql = "SELECT COUNT(s.id) " . $pending_pod_base_sql;
$stmt_count_pending = $mysqli->prepare($total_pending_sql);
if (!empty($pending_params)) {
    $stmt_count_pending->bind_param($pending_types, ...$pending_params);
}
$stmt_count_pending->execute();
$total_pending = $stmt_count_pending->get_result()->fetch_row()[0];
$stmt_count_pending->close();
$total_pages_pending = ceil($total_pending / $limit);


$pending_pod_sql = "SELECT s.id, s.consignment_no, s.destination, p.name as consignee_name, st.created_at as delivery_date
                        " . $pending_pod_base_sql . "
                        ORDER BY st.created_at DESC
                        LIMIT ? OFFSET ?";
                        
$pending_params[] = $limit;
$pending_types .= "i";
$pending_params[] = $offset_pending;
$pending_types .= "i";

$pending_pod_result = [];
if ($stmt_pending = $mysqli->prepare($pending_pod_sql)) {
    $stmt_pending->bind_param($pending_types, ...$pending_params);
    $stmt_pending->execute();
    $result = $stmt_pending->get_result();
    $pending_pods = [];
    if ($result) { while($row = $result->fetch_assoc()) { $pending_pods[] = $row; } }
    $stmt_pending->close();
}


// Completed PODs
$search_completed = $_GET['search_completed'] ?? '';
$start_date_completed = $_GET['start_date_completed'] ?? '';
$end_date_completed = $_GET['end_date_completed'] ?? '';

$where_clauses_completed = ["s.status = 'Completed'"];
$completed_params = [];
$completed_types = "";

if (!empty($search_completed)) {
    $like_term = "%{$search_completed}%";
    $where_clauses_completed[] = "(s.consignment_no LIKE ? OR v.vehicle_number LIKE ?)";
    $completed_params = [$like_term, $like_term];
    $completed_types = "ss";
}
if (!empty($start_date_completed)) {
    $where_clauses_completed[] = "s.consignment_date >= ?";
    $completed_params[] = $start_date_completed;
    $completed_types .= "s";
}
if (!empty($end_date_completed)) {
    $where_clauses_completed[] = "s.consignment_date <= ?";
    $completed_params[] = $end_date_completed;
    $completed_types .= "s";
}
$where_sql_completed = " WHERE " . implode(' AND ', $where_clauses_completed) . $branch_filter_sql;


$page_completed = isset($_GET['page_completed']) ? (int)$_GET['page_completed'] : 1;
$offset_completed = ($page_completed - 1) * $limit;

// Count query for completed
$total_completed_sql = "SELECT COUNT(s.id) FROM shipments s LEFT JOIN vehicles v ON s.vehicle_id = v.id" . $where_sql_completed;
$stmt_count_completed = $mysqli->prepare($total_completed_sql);
if (!empty($completed_params)) {
    $stmt_count_completed->bind_param($completed_types, ...$completed_params);
}
$stmt_count_completed->execute();
$total_completed = $stmt_count_completed->get_result()->fetch_row()[0];
$stmt_count_completed->close();
$total_pages_completed = ceil($total_completed / $limit);

// List query for completed
$completed_pod_sql = "SELECT s.id, s.consignment_no, s.destination, s.pod_doc_path, s.consignment_date, v.vehicle_number FROM shipments s LEFT JOIN vehicles v ON s.vehicle_id = v.id " . $where_sql_completed . " ORDER BY s.id DESC LIMIT ? OFFSET ?";

$completed_params[] = $limit;
$completed_types .= "i";
$completed_params[] = $offset_completed;
$completed_types .= "i";

$completed_pod_result = [];
if ($stmt_completed = $mysqli->prepare($completed_pod_sql)) {
    $stmt_completed->bind_param($completed_types, ...$completed_params);
    $stmt_completed->execute();
    $result = $stmt_completed->get_result();
    $completed_pods = [];
    if ($result) { while($row = $result->fetch_assoc()) { $completed_pods[] = $row; } }
    $stmt_completed->close();
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage POD - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
    <style> body { font-family: 'Inter', sans-serif; } [x-cloak] { display: none; } main::-webkit-scrollbar { width: 8px; } main::-webkit-scrollbar-track { background: #f1f5f9; } main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; } main::-webkit-scrollbar-thumb:hover { background: #94a3b8; } </style>
</head>
<body class="bg-gray-50 text-gray-800">

<div id="loader" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="flex flex-col items-center">
        <div class="fas fa-circle-notch fa-spin fa-3x text-indigo-600 mb-4"></div>
        <p class="text-gray-500 font-medium">Loading...</p>
    </div>
</div>

<div class="flex h-screen bg-gray-50 overflow-hidden">
    
    <div class="md:hidden fixed inset-0 z-40 hidden transition-opacity duration-300 bg-gray-900 bg-opacity-50" id="sidebar-overlay"></div>
    <div id="sidebar-wrapper" class="z-50 md:relative md:z-0 md:block hidden h-full shadow-xl">
        <?php include 'sidebar.php'; ?>
    </div>

    <div class="flex flex-col flex-1 relative w-full">
        
        <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                            <i class="fas fa-file-signature opacity-80"></i> Proof of Delivery
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 space-y-6" x-data="podApp()" x-init="init()">
            <?php if(!empty($form_message)) echo $form_message; ?>

            <div x-data="{ activeTab: '<?php echo isset($_GET['search_completed']) || isset($_GET['page_completed']) ? 'completed' : 'pending'; ?>' }">
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-6">
                    <nav class="flex space-x-1">
                        <a href="#" @click.prevent="activeTab = 'pending'" 
                           :class="{'bg-indigo-50 text-indigo-700 shadow-inner': activeTab === 'pending', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'pending'}" 
                           class="flex-1 px-4 py-3 rounded-lg text-sm font-bold text-center transition-all">
                           <i class="fas fa-clock mr-2" :class="activeTab === 'pending' ? 'text-indigo-500' : 'text-gray-400'"></i> Pending Uploads
                        </a>
                        <a href="#" @click.prevent="activeTab = 'completed'" 
                           :class="{'bg-emerald-50 text-emerald-700 shadow-inner': activeTab === 'completed', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'completed'}" 
                           class="flex-1 px-4 py-3 rounded-lg text-sm font-bold text-center transition-all">
                           <i class="fas fa-check-circle mr-2" :class="activeTab === 'completed' ? 'text-emerald-500' : 'text-gray-400'"></i> Completed Archive
                        </a>
                    </nav>
                </div>

                <div>
                    <div x-show="activeTab === 'pending'" x-cloak x-transition.opacity>
                        <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 mb-6">
                             <form method="get" class="flex flex-col md:flex-row gap-4 items-center">
                                <input type="hidden" name="tab" value="pending">
                                <div class="relative w-full">
                                    <input type="text" id="search_pending" name="search_pending" placeholder="Search by Consignment or Vehicle No..." value="<?php echo htmlspecialchars($search_pending); ?>" class="w-full pl-10 pr-4 py-2.5 border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                                </div>
                                <div class="flex items-center gap-2 w-full md:w-auto">
                                    <button type="submit" class="w-full md:w-auto px-6 py-2.5 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 shadow-md transition">Filter</button>
                                    <a href="manage_pod.php?tab=pending" class="w-full md:w-auto px-6 py-2.5 bg-white border border-gray-300 text-gray-700 font-bold rounded-lg hover:bg-gray-50 text-center shadow-sm transition">Reset</a>
                                </div>
                            </form>
                        </div>
                        <?php renderPodCardList($pending_pods, $total_pages_pending, $page_pending, 'page_pending'); ?>
                    </div>

                    <div x-show="activeTab === 'completed'" x-cloak x-transition.opacity>
                        <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 mb-6">
                             <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                                <input type="hidden" name="tab" value="completed">
                                <div class="lg:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Search</label>
                                    <input type="text" name="search_completed" placeholder="Consignment or Vehicle..." value="<?php echo htmlspecialchars($search_completed); ?>" class="w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">From Date</label>
                                    <input type="date" name="start_date_completed" value="<?php echo htmlspecialchars($start_date_completed); ?>" class="w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">To Date</label>
                                    <input type="date" name="end_date_completed" value="<?php echo htmlspecialchars($end_date_completed); ?>" class="w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                </div>
                                <div class="lg:col-start-4 flex gap-2">
                                    <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 shadow-md transition">Filter</button>
                                    <a href="manage_pod.php?tab=completed" class="flex-1 px-4 py-2 bg-white border border-gray-300 text-gray-700 font-bold rounded-lg hover:bg-gray-50 text-center shadow-sm transition">Reset</a>
                                </div>
                            </form>
                        </div>

                        <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                            <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-bold text-gray-800"><i class="fas fa-archive text-gray-400 mr-2"></i> Archived PODs</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                   <thead class="bg-gray-50">
                                       <tr>
                                           <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">CN No.</th>
                                           <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Vehicle No.</th>
                                           <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Booking Date</th>
                                           <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Destination</th>
                                           <th class="py-3 px-6 text-right font-bold text-gray-500 uppercase">Action</th>
                                       </tr>
                                   </thead>
                                   <tbody class="divide-y divide-gray-100">
                                    <?php if(empty($completed_pods)): ?>
                                    <tr><td colspan="5" class="text-center py-8 text-gray-400 italic">No completed PODs found.</td></tr>
                                    <?php else: foreach($completed_pods as $pod): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="py-3 px-6 font-medium text-gray-800"><?php echo htmlspecialchars($pod['consignment_no']); ?></td>
                                        <td class="py-3 px-6 text-gray-600"><?php echo htmlspecialchars($pod['vehicle_number'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-6 text-gray-600"><?php echo htmlspecialchars(date("d-m-Y", strtotime($pod['consignment_date']))); ?></td>
                                        <td class="py-3 px-6 text-gray-600"><?php echo htmlspecialchars($pod['destination']); ?></td>
                                        <td class="py-3 px-6 text-right"><a href="<?php echo htmlspecialchars($pod['pod_doc_path']); ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 font-bold hover:underline"><i class="fas fa-eye mr-1"></i> View</a></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                   </tbody>
                                </table>
                            </div>
                             <div class="bg-gray-50 px-6 py-4 flex justify-end">
                                 <?php 
                                 $base_query = $_GET;
                                 unset($base_query['page_completed']);
                                 $query_string_completed = http_build_query($base_query);

                                 if ($total_pages_completed > 1) {
                                     if ($page_completed > 1) {
                                         echo "<a href='?page_completed=" . ($page_completed - 1) . "&amp;{$query_string_completed}' class='px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 mr-2 transition shadow-sm'>Prev</a>";
                                     }
                                     if ($page_completed < $total_pages_completed) {
                                         echo "<a href='?page_completed=" . ($page_completed + 1) . "&amp;{$query_string_completed}' class='px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-lg hover:bg-indigo-700 transition shadow-md'>Next</a>";
                                     }
                                 }
                                 ?>
                             </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="isModalOpen" @keydown.escape.window="isModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                 <div class="flex items-center justify-center min-h-screen px-4">
                    <div @click="isModalOpen = false" class="fixed inset-0 transition-opacity"><div class="absolute inset-0 bg-slate-900 opacity-75 backdrop-blur-sm"></div></div>
                    
                    <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-lg sm:w-full border border-gray-100 relative z-10">
                        <div class="bg-green-600 px-6 py-4 flex justify-between items-center">
                            <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fas fa-upload"></i> Upload Proof of Delivery</h3>
                            <button @click="isModalOpen = false" class="text-green-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                        </div>

                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="pod_shipment_id" :value="shipmentId">
                            <div class="px-6 py-6 space-y-5">
                                
                                <div class="bg-green-50 p-4 rounded-lg border border-green-100 flex items-center justify-between">
                                    <span class="text-xs font-bold text-green-600 uppercase">Consignment</span>
                                    <span x-text="consignmentNo" class="font-bold text-green-900 text-lg"></span>
                                </div>

                                <div class="grid grid-cols-2 gap-4 text-sm text-gray-600">
                                    <div><span class="block text-xs font-bold text-gray-400 uppercase">Consignee</span> <span x-text="consigneeName" class="font-medium"></span></div>
                                    <div><span class="block text-xs font-bold text-gray-400 uppercase">Delivered On</span> <span x-text="deliveryDate" class="font-medium"></span></div>
                                </div>

                                <div>
                                    <label for="pod_doc" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Document (JPG, PNG, PDF)</label>
                                    <input type="file" name="pod_doc" id="pod_doc" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 cursor-pointer border border-gray-300 rounded-lg"/>
                                </div>
                                <div>
                                    <label for="pod_remarks" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Remarks (Optional)</label>
                                    <textarea name="pod_remarks" id="pod_remarks" rows="3" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="Any comments..."></textarea>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3 border-t border-gray-100">
                                <button type="button" @click="isModalOpen = false" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-bold hover:bg-gray-50 text-gray-700 transition">Cancel</button>
                                <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-bold hover:bg-green-700 shadow-md transition transform hover:-translate-y-0.5">Submit POD</button>
                            </div>
                        </form>
                    </div>
                 </div>
            </div>
            <?php include 'footer.php'; ?>
        </main>
    </div>
</div>
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
    function podApp() {
        return {
            activeTab: 'pending',
            isModalOpen: false,
            shipmentId: '',
            consignmentNo: '',
            consigneeName: '',
            deliveryDate: '',
            
            openModal(event) {
                const btn = event.currentTarget;
                this.shipmentId = btn.dataset.id;
                this.consignmentNo = btn.dataset.cn;
                this.consigneeName = btn.dataset.consignee;
                this.deliveryDate = btn.dataset.deliveryDate;
                this.isModalOpen = true;
            },

            init() {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('search_completed') || urlParams.has('page_completed') || urlParams.get('tab') === 'completed') {
                    this.activeTab = 'completed';
                } else {
                    this.activeTab = 'pending';
                }
            }
        };
    }

    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('close-sidebar-btn');

    function toggleSidebar() {
        const wrapper = document.getElementById('sidebar-wrapper');
        const overlay = document.getElementById('sidebar-overlay');
        if(wrapper.classList.contains('hidden')){
            wrapper.classList.remove('hidden');
            wrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
            overlay.classList.remove('hidden');
        } else {
            wrapper.classList.add('hidden');
            wrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
            overlay.classList.add('hidden');
        }
    }

    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
    
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) {
            setTimeout(() => {
                loader.style.opacity = '0';
                setTimeout(() => loader.style.display = 'none', 300);
            }, 300);
        }
    };
</script>
</body>
</html>