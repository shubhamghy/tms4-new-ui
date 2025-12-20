<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- Filter Handling ---
$vehicle_filter = $_GET['vehicle_id'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date_range'] ?? 'all';

// --- Data Fetching ---
$where_clauses = [];
$params = [];
$types = "";

if (!empty($vehicle_filter)) {
    $where_clauses[] = "m.vehicle_id = ?";
    $params[] = $vehicle_filter;
    $types .= "i";
}

$today = date('Y-m-d');
if ($status_filter === 'due') {
    $where_clauses[] = "m.next_service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($status_filter === 'overdue') {
    $where_clauses[] = "m.next_service_date < CURDATE()";
}

$sql = "SELECT 
            v.vehicle_number,
            m.service_type,
            m.service_date AS last_service_date,
            m.next_service_date,
            m.service_cost,
            m.vendor_name,
            DATEDIFF(m.next_service_date, CURDATE()) as days_diff
        FROM maintenance_logs m
        JOIN vehicles v ON m.vehicle_id = v.id
        WHERE m.id IN (
            SELECT MAX(id) 
            FROM maintenance_logs 
            GROUP BY vehicle_id, service_type
        )
        ";

if (!empty($where_clauses)) {
    $sql .= " AND " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY days_diff ASC";


$stmt = $mysqli->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$maintenance_schedule = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch vehicles for the filter dropdown
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);

function getStatusBadge($days_diff) {
    if ($days_diff === null) {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Not Set</span>';
    }
    if ($days_diff < 0) {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Overdue</span>';
    } elseif ($days_diff <= 30) {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Due Soon</span>';
    } else {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Scheduled</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Maintenance Schedule - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 relative">
            <header class="bg-white shadow-sm border-b border-gray-200 no-print">
                <div class="mx-auto px-4 sm:px-6 lg:px-8"><div class="flex justify-between items-center h-16"><h1 class="text-xl font-semibold text-gray-800">Vehicle Maintenance Schedule</h1></div></div>
            </header>
            <main class="flex-1 overflow-y-auto bg-gray-100 p-4 md:p-8">
                <div class="bg-white p-4 rounded-xl shadow-md mb-6 no-print">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div class="md:col-span-2">
                            <label for="vehicle_id" class="block text-sm font-medium text-gray-700">Filter by Vehicle</label>
                            <select name="vehicle_id" id="vehicle_id" class="searchable-select mt-1 block w-full">
                                <option value="">All Vehicles</option>
                                <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php if ($vehicle_filter == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Filter by Status</label>
                            <select name="status" id="status" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="all" <?php if ($status_filter == 'all') echo 'selected'; ?>>All</option>
                                <option value="due" <?php if ($status_filter == 'due') echo 'selected'; ?>>Due in 30 Days</option>
                                <option value="overdue" <?php if ($status_filter == 'overdue') echo 'selected'; ?>>Overdue</option>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700">Filter</button>
                            <a href="vehicle_maintenance_report.php" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-md print-area">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">Vehicle No.</th>
                                    <th class="px-4 py-3 text-left font-medium">Service Type</th>
                                    <th class="px-4 py-3 text-left font-medium">Last Service</th>
                                    <th class="px-4 py-3 text-left font-medium">Next Due</th>
                                    <th class="px-4 py-3 text-center font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($maintenance_schedule)): ?>
                                    <tr><td colspan="5" class="text-center py-10 text-gray-500">No maintenance records found for the selected filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($maintenance_schedule as $item): ?>
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap font-semibold"><?php echo htmlspecialchars($item['vehicle_number']); ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap"><?php echo htmlspecialchars($item['service_type']); ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap"><?php echo date('d-m-Y', strtotime($item['last_service_date'])); ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap font-bold"><?php echo $item['next_service_date'] ? date('d-m-Y', strtotime($item['next_service_date'])) : 'N/A'; ?></td>
                                        <td class="px-4 py-3 text-center"><?php echo getStatusBadge($item['days_diff']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script>
    $(document).ready(function() {
        $('.searchable-select').select2({ width: '100%' });
    });
    </script>
</body>
</html>