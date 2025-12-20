<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$limit = 9;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_term = $_GET['search'] ?? '';
$consignor_filter = $_GET['consignor_id'] ?? '';
$consignee_filter = $_GET['consignee_id'] ?? '';
$vehicle_filter = $_GET['vehicle_id'] ?? '';
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

$where_clauses = [];
$params = []; 
$types = "";  

$user_role = $_SESSION['role'] ?? null;
$user_branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : null;

if ($user_role !== 'admin' && !empty($user_branch_id)) {
    $where_clauses[] = "s.branch_id = ?";
    $params[] = $user_branch_id;
    $types .= "i";
}

if (!empty($search_term)) {
    $like_term = "%" . $search_term . "%";
    $where_clauses[] = "(s.consignment_no LIKE ? OR v.vehicle_number LIKE ? OR p_consignor.name LIKE ? OR p_consignee.name LIKE ?)";
    array_push($params, $like_term, $like_term, $like_term, $like_term);
    $types .= "ssss";
}
if (!empty($consignor_filter)) { $where_clauses[] = "s.consignor_id = ?"; $params[] = $consignor_filter; $types .= "i"; }
if (!empty($consignee_filter)) { $where_clauses[] = "s.consignee_id = ?"; $params[] = $consignee_filter; $types .= "i"; }
if (!empty($vehicle_filter)) { $where_clauses[] = "s.vehicle_id = ?"; $params[] = $vehicle_filter; $types .= "i"; }
if (!empty($start_date_filter)) { $where_clauses[] = "s.consignment_date >= ?"; $params[] = $start_date_filter; $types .= "s"; }
if (!empty($end_date_filter)) { $where_clauses[] = "s.consignment_date <= ?"; $params[] = $end_date_filter; $types .= "s"; }

$where_sql = "";
if (count($where_clauses) > 0) { $where_sql = " WHERE " . implode(" AND ", $where_clauses); }

$total_records_sql = "SELECT COUNT(s.id) FROM shipments s
                      LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
                      LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
                      LEFT JOIN vehicles v ON s.vehicle_id = v.id
                      $where_sql";

$stmt_total = $mysqli->prepare($total_records_sql);
if (count($params) > 0) { $stmt_total->bind_param($types, ...$params); }
$stmt_total->execute();
$total_result = $stmt_total->get_result();
$total_records = $total_result->fetch_row()[0];
$total_pages = ceil($total_records / $limit);
$stmt_total->close();

$sql = "SELECT s.id, s.consignment_no, s.consignment_date, s.status, s.origin, s.destination,
               p_consignor.name as consignor_name,
               p_consignee.name as consignee_name,
               b.name as broker_name,
               v.vehicle_number
        FROM shipments s
        LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
        LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
        LEFT JOIN brokers b ON s.broker_id = b.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        $where_sql
        ORDER BY s.consignment_date DESC, s.id DESC
        LIMIT ? OFFSET ?";

$params_with_pagination = $params;
$params_with_pagination[] = $limit;
$params_with_pagination[] = $offset;
$types_with_pagination = $types . "ii";

$stmt_data = $mysqli->prepare($sql);
if (count($params_with_pagination) > 0) { $stmt_data->bind_param($types_with_pagination, ...$params_with_pagination); }
$stmt_data->execute();
$result = $stmt_data->get_result();

$shipments = [];
if ($result) { while ($row = $result->fetch_assoc()) { $shipments[] = $row; } }
$stmt_data->close();

$consignors = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignor', 'Both') AND is_active = 1 ORDER BY name");
$consignees = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignee', 'Both') AND is_active = 1 ORDER BY name");
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");

function getStatusBadge($status) {
    $colors = [
        'Booked' => 'bg-blue-100 text-blue-700 border-blue-200',
        'Billed' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        'Pending Payment' => 'bg-amber-100 text-amber-700 border-amber-200',
        'Reverify' => 'bg-red-100 text-red-700 border-red-200',
        'In Transit' => 'bg-cyan-100 text-cyan-700 border-cyan-200',
        'Reached' => 'bg-teal-100 text-teal-700 border-teal-200',
        'Delivered' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'Completed' => 'bg-gray-100 text-gray-700 border-gray-200',
    ];
    $color_class = $colors[$status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
    return "<span class='px-2.5 py-0.5 inline-flex text-[10px] uppercase font-bold tracking-wide rounded-full border {$color_class}'>" . htmlspecialchars($status) . "</span>";
}

$query_params = $_GET;
unset($query_params['page']);
$query_string = http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Bookings - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 40px; border-radius: 0.5rem; border: 1px solid #e5e7eb; background-color: #f9fafb; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; padding-left: 0.75rem; color: #374151; font-size: 0.875rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-gray-50">

<div id="loader" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="flex flex-col items-center">
        <div class="fas fa-circle-notch fa-spin fa-3x text-indigo-600 mb-4"></div>
        <p class="text-gray-500 font-medium">Loading Shipments...</p>
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
                            <i class="fas fa-list-alt opacity-80"></i> Shipment Bookings
                        </h1>
                    </div>
                     <div class="flex items-center gap-4">
                        <a href="booking.php" class="hidden md:inline-flex items-center px-4 py-1.5 bg-white/10 hover:bg-white/20 text-white text-sm font-semibold rounded-full border border-white/20 transition shadow-sm">
                            <i class="fas fa-plus mr-2"></i> New Booking
                        </a>
                        <a href="logout.php" class="text-indigo-200 hover:text-white hover:bg-white/10 p-2 rounded-full transition-colors" title="Logout">
                            <i class="fas fa-sign-out-alt fa-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8">
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-8">
                 <div class="flex items-center mb-4">
                     <div class="bg-indigo-100 p-2 rounded-lg text-indigo-600 mr-3"><i class="fas fa-filter"></i></div>
                     <h2 class="text-lg font-bold text-gray-800">Filter Shipments</h2>
                 </div>
                 <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Search Keyword</label>
                        <input type="text" id="search" name="search" placeholder="Enter Consignment No, Vehicle..." value="<?php echo htmlspecialchars($search_term); ?>" class="block w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 text-sm">
                    </div>
                     <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Consignor</label>
                        <select id="consignor_id" name="consignor_id" class="select2-filter block w-full"><option value="">All Consignors</option><?php mysqli_data_seek($consignors, 0); while($row = $consignors->fetch_assoc()) echo "<option value='{$row['id']}' ".($consignor_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['name'])."</option>"; ?></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Consignee</label>
                        <select id="consignee_id" name="consignee_id" class="select2-filter block w-full"><option value="">All Consignees</option><?php mysqli_data_seek($consignees, 0); while($row = $consignees->fetch_assoc()) echo "<option value='{$row['id']}' ".($consignee_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['name'])."</option>"; ?></select>
                    </div>
                     <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Vehicle</label>
                        <select id="vehicle_id" name="vehicle_id" class="select2-filter block w-full"><option value="">All Vehicles</option><?php mysqli_data_seek($vehicles, 0); while($row = $vehicles->fetch_assoc()) echo "<option value='{$row['id']}' ".($vehicle_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['vehicle_number'])."</option>"; ?></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">From Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>" class="block w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">To Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>" class="block w-full px-3 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 text-sm">
                    </div>
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-md text-sm font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition">Apply</button>
                        <a href="view_bookings.php" class="w-full inline-flex justify-center py-2 px-4 border border-gray-200 shadow-sm text-sm font-bold rounded-lg text-gray-600 bg-white hover:bg-gray-50 transition">Reset</a>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($shipments as $shipment): ?>
                <div class="bg-white rounded-xl shadow-sm hover:shadow-xl border border-gray-200 transition-all duration-300 flex flex-col group relative overflow-hidden">
                    <div class="h-1.5 w-full bg-gradient-to-r from-indigo-500 to-blue-500"></div>
                    
                    <div class="p-5 flex-1 flex flex-col">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="bg-indigo-50 text-indigo-700 text-xs font-bold px-2 py-0.5 rounded border border-indigo-100">LR</span>
                                    <a href="view_shipment_details.php?id=<?php echo $shipment['id']; ?>" class="text-gray-900 hover:text-indigo-600 font-bold text-lg tracking-tight"><?php echo htmlspecialchars($shipment['consignment_no']); ?></a>
                                </div>
                                <p class="text-xs text-gray-400 mt-1 font-medium"><i class="far fa-calendar-alt mr-1"></i> <?php echo date("d M, Y", strtotime($shipment['consignment_date'])); ?></p>
                            </div>
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="text-gray-400 hover:text-indigo-600 p-1 rounded-full hover:bg-indigo-50 transition">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-100 z-20 py-1" x-cloak>
                                    <a href="view_shipment_details.php?id=<?php echo $shipment['id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700"><i class="fas fa-eye w-5 text-center mr-2"></i> View Details</a>
                                    <a href="print_lr_landscape.php?id=<?php echo $shipment['id']; ?>" target="_blank" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700"><i class="fas fa-print w-5 text-center mr-2"></i> Print LR</a>
                                    <a href="booking.php?action=edit&id=<?php echo $shipment['id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700"><i class="fas fa-edit w-5 text-center mr-2"></i> Edit</a>
                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="view_bookings.php?action=delete&id=<?php echo $shipment['id']; ?>" onclick="return confirm('Are you sure you want to delete this booking?');" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50"><i class="fas fa-trash-alt w-5 text-center mr-2"></i> Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <?php echo getStatusBadge($shipment['status']); ?>
                        </div>

                        <div class="bg-gray-50 rounded-lg p-3 mb-4 border border-gray-100">
                             <div class="flex items-center justify-between text-sm">
                                <div class="flex flex-col items-start w-1/2 pr-2">
                                    <span class="text-[10px] text-gray-400 uppercase font-bold">Origin</span>
                                    <span class="font-bold text-gray-800 truncate w-full" title="<?php echo htmlspecialchars($shipment['origin']); ?>"><?php echo htmlspecialchars($shipment['origin']); ?></span>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300"></i>
                                <div class="flex flex-col items-end w-1/2 pl-2">
                                    <span class="text-[10px] text-gray-400 uppercase font-bold">Destination</span>
                                    <span class="font-bold text-gray-800 truncate w-full text-right" title="<?php echo htmlspecialchars($shipment['destination']); ?>"><?php echo htmlspecialchars($shipment['destination']); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-y-3 gap-x-2 text-sm mt-auto">
                            <div class="flex flex-col">
                                <span class="text-[10px] text-gray-400 uppercase font-bold">Consignor</span>
                                <span class="text-gray-700 font-medium truncate" title="<?php echo htmlspecialchars($shipment['consignor_name']); ?>"><?php echo htmlspecialchars($shipment['consignor_name']); ?></span>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-[10px] text-gray-400 uppercase font-bold">Consignee</span>
                                <span class="text-gray-700 font-medium truncate" title="<?php echo htmlspecialchars($shipment['consignee_name']); ?>"><?php echo htmlspecialchars($shipment['consignee_name']); ?></span>
                            </div>
                             <div class="col-span-2 border-t border-gray-100 pt-3 flex justify-between items-center">
                                <span class="bg-gray-100 text-gray-600 text-xs px-2 py-1 rounded font-medium"><i class="fas fa-truck mr-1"></i> <?php echo htmlspecialchars($shipment['vehicle_number'] ?? 'N/A'); ?></span>
                                <span class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($shipment['broker_name'] ?? 'No Broker'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($shipments)): ?>
                    <div class="md:col-span-2 lg:col-span-3 text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 mb-4">
                            <i class="fas fa-box-open fa-2x text-gray-300"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">No shipments found</h3>
                        <p class="mt-1 text-gray-500 max-w-sm mx-auto">Try adjusting your search or filters to find what you're looking for.</p>
                        <a href="view_bookings.php" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200">
                            Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-8 flex flex-col sm:flex-row justify-between items-center gap-4 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                <span class="text-sm text-gray-500 font-medium">
                    Showing <span class="font-bold text-gray-800"><?php echo $total_records > 0 ? $offset + 1 : 0; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> results
                </span>
                <div class="flex items-center space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&<?php echo $query_string; ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition shadow-sm">
                            <i class="fas fa-arrow-left mr-1"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&<?php echo $query_string; ?>" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-lg hover:bg-indigo-700 transition shadow-md">
                            Next <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <footer class="mt-8 text-center text-xs text-gray-400">
                &copy; <?php echo date('Y'); ?> TMS System. All rights reserved.
            </footer>
        </main>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.select2-filter').select2({ width: '100%' });
        
        // Mobile sidebar toggle
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarClose = document.getElementById('close-sidebar-btn'); // Assuming this is in sidebar.php

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
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        
        // Loader Fade Out
        const loader = document.getElementById('loader');
        if (loader) {
            setTimeout(() => {
                loader.style.opacity = '0';
                setTimeout(() => loader.style.display = 'none', 300);
            }, 300);
        }
    });
</script>
</body>
</html>