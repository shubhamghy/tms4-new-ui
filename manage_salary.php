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
        $sql = "SELECT e.id, e.full_name, e.employee_code, b.name as branch_name,
                       (SELECT COUNT(*) FROM salary_structures WHERE employee_id = e.id) as salary_set_count
                FROM employees e 
                LEFT JOIN branches b ON e.branch_id = b.id
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

    if ($action === 'get_salary_details') {
        $employee_id = intval($_GET['employee_id'] ?? 0);
        if ($employee_id === 0) { http_response_code(400); echo json_encode(['error' => 'Invalid Employee ID']); exit; }
        
        $sql = "SELECT ss.* FROM salary_structures ss JOIN employees e ON ss.employee_id = e.id WHERE ss.employee_id = ? $branch_filter_aliased";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $salary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode($salary ?: new stdClass()); // Return empty object if no salary is set
        exit;
    }

    if ($action === 'save_salary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        if ($employee_id === 0) { echo json_encode(['success' => false, 'message' => 'Invalid Employee ID.']); exit; }

        // Security Check: Ensure manager is not editing employee from another branch
        if ($user_role !== 'admin') {
            $check_stmt = $mysqli->prepare("SELECT id FROM employees WHERE id = ? AND branch_id = ?");
            $check_stmt->bind_param("ii", $employee_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Permission denied.']); exit;
            }
            $check_stmt->close();
        }

        $effective_date = $_POST['effective_date'];
        // All salary components
        $basic_salary = (float)($_POST['basic_salary'] ?? 0);
        $hra = (float)($_POST['hra'] ?? 0);
        $conveyance_allowance = (float)($_POST['conveyance_allowance'] ?? 0);
        $special_allowance = (float)($_POST['special_allowance'] ?? 0);
        $pf_employee_contribution = (float)($_POST['pf_employee_contribution'] ?? 0);
        $esi_employee_contribution = (float)($_POST['esi_employee_contribution'] ?? 0);
        $professional_tax = (float)($_POST['professional_tax'] ?? 0);
        $tds = (float)($_POST['tds'] ?? 0);

        // Check if a structure already exists
        $check_stmt = $mysqli->prepare("SELECT id FROM salary_structures WHERE employee_id = ?");
        $check_stmt->bind_param("i", $employee_id);
        $check_stmt->execute();
        $existing_id = $check_stmt->get_result()->fetch_assoc()['id'] ?? null;
        $check_stmt->close();

        if ($existing_id) { // UPDATE
            $sql = "UPDATE salary_structures SET effective_date=?, basic_salary=?, hra=?, conveyance_allowance=?, special_allowance=?, pf_employee_contribution=?, esi_employee_contribution=?, professional_tax=?, tds=? WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sddddddddi", $effective_date, $basic_salary, $hra, $conveyance_allowance, $special_allowance, $pf_employee_contribution, $esi_employee_contribution, $professional_tax, $tds, $existing_id);
        } else { // INSERT
            $sql = "INSERT INTO salary_structures (employee_id, effective_date, basic_salary, hra, conveyance_allowance, special_allowance, pf_employee_contribution, esi_employee_contribution, professional_tax, tds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("isdddddddd", $employee_id, $effective_date, $basic_salary, $hra, $conveyance_allowance, $special_allowance, $pf_employee_contribution, $esi_employee_contribution, $professional_tax, $tds);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Salary structure saved successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        $stmt->close();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Salary - TMS</title>
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
                                <i class="fas fa-money-check-alt opacity-80"></i> Salary Management
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
            
            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6" x-data="salaryApp()">
                
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="relative w-full md:max-w-md">
                        <input type="text" x-model.debounce.500ms="search" placeholder="Search employee..." class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                    </div>
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
                                            <p class="text-xs text-gray-500 font-medium" x-text="employee.employee_code"></p>
                                        </div>
                                    </div>
                                    <span :class="employee.salary_set_count > 0 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border border-opacity-20" x-text="employee.salary_set_count > 0 ? 'Configured' : 'Not Set'"></span>
                                </div>
                                
                                <div class="space-y-2 text-sm text-gray-600 mb-2">
                                    <p class="flex items-center justify-between"><span class="text-gray-400">Branch:</span> <span class="font-medium" x-text="employee.branch_name || 'N/A'"></span></p>
                                </div>
                            </div>
                            
                            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                <button @click="openModal(employee)" class="w-full text-indigo-600 hover:text-indigo-800 font-bold text-sm transition flex items-center justify-center"><i class="fas fa-cog mr-2"></i> Configure Salary</button>
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
                        <div class="bg-white rounded-xl overflow-hidden shadow-2xl transform transition-all sm:max-w-4xl w-full relative z-50 border border-gray-100">
                            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-white">Salary Structure: <span x-text="currentEmployee.full_name" class="font-light opacity-90"></span></h3>
                                <button @click="isModalOpen = false" class="text-indigo-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                            </div>
                            
                            <form @submit.prevent="saveSalary" class="p-6">
                                <p class="text-red-600 text-sm mb-4 bg-red-50 p-2 rounded border border-red-100" x-show="modalError" x-text="modalError"></p>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-h-[70vh] overflow-y-auto pr-2 custom-scrollbar">
                                    
                                    <div class="md:col-span-2 space-y-4">
                                        <div class="border-b border-gray-100 pb-2 mb-2"><h4 class="text-xs font-bold text-green-600 uppercase tracking-wide">Earnings</h4></div>
                                        
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Effective Date*</label><input type="date" name="effective_date" x-model="formData.effective_date" required class="block w-full border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Basic Salary</label><input type="number" step="0.01" x-model="formData.basic_salary" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-green-500 focus:border-green-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">HRA</label><input type="number" step="0.01" x-model="formData.hra" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-green-500 focus:border-green-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Conveyance</label><input type="number" step="0.01" x-model="formData.conveyance_allowance" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-green-500 focus:border-green-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Special Allow.</label><input type="number" step="0.01" x-model="formData.special_allowance" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-green-500 focus:border-green-500"></div>
                                        </div>

                                        <div class="border-b border-gray-100 pb-2 mb-2 mt-6"><h4 class="text-xs font-bold text-red-500 uppercase tracking-wide">Deductions</h4></div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PF (Employee)</label><input type="number" step="0.01" x-model="formData.pf_employee_contribution" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-red-500 focus:border-red-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">ESI (Employee)</label><input type="number" step="0.01" x-model="formData.esi_employee_contribution" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-red-500 focus:border-red-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Prof. Tax</label><input type="number" step="0.01" x-model="formData.professional_tax" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-red-500 focus:border-red-500"></div>
                                            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">TDS / Tax</label><input type="number" step="0.01" x-model="formData.tds" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-red-500 focus:border-red-500"></div>
                                        </div>
                                    </div>

                                    <div class="bg-indigo-50 rounded-xl p-5 border border-indigo-100 h-fit">
                                        <h4 class="text-sm font-bold text-indigo-900 uppercase tracking-wide mb-4 text-center">Summary</h4>
                                        <div class="space-y-3 text-sm">
                                            <div class="flex justify-between text-gray-600"><span>Gross Earnings</span><span class="font-bold text-gray-800" x-text="formatCurrency(grossEarnings)"></span></div>
                                            <div class="flex justify-between text-gray-600"><span>Total Deductions</span><span class="font-bold text-red-600" x-text="formatCurrency(totalDeductions)"></span></div>
                                            <div class="border-t border-indigo-200 my-2"></div>
                                            <div class="flex justify-between items-center pt-1">
                                                <span class="text-indigo-900 font-bold text-base">Net Salary</span>
                                                <span class="text-xl font-extrabold text-indigo-700" x-text="formatCurrency(netSalary)"></span>
                                            </div>
                                            <p class="text-xs text-center text-indigo-400 mt-4 italic">Calculated automatically</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end pt-6 mt-2 border-t border-gray-100">
                                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold text-sm shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">Save Structure</button>
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
    Alpine.data('salaryApp', () => ({
        employees: [], isLoading: true, search: '', currentPage: 1, totalPages: 1,
        isModalOpen: false, modalError: '', currentEmployee: {},
        formData: {},
        
        init() { this.fetchEmployees(); this.$watch('search', () => { this.currentPage = 1; this.fetchEmployees(); }); },
        async fetchEmployees() {
            this.isLoading = true;
            const params = new URLSearchParams({ search: this.search, page: this.currentPage });
            try {
                const response = await fetch(`manage_salary.php?action=get_employees&${params}`);
                const data = await response.json();
                this.employees = data.employees;
                this.totalPages = data.pagination.total_pages;
                this.currentPage = data.pagination.current_page;
            } catch (error) { console.error('Error fetching employees:', error); } 
            finally { this.isLoading = false; }
        },
        changePage(page) { if (page > 0 && page <= this.totalPages) { this.currentPage = page; this.fetchEmployees(); } },
        resetForm() {
            this.formData = { employee_id: 0, effective_date: new Date().toISOString().slice(0, 10), basic_salary: 0, hra: 0, conveyance_allowance: 0, special_allowance: 0, pf_employee_contribution: 0, esi_employee_contribution: 0, professional_tax: 0, tds: 0 };
            this.modalError = '';
        },
        async openModal(employee) {
            this.resetForm();
            this.currentEmployee = employee;
            this.formData.employee_id = employee.id;
            try {
                const response = await fetch(`manage_salary.php?action=get_salary_details&employee_id=${employee.id}`);
                const data = await response.json();
                if (Object.keys(data).length > 0) { this.formData = { ...this.formData, ...data }; }
            } catch (error) { console.error('Could not fetch salary details', error); }
            this.isModalOpen = true;
        },
        async saveSalary() {
            this.modalError = '';
            const formBody = new URLSearchParams(this.formData);
            try {
                const response = await fetch('manage_salary.php?action=save_salary', { method: 'POST', body: formBody });
                const result = await response.json();
                if (result.success) { 
                    this.isModalOpen = false; 
                    this.fetchEmployees(); 
                } else { 
                    this.modalError = result.message || 'An unknown error occurred.'; 
                }
            } catch (error) { this.modalError = 'A network error occurred.'; }
        },
        formatCurrency(value) { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(value); },
        get grossEarnings() { return (parseFloat(this.formData.basic_salary) || 0) + (parseFloat(this.formData.hra) || 0) + (parseFloat(this.formData.conveyance_allowance) || 0) + (parseFloat(this.formData.special_allowance) || 0); },
        get totalDeductions() { return (parseFloat(this.formData.pf_employee_contribution) || 0) + (parseFloat(this.formData.esi_employee_contribution) || 0) + (parseFloat(this.formData.professional_tax) || 0) + (parseFloat(this.formData.tds) || 0); },
        get netSalary() { return this.grossEarnings - this.totalDeductions; }
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