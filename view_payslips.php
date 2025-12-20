<?php
session_start();
require_once "config.php";

// ===================================================================================
// --- SECTION 1: API LOGIC (For Marking Payslips as Paid) ---
// ===================================================================================
if (isset($_GET['action']) && $_GET['action'] === 'mark_paid') {
    header('Content-Type: application/json');
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit;
    }
    
    $payslip_id = intval($_POST['payslip_id'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? null;
    $payment_mode = trim($_POST['payment_mode'] ?? '');
    $reference_no = trim($_POST['reference_no'] ?? '');

    if($payslip_id > 0 && !empty($payment_date) && !empty($payment_mode)) {
        $user_role = $_SESSION['role'] ?? '';
        $branch_id = $_SESSION['branch_id'] ?? 0;
        $can_update = false;
        if ($user_role === 'admin') {
            $can_update = true;
        } else {
            $check_sql = "SELECT p.id FROM payslips p JOIN employees e ON p.employee_id = e.id WHERE p.id = ? AND e.branch_id = ?";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("ii", $payslip_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) { $can_update = true; }
            $check_stmt->close();
        }

        if ($can_update) {
            $update_sql = "UPDATE payslips SET status='Paid', payment_date=?, payment_mode=?, reference_no=? WHERE id=?";
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param("sssi", $payment_date, $payment_mode, $reference_no, $payslip_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database update failed.']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    }
    exit;
}

// ===================================================================================
// --- SECTION 2: REGULAR PAGE LOAD LOGIC ---
// ===================================================================================

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) { header("location: index.php"); exit; }
$user_role = $_SESSION['role'] ?? '';
$can_manage = in_array($user_role, ['admin', 'manager']);
if (!$can_manage) { header("location: dashboard.php"); exit; }

$month_year = $_GET['month_year'] ?? '';
$branch_id = intval($_GET['branch_id'] ?? 0);

if ($user_role !== 'admin' && $branch_id !== intval($_SESSION['branch_id'])) {
    die("Access Denied: You do not have permission to view this branch's data.");
}

$payslips = [];
$summary = ['total_payout' => 0, 'paid_count' => 0, 'employee_count' => 0, 'branch_name' => 'N/A'];

if (!empty($month_year) && $branch_id > 0) {
    // ✅ CORRECTED QUERY: This now joins with the salary_structures table to get the
    // historically accurate breakdown of earnings and deductions for each payslip.
    $sql = "SELECT 
                p.*, e.full_name, e.employee_code,
                ss.basic_salary, ss.hra, ss.conveyance_allowance, ss.special_allowance, 
                ss.pf_employee_contribution, ss.esi_employee_contribution, ss.professional_tax, ss.tds
            FROM payslips p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN salary_structures ss ON ss.id = (
                SELECT id FROM salary_structures 
                WHERE employee_id = e.id AND effective_date <= LAST_DAY(CONCAT(p.month_year, '-01'))
                ORDER BY effective_date DESC 
                LIMIT 1
            )
            WHERE p.month_year = ? AND e.branch_id = ?
            ORDER BY e.full_name ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("si", $month_year, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payslips = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate summary
    foreach ($payslips as $p) {
        $summary['total_payout'] += $p['net_salary'];
        if ($p['status'] === 'Paid') { $summary['paid_count']++; }
    }
    $summary['employee_count'] = count($payslips);
    $branch_name_res = $mysqli->query("SELECT name FROM branches WHERE id = $branch_id");
    $summary['branch_name'] = $branch_name_res->fetch_assoc()['name'] ?? 'Unknown Branch';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Payslips - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> body { font-family: 'Inter', sans-serif; } [x-cloak] { display: none; } </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 overflow-y-auto">
            <header class="bg-white shadow-sm border-b border-gray-200">
                 <div class="mx-auto px-4 sm:px-6 lg:px-8"><div class="flex justify-between items-center h-16"><button id="sidebar-toggle" class="text-gray-500 hover:text-gray-600 md:hidden"><i class="fas fa-bars fa-lg"></i></button><h1 class="text-xl font-semibold text-gray-800">View Payslips</h1><a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a></div></div>
            </header>
            
            <main class="p-4 md:p-8" x-data="payslipsApp()">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Payroll for <?php echo date("F Y", strtotime($month_year)); ?></h2>
                    <p class="text-md text-gray-600"><?php echo htmlspecialchars($summary['branch_name']); ?></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white p-4 rounded-lg shadow"><p class="text-sm text-gray-500">Total Payout</p><p class="text-2xl font-bold">₹<?php echo number_format($summary['total_payout'], 2); ?></p></div>
                    <div class="bg-white p-4 rounded-lg shadow"><p class="text-sm text-gray-500">Total Employees</p><p class="text-2xl font-bold"><?php echo $summary['employee_count']; ?></p></div>
                    <div class="bg-white p-4 rounded-lg shadow"><p class="text-sm text-gray-500">Payment Status</p><p class="text-2xl font-bold"><?php echo $summary['paid_count'] . ' / ' . $summary['employee_count']; ?> Paid</p></div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employee</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Gross Earnings</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Deductions</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Net Salary</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <template x-for="payslip in payslips" :key="payslip.id">
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><p x-text="payslip.full_name"></p><p class="text-xs text-gray-500" x-text="payslip.employee_code"></p></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="formatCurrency(payslip.gross_earnings)"></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" x-text="formatCurrency(payslip.total_deductions)"></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-800" x-text="formatCurrency(payslip.net_salary)"></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><span :class="payslip.status === 'Paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" x-text="payslip.status"></span></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-4">
                                            <a :href="`print_payslip.php?id=${payslip.id}`" target="_blank" class="text-gray-600 hover:text-indigo-900">View/Print</a>
                                            <button x-show="payslip.status !== 'Paid'" @click="openModal(payslip)" class="text-indigo-600 hover:text-indigo-900">Mark as Paid</button>
                                        </td>
                                    </tr>
                                </template>
                                <?php if (empty($payslips)): ?><tr><td colspan="6" class="text-center py-6 text-gray-500">No payslips found for this period.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="isModalOpen" x-trap.inert.noscroll="isModalOpen" class="fixed inset-0 z-30 overflow-y-auto" x-cloak>
                    <div class="flex items-center justify-center min-h-screen"><div x-show="isModalOpen" x-transition.opacity @click="isModalOpen = false" class="fixed inset-0 bg-gray-500 opacity-75"></div><div x-show="isModalOpen" x-transition class="bg-white rounded-lg overflow-hidden shadow-xl transform sm:max-w-md sm:w-full">
                            <form @submit.prevent="markAsPaid">
                                <div class="px-6 py-4"><h3 class="text-lg font-medium">Mark Payment as Paid</h3><p class="text-sm text-gray-600">For <strong x-text="currentPayslip.full_name"></strong> - <strong x-text="formatCurrency(currentPayslip.net_salary)"></strong></p><p class="text-red-600 text-sm mt-2" x-text="modalError"></p>
                                    <div class="mt-4 space-y-4">
                                        <div><label class="block text-sm">Payment Date*</label><input type="date" x-model="formData.payment_date" required class="mt-1 w-full p-2 border rounded-md"></div>
                                        <div><label class="block text-sm">Payment Mode*</label><select x-model="formData.payment_mode" required class="mt-1 w-full p-2 border rounded-md bg-white"><option>Bank Transfer</option><option>Cheque</option><option>Cash</option></select></div>
                                        <div><label class="block text-sm">Reference / Cheque No.</label><input type="text" x-model="formData.reference_no" class="mt-1 w-full p-2 border rounded-md"></div>
                                    </div>
                                </div>
                                <div class="bg-gray-50 px-6 py-3 flex justify-end space-x-3"><button type="button" @click="isModalOpen = false" class="px-4 py-2 bg-white border rounded-md">Cancel</button><button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Confirm Payment</button></div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('payslipsApp', () => ({
        payslips: <?php echo json_encode($payslips); ?>,
        isModalOpen: false, modalError: '', currentPayslip: {}, formData: {},
        
        openModal(payslip) {
            this.currentPayslip = payslip;
            this.formData = {
                payslip_id: payslip.id,
                payment_date: new Date().toISOString().slice(0, 10),
                payment_mode: 'Bank Transfer',
                reference_no: ''
            };
            this.isModalOpen = true;
        },
        async markAsPaid() {
            this.modalError = '';
            const formBody = new URLSearchParams(this.formData);
            try {
                const response = await fetch('view_payslips.php?action=mark_paid', { method: 'POST', body: formBody });
                const result = await response.json();
                if (result.success) {
                    const index = this.payslips.findIndex(p => p.id === this.formData.payslip_id);
                    if (index !== -1) {
                        this.payslips[index].status = 'Paid';
                        this.payslips[index].payment_date = this.formData.payment_date;
                    }
                    this.isModalOpen = false;
                } else {
                    this.modalError = result.message || 'An unknown error occurred.';
                }
            } catch (error) {
                this.modalError = 'A network error occurred.';
            }
        },
        formatCurrency(value) {
            if (isNaN(value) || value === null) return '₹0.00';
            return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(value);
        }
    }));
});
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