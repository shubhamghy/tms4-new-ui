<?php
session_start();
require_once "config.php";

// Access Control
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$vehicle_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($vehicle_id === 0) {
    die("No vehicle ID provided.");
}

// --- 1. Fetch Basic Vehicle Details ---
$stmt = $mysqli->prepare("SELECT v.*, d.name as driver_name FROM vehicles v LEFT JOIN drivers d ON v.driver_id = d.id WHERE v.id = ?");
$stmt->bind_param("i", $vehicle_id);
$stmt->execute();
$result = $stmt->get_result();
$vehicle = $result->fetch_assoc();
$stmt->close();

if (!$vehicle) {
    die("Vehicle not found.");
}

// --- 2. Fetch Financial & Operational Summaries ---
$summary = [];

// Fuel Summary
$fuel_sql = "SELECT COUNT(id) as total_fuel_logs, SUM(total_cost) as total_fuel_cost, SUM(fuel_quantity) as total_fuel_qty FROM fuel_logs WHERE vehicle_id = ?";
$stmt_fuel = $mysqli->prepare($fuel_sql);
$stmt_fuel->bind_param("i", $vehicle_id);
$stmt_fuel->execute();
$summary['fuel'] = $stmt_fuel->get_result()->fetch_assoc();
$stmt_fuel->close();

// Maintenance Summary
$maint_sql = "SELECT COUNT(id) as total_maint_logs, SUM(service_cost) as total_maint_cost FROM maintenance_logs WHERE vehicle_id = ?";
$stmt_maint = $mysqli->prepare($maint_sql);
$stmt_maint->bind_param("i", $vehicle_id);
$stmt_maint->execute();
$summary['maintenance'] = $stmt_maint->get_result()->fetch_assoc();
$stmt_maint->close();

// Other Expenses Summary
$exp_sql = "SELECT COUNT(id) as total_exp_logs, SUM(amount) as total_other_expense FROM expenses WHERE vehicle_id = ?";
$stmt_exp = $mysqli->prepare($exp_sql);
$stmt_exp->bind_param("i", $vehicle_id);
$stmt_exp->execute();
$summary['expenses'] = $stmt_exp->get_result()->fetch_assoc();
$stmt_exp->close();

// Calculate Total Running Cost
$total_running_cost = ($summary['fuel']['total_fuel_cost'] ?? 0) + ($summary['maintenance']['total_maint_cost'] ?? 0) + ($summary['expenses']['total_other_expense'] ?? 0);

// --- 3. Fetch Recent Activity Logs (Last 5) ---
$recent_fuel = $mysqli->query("SELECT * FROM fuel_logs WHERE vehicle_id = $vehicle_id ORDER BY log_date DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$recent_maint = $mysqli->query("SELECT * FROM maintenance_logs WHERE vehicle_id = $vehicle_id ORDER BY service_date DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$recent_exp = $mysqli->query("SELECT * FROM expenses WHERE vehicle_id = $vehicle_id ORDER BY expense_date DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Helper function for expiry date styling
function getExpiryBadge($date_str) {
    if (empty($date_str)) return '<span class="text-gray-500">N/A</span>';
    $expiry_date = new DateTime($date_str);
    $now = new DateTime();
    $diff = $now->diff($expiry_date);
    $days_left = (int)$diff->format('%r%a');

    if ($days_left < 0) {
        return '<span class="font-semibold text-red-600">Expired</span>';
    } elseif ($days_left <= 30) {
        return '<span class="font-semibold text-yellow-600">' . $days_left . ' days left</span>';
    } else {
        return '<span class="font-semibold text-green-600">' . date("d M, Y", strtotime($date_str)) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Profile: <?php echo htmlspecialchars($vehicle['vehicle_number']); ?> - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 relative">
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                         <h1 class="text-xl font-semibold text-gray-800">Vehicle Profile</h1>
                        <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8">
                
                <div class="bg-white p-6 sm:p-8 rounded-xl shadow-md mb-8">
                    <div class="flex flex-col sm:flex-row justify-between sm:items-center">
                        <div>
                            <h2 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></h2>
                            <p class="text-gray-500"><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></p>
                        </div>
                        <div class="mt-4 sm:mt-0 flex space-x-2">
                             <a href="manage_vehicles.php?action=edit&id=<?php echo $vehicle['id']; ?>" class="py-2 px-4 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Edit Vehicle</a>
                             <a href="manage_vehicles.php" class="py-2 px-4 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Back to List</a>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    <div class="bg-blue-500 text-white p-6 rounded-xl shadow-lg"><h4 class="text-lg opacity-80">Total Running Cost</h4><p class="text-3xl font-bold">₹<?php echo number_format($total_running_cost, 2); ?></p></div>
                    <div class="bg-orange-500 text-white p-6 rounded-xl shadow-lg"><h4 class="text-lg opacity-80">Fuel Expenses</h4><p class="text-3xl font-bold">₹<?php echo number_format($summary['fuel']['total_fuel_cost'] ?? 0, 2); ?></p></div>
                    <div class="bg-teal-500 text-white p-6 rounded-xl shadow-lg"><h4 class="text-lg opacity-80">Maintenance Expenses</h4><p class="text-3xl font-bold">₹<?php echo number_format($summary['maintenance']['total_maint_cost'] ?? 0, 2); ?></p></div>
                    <div class="bg-gray-500 text-white p-6 rounded-xl shadow-lg"><h4 class="text-lg opacity-80">Other Expenses</h4><p class="text-3xl font-bold">₹<?php echo number_format($summary['expenses']['total_other_expense'] ?? 0, 2); ?></p></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-md">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Vehicle Information</h3>
                        <div class="space-y-3 text-sm">
                            <p><strong>Owner:</strong> <?php echo htmlspecialchars($vehicle['owner_name']); ?></p>
                            <p><strong>Ownership:</strong> <?php echo htmlspecialchars($vehicle['ownership_type']); ?></p>
                            <p><strong>Assigned Driver:</strong> <?php echo htmlspecialchars($vehicle['driver_name'] ?? 'N/A'); ?></p>
                            <p><strong>Registration Date:</strong> <?php echo $vehicle['registration_date'] ? date("d M, Y", strtotime($vehicle['registration_date'])) : 'N/A'; ?></p>
                        </div>
                    </div>
                    <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-md">
                         <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Document Status</h3>
                         <div class="space-y-3 text-sm">
                            <div class="flex justify-between items-center"><span>Insurance Expiry:</span> <?php echo getExpiryBadge($vehicle['insurance_expiry']); ?></div>
                            <div class="flex justify-between items-center"><span>Tax Expiry:</span> <?php echo getExpiryBadge($vehicle['tax_expiry']); ?></div>
                            <div class="flex justify-between items-center"><span>Fitness Expiry:</span> <?php echo getExpiryBadge($vehicle['fitness_expiry']); ?></div>
                            <div class="flex justify-between items-center"><span>Permit Expiry:</span> <?php echo getExpiryBadge($vehicle['permit_expiry']); ?></div>
                            <div class="flex justify-between items-center"><span>PUC Expiry:</span> <?php echo getExpiryBadge($vehicle['puc_expiry']); ?></div>
                         </div>
                    </div>
                     <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-md">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Recent Expenses</h3>
                        <ul class="space-y-2 text-sm">
                           <?php foreach($recent_fuel as $log): ?>
                                <li class="flex justify-between"><span>Fuel on <?php echo date('d/m', strtotime($log['log_date'])); ?></span> <span class="font-semibold">₹<?php echo number_format($log['total_cost'], 2); ?></span></li>
                           <?php endforeach; ?>
                           <?php foreach($recent_maint as $log): ?>
                                <li class="flex justify-between"><span><?php echo $log['service_type']; ?></span> <span class="font-semibold">₹<?php echo number_format($log['service_cost'], 2); ?></span></li>
                           <?php endforeach; ?>
                           <?php foreach($recent_exp as $log): ?>
                                <li class="flex justify-between"><span><?php echo $log['category']; ?></span> <span class="font-semibold">₹<?php echo number_format($log['amount'], 2); ?></span></li>
                           <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

            </main>
        </div>
    </div>
</body>
</html>