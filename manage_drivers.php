<?php
session_start();
require_once "config.php";

// ===================================================================================
// --- SECTION 1: API LOGIC (Handles background AJAX requests) ---
// ===================================================================================

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        http_response_code(403); echo json_encode(['error' => 'Access Denied']); exit;
    }
    
    $user_role = $_SESSION['role'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $can_manage = in_array($user_role, ['admin', 'manager']);

    if (!$can_manage) {
        http_response_code(403); echo json_encode(['error' => 'Permission Denied']); exit;
    }

    $branch_filter_aliased = "";
    if ($user_role !== 'admin' && !empty($branch_id)) {
        $user_branch_id = intval($branch_id);
        $branch_filter_aliased = " AND d.branch_id = $user_branch_id";
    }

    $action = $_GET['action'];

    function handle_upload_api($file_input_name, $existing_path = '') {
        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
            $target_dir = "uploads/drivers/";
            if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
            $file_ext = strtolower(pathinfo(basename($_FILES[$file_input_name]["name"]), PATHINFO_EXTENSION));
            $new_file_name = "driver_{$file_input_name}_" . time() . ".{$file_ext}";
            $target_file = $target_dir . $new_file_name;
            if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
                if (!empty($existing_path) && file_exists($existing_path)) { @unlink($existing_path); }
                return $target_file;
            }
        }
        return $existing_path;
    }

    if ($action === 'get_drivers') {
        $page = $_GET['page'] ?? 1; $search = $_GET['search'] ?? ''; $limit = 9; $offset = ($page - 1) * $limit; // Changed limit to 9 for grid
        $where_clauses = ["1=1"]; $params = []; $types = "";
        if (!empty($search)) {
            $where_clauses[] = "(d.name LIKE ? OR d.license_number LIKE ?)";
            $search_term = "%{$search}%";
            array_push($params, $search_term, $search_term);
            $types .= "ss";
        }
        $where_sql = implode(" AND ", $where_clauses);

        $total_sql = "SELECT COUNT(d.id) FROM drivers d WHERE $where_sql $branch_filter_aliased";
        $stmt_total = $mysqli->prepare($total_sql);
        if (!empty($search)) { $stmt_total->bind_param($types, ...$params); }
        $stmt_total->execute();
        $total_records = $stmt_total->get_result()->fetch_row()[0];
        $total_pages = ceil($total_records / $limit);
        $stmt_total->close();

        $drivers = [];
        $sql = "SELECT d.id, d.name, d.contact_number, d.license_number, d.photo_path, e.employee_code 
                FROM drivers d
                LEFT JOIN employees e ON d.employee_id = e.id
                WHERE $where_sql $branch_filter_aliased ORDER BY d.name ASC LIMIT ? OFFSET ?";
        
        $types .= "ii";
        array_push($params, $limit, $offset);
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $drivers[] = $row; }
        $stmt->close();

        echo json_encode(['drivers' => $drivers, 'pagination' => ['total_records' => $total_records, 'total_pages' => $total_pages, 'current_page' => (int)$page]]);
        exit;
    }

    if ($action === 'get_details') {
        $driver_id = intval($_GET['id'] ?? 0);
        if ($driver_id === 0) { http_response_code(400); echo json_encode(['error' => 'Invalid ID']); exit; }
        
        $sql = "SELECT d.*, e.employee_code 
                FROM drivers d 
                LEFT JOIN employees e ON d.employee_id = e.id
                WHERE d.id = ? $branch_filter_aliased";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $driver_id);
        $stmt->execute();
        $details = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($details) { echo json_encode($details); } 
        else { http_response_code(404); echo json_encode(['error' => 'Driver not found or access denied.']); }
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name']);
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
        
        if ($id > 0) { // UPDATE
            $sql = "UPDATE drivers SET name=?, address=?, contact_number=?, license_number=?, license_expiry_date=?, aadhaar_no=?, pan_no=?, photo_path=?, license_doc_path=?, aadhaar_doc_path=?, bank_doc_path=?, is_active=? WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssssssssssii", $name, $_POST['address'], $_POST['contact_number'], $_POST['license_number'], $_POST['license_expiry_date'], $_POST['aadhaar_no'], $_POST['pan_no'], handle_upload_api('photo_path', $_POST['existing_photo_path']), handle_upload_api('license_doc_path', $_POST['existing_license_doc_path']), handle_upload_api('aadhaar_doc_path', $_POST['existing_aadhaar_doc_path']), handle_upload_api('bank_doc_path', $_POST['existing_bank_doc_path']), $is_active, $id);
        } else { // INSERT
            $sql = "INSERT INTO drivers (name, address, contact_number, license_number, license_expiry_date, aadhaar_no, pan_no, photo_path, license_doc_path, aadhaar_doc_path, bank_doc_path, is_active, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssssssssssii", $name, $_POST['address'], $_POST['contact_number'], $_POST['license_number'], $_POST['license_expiry_date'], $_POST['aadhaar_no'], $_POST['pan_no'], handle_upload_api('photo_path'), handle_upload_api('license_doc_path'), handle_upload_api('aadhaar_doc_path'), handle_upload_api('bank_doc_path'), $is_active, $branch_id);
        }
        
        if ($stmt->execute()) { echo json_encode(['success' => true]); } 
        else { echo json_encode(['success' => false, 'message' => $stmt->error]); }
        $stmt->close();
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $request_body = json_decode(file_get_contents("php://input"), true);
        $id = intval($request_body['id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("DELETE d FROM drivers d WHERE d.id = ? $branch_filter_aliased");
            $stmt->bind_param("i", $id);
            if ($stmt->execute() && $stmt->affected_rows > 0) { echo json_encode(['success' => true]); } 
            else { echo json_encode(['success' => false, 'message' => 'Could not delete driver.']); }
            $stmt->close();
        } else { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); }
        exit;
    }
}

// ===================================================================================
// --- SECTION 3: REGULAR PAGE LOAD LOGIC ---
// ===================================================================================

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) { header("location: index.php"); exit; }
$user_role = $_SESSION['role'] ?? '';
$can_manage = in_array($user_role, ['admin', 'manager']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Drivers - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
        .spinner { border-top-color: #4f46e5; }
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
                                <i class="fas fa-id-card-alt opacity-80"></i> Manage Drivers
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
            
            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6" x-data="driversApp()">
                
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="relative w-full md:max-w-md">
                        <input type="text" x-model.debounce.500ms="search" placeholder="Search by name or license..." class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                    </div>
                    <button @click="openModal()" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                        <i class="fas fa-plus mr-2"></i> Add New Driver
                    </button>
                </div>

                <div x-show="!isLoading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <template x-for="driver in drivers" :key="driver.id">
                        <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                            <div class="p-5 flex-grow">
                                <div class="flex items-center space-x-4">
                                    <div class="h-16 w-16 rounded-full overflow-hidden border-2 border-indigo-100 bg-gray-50 flex-shrink-0">
                                        <img :src="driver.photo_path || 'https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Img'" alt="Driver" class="h-full w-full object-cover">
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-900" x-text="driver.name"></h3>
                                        <p class="text-sm text-gray-500" x-text="driver.contact_number"></p>
                                        <p x-show="driver.employee_code" class="text-xs text-indigo-600 font-bold bg-indigo-50 px-2 py-0.5 rounded mt-1 inline-block" x-text="`ID: ${driver.employee_code}`"></p>
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-gray-50">
                                    <p class="text-sm text-gray-600 flex items-center"><i class="fas fa-id-card w-5 text-gray-400"></i> <span class="font-medium" x-text="driver.license_number"></span></p>
                                </div>
                            </div>
                            
                            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                <button @click="openViewModal(driver.id)" class="text-gray-500 hover:text-gray-700 font-medium text-sm transition">Details</button>
                                <button @click="openModal(driver.id)" class="text-indigo-600 hover:text-indigo-800 font-medium text-sm transition">Edit</button>
                                <button @click="deleteDriver(driver.id)" class="text-red-600 hover:text-red-800 font-medium text-sm transition">Delete</button>
                            </div>
                        </div>
                    </template>
                </div>

                <div x-show="!isLoading && drivers.length === 0" class="flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-dashed border-gray-300 text-gray-400">
                    <i class="fas fa-users-slash fa-3x mb-4 opacity-50"></i>
                    <p class="text-lg font-medium">No drivers found.</p>
                </div>

                <div x-show="!isLoading && totalPages > 1" class="flex flex-col md:flex-row justify-between items-center gap-4 mt-8 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-sm text-gray-600">
                        Page <span class="font-bold text-gray-800" x-text="currentPage"></span> of <span class="font-bold text-gray-800" x-text="totalPages"></span>
                    </span>
                    <div class="flex gap-1">
                        <button @click="changePage(currentPage - 1)" :disabled="currentPage <= 1" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
                        <button @click="changePage(currentPage + 1)" :disabled="currentPage >= totalPages" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
                    </div>
                </div>

                <div x-show="isViewModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                    <div class="flex items-center justify-center min-h-screen px-4">
                        <div @click="isViewModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                        <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-2xl w-full relative z-50 border border-gray-100">
                            <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-gray-800" x-text="details.name"></h3>
                                <button @click="isViewModalOpen = false" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
                            </div>
                            <div class="p-6 text-sm">
                                <div class="flex items-center mb-6">
                                    <img :src="details.photo_path || 'https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Img'" class="h-20 w-20 rounded-full object-cover border-2 border-gray-200 mr-4">
                                    <div>
                                        <p class="text-gray-900 font-bold text-lg" x-text="details.name"></p>
                                        <p class="text-gray-500" x-text="details.contact_number"></p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="bg-gray-50 p-3 rounded-lg"><p class="text-xs font-bold text-gray-500 uppercase mb-1">License No.</p><p class="font-medium text-gray-900" x-text="details.license_number"></p></div>
                                    <div class="bg-gray-50 p-3 rounded-lg"><p class="text-xs font-bold text-gray-500 uppercase mb-1">License Expiry</p><p class="font-medium text-gray-900" x-text="details.license_expiry_date || 'N/A'"></p></div>
                                    <div class="bg-gray-50 p-3 rounded-lg"><p class="text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar No.</p><p class="font-medium text-gray-900" x-text="details.aadhaar_no || 'N/A'"></p></div>
                                    <div class="bg-gray-50 p-3 rounded-lg"><p class="text-xs font-bold text-gray-500 uppercase mb-1">PAN No.</p><p class="font-medium text-gray-900" x-text="details.pan_no || 'N/A'"></p></div>
                                    <div class="md:col-span-2 bg-gray-50 p-3 rounded-lg"><p class="text-xs font-bold text-gray-500 uppercase mb-1">Address</p><p class="font-medium text-gray-900" x-text="details.address"></p></div>
                                </div>
                                <div class="mt-6 pt-4 border-t border-gray-100">
                                    <h4 class="text-xs font-bold text-gray-500 uppercase mb-3">Documents</h4>
                                    <div class="flex gap-4">
                                        <a x-show="details.license_doc_path" :href="details.license_doc_path" target="_blank" class="text-indigo-600 hover:underline text-xs font-bold flex items-center"><i class="fas fa-id-card mr-1"></i> License</a>
                                        <a x-show="details.aadhaar_doc_path" :href="details.aadhaar_doc_path" target="_blank" class="text-indigo-600 hover:underline text-xs font-bold flex items-center"><i class="fas fa-file-contract mr-1"></i> Aadhaar</a>
                                        <a x-show="details.bank_doc_path" :href="details.bank_doc_path" target="_blank" class="text-indigo-600 hover:underline text-xs font-bold flex items-center"><i class="fas fa-university mr-1"></i> Bank Doc</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                    <div class="flex items-center justify-center min-h-screen px-4">
                        <div @click="isModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                        <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-3xl w-full relative z-50 border border-gray-100">
                            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-white" x-text="modalTitle"></h3>
                                <button @click="isModalOpen = false" class="text-indigo-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                            </div>
                            
                            <form @submit.prevent="saveDriver" x-ref="saveForm" class="p-6" enctype="multipart/form-data">
                                <p class="text-red-600 text-sm mb-4 bg-red-50 p-2 rounded border border-red-100" x-show="modalError" x-text="modalError"></p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[65vh] overflow-y-auto pr-2 custom-scrollbar">
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Name*</label><input type="text" name="name" x-model="formData.name" required class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Contact Number*</label><input type="text" name="contact_number" x-model="formData.contact_number" required class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Address</label><textarea name="address" x-model="formData.address" rows="2" class="block w-full border-gray-300 rounded-lg text-sm"></textarea></div>
                                    
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">License No.*</label><input type="text" name="license_number" x-model="formData.license_number" required class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">License Expiry</label><input type="date" name="license_expiry_date" x-model="formData.license_expiry_date" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar No.</label><input type="text" name="aadhaar_no" x-model="formData.aadhaar_no" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN No.</label><input type="text" name="pan_no" x-model="formData.pan_no" class="block w-full border-gray-300 rounded-lg text-sm"></div>

                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Photo</label><input type="file" name="photo_path" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_photo_path" :value="formData.photo_path"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">License Doc</label><input type="file" name="license_doc_path" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_license_doc_path" :value="formData.license_doc_path"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar Doc</label><input type="file" name="aadhaar_doc_path" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_aadhaar_doc_path" :value="formData.aadhaar_doc_path"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bank Doc</label><input type="file" name="bank_doc_path" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"><input type="hidden" name="existing_bank_doc_path" :value="formData.bank_doc_path"></div>
                                    
                                    <div class="md:col-span-2 flex items-center pt-2"><input type="checkbox" name="is_active" value="1" x-model="formData.is_active" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label class="ml-2 block text-sm text-gray-900 font-bold">Is Active</label></div>
                                </div>
                                <div class="flex justify-end pt-4 mt-4 border-t border-gray-100">
                                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-indigo-700 transition">Save Driver</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('driversApp', () => ({
        drivers: [], isLoading: true, search: '', currentPage: 1, totalPages: 1,
        isModalOpen: false, isViewModalOpen: false, modalTitle: '', modalError: '', 
        formData: {}, details: {},
        
        init() { this.fetchDrivers(); this.$watch('search', () => { this.currentPage = 1; this.fetchDrivers(); }); },
        
        async fetchDrivers() {
            this.isLoading = true;
            const params = new URLSearchParams({ search: this.search, page: this.currentPage });
            try {
                const response = await fetch(`manage_drivers.php?action=get_drivers&${params}`);
                const data = await response.json();
                this.drivers = data.drivers;
                this.totalPages = data.pagination.total_pages;
                this.currentPage = data.pagination.current_page;
            } catch (error) { console.error('Error:', error); } 
            finally { this.isLoading = false; }
        },
        
        changePage(page) { if (page > 0 && page <= this.totalPages) { this.currentPage = page; this.fetchDrivers(); } },
        
        resetForm() {
            this.formData = { id: 0, name: '', is_active: true };
            this.modalError = '';
        },
        
        async openModal(driverId = 0) {
            this.resetForm();
            if (driverId) {
                this.modalTitle = 'Edit Driver';
                const response = await fetch(`manage_drivers.php?action=get_details&id=${driverId}`);
                const data = await response.json();
                if(data) { this.formData = {...data, is_active: data.is_active == 1}; }
            } else {
                this.modalTitle = 'Add New Driver';
            }
            this.isModalOpen = true;
        },

        async openViewModal(driverId) {
            this.details = {};
            const response = await fetch(`manage_drivers.php?action=get_details&id=${driverId}`);
            this.details = await response.json();
            this.isViewModalOpen = true;
        },
        
        async saveDriver() {
            this.modalError = '';
            const formElement = this.$refs.saveForm;
            const formBody = new FormData(formElement);
            formBody.append('id', this.formData.id);
            formBody.set('is_active', this.formData.is_active ? '1' : '0');

            try {
                const response = await fetch('manage_drivers.php?action=save', { method: 'POST', body: formBody });
                const result = await response.json();
                if (result.success) { this.isModalOpen = false; this.fetchDrivers(); } 
                else { this.modalError = result.message || 'Error occurred.'; }
            } catch (error) { this.modalError = 'Network error.'; }
        },

        async deleteDriver(driverId) {
            if (confirm('Are you sure you want to delete this driver?')) {
                try {
                    const response = await fetch('manage_drivers.php?action=delete', { 
                        method: 'POST', 
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: driverId }) 
                    });
                    const result = await response.json();
                    if (result.success) { this.fetchDrivers(); } 
                    else { alert(result.message); }
                } catch (error) { alert('Network error.'); }
            }
        }
    }));
});

// Mobile sidebar toggle script
document.addEventListener('DOMContentLoaded', () => {
    const sidebarWrapper = document.getElementById('sidebar-wrapper');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
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

// Hide loader
window.onload = function() {
    const loader = document.getElementById('loader');
    if (loader) { loader.style.display = 'none'; }
};
</script>
</body>
</html>