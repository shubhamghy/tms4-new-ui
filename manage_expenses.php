<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- Page State & Data ---
$form_message = "";
$edit_mode = false;
$add_mode = false;
$expense_data = ['id' => '', 'expense_date' => date('Y-m-d'), 'category' => '', 'amount' => '', 'paid_to' => '', 'shipment_id' => null, 'vehicle_id' => null, 'employee_id' => null, 'description' => ''];
$expense_categories = ['Fuel', 'Maintenance', 'Toll', 'Salary', 'Office Rent', 'Utilities', 'Repair', 'Other'];

// --- Form Submission (Add/Edit) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $expense_date = $_POST['expense_date'];
    $category = trim($_POST['category']);
    $amount = (float)$_POST['amount'];
    $paid_to = trim($_POST['paid_to']);
    $shipment_id = !empty($_POST['shipment_id']) ? intval($_POST['shipment_id']) : null;
    $vehicle_id = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $employee_id = !empty($_POST['employee_id']) ? intval($_POST['employee_id']) : null;
    $description = trim($_POST['description']);
    $branch_id = $_SESSION['branch_id'];
    $created_by = $_SESSION['id'];

    if ($id > 0) { // Update
        $sql = "UPDATE expenses SET expense_date=?, category=?, amount=?, paid_to=?, shipment_id=?, vehicle_id=?, employee_id=?, description=? WHERE id=? AND branch_id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssdsiiisii", $expense_date, $category, $amount, $paid_to, $shipment_id, $vehicle_id, $employee_id, $description, $id, $branch_id);
    } else { // Insert
        $sql = "INSERT INTO expenses (expense_date, category, amount, paid_to, shipment_id, vehicle_id, employee_id, description, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssdsiiisii", $expense_date, $category, $amount, $paid_to, $shipment_id, $vehicle_id, $employee_id, $description, $branch_id, $created_by);
    }

    if ($stmt->execute()) {
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Expense saved successfully!</div>';
    } else {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error saving expense: '. $stmt->error .'</div>';
    }
    $stmt->close();
}

// --- Handle GET Actions (Edit, Delete) ---
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif ($_GET['action'] == 'edit' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM expenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $expense_data = $result->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    } elseif ($_GET['action'] == 'delete' && $id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $form_message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Expense deleted successfully.</div>";
        } else {
            $form_message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error deleting expense.</div>";
        }
        $stmt->close();
    }
}

// --- Data Fetching for Lists/Dropdowns ---
$expenses_list = [];
$shipments = [];
$vehicles = [];
$employees = [];

$branch_filter_clause = ($_SESSION['role'] !== 'admin') ? " WHERE branch_id = " . intval($_SESSION['branch_id']) : "";
$employees = $mysqli->query("SELECT id, full_name, employee_code FROM employees{$branch_filter_clause} ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

if ($add_mode || $edit_mode) {
    $shipments = $mysqli->query("SELECT id, consignment_no FROM shipments WHERE status != 'Completed' ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
    $vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);
} else {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9;
    $offset = ($page - 1) * $records_per_page;
    
    $search_term = trim($_GET['search'] ?? '');
    $where_sql = " WHERE e.branch_id = ?";
    $params = [$_SESSION['branch_id']];
    $types = "i";
    
    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_sql .= " AND (e.category LIKE ? OR e.paid_to LIKE ? OR s.consignment_no LIKE ? OR v.vehicle_number LIKE ?)";
        array_push($params, $like_term, $like_term, $like_term, $like_term);
        $types .= "ssss";
    }

    $count_sql = "SELECT COUNT(e.id) FROM expenses e LEFT JOIN shipments s ON e.shipment_id = s.id LEFT JOIN vehicles v ON e.vehicle_id = v.id" . $where_sql;
    $stmt_count = $mysqli->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    $list_sql = "SELECT e.*, s.consignment_no, v.vehicle_number 
                 FROM expenses e 
                 LEFT JOIN shipments s ON e.shipment_id = s.id 
                 LEFT JOIN vehicles v ON e.vehicle_id = v.id" . $where_sql . " 
                 ORDER BY e.expense_date DESC, e.id DESC LIMIT ? OFFSET ?";
    
    $params[] = $records_per_page;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    
    $stmt_list->execute();
    $expenses_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Expenses - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.5rem; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
        [x-cloak] { display: none; }
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
                                <i class="fas fa-wallet opacity-80"></i> Manage Expenses
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
            
            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6" x-data="{ expenseCategory: '<?php echo htmlspecialchars($expense_data['category'] ?: ''); ?>' }">
                <?php if(!empty($form_message)) echo $form_message; ?>

                <?php if ($add_mode || $edit_mode): ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-4xl mx-auto">
                    <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2">
                            <i class="fas fa-edit text-indigo-500"></i> <?php echo $edit_mode ? 'Edit Expense' : 'Add New Expense'; ?>
                        </h2>
                        <a href="manage_expenses.php" class="text-sm font-medium text-gray-600 hover:text-gray-900"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                    
                    <form method="POST" class="p-6 md:p-8 space-y-8">
                        <input type="hidden" name="id" value="<?php echo $expense_data['id']; ?>">
                        
                        <div>
                            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-4">Transaction Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Expense Date <span class="text-red-500">*</span></label>
                                    <input type="date" name="expense_date" value="<?php echo htmlspecialchars($expense_data['expense_date']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Category <span class="text-red-500">*</span></label>
                                    <select name="category" x-model="expenseCategory" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white" required>
                                        <?php foreach($expense_categories as $cat): ?>
                                            <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (₹) <span class="text-red-500">*</span></label>
                                    <input type="number" step="0.01" name="amount" value="<?php echo htmlspecialchars($expense_data['amount']); ?>" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                                
                                <div class="md:col-span-3" x-show="expenseCategory !== 'Salary'" x-cloak>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Paid To</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-user-tag text-gray-400"></i></div>
                                        <input type="text" name="paid_to" value="<?php echo htmlspecialchars($expense_data['paid_to']); ?>" placeholder="e.g., Indian Oil, NHAI Toll Plaza" class="pl-10 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                </div>
                                
                                <div class="md:col-span-3" x-show="expenseCategory === 'Salary'" x-cloak>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Employee <span class="text-red-500">*</span></label>
                                    <select name="employee_id" class="searchable-select block w-full">
                                        <option value="">Select an Employee</option>
                                        <?php foreach($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php if($expense_data['employee_id'] == $emp['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($emp['full_name'] . ($emp['employee_code'] ? ' (' . $emp['employee_code'] . ')' : '')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wide border-b pb-2 mb-4">Associations (Optional)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Link to Shipment (LR)</label>
                                    <select name="shipment_id" class="searchable-select block w-full">
                                        <option value="">None</option>
                                        <?php foreach($shipments as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php if($expense_data['shipment_id'] == $s['id']) echo 'selected'; ?>><?php echo htmlspecialchars($s['consignment_no']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Link to Vehicle</label>
                                    <select name="vehicle_id" class="searchable-select block w-full">
                                        <option value="">None</option>
                                        <?php foreach($vehicles as $v): ?>
                                            <option value="<?php echo $v['id']; ?>" <?php if($expense_data['vehicle_id'] == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description / Notes</label>
                                    <textarea name="description" rows="3" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($expense_data['description']); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-4 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Expense' : 'Save Expense'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                <div class="space-y-6">
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <form method="GET" action="manage_expenses.php" class="w-full md:w-auto flex-1 flex gap-2">
                            <div class="relative w-full md:max-w-md">
                                <input type="text" name="search" placeholder="Search category, paid to, LR no..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                            </div>
                            <button type="submit" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition"><i class="fas fa-arrow-right"></i></button>
                            <?php if(!empty($search_term)): ?>
                                <a href="manage_expenses.php" class="px-4 py-2.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 shadow-sm transition">Reset</a>
                            <?php endif; ?>
                        </form>
                        <a href="manage_expenses.php?action=add" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-lg font-bold text-sm text-white shadow-md hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Add New Expense
                        </a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php if (empty($expenses_list)): ?>
                            <div class="md:col-span-2 xl:col-span-3 flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-dashed border-gray-300 text-gray-400">
                                <i class="fas fa-receipt fa-3x mb-4 opacity-50"></i>
                                <p class="text-lg font-medium">No expenses found.</p>
                                <p class="text-sm">Click 'Add New Expense' to get started.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($expenses_list as $expense): ?>
                            <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                                <div class="p-6 flex-grow">
                                    <div class="flex justify-between items-start mb-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 uppercase tracking-wide border border-indigo-100">
                                            <?php echo htmlspecialchars($expense['category']); ?>
                                        </span>
                                        <span class="text-xs font-medium text-gray-400"><?php echo date("d M, Y", strtotime($expense['expense_date'])); ?></span>
                                    </div>
                                    
                                    <div class="flex items-baseline mb-4">
                                        <span class="text-2xl font-bold text-gray-900">₹<?php echo number_format($expense['amount'], 2); ?></span>
                                    </div>
                                    
                                    <div class="space-y-2 text-sm text-gray-600">
                                        <p class="flex items-center"><i class="fas fa-user-tag w-5 text-gray-400"></i> <?php echo htmlspecialchars($expense['paid_to'] ?: 'N/A'); ?></p>
                                        <?php if($expense['consignment_no']): ?>
                                            <p class="flex items-center"><i class="fas fa-box w-5 text-indigo-400"></i> <span class="font-medium text-indigo-600"><?php echo htmlspecialchars($expense['consignment_no']); ?></span></p>
                                        <?php endif; ?>
                                        <?php if($expense['vehicle_number']): ?>
                                            <p class="flex items-center"><i class="fas fa-truck w-5 text-gray-400"></i> <?php echo htmlspecialchars($expense['vehicle_number']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 opacity-80 group-hover:opacity-100 transition-opacity">
                                    <a href="manage_expenses.php?action=edit&id=<?php echo $expense['id']; ?>" class="text-sm font-bold text-indigo-600 hover:text-indigo-800 transition"><i class="fas fa-edit mr-1"></i> Edit</a>
                                    <a href="manage_expenses.php?action=delete&id=<?php echo $expense['id']; ?>" class="text-sm font-bold text-red-600 hover:text-red-800 transition" onclick="return confirm('Are you sure you want to delete this expense?');"><i class="fas fa-trash-alt mr-1"></i> Delete</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
        
        // --- Mobile sidebar toggle ---
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarClose = document.getElementById('close-sidebar-btn');

        function toggleSidebar() {
            if (sidebarWrapper.classList.contains('hidden')) {
                // Open Sidebar
                sidebarWrapper.classList.remove('hidden');
                sidebarWrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.remove('hidden');
            } else {
                // Close Sidebar
                sidebarWrapper.classList.add('hidden');
                sidebarWrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.add('hidden');
            }
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
    });

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>