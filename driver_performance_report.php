<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

// --- Filter Handling ---
$driver_filter = $_GET['driver_id'] ?? '';
$start_date_filter = $_GET['start_date'] ?? date('Y-m-01');
$end_date_filter = $_GET['end_date'] ?? date('Y-m-d');

// --- Data Fetching ---
$where_clauses = ["s.consignment_date BETWEEN ? AND ?"];
$params = [$start_date_filter, $end_date_filter];
$types = "ss";

if (!empty($driver_filter)) {
    $where_clauses[] = "s.driver_id = ?";
    $params[] = $driver_filter;
    $types .= "i";
}

$sql = "SELECT 
            d.id as driver_id,
            d.name as driver_name,
            COUNT(DISTINCT s.id) as total_trips,
            SUM(sp.amount) as total_revenue
        FROM shipments s
        JOIN drivers d ON s.driver_id = d.id
        LEFT JOIN shipment_payments sp ON s.id = sp.shipment_id AND sp.payment_type = 'Lorry Hire'
        WHERE " . implode(" AND ", $where_clauses) . "
        GROUP BY d.id, d.name
        ORDER BY total_revenue DESC";

$stmt = $mysqli->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$driver_performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch drivers for the filter dropdown
$drivers = $mysqli->query("SELECT id, name FROM drivers WHERE is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Performance Report - TMS</title>
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
                <div class="mx-auto px-4 sm:px-6 lg:px-8"><div class="flex justify-between items-center h-16"><h1 class="text-xl font-semibold text-gray-800">Driver Performance Report</h1></div></div>
            </header>
            <main class="flex-1 overflow-y-auto bg-gray-100 p-4 md:p-8">
                <div class="bg-white p-4 rounded-xl shadow-md mb-6 no-print">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div class="md:col-span-2">
                        <label for="driver_id" class="block text-sm font-medium text-gray-700">Filter by Driver</label>
                        <select name="driver_id" id="driver_id" class="searchable-select mt-1 block w-full">
                            <option value="">All Drivers</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php if ($driver_filter == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>" class="mt-1 block w-full px-3 py-2 border rounded-md">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>" class="mt-1 block w-full px-3 py-2 border rounded-md">
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700">Filter</button>
                            <a href="driver_performance_report.php" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-md print-area">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">Driver Name</th>
                                    <th class="px-4 py-3 text-center font-medium">Total Trips</th>
                                    <th class="px-4 py-3 text-right font-medium">Total Revenue (Lorry Hire)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($driver_performance)): ?>
                                    <tr><td colspan="3" class="text-center py-10 text-gray-500">No performance data found for the selected filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($driver_performance as $item): ?>
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap font-semibold"><?php echo htmlspecialchars($item['driver_name']); ?></td>
                                        <td class="px-4 py-3 text-center whitespace-nowrap"><?php echo $item['total_trips']; ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right font-bold text-green-600">₹<?php echo number_format($item['total_revenue'], 2); ?></td>
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