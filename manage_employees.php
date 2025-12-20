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
        $branch_filter_aliased = " AND e.branch_id = $user_branch_id";
    }

    $action = $_GET['action'];

    if ($action === 'get_employees') {
        $page = $_GET['page'] ?? 1; $search = $_GET['search'] ?? ''; $limit = 9; $offset = ($page - 1) * $limit; // Grid view
        $where_clauses = ["e.status = 'Active'"]; $params = []; $types = "";

        if (!empty($search)) {
            $where_clauses[] = "(e.full_name LIKE ? OR e.employee_code LIKE ?)";
            $search_term = "%{$search}%";
            array_push($params, $search_term, $search_term);
            $types .= "ss";
        }
        $where_sql = implode(" AND ", $where_clauses);

        $total_sql = "SELECT COUNT(e.id) FROM employees e WHERE $where_sql $branch_filter_aliased";
        $stmt_total = $mysqli->prepare($total_sql);
        if (!empty($search)) { $stmt_total->bind_param($types, ...$params); }
        $stmt_total->execute();
        $total_records = $stmt_total->get_result()->fetch_row()[0];
        $total_pages = ceil($total_records / $limit);
        $stmt_total->close();

        $employees = [];
        $sql = "SELECT e.id, e.full_name, e.employee_code, b.name as branch_name, u.username, e.designation, e.status,
                       (SELECT COUNT(*) FROM salary_structures WHERE employee_id = e.id) as salary_set_count
                FROM employees e 
                LEFT JOIN branches b ON e.branch_id = b.id
                LEFT JOIN users u ON e.user_id = u.id
                WHERE $where_sql $branch_filter_aliased 
                ORDER BY e.full_name ASC LIMIT ? OFFSET ?";
        
        $types .= "ii";
        array_push($params, $limit, $offset);
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $employees[] = $row; }
        $stmt->close();

        echo json_encode(['employees' => $employees, 'pagination' => ['total_records' => $total_records, 'total_pages' => $total_pages, 'current_page' => (int)$page]]);
        exit;
    }

    if ($action === 'get_details') {
        $employee_id = intval($_GET['id'] ?? 0);
        if ($employee_id === 0) { http_response_code(400); echo json_encode(['error' => 'Invalid ID']); exit; }
        
        $sql = "SELECT e.*, d.id as driver_id, d.license_number, d.license_expiry_date 
                FROM employees e 
                LEFT JOIN drivers d ON e.id = d.employee_id
                WHERE e.id = ? $branch_filter_aliased";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($employee) { echo json_encode($employee); } 
        else { http_response_code(404); echo json_encode(['error' => 'Employee not found or access denied.']); }
        exit;
    }
    
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mysqli->begin_transaction();
        try {
            $id = intval($_POST['id'] ?? 0);
            $full_name = trim($_POST['full_name']);
            $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
            $branch_id_form = ($user_role === 'admin') ? intval($_POST['branch_id']) : $branch_id;
            $status = trim($_POST['status']);
            $is_driver = isset($_POST['is_driver']);

            if (empty($full_name)) { throw new Exception('Full Name is required.'); }
            
            if ($id > 0) { // UPDATE
                $sql = "UPDATE employees SET user_id=?, branch_id=?, full_name=?, employee_code=?, designation=?, department=?, date_of_joining=?, pan_no=?, aadhaar_no=?, bank_account_no=?, bank_ifsc_code=?, status=? WHERE id=?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("iissssssssssi", $user_id, $branch_id_form, $full_name, $_POST['employee_code'], $_POST['designation'], $_POST['department'], $_POST['date_of_joining'], $_POST['pan_no'], $_POST['aadhaar_no'], $_POST['bank_account_no'], $_POST['bank_ifsc_code'], $status, $id);
            } else { // INSERT
                $sql = "INSERT INTO employees (user_id, branch_id, full_name, employee_code, designation, department, date_of_joining, pan_no, aadhaar_no, bank_account_no, bank_ifsc_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("iissssssssss", $user_id, $branch_id_form, $full_name, $_POST['employee_code'], $_POST['designation'], $_POST['department'], $_POST['date_of_joining'], $_POST['pan_no'], $_POST['aadhaar_no'], $_POST['bank_account_no'], $_POST['bank_ifsc_code'], $status);
            }

            if (!$stmt->execute()) { throw new Exception("Database error saving employee: " . $stmt->error); }
            $employee_id = ($id > 0) ? $id : $stmt->insert_id;
            $stmt->close();

            $driver_id = intval($_POST['driver_id'] ?? 0);
            if ($is_driver) {
                $license_number = trim($_POST['license_number']);
                $license_expiry = !empty($_POST['license_expiry_date']) ? $_POST['license_expiry_date'] : null;
                if (empty($license_number)) { throw new Exception("License number is required for a driver."); }
                
                if ($driver_id > 0) { // Update existing driver record
                    $driver_sql = "UPDATE drivers SET name=?, license_number=?, license_expiry_date=?, branch_id=? WHERE id=? AND employee_id=?";
                    $driver_stmt = $mysqli->prepare($driver_sql);
                    $driver_stmt->bind_param("sssiii", $full_name, $license_number, $license_expiry, $branch_id_form, $driver_id, $employee_id);
                } else { // Insert new driver record
                    $driver_sql = "INSERT INTO drivers (name, license_number, license_expiry_date, branch_id, employee_id) VALUES (?, ?, ?, ?, ?)";
                    $driver_stmt = $mysqli->prepare($driver_sql);
                    $driver_stmt->bind_param("sssii", $full_name, $license_number, $license_expiry, $branch_id_form, $employee_id);
                }
                if (!$driver_stmt->execute()) { throw new Exception("Database error saving driver profile: " . $driver_stmt->error); }
                $driver_stmt->close();
            } else {
                if ($driver_id > 0) {
                    $mysqli->query("UPDATE drivers SET employee_id = NULL WHERE id = $driver_id");
                }
            }

            $mysqli->commit();
            echo json_encode(['success' => true, 'message' => 'Employee saved successfully!']);
        } catch (Exception $e) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ===================================================================================
// --- SECTION 2: REGULAR PAGE LOAD LOGIC ---
// ===================================================================================

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) { header("location: index.php"); exit; }
$user_role = $_SESSION['role'] ?? '';
$can_manage = in_array($user_role, ['admin', 'manager']);
if (!$can_manage) { header("location: dashboard.php"); exit; }

$branches = $mysqli->query("SELECT id, name FROM branches ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$unassigned_users = $mysqli->query("SELECT u.id, u.username FROM users u LEFT JOIN employees e ON u.id = e.user_id WHERE e.id IS NULL")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - TMS</title>
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
                                <i class="fas fa-users-cog opacity-80"></i> Manage Employees
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
            
            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6" x-data="employeesApp()">
                
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="relative w-full md:max-w-md">
                        <input type="text" x-model.debounce.500ms="search" placeholder="Search by name or code..." class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                    </div>
                    <button @click="openModal()" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                        <i class="fas fa-plus mr-2"></i> Add Employee
                    </button>
                </div>

                <div x-show="isLoading" class="flex flex-col items-center justify-center py-12 text-gray-500">
                    <i class="fas fa-spinner fa-spin fa-2x mb-3 text-indigo-500"></i>
                    <p>Loading employee data...</p>
                </div>

                <div x-show="!isLoading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <template x-for="employee in employees" :key="employee.id">
                        <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                            <div class="p-5 flex-grow">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-lg uppercase" x-text="employee.full_name.charAt(0)"></div>
                                        <div>
                                            <h3 class="text-lg font-bold text-indigo-900 leading-tight" x-text="employee.full_name"></h3>
                                            <p class="text-xs text-gray-500 font-medium" x-text="employee.designation || 'N/A'"></p>
                                        </div>
                                    </div>
                                    <span :class="{'bg-green-100 text-green-800': employee.status === 'Active', 'bg-red-100 text-red-800': employee.status !== 'Active'}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border border-opacity-20" x-text="employee.status"></span>
                                </div>
                                
                                <div class="space-y-2 text-sm text-gray-600 mb-2">
                                    <p class="flex items-center justify-between"><span class="text-gray-400">Code:</span> <span class="font-bold text-gray-800" x-text="employee.employee_code"></span></p>
                                    <p class="flex items-center justify-between"><span class="text-gray-400">Branch:</span> <span class="font-medium" x-text="employee.branch_name || 'N/A'"></span></p>
                                    <p class="flex items-center justify-between" x-show="employee.username"><span class="text-gray-400">User:</span> <span class="text-indigo-600 font-medium" x-text="'@' + employee.username"></span></p>
                                </div>

                                <div class="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between">
                                    <span class="text-xs text-gray-400 font-bold uppercase">Salary Struct</span>
                                    <span :class="employee.salary_set_count > 0 ? 'text-green-600' : 'text-gray-400'" class="text-xs font-bold"><i :class="employee.salary_set_count > 0 ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i> <span x-text="employee.salary_set_count > 0 ? 'Configured' : 'Not Set'"></span></span>
                                </div>
                            </div>
                            
                            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                <button @click="openModal(employee.id)" class="text-indigo-600 hover:text-indigo-800 font-bold text-sm transition flex items-center"><i class="fas fa-edit mr-1"></i> Edit</button>
                            </div>
                        </div>
                    </template>
                </div>

                <div x-show="!isLoading && employees.length === 0" class="flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-dashed border-gray-300 text-gray-400">
                    <i class="fas fa-users-slash fa-3x mb-4 opacity-50"></i>
                    <p class="text-lg font-medium">No employees found.</p>
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

                <div x-show="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                    <div class="flex items-center justify-center min-h-screen px-4">
                        <div @click="isModalOpen = false" class="fixed inset-0 bg-slate-900 bg-opacity-75 backdrop-blur-sm transition-opacity"></div>
                        <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-3xl w-full relative z-50 border border-gray-100">
                            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-white" x-text="modalTitle"></h3>
                                <button @click="isModalOpen = false" class="text-indigo-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                            </div>
                            
                            <form @submit.prevent="saveEmployee" x-ref="saveForm" class="p-6">
                                <p class="text-red-600 text-sm mb-4 bg-red-50 p-2 rounded border border-red-100" x-show="modalError" x-text="modalError"></p>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-h-[70vh] overflow-y-auto pr-2 custom-scrollbar">
                                    
                                    <div class="md:col-span-3 pb-2 border-b border-gray-100 mb-2"><h4 class="text-xs font-bold text-indigo-500 uppercase tracking-wide">Personal Information</h4></div>
                                    <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Full Name*</label><input type="text" name="full_name" x-model="formData.full_name" required class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Emp Code</label><input type="text" name="employee_code" x-model="formData.employee_code" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Designation</label><input type="text" name="designation" x-model="formData.designation" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Department</label><input type="text" name="department" x-model="formData.department" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Date of Joining</label><input type="date" name="date_of_joining" x-model="formData.date_of_joining" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    
                                    <?php if ($user_role === 'admin'): ?>
                                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Branch</label><select name="branch_id" x-model="formData.branch_id" class="block w-full border-gray-300 rounded-lg text-sm bg-white"><option value="">Select...</option><template x-for="branch in branches"><option :value="branch.id" x-text="branch.name"></option></template></select></div>
                                    <?php else: ?>
                                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Branch</label><input type="text" value="<?php echo htmlspecialchars($_SESSION['branch_name'] ?? ''); ?>" disabled class="block w-full border-gray-300 bg-gray-100 rounded-lg text-sm cursor-not-allowed"></div>
                                    <?php endif; ?>
                                    
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Link User Account</label><select name="user_id" x-model="formData.user_id" class="block w-full border-gray-300 rounded-lg text-sm bg-white"><option value="">None</option><template x-if="formData.user_id && !unassignedUsers.some(u => u.id == formData.user_id)"><option :value="formData.user_id" x-text="`Linked User #${formData.user_id}`"></option></template><template x-for="user in unassignedUsers"><option :value="user.id" x-text="user.username"></option></template></select></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Status</label><select name="status" x-model="formData.status" class="block w-full border-gray-300 rounded-lg text-sm bg-white"><option>Active</option><option>Resigned</option><option>Terminated</option></select></div>
                                    
                                    <div class="md:col-span-3 pt-4 pb-2 border-b border-gray-100 mb-2"><h4 class="text-xs font-bold text-indigo-500 uppercase tracking-wide">Financial Details</h4></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN Number</label><input type="text" name="pan_no" x-model="formData.pan_no" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Aadhaar No.</label><input type="text" name="aadhaar_no" x-model="formData.aadhaar_no" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bank Account No.</label><input type="text" name="bank_account_no" x-model="formData.bank_account_no" class="block w-full border-gray-300 rounded-lg text-sm"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">IFSC Code</label><input type="text" name="bank_ifsc_code" x-model="formData.bank_ifsc_code" class="block w-full border-gray-300 rounded-lg text-sm"></div>

                                    <div class="md:col-span-3 mt-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                                        <div class="flex items-center"><input type="checkbox" name="is_driver" id="is_driver" x-model="isDriver" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"><label for="is_driver" class="ml-2 block text-sm font-bold text-gray-700">This employee is also a Driver</label></div>
                                        
                                        <template x-if="isDriver">
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3 pt-3 border-t border-gray-200">
                                                <input type="hidden" name="driver_id" :value="formData.driver_id || 0">
                                                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">License Number*</label><input type="text" name="license_number" x-model="formData.license_number" :required="isDriver" class="block w-full border-gray-300 rounded-lg text-sm bg-white"></div>
                                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Expiry Date</label><input type="date" name="license_expiry_date" x-model="formData.license_expiry_date" class="block w-full border-gray-300 rounded-lg text-sm bg-white"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-6 mt-2 border-t border-gray-100">
                                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">Save Employee</button>
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
    Alpine.data('employeesApp', () => ({
        employees: [], isLoading: true, search: '', currentPage: 1, totalPages: 1,
        isModalOpen: false, modalError: '', formData: {},
        isDriver: false,
        branches: <?php echo json_encode($branches); ?>,
        unassignedUsers: <?php echo json_encode($unassigned_users); ?>,
        
        init() { this.fetchEmployees(); this.$watch('search', () => { this.currentPage = 1; this.fetchEmployees(); }); },
        async fetchEmployees() {
            this.isLoading = true;
            const params = new URLSearchParams({ search: this.search, page: this.currentPage });
            try {
                const response = await fetch(`manage_employees.php?action=get_employees&${params}`);
                const data = await response.json();
                this.employees = data.employees;
                this.totalPages = data.pagination.total_pages;
                this.currentPage = data.pagination.current_page;
            } catch (error) { console.error('Error fetching employees:', error); } 
            finally { this.isLoading = false; }
        },
        changePage(page) { if (page > 0 && page <= this.totalPages) { this.currentPage = page; this.fetchEmployees(); } },
        resetForm() {
            this.formData = { id: 0, full_name: '', user_id: '', branch_id: '<?php echo $user_role !== 'admin' ? $_SESSION['branch_id'] : ''; ?>', status: 'Active', license_number: '', license_expiry_date: '', driver_id: 0 };
            this.isDriver = false;
            this.modalError = '';
        },
        async openModal(employeeId = 0) {
            this.resetForm();
            if (employeeId) {
                this.modalTitle = 'Edit Employee';
                try {
                    const response = await fetch(`manage_employees.php?action=get_details&id=${employeeId}`);
                    if (!response.ok) throw new Error('Employee not found or access denied.');
                    const data = await response.json();
                    this.formData = data;
                    this.isDriver = !!data.driver_id;
                    if (data.user_id && !this.unassignedUsers.some(u => u.id == data.user_id)) {
                        const userResponse = await fetch(`manage_users.php?action=get_user_details&id=${data.user_id}`); // Ensure this endpoint exists if needed, or rely on pre-load logic
                        // Fallback if endpoint missing: just push a dummy object so the dropdown shows the ID
                        this.unassignedUsers.push({ id: data.user_id, username: `Linked User #${data.user_id}` });
                    }
                } catch (error) { alert(error.message); return; }
            } else {
                this.modalTitle = 'Add New Employee';
            }
            this.isModalOpen = true;
        },
        async saveEmployee() {
            this.modalError = '';
            const formElement = this.$refs.saveForm;
            const formBody = new FormData(formElement);
            formBody.append('id', this.formData.id);
            try {
                const response = await fetch('manage_employees.php?action=save', { method: 'POST', body: formBody });
                const result = await response.json();
                if (result.success) { 
                    this.isModalOpen = false; 
                    // Refresh unassigned users list
                    const userResponse = await fetch('manage_users.php?action=get_unassigned'); // Ensure endpoint
                    if(userResponse.ok) this.unassignedUsers = await userResponse.json();
                    this.fetchEmployees(); 
                } else { 
                    this.modalError = result.message || 'An unknown error occurred.'; 
                }
            } catch (error) { 
                this.modalError = 'A network error occurred.'; 
            }
        }
    }));
});
// Mobile sidebar toggle script
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

// Hide loader
window.onload = function() {
    const loader = document.getElementById('loader');
    if (loader) { loader.style.display = 'none'; }
};
</script>
</body>
</html>