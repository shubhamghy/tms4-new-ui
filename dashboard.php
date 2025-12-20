<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) {
    header("location: index.php");
    exit;
}

// --- Helper to preserve query params for links ---
function build_url($new_params = []) {
    $params = $_GET;
    foreach ($new_params as $key => $value) {
        $params[$key] = $value;
    }
    return '?' . http_build_query($params);
}

// --- Data Fetching for Dropdowns ---
$branches = [];
if ($_SESSION['role'] === 'admin') {
    $sql_branches = "SELECT id, name FROM branches ORDER BY name ASC";
    if ($result = $mysqli->query($sql_branches)) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

// --- Dashboard Data Calculation ---
$branch_conditions_string = "";
$selected_branch_ids = [];

// Apply branch filter for non-admins
if (in_array($_SESSION['role'], ['manager', 'staff'])) {
    $branch_id = $_SESSION['branch_id'] ?? 0;
    $branch_conditions_string = " WHERE s.branch_id = " . $branch_id;
    $selected_branch_ids[] = $branch_id;

} elseif ($_SESSION['role'] === 'admin') {
    // Admins can filter by one or more branches
    if (isset($_GET['branch_ids']) && is_array($_GET['branch_ids'])) {
        $selected_branch_ids = array_map('intval', $_GET['branch_ids']);
        if (!empty($selected_branch_ids)) {
            $branch_conditions_string = " WHERE s.branch_id IN (" . implode(',', $selected_branch_ids) . ")";
        }
    }
}

// Helper function
function get_where_clause($existing_conditions = []) {
    global $branch_conditions_string;
    if (empty($existing_conditions)) {
        return $branch_conditions_string;
    }
    return ($branch_conditions_string ? $branch_conditions_string . " AND " : " WHERE ") . implode(" AND ", $existing_conditions);
}


// KPI Calculations
$total_bookings = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause())->fetch_assoc()['count'];
$booked_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["status = 'Booked'"]))->fetch_assoc()['count'];
$in_transit_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["status = 'In Transit'"]))->fetch_assoc()['count'];
$delivered_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["status = 'Delivered'"]))->fetch_assoc()['count'];
$completed_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["status = 'Completed'"]))->fetch_assoc()['count'];

// Financial KPIs
$invoiced_count = 0;
$pending_invoice_count = 0;
$awaiting_payment_count = 0;
$payment_received_total = 0;

if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $invoiced_count = $mysqli->query("SELECT COUNT(DISTINCT s.id) as count FROM shipments s JOIN invoice_items ii ON s.id = ii.shipment_id" . get_where_clause())->fetch_assoc()['count'];
    $pending_invoice_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s " . get_where_clause(["payment_entry_status = 'Done'", "NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.shipment_id = s.id)"]))->fetch_assoc()['count'];
    $awaiting_payment_count = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["payment_entry_status = 'Pending'"]))->fetch_assoc()['count'];
    $payment_received_query = "SELECT SUM(ip.amount_received) as total FROM invoice_payments ip JOIN invoice_items ii ON ip.invoice_id = ii.invoice_id JOIN shipments s ON ii.shipment_id = s.id" . get_where_clause();
    $payment_received_total = $mysqli->query($payment_received_query)->fetch_assoc()['total'] ?? 0;
}


// Comparison Data
$current_month_bookings = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["MONTH(consignment_date) = MONTH(CURDATE())", "YEAR(consignment_date) = YEAR(CURDATE())"]))->fetch_assoc()['count'];
$last_month_bookings = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["consignment_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"]))->fetch_assoc()['count'];
$last_3_months_bookings = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["consignment_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)"]))->fetch_assoc()['count'];
$last_6_months_bookings = $mysqli->query("SELECT COUNT(id) as count FROM shipments s" . get_where_clause(["consignment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"]))->fetch_assoc()['count'];


// Chart Data
$status_counts_query = "SELECT status, COUNT(id) as count FROM shipments s" . get_where_clause() . " GROUP BY status";
$status_counts_result = $mysqli->query($status_counts_query);
$status_chart_labels = [];
$status_chart_data = [];
if ($status_counts_result) {
    while ($row = $status_counts_result->fetch_assoc()) {
        $status_chart_labels[] = $row['status'];
        $status_chart_data[] = $row['count'];
    }
}

// Trend Data
$booking_trend_labels = [];
$booking_trend_datasets = [];

for ($i = 5; $i >= 0; $i--) {
    $booking_trend_labels[] = date('M Y', strtotime("-$i months"));
}

$branch_comparison_query = "
    SELECT b.name as branch_name, DATE_FORMAT(s.consignment_date, '%b %Y') as month, COUNT(s.id) as count 
    FROM shipments s
    JOIN branches b ON s.branch_id = b.id
    " . get_where_clause(["s.consignment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"]) . "
    GROUP BY branch_name, month 
    ORDER BY s.consignment_date ASC";

$booking_trend_result = $mysqli->query($branch_comparison_query);
$branch_data = [];
if($booking_trend_result) {
    while($row = $booking_trend_result->fetch_assoc()){
        $branch_data[$row['branch_name']][$row['month']] = $row['count'];
    }
}

$colors = ['#3b82f6', '#10b981', '#ef4444', '#f59e0b', '#6366f1', '#a855f7', '#ec4899'];
$color_index = 0;

foreach ($branch_data as $branch_name => $monthly_counts) {
    $data_points = [];
    foreach ($booking_trend_labels as $label) {
        $data_points[] = $monthly_counts[$label] ?? 0;
    }
    $color = $colors[$color_index % count($colors)];
    $booking_trend_datasets[] = [
        'label' => $branch_name,
        'data' => $data_points,
        'fill' => false,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'tension' => 0.4
    ];
    $color_index++;
}

// Vehicle Expiry
$vehicle_expiry_sql = "SELECT id, vehicle_number, owner_name, rc_expiry, insurance_expiry, tax_expiry, fitness_expiry, permit_expiry 
                       FROM vehicles 
                       WHERE is_active = 1 AND (
                         rc_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR
                         insurance_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR
                         tax_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR
                         fitness_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR
                         permit_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                       ) ORDER BY vehicle_number ASC";
$vehicle_expiry_result = $mysqli->query($vehicle_expiry_sql);
$expiring_vehicles = [];
if ($vehicle_expiry_result) { while ($row = $vehicle_expiry_result->fetch_assoc()) { $expiring_vehicles[] = $row; } }

// Driver Expiry
$driver_expiry_sql = "SELECT id, name, contact_number, license_expiry_date FROM drivers WHERE is_active = 1 AND license_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY license_expiry_date ASC";
$driver_expiry_result = $mysqli->query($driver_expiry_sql);
$expiring_drivers = [];
if ($driver_expiry_result) { while ($row = $driver_expiry_result->fetch_assoc()) { $expiring_drivers[] = $row; } }

// ---------------- E-WAY EXPIRY LOGIC ----------------
$eway_filter_days = isset($_GET['eway_days']) ? (int)$_GET['eway_days'] : 0;
$eway_condition = "si.eway_bill_expiry = CURDATE()"; // Default Today
$eway_title_suffix = "Expires Today";

if ($eway_filter_days == 1) {
    $eway_condition = "si.eway_bill_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    $eway_title_suffix = "Expires within 24 Hours";
} elseif ($eway_filter_days == 2) {
    $eway_condition = "si.eway_bill_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)";
    $eway_title_suffix = "Expires in Next 2 Days";
} elseif ($eway_filter_days == 3) {
    $eway_condition = "si.eway_bill_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
    $eway_title_suffix = "Expires in Next 3 Days";
}

$eway_expiry_sql = "SELECT s.consignment_no, si.eway_bill_no, si.eway_bill_expiry, p.name as consignor_name
                    FROM shipment_invoices si
                    JOIN shipments s ON si.shipment_id = s.id
                    JOIN parties p ON s.consignor_id = p.id
                    " . get_where_clause([$eway_condition]); 

$eway_expiry_result = $mysqli->query($eway_expiry_sql);
$expiring_eways = [];
if ($eway_expiry_result) { while ($row = $eway_expiry_result->fetch_assoc()) { $expiring_eways[] = $row; } }
// -------------------------------------------------------------

// Map Data
$in_transit_locations_sql = "
    SELECT 
        s.id,
        s.consignment_no, 
        v.vehicle_number, 
        d.name as driver_name, 
        st_latest.location,
        st_latest.created_at as last_updated
    FROM shipments s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    LEFT JOIN drivers d ON s.driver_id = d.id
    LEFT JOIN (
        SELECT 
            st1.shipment_id, 
            st1.location, 
            st1.created_at
        FROM shipment_tracking st1
        INNER JOIN (
            SELECT shipment_id, MAX(id) as max_id
            FROM shipment_tracking
            GROUP BY shipment_id
        ) st_max ON st1.shipment_id = st_max.shipment_id AND st1.id = st_max.max_id
    ) st_latest ON s.id = st_latest.shipment_id
    " . get_where_clause(["s.status = 'In Transit'", "st_latest.location IS NOT NULL"]);

$in_transit_result = $mysqli->query($in_transit_locations_sql);
$in_transit_data = [];
if ($in_transit_result) {
    while ($row = $in_transit_result->fetch_assoc()) {
        $in_transit_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--multiple { border-radius: 0.5rem; border: 1px solid #e5e7eb; padding: 2px; }
        .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: #6366f1; }
        #inTransitMap { height: 450px; width: 100%; border-radius: 0.75rem; z-index: 1; }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center backdrop-blur-sm">
        <div class="flex flex-col items-center">
            <div class="fas fa-circle-notch fa-spin fa-3x text-indigo-600 mb-4"></div>
            <p class="text-gray-500 font-medium">Loading Dashboard...</p>
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
                                <i class="fas fa-tachometer-alt opacity-80"></i> Dashboard
                            </h1>
                        </div>
                        
                        <div class="flex items-center gap-4">
                             <span class="text-indigo-100 text-sm hidden md:inline-block bg-white/10 px-3 py-1 rounded-full border border-white/10">
                                <i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($_SESSION['branch_name'] ?? 'Head Office'); ?>
                             </span>
                            <a href="logout.php" class="text-indigo-200 hover:text-white hover:bg-white/10 p-2 rounded-full transition-colors" title="Logout">
                                <i class="fas fa-sign-out-alt fa-lg"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 space-y-8">
                
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                    <form method="get" class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="flex-1 w-full">
                            <label for="branch_ids" class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1 block">Compare Branches</label>
                            <select name="branch_ids[]" id="branch_ids" multiple="multiple" class="block w-full">
                                <?php foreach($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php if(in_array($branch['id'], $selected_branch_ids)) echo 'selected'; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 shadow-md transition transform hover:-translate-y-0.5 mt-auto">
                            Apply Filter
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div>
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-network-wired text-indigo-500"></i> Operational Overview
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center hover:shadow-md transition-shadow relative overflow-hidden group">
                            <div class="absolute right-0 top-0 h-full w-1 bg-cyan-500"></div>
                            <div class="p-3 rounded-full bg-cyan-50 text-cyan-600 mr-4 group-hover:bg-cyan-600 group-hover:text-white transition-colors">
                                <i class="fas fa-clipboard-list text-xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-bold uppercase tracking-wide">Booked</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $booked_count; ?></p>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center hover:shadow-md transition-shadow relative overflow-hidden group">
                            <div class="absolute right-0 top-0 h-full w-1 bg-violet-500"></div>
                            <div class="p-3 rounded-full bg-violet-50 text-violet-600 mr-4 group-hover:bg-violet-600 group-hover:text-white transition-colors">
                                <i class="fas fa-truck-fast text-xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-bold uppercase tracking-wide">In Transit</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $in_transit_count; ?></p>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center hover:shadow-md transition-shadow relative overflow-hidden group">
                            <div class="absolute right-0 top-0 h-full w-1 bg-emerald-500"></div>
                            <div class="p-3 rounded-full bg-emerald-50 text-emerald-600 mr-4 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                                <i class="fas fa-check-double text-xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-bold uppercase tracking-wide">Delivered</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $delivered_count; ?></p>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex items-center hover:shadow-md transition-shadow relative overflow-hidden group">
                            <div class="absolute right-0 top-0 h-full w-1 bg-slate-500"></div>
                            <div class="p-3 rounded-full bg-slate-50 text-slate-600 mr-4 group-hover:bg-slate-600 group-hover:text-white transition-colors">
                                <i class="fas fa-flag-checkered text-xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-bold uppercase tracking-wide">Completed</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $completed_count; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                <div>
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-indigo-500"></i> Financial Summary
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-xl shadow-lg p-5 text-white flex items-center justify-between relative overflow-hidden">
                            <div>
                                <p class="text-blue-100 text-xs font-bold uppercase tracking-wide mb-1">Total Bookings</p>
                                <p class="text-2xl font-bold"><?php echo $total_bookings; ?></p>
                            </div>
                            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm"><i class="fas fa-box-open text-xl"></i></div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-700 to-gray-800 rounded-xl shadow-lg p-5 text-white flex items-center justify-between relative overflow-hidden">
                            <div>
                                <p class="text-gray-300 text-xs font-bold uppercase tracking-wide mb-1">Invoices Raised</p>
                                <p class="text-2xl font-bold"><?php echo $invoiced_count; ?></p>
                            </div>
                            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm"><i class="fas fa-file-invoice text-xl"></i></div>
                        </div>

                        <div class="bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl shadow-lg p-5 text-white flex items-center justify-between relative overflow-hidden">
                            <div>
                                <p class="text-amber-100 text-xs font-bold uppercase tracking-wide mb-1">Pending Invoice</p>
                                <p class="text-2xl font-bold"><?php echo $pending_invoice_count; ?></p>
                            </div>
                            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm"><i class="fas fa-hourglass-half text-xl"></i></div>
                        </div>

                        <div class="bg-gradient-to-br from-rose-500 to-pink-600 rounded-xl shadow-lg p-5 text-white flex items-center justify-between relative overflow-hidden">
                            <div>
                                <p class="text-rose-100 text-xs font-bold uppercase tracking-wide mb-1">Pending Entry</p>
                                <p class="text-2xl font-bold"><?php echo $awaiting_payment_count; ?></p>
                            </div>
                            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm"><i class="fas fa-clock text-xl"></i></div>
                        </div>

                        <div class="bg-gradient-to-br from-emerald-600 to-teal-600 rounded-xl shadow-lg p-5 text-white flex items-center justify-between relative overflow-hidden">
                            <div>
                                <p class="text-emerald-100 text-xs font-bold uppercase tracking-wide mb-1">Total Received</p>
                                <p class="text-xl font-bold">₹<?php echo number_format($payment_received_total, 2); ?></p>
                            </div>
                            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm"><i class="fas fa-rupee-sign text-xl"></i></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-gradient-to-r from-orange-50 to-amber-50 rounded-xl shadow-md border border-orange-200 overflow-hidden">
                     <div class="bg-gradient-to-r from-orange-600 to-amber-600 px-4 md:px-6 py-3 flex flex-col md:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-2 text-white">
                            <i class="fas fa-triangle-exclamation text-xl"></i> 
                            <h3 class="font-bold text-lg">E-Way Bill Alerts</h3>
                            <span class="bg-white/20 text-xs font-bold px-2 py-0.5 rounded backdrop-blur-sm ml-2 hidden sm:inline-block"><?php echo $eway_title_suffix; ?></span>
                        </div>
                        
                        <div class="flex items-center gap-2 bg-black/10 p-1 rounded-lg">
                            <a href="<?php echo build_url(['eway_days' => 0]); ?>" class="px-3 py-1 text-xs font-bold rounded-md transition <?php echo $eway_filter_days == 0 ? 'bg-white text-orange-600 shadow-sm' : 'text-orange-100 hover:bg-white/10'; ?>">Today</a>
                            <a href="<?php echo build_url(['eway_days' => 1]); ?>" class="px-3 py-1 text-xs font-bold rounded-md transition <?php echo $eway_filter_days == 1 ? 'bg-white text-orange-600 shadow-sm' : 'text-orange-100 hover:bg-white/10'; ?>">+1 Day</a>
                            <a href="<?php echo build_url(['eway_days' => 2]); ?>" class="px-3 py-1 text-xs font-bold rounded-md transition <?php echo $eway_filter_days == 2 ? 'bg-white text-orange-600 shadow-sm' : 'text-orange-100 hover:bg-white/10'; ?>">+2 Days</a>
                            <a href="<?php echo build_url(['eway_days' => 3]); ?>" class="px-3 py-1 text-xs font-bold rounded-md transition <?php echo $eway_filter_days == 3 ? 'bg-white text-orange-600 shadow-sm' : 'text-orange-100 hover:bg-white/10'; ?>">+3 Days</a>
                        </div>
                    </div>
                     <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-white/50 border-b border-orange-200">
                                <tr>
                                    <th class="py-3 px-6 text-left text-xs font-bold text-orange-800 uppercase">CN Number</th>
                                    <th class="py-3 px-6 text-left text-xs font-bold text-orange-800 uppercase">E-Way Bill No.</th>
                                    <th class="py-3 px-6 text-left text-xs font-bold text-orange-800 uppercase">Consignor</th>
                                    <th class="py-3 px-6 text-left text-xs font-bold text-orange-800 uppercase">Expiry Date</th>
                                    <th class="py-3 px-6 text-left text-xs font-bold text-orange-800 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-orange-200">
                                 <?php if(empty($expiring_eways)): ?>
                                    <tr><td colspan="5" class="py-8 text-center text-orange-600 text-sm font-medium"><i class="fas fa-check-circle mr-2"></i> No active E-Way bills expiring for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($expiring_eways as $eway): 
                                        $expiry = strtotime($eway['eway_bill_expiry']);
                                        $today = strtotime(date('Y-m-d'));
                                        $status = ($expiry < $today) ? "Expired" : (($expiry == $today) ? "Expires Today" : "Expires Soon");
                                        $statusColor = ($expiry < $today) ? "bg-red-100 text-red-800 border-red-200" : (($expiry == $today) ? "bg-orange-100 text-orange-800 border-orange-200" : "bg-amber-100 text-amber-800 border-amber-200");
                                    ?>
                                    <tr class="hover:bg-orange-100/50 transition">
                                        <td class="py-3 px-6 text-sm font-bold text-gray-800"><?php echo htmlspecialchars($eway['consignment_no']); ?></td>
                                        <td class="py-3 px-6 text-sm font-mono text-gray-700"><?php echo htmlspecialchars($eway['eway_bill_no']); ?></td>
                                        <td class="py-3 px-6 text-sm text-gray-600"><?php echo htmlspecialchars($eway['consignor_name']); ?></td>
                                        <td class="py-3 px-6 text-sm font-medium text-gray-700"><?php echo date("d-M-Y", strtotime($eway['eway_bill_expiry'])); ?></td>
                                        <td class="py-3 px-6">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?php echo $statusColor; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div> 
                </div>

                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-map-marked-alt text-indigo-500"></i> Live Shipments
                        </h3>
                        <span class="bg-indigo-100 text-indigo-700 text-xs font-bold px-3 py-1 rounded-full"><?php echo count($in_transit_data); ?> Active</span>
                    </div>
                    <?php if(empty($in_transit_data)): ?>
                        <div class="flex flex-col items-center justify-center h-64 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                            <i class="fas fa-map-location-dot text-4xl text-gray-300 mb-2"></i>
                            <p class="text-gray-500 font-medium">No shipments currently in transit.</p>
                        </div>
                    <?php else: ?>
                        <div id="inTransitMap" class="shadow-inner border border-gray-200"></div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-1 space-y-4">
                         <h3 class="text-lg font-bold text-gray-800 mb-2">Booking Performance</h3>
                         
                         <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between hover:border-indigo-200 transition-colors">
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase">This Month</p>
                                <p class="text-2xl font-bold text-indigo-600"><?php echo $current_month_bookings; ?></p>
                            </div>
                            <div class="h-10 w-10 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-500"><i class="fas fa-calendar-check"></i></div>
                         </div>
                         <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between hover:border-blue-200 transition-colors">
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase">Last Month</p>
                                <p class="text-2xl font-bold text-blue-600"><?php echo $last_month_bookings; ?></p>
                            </div>
                            <div class="h-10 w-10 bg-blue-50 rounded-full flex items-center justify-center text-blue-500"><i class="fas fa-calendar-day"></i></div>
                         </div>
                         <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between hover:border-purple-200 transition-colors">
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase">Last 6 Months</p>
                                <p class="text-2xl font-bold text-purple-600"><?php echo $last_6_months_bookings; ?></p>
                            </div>
                            <div class="h-10 w-10 bg-purple-50 rounded-full flex items-center justify-center text-purple-500"><i class="fas fa-calendar-alt"></i></div>
                         </div>
                    </div>
                    
                    <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-md border border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Monthly Trends</h3>
                        <div class="relative h-64 w-full">
                            <canvas id="bookingTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Shipment Status Distribution</h3>
                    <div class="relative h-64 w-full">
                        <canvas id="shipmentStatusChart"></canvas>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                        <div class="bg-red-50 px-6 py-4 border-b border-red-100 flex items-center justify-between">
                            <h3 class="font-bold text-red-800 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Vehicle Alerts</h3>
                            <span class="bg-white text-red-600 text-xs font-bold px-2 py-1 rounded border border-red-200">Next 30 Days</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-gray-50"><tr><th class="py-3 px-6 text-left text-xs font-bold text-gray-500 uppercase">Vehicle</th><th class="py-3 px-6 text-left text-xs font-bold text-gray-500 uppercase">Doc</th><th class="py-3 px-6 text-left text-xs font-bold text-gray-500 uppercase">Expiry</th></tr></thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if(empty($expiring_vehicles)): ?>
                                        <tr><td colspan="3" class="py-8 text-center text-gray-400 text-sm italic">No alerts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($expiring_vehicles as $vehicle): 
                                            $expiries = ['RC' => $vehicle['rc_expiry'], 'Insurance' => $vehicle['insurance_expiry'], 'Tax' => $vehicle['tax_expiry'], 'Fitness' => $vehicle['fitness_expiry'], 'Permit' => $vehicle['permit_expiry']];
                                            foreach($expiries as $doc => $date): if($date && strtotime($date) >= time() && strtotime($date) <= strtotime('+30 days')): ?>
                                                <tr class="hover:bg-red-50/50"><td class="py-3 px-6 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></td><td class="py-3 px-6 text-sm text-gray-600"><?php echo $doc; ?></td><td class="py-3 px-6 text-sm font-bold text-red-600"><?php echo date("d-m-Y", strtotime($date)); ?></td></tr>
                                        <?php endif; endforeach; endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                         <div class="bg-orange-50 px-6 py-4 border-b border-orange-100 flex items-center justify-between">
                            <h3 class="font-bold text-orange-800 flex items-center gap-2"><i class="fas fa-id-card"></i> Driver Licenses</h3>
                        </div>
                         <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-gray-50"><tr><th class="py-3 px-6 text-left text-xs font-bold text-gray-500 uppercase">Driver</th><th class="py-3 px-6 text-left text-xs font-bold text-gray-500 uppercase">Expiry</th></tr></thead>
                                <tbody class="divide-y divide-gray-100">
                                     <?php if(empty($expiring_drivers)): ?>
                                        <tr><td colspan="2" class="py-6 text-center text-gray-400 text-sm italic">No alerts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($expiring_drivers as $driver): ?>
                                        <tr class="hover:bg-orange-50/50"><td class="py-3 px-6 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($driver['name']); ?></td><td class="py-3 px-6 text-sm font-bold text-orange-600"><?php echo date("d-m-Y", strtotime($driver['license_expiry_date'])); ?></td></tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <?php include 'footer.php'; ?>
            </main> 
        </div>
    </div>
    <script>
        $(document).ready(function() {
            // Mobile sidebar toggle
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

            if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
            if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
            if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }

            if ($('#branch_ids').length) {
                $('#branch_ids').select2({ placeholder: "Select branches...", allowClear: true, width: '100%' });
            }

            // In-Transit Map
            if ($('#inTransitMap').length) {
                const inTransitData = <?php echo json_encode($in_transit_data); ?>;
                const locationCoords = {
                    'Guwahati': [26.14, 91.73], 'Silchar': [24.83, 92.79], 'Nagaon': [26.35, 92.68], 'Tura': [25.51, 90.22],
                    'Karimganj': [24.87, 92.36], 'Maibong': [25.30, 93.17], 'Haflong': [25.17, 93.03], 'Khanpui': [23.90, 92.68],
                    'Bawngkawn': [23.74, 92.73], 'Aizawl': [23.72, 92.71], 'Lunglei': [22.88, 92.73], 'Saiha': [22.48, 92.97],
                    'Rangvamual': [23.63, 92.69], 'Agartala': [23.83, 91.28], 'Teliamura': [23.85, 91.63], 'Udaipur': [23.53, 91.48],
                    'Kumarghat': [24.16, 91.83], 'Dharmanagar': [24.37, 92.17], 'Belonia': [23.25, 91.45], 'Khowai': [24.06, 91.60],
                    'Kolasib': [24.23, 92.67], 'Gohpur': [26.88, 93.63], 'Sivsagar': [26.98, 94.63], 'Jorhat': [26.75, 94.22],
                    'North Lakhimpur': [27.23, 94.12], 'Bilasipara': [26.23, 90.23], 'Dhubri': [26.02, 89.97], 'Bongaigaon': [26.47, 90.56],
                    'Sapatgaram': [26.40, 90.27], 'Srirampur': [26.43, 89.98], 'Ladrymbai': [25.43, 92.39], 'Phulbari': [25.86, 90.02],
                    'Williamnagar': [25.61, 90.60], 'Mendipathar': [25.92, 90.62], 'Krishnai': [26.00, 90.81], 'Dudhnoi': [25.98, 90.73],
                    'Kokrajhar': [26.40, 90.27], 'Barpeta town': [26.33, 91.00], 'Digarkhal': [24.81, 92.62], 'Churaibari': [24.33, 92.31],
                    'Mankachar': [25.53, 89.86], 'Hatsingimari': [25.86, 89.98], 'Phuentsholing': [26.85, 89.38], 'Thimphu town': [27.47, 89.63],
                    'Shilong': [25.57, 91.89], 'Malidor': [24.71, 92.42], 'Lumding': [25.75, 93.17], 'Karupetia': [26.51, 92.13],
                    'Sairang': [23.80, 92.67], 'Howly': [26.43, 90.97], 'Singimari': [25.75, 90.63], 'Champaknagar': [23.77, 91.38],
                    'Bhaga Bazar': [24.70, 92.83], 'Lakhipur': [24.80, 93.02], 'Kalacherra': [24.23, 91.95], 'Gandacherra': [23.86, 91.78],
                    'Abdullapur': [24.57, 92.30], 'Moranhat': [27.18, 94.93], 'Bagmara': [25.19, 90.64], 'Tulamura': [23.45, 91.43],
                    'Hailakandi': [24.68, 92.57], 'Mahendraganj': [25.30, 89.84], 'Tikrikilla': [25.86, 90.30], 'Lawngtlai': [22.53, 92.88],
                    'Kakraban': [23.40, 91.43], 'Nalchar': [23.51, 91.43]
                };

                const map = L.map('inTransitMap').setView([25.5, 92.9], 7); 
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);

                let markers = [];
                inTransitData.forEach(shipment => {
                    const cleanLocation = shipment.location ? shipment.location.trim() : '';
                    const coords = locationCoords[cleanLocation];
                    
                    if (coords) {
                        const popupContent = `
                            <div class='text-sm p-1 font-sans'>
                                <div class='font-bold text-indigo-700 border-b pb-1 mb-1'>${shipment.consignment_no}</div>
                                <div class='text-gray-600'><b>Truck:</b> ${shipment.vehicle_number || 'N/A'}</div>
                                <div class='text-gray-600'><b>Location:</b> ${shipment.location}</div>
                                <div class='text-xs text-gray-400 mt-1'>${new Date(shipment.last_updated).toLocaleString()}</div>
                            </div>
                        `;
                        const truckIcon = L.divIcon({ html: '<i class="fas fa-truck fa-2x text-indigo-600 drop-shadow-md"></i>', className: 'bg-transparent border-0', iconSize: [24, 24], iconAnchor: [12, 12] });
                        let marker = L.marker(coords, { icon: truckIcon }).addTo(map).bindPopup(popupContent);
                        markers.push(marker);
                    }
                });
                
                if (markers.length > 0) {
                    const group = new L.featureGroup(markers);
                    map.fitBounds(group.getBounds().pad(0.2));
                }
            }

            // Charts
            if (document.getElementById('shipmentStatusChart')) {
                new Chart(document.getElementById('shipmentStatusChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($status_chart_labels); ?>,
                        datasets: [{
                            label: 'Shipments',
                            data: <?php echo json_encode($status_chart_data); ?>,
                            backgroundColor: ['#6366f1', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                            borderRadius: 6,
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } } } }
                });
            }

            if (document.getElementById('bookingTrendChart')) {
                new Chart(document.getElementById('bookingTrendChart').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($booking_trend_labels); ?>,
                        datasets: <?php echo json_encode($booking_trend_datasets); ?>
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } } }, plugins: { legend: { position: 'bottom' } } }
                });
            }
            
            // Loader fade
            const loader = document.getElementById('loader');
            if(loader) { setTimeout(() => { loader.style.opacity = '0'; setTimeout(() => loader.style.display = 'none', 300); }, 400); }
        });
    </script>
</body>
</html>