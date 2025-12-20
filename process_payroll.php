<?php
session_start();
require_once "config.php";

// ===================================================================================
// --- SECTION 1: PAGE SETUP AND PERMISSIONS ---
// ===================================================================================

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = $_SESSION['role'] ?? '';
$can_manage = in_array($user_role, ['admin', 'manager']);
if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}

$form_message = "";
$skipped_employees = [];

// ===================================================================================
// --- SECTION 2: PAYROLL PROCESSING LOGIC (Handles Form Submission) ---
// ===================================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['month_year'])) {
    $month_year = $_POST['month_year']; // Format: YYYY-MM
    $process_branch_id = ($user_role === 'admin' && isset($_POST['branch_id'])) ? intval($_POST['branch_id']) : $_SESSION['branch_id'];
    
    if (empty($month_year) || ($user_role === 'admin' && empty($process_branch_id))) {
        $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Error: Please select a month, year, and branch to process.</div>';
    } else {
        // --- Start of Payroll Processing ---
        $mysqli->begin_transaction();
        try {
            // 1. Check if payroll for this month and branch has already been run
            $check_sql = "SELECT COUNT(p.id) FROM payslips p JOIN employees e ON p.employee_id = e.id WHERE p.month_year = ? AND e.branch_id = ?";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("si", $month_year, $process_branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->fetch_row()[0] > 0) {
                throw new Exception("Payroll for this month and branch has already been processed.");
            }
            $check_stmt->close();

            // 2. Get all active employees for the selected branch
            $employee_sql = "SELECT id, full_name FROM employees WHERE status = 'Active' AND branch_id = ?";
            $emp_stmt = $mysqli->prepare($employee_sql);
            $emp_stmt->bind_param("i", $process_branch_id);
            $emp_stmt->execute();
            $employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $emp_stmt->close();

            if (empty($employees)) {
                throw new Exception("No active employees found for this branch.");
            }

            // 3. Prepare statement for inserting payslips
            $payslip_sql = "INSERT INTO payslips (employee_id, month_year, payable_days, gross_earnings, total_deductions, net_salary, status) VALUES (?, ?, ?, ?, ?, ?, 'Generated')";
            $payslip_stmt = $mysqli->prepare($payslip_sql);

            $processed_count = 0;
            // 4. Loop through each employee to calculate and save their payslip
            foreach ($employees as $employee) {
                $employee_id = $employee['id'];
                
                // Get the latest applicable salary structure
                $salary_sql = "SELECT * FROM salary_structures WHERE employee_id = ? AND effective_date <= LAST_DAY(?) ORDER BY effective_date DESC LIMIT 1";
                $salary_stmt = $mysqli->prepare($salary_sql);
                $month_date_for_query = $month_year . "-01";
                $salary_stmt->bind_param("is", $employee_id, $month_date_for_query);
                $salary_stmt->execute();
                $structure = $salary_stmt->get_result()->fetch_assoc();
                $salary_stmt->close();
                
                if (!$structure) {
                    $skipped_employees[] = $employee['full_name']; // Add name to skipped list
                    continue;
                }

                // --- Perform Calculations ---
                $payable_days = 30; // NOTE: For now, we assume 30 days. This can be enhanced later with an attendance table.
                
                $gross_earnings = $structure['basic_salary'] + $structure['hra'] + $structure['conveyance_allowance'] + $structure['special_allowance'];
                
                $total_deductions = $structure['pf_employee_contribution'] + $structure['esi_employee_contribution'] + $structure['professional_tax'] + $structure['tds'];
                
                $net_salary = $gross_earnings - $total_deductions;

                // Bind and execute the insert statement for the payslip
                $payslip_stmt->bind_param("isddds", $employee_id, $month_year, $payable_days, $gross_earnings, $total_deductions, $net_salary);
                if(!$payslip_stmt->execute()) {
                    throw new Exception("Failed to save payslip for employee ID {$employee_id}.");
                }
                $processed_count++;
            }
            $payslip_stmt->close();

            // 5. If all successful, commit the transaction
            $mysqli->commit();
            $form_message = "<div class='p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50'>Payroll for <strong>{$month_year}</strong> processed successfully for <strong>{$processed_count}</strong> employees.</div>";
            if (!empty($skipped_employees)) {
                 $skipped_list = implode(', ', $skipped_employees);
                 $form_message .= "<div class='p-4 mt-2 text-sm text-yellow-800 rounded-lg bg-yellow-50'><strong>" . count($skipped_employees) . "</strong> employee(s) were skipped because their salary structure was not set: " . htmlspecialchars($skipped_list) . "</div>";
            }

        } catch (Exception $e) {
            $mysqli->rollback();
            $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Error: ' . $e->getMessage() . '</div>';
        }
    }
}

// ===================================================================================
// --- SECTION 3: PAGE LOAD DATA (Payroll History) ---
// ===================================================================================

$payroll_history = [];
$branch_filter_clause = "";
if ($user_role !== 'admin') {
    $branch_filter_clause = " WHERE e.branch_id = " . intval($_SESSION['branch_id']);
}
// ✅ MODIFIED: Added b.id as branch_id to the SELECT statement
$history_sql = "SELECT p.month_year, b.name as branch_name, b.id as branch_id, COUNT(p.id) as employee_count, 
                       SUM(CASE WHEN p.status = 'Paid' THEN 1 ELSE 0 END) as paid_count,
                       SUM(p.net_salary) as total_payout
                FROM payslips p
                JOIN employees e ON p.employee_id = e.id
                JOIN branches b ON e.branch_id = b.id
                $branch_filter_clause
                GROUP BY p.month_year, b.name, b.id
                ORDER BY p.month_year DESC";

if($result = $mysqli->query($history_sql)) {
    $payroll_history = $result->fetch_all(MYSQLI_ASSOC);
}
$branches = $mysqli->query("SELECT id, name FROM branches ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payroll - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 overflow-y-auto">
            <header class="bg-white shadow-sm border-b border-gray-200">
                 <div class="mx-auto px-4 sm:px-6 lg:px-8"><div class="flex justify-between items-center h-16"><button id="sidebar-toggle" class="text-gray-500 hover:text-gray-600 md:hidden"><i class="fas fa-bars fa-lg"></i></button><h1 class="text-xl font-semibold text-gray-800">Process Payroll</h1><a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a></div></div>
            </header>
            
            <main class="p-4 md:p-8">
                <?php if(!empty($form_message)) echo $form_message; ?>

                <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Run New Payroll</h2>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label for="month_year" class="block text-sm font-medium">Select Month & Year*</label>
                            <input type="month" id="month_year" name="month_year" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                        <?php if ($user_role === 'admin'): ?>
                        <div>
                            <label for="branch_id" class="block text-sm font-medium">For Branch*</label>
                            <select id="branch_id" name="branch_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-white">
                                <option value="">Select a Branch</option>
                                <?php foreach($branches as $branch) { echo "<option value='{$branch['id']}'>".htmlspecialchars($branch['name'])."</option>"; } ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div>
                            <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                                <i class="fas fa-cogs mr-2"></i> Process Payroll
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Payroll History</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Month/Year</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Branch</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employees</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Total Payout</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($payroll_history)): ?>
                                    <tr><td colspan="5" class="text-center py-6 text-gray-500">No payroll history found.</td></tr>
                                <?php else: foreach ($payroll_history as $history): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?php echo date("F Y", strtotime($history['month_year'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($history['branch_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $history['paid_count'] . ' / ' . $history['employee_count']; ?> Paid</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₹<?php echo number_format($history['total_payout'], 2); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="view_payslips.php?month_year=<?php echo $history['month_year']; ?>&branch_id=<?php echo $history['branch_id']; ?>" class="text-indigo-600 hover:text-indigo-900">View Payslips</a>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
// Mobile sidebar toggle script
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    function toggleSidebar() { if (sidebar && sidebarOverlay) { sidebar.classList.toggle('-translate-x-full'); sidebarOverlay.classList.toggle('hidden'); } }
    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
});
</script>
</body>
</html>