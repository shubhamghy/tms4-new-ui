<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$form_message = "";
$search_term = trim($_GET['search'] ?? '');
$active_tab = $_GET['tab'] ?? 'booked';


// --- Handle Form Submission for Status Update ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_shipment_id'])) {
    $shipment_id = intval($_POST['update_shipment_id']);
    $new_status = $_POST['new_status'];
    $location = trim($_POST['location']);
    $remarks = trim($_POST['remarks']);
    $updated_by_id = $_SESSION['id'];

    $mysqli->begin_transaction();
    try {
        // 1. Insert into tracking history
        $sql_track = "INSERT INTO shipment_tracking (shipment_id, location, remarks, updated_by_id) VALUES (?, ?, ?, ?)";
        $stmt_track = $mysqli->prepare($sql_track);
        $stmt_track->bind_param("issi", $shipment_id, $location, $remarks, $updated_by_id);
        if (!$stmt_track->execute()) { throw new Exception("Error saving tracking history: " . $stmt_track->error); }
        $stmt_track->close();

        // 2. Update the main shipment status
        $sql_update = "UPDATE shipments SET status = ? WHERE id = ?";
        $stmt_update = $mysqli->prepare($sql_update);
        $stmt_update->bind_param("si", $new_status, $shipment_id);
        if (!$stmt_update->execute()) { throw new Exception("Error updating shipment status: " . $stmt_update->error); }
        $stmt_update->close();
        
        $mysqli->commit();
        // Redirect back to the active tab after successful submission
        header("location: update_tracking.php?tab=" . urlencode($active_tab) . "&search=" . urlencode($search_term));
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Error: ' . $e->getMessage() . '</div>';
    }
}

// --- Function to Render Shipment Cards ---
function renderCardList($shipments, $total_pages, $current_page, $page_param_name, $theme_color = 'indigo') {
    if (empty($shipments)) {
        echo '<div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 mb-4 text-gray-300">
                    <i class="fas fa-box-open fa-2x"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900">No shipments found</h3>
                <p class="mt-1 text-gray-500 max-w-sm mx-auto">There are no shipments matching your criteria in this section.</p>
              </div>';
        return;
    }
    
    echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">';
    foreach ($shipments as $shipment) {
        $current_status = htmlspecialchars($shipment['status']);
        $consignment_no = htmlspecialchars($shipment['consignment_no']);
        $consignment_date = htmlspecialchars(date("d M, Y", strtotime($shipment['consignment_date'])));
        $origin = htmlspecialchars($shipment['origin']);
        $destination = htmlspecialchars($shipment['destination']);
        $last_location_html = '';
        
        $last_location_data = isset($shipment['last_location']) ? htmlspecialchars($shipment['last_location']) : '';

        if (isset($shipment['last_location'])) {
            $last_location = htmlspecialchars($shipment['last_location']);
            $last_updated = htmlspecialchars(date("d M, h:i A", strtotime($shipment['last_updated_at'])));
            $last_location_html = <<<HTML
            <div class="mt-4 pt-4 border-t border-dashed border-gray-200">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 mt-0.5">
                        <span class="flex h-2 w-2 rounded-full bg-{$theme_color}-500 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-{$theme_color}-400 opacity-75"></span>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-800">{$last_location}</p>
                        <p class="text-xs text-gray-500 mt-0.5"><i class="far fa-clock mr-1"></i> {$last_updated}</p>
                    </div>
                </div>
            </div>
HTML;
        } else {
             $last_location_html = <<<HTML
            <div class="mt-4 pt-4 border-t border-dashed border-gray-200 text-sm text-gray-400 italic">
                <i class="fas fa-map-marker-alt mr-1"></i> Location not updated yet
            </div>
HTML;
        }

        echo <<<HTML
        <div class="bg-white rounded-xl shadow-sm hover:shadow-lg border border-gray-200 transition-all duration-300 flex flex-col relative overflow-hidden group">
            <div class="absolute top-0 left-0 w-1 h-full bg-gradient-to-b from-{$theme_color}-400 to-{$theme_color}-600"></div>
            
            <div class="p-5 flex-1">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-{$theme_color}-50 text-{$theme_color}-700 border border-{$theme_color}-100 uppercase tracking-wide mb-1">
                            LR No.
                        </span>
                        <a href="view_shipment_details.php?id={$shipment['id']}" class="block text-lg font-bold text-gray-900 hover:text-{$theme_color}-600 transition-colors">{$consignment_no}</a>
                    </div>
                    <button @click="openModal(\$event)" 
                            data-id="{$shipment['id']}" 
                            data-cn="{$consignment_no}"
                            data-status="{$current_status}"
                            data-origin="{$origin}"
                            data-last-location="{$last_location_data}"
                            class="p-2 rounded-lg bg-gray-50 text-gray-500 hover:bg-{$theme_color}-50 hover:text-{$theme_color}-600 transition border border-gray-200 hover:border-{$theme_color}-200" title="Update Status">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
                
                <div class="flex items-center text-xs text-gray-500 mb-4 font-medium">
                    <i class="far fa-calendar-alt mr-1.5"></i> {$consignment_date}
                </div>

                <div class="bg-gray-50 rounded-lg p-3 border border-gray-100 mb-2">
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex flex-col w-1/2 pr-2">
                            <span class="text-[10px] text-gray-400 uppercase font-bold tracking-wider">From</span>
                            <span class="font-bold text-gray-800 truncate" title="{$origin}">{$origin}</span>
                        </div>
                        <div class="text-gray-300 flex-shrink-0"><i class="fas fa-chevron-right"></i></div>
                        <div class="flex flex-col w-1/2 pl-2 text-right">
                            <span class="text-[10px] text-gray-400 uppercase font-bold tracking-wider">To</span>
                            <span class="font-bold text-gray-800 truncate" title="{$destination}">{$destination}</span>
                        </div>
                    </div>
                </div>
                
                {$last_location_html}
            </div>
        </div>
HTML;
    }
    echo '</div>';

    // Pagination
    $base_query = $_GET;
    unset($base_query['page_booked'], $base_query['page_transit'], $base_query['page_reached'], $base_query['tab']);

    echo '<div class="mt-8 flex justify-end">';
    if ($total_pages > 1) {
        $pagination_params = array_merge($base_query, ['tab' => $_GET['tab'] ?? 'booked']);
        
        if ($current_page > 1) {
            $prev_page_query = http_build_query(array_merge($pagination_params, [$page_param_name => $current_page - 1]));
            echo "<a href='?{$prev_page_query}' class='px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 mr-2 transition shadow-sm'><i class='fas fa-arrow-left mr-1'></i> Prev</a>";
        }
        if ($current_page < $total_pages) {
            $next_page_query = http_build_query(array_merge($pagination_params, [$page_param_name => $current_page + 1]));
            echo "<a href='?{$next_page_query}' class='px-4 py-2 text-sm font-medium text-white bg-{$theme_color}-600 border border-transparent rounded-lg hover:bg-{$theme_color}-700 transition shadow-md'>Next <i class='fas fa-arrow-right ml-1'></i></a>";
        }
    }
    echo '</div>';
}


// --- Data Fetching & Pagination ---
function fetchShipmentsByStatus($mysqli, $statuses, $page_param, $branch_filter, $search_term, $with_last_location = false) {
    global $mysqli; 
    
    $limit = 6;
    $page = isset($_GET[$page_param]) ? (int)$_GET[$page_param] : 1;
    $offset = ($page - 1) * $limit;
    
    if (empty($statuses)) return [[], 0, 1, 0];

    $status_placeholders = implode(',', array_fill(0, count($statuses), '?'));
    
    $search_filter_sql = "";
    $search_params = [];
    $search_types = "";
    
    if (!empty($search_term)) {
        $search_filter_sql = " AND (s.consignment_no LIKE ? OR s.origin LIKE ? OR s.destination LIKE ?)";
        $like_term = "%{$search_term}%";
        $search_params = [$like_term, $like_term, $like_term];
        $search_types = "sss";
    }

    $count_sql = "SELECT COUNT(s.id) FROM shipments s WHERE status IN ($status_placeholders) $branch_filter $search_filter_sql";
    $stmt_count = $mysqli->prepare($count_sql);
    
    $count_args = array(str_repeat('s', count($statuses)) . $search_types);
    foreach($statuses as &$status) { $count_args[] = $status; }
    foreach($search_params as &$param) { $count_args[] = $param; }
    
    $stmt_count->bind_param(...$count_args);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $total_pages = ceil($total_records / $limit);
    $stmt_count->close();

    $select_cols = "s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, s.vehicle_id, s.status";
    $joins = "";
    
    if ($with_last_location) {
        $select_cols .= ", lt.location as last_location, lt.created_at as last_updated_at";
        $joins = "LEFT JOIN shipment_tracking lt ON lt.id = (SELECT MAX(id) FROM shipment_tracking WHERE shipment_id = s.id)";
    }
    
    $sql = "SELECT $select_cols
            FROM shipments s $joins
            WHERE s.status IN ($status_placeholders) $branch_filter $search_filter_sql
            ORDER BY s.consignment_date DESC, s.id DESC 
            LIMIT ? OFFSET ?";
            
    $stmt = $mysqli->prepare($sql);
    
    $types = str_repeat('s', count($statuses)) . $search_types . 'ii';
    $bind_params = array_merge($statuses, $search_params, array($limit, $offset));
    
    $bind_args = array();
    $bind_args[] = $types;
    foreach ($bind_params as &$param) { $bind_args[] = $param; }
    
    $stmt->bind_param(...$bind_args);
    $stmt->execute();
    $result = $stmt->get_result();
    $shipments = [];
    if($result) { while($row = $result->fetch_assoc()) { $shipments[] = $row; } }
    $stmt->close();
    
    return [$shipments, $total_pages, $page, $total_records];
}


// --- ROLE-BASED FILTERING LOGIC ---
$branch_filter_sql = "";
$user_role = $_SESSION['role'] ?? null;
if ($user_role !== 'admin' && !empty($_SESSION['branch_id'])) {
    $user_branch_id = intval($_SESSION['branch_id']);
    $branch_filter_sql = " AND s.branch_id = $user_branch_id";
}

$booked_statuses = ['Booked', 'Billed', 'Pending Payment', 'Reverify'];
list($booked_shipments, $booked_total_pages, $booked_page, $booked_count) = fetchShipmentsByStatus($mysqli, $booked_statuses, 'page_booked', $branch_filter_sql, $search_term);

list($in_transit_shipments, $in_transit_total_pages, $in_transit_page, $in_transit_count) = fetchShipmentsByStatus($mysqli, ['In Transit'], 'page_transit', $branch_filter_sql, $search_term, true);

list($reached_shipments, $reached_total_pages, $reached_page, $reached_count) = fetchShipmentsByStatus($mysqli, ['Reached'], 'page_reached', $branch_filter_sql, $search_term, true);


// Fetch last 10 delivered shipments
$delivered_count_sql = "SELECT COUNT(s.id) FROM shipments s WHERE s.status = 'Delivered' $branch_filter_sql";
$delivered_count_result = $mysqli->query($delivered_count_sql);
$delivered_count_filtered = $delivered_count_result ? $delivered_count_result->fetch_row()[0] : 0;

$last_delivered_sql = "SELECT s.id, s.consignment_no, s.destination, st.created_at as delivery_date
                       FROM shipments s
                       JOIN shipment_tracking st ON s.id = st.shipment_id
                       WHERE s.status = 'Delivered' AND st.id = (SELECT MAX(id) FROM shipment_tracking WHERE shipment_id = s.id)
                       $branch_filter_sql
                       ORDER BY st.created_at DESC
                       LIMIT 10";
$last_delivered_result = $mysqli->query($last_delivered_sql);
$last_delivered_shipments = [];
if ($last_delivered_result) { while($row = $last_delivered_result->fetch_assoc()) { $last_delivered_shipments[] = $row; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Tracking - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none; }
        /* Smooth scrolling */
        html { scroll-behavior: smooth; }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center backdrop-blur-sm">
        <div class="flex flex-col items-center">
            <div class="fas fa-circle-notch fa-spin fa-3x text-indigo-600 mb-4"></div>
            <p class="text-gray-500 font-medium">Loading...</p>
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
                            <i class="fas fa-map-marked-alt opacity-80"></i> Update Tracking
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 md:p-8 space-y-6">
            <?php if(!empty($form_message)) echo $form_message; ?>

            <div x-data="trackingApp()" x-init="init()" class="space-y-6">
                
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                    <form method="GET" action="update_tracking.php" class="flex flex-col sm:flex-row items-center gap-4">
                        <input type="hidden" name="tab" :value="activeTab">
                        <div class="relative w-full">
                            <input type="text" name="search" placeholder="Search by Consignment No, Origin, or Destination..." value="<?php echo htmlspecialchars($search_term); ?>" class="w-full pl-10 pr-4 py-2.5 border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 shadow-md transition">
                                Search
                            </button>
                            <?php if (!empty($search_term)): ?>
                                <a href="update_tracking.php?tab=<?php echo urlencode($active_tab); ?>" class="w-full sm:w-auto px-6 py-2.5 bg-white border border-gray-300 text-gray-700 font-bold rounded-lg hover:bg-gray-50 text-center shadow-sm transition">
                                    Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-1">
                    <nav class="flex flex-col sm:flex-row space-y-1 sm:space-y-0 sm:space-x-1" aria-label="Tabs">
                        <a href="#" @click.prevent="activeTab = 'booked'" 
                           :class="{ 'bg-indigo-50 text-indigo-700 shadow-inner': activeTab === 'booked', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'booked' }" 
                           class="flex-1 group flex items-center justify-center px-4 py-3 rounded-lg text-sm font-bold transition-all">
                            <i class="fas fa-clipboard-check mr-2" :class="activeTab === 'booked' ? 'text-indigo-500' : 'text-gray-400'"></i> To Be Dispatched
                            <span class="ml-2 bg-indigo-100 text-indigo-700 py-0.5 px-2.5 rounded-full text-xs" x-text="'<?php echo $booked_count; ?>'"></span>
                        </a>
                        
                        <a href="#" @click.prevent="activeTab = 'transit'" 
                           :class="{ 'bg-cyan-50 text-cyan-700 shadow-inner': activeTab === 'transit', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'transit' }" 
                           class="flex-1 group flex items-center justify-center px-4 py-3 rounded-lg text-sm font-bold transition-all">
                            <i class="fas fa-truck-fast mr-2" :class="activeTab === 'transit' ? 'text-cyan-500' : 'text-gray-400'"></i> In Transit
                            <span class="ml-2 bg-cyan-100 text-cyan-700 py-0.5 px-2.5 rounded-full text-xs" x-text="'<?php echo $in_transit_count; ?>'"></span>
                        </a>
                        
                        <a href="#" @click.prevent="activeTab = 'reached'" 
                           :class="{ 'bg-teal-50 text-teal-700 shadow-inner': activeTab === 'reached', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'reached' }" 
                           class="flex-1 group flex items-center justify-center px-4 py-3 rounded-lg text-sm font-bold transition-all">
                            <i class="fas fa-map-marker-alt mr-2" :class="activeTab === 'reached' ? 'text-teal-500' : 'text-gray-400'"></i> Reached Destination
                            <span class="ml-2 bg-teal-100 text-teal-700 py-0.5 px-2.5 rounded-full text-xs" x-text="'<?php echo $reached_count; ?>'"></span>
                        </a>
                        
                        <a href="#" @click.prevent="activeTab = 'delivered'" 
                           :class="{ 'bg-emerald-50 text-emerald-700 shadow-inner': activeTab === 'delivered', 'text-gray-500 hover:text-gray-700 hover:bg-gray-50': activeTab !== 'delivered' }" 
                           class="flex-1 group flex items-center justify-center px-4 py-3 rounded-lg text-sm font-bold transition-all">
                            <i class="fas fa-check-circle mr-2" :class="activeTab === 'delivered' ? 'text-emerald-500' : 'text-gray-400'"></i> Delivered History
                             <span class="ml-2 bg-emerald-100 text-emerald-700 py-0.5 px-2.5 rounded-full text-xs" x-text="'<?php echo $delivered_count_filtered; ?>'"></span>
                        </a>
                    </nav>
                </div>

                <div>
                    <div x-show="activeTab === 'booked'" x-cloak x-transition.opacity>
                        <?php renderCardList($booked_shipments, $booked_total_pages, $booked_page, 'page_booked', 'indigo'); ?>
                    </div>
                    <div x-show="activeTab === 'transit'" x-cloak x-transition.opacity>
                        <?php renderCardList($in_transit_shipments, $in_transit_total_pages, $in_transit_page, 'page_transit', 'cyan'); ?>
                    </div>
                    <div x-show="activeTab === 'reached'" x-cloak x-transition.opacity>
                        <?php renderCardList($reached_shipments, $reached_total_pages, $reached_page, 'page_reached', 'teal'); ?>
                    </div>
                    
                    <div x-show="activeTab === 'delivered'" x-cloak x-transition.opacity>
                        <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                             <div class="bg-emerald-50 px-6 py-4 border-b border-emerald-100 flex justify-between items-center">
                                 <h3 class="text-lg font-bold text-emerald-800 flex items-center gap-2"><i class="fas fa-history"></i> Last 10 Delivered Shipments</h3>
                             </div>
                             <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">CN No.</th>
                                            <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Destination</th>
                                            <th class="py-3 px-6 text-left font-bold text-gray-500 uppercase">Delivery Date</th>
                                            <th class="py-3 px-6 text-right font-bold text-gray-500 uppercase">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php if(empty($last_delivered_shipments)): ?>
                                            <tr><td colspan="4" class="text-center py-8 text-gray-400 italic">No recently delivered shipments found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($last_delivered_shipments as $shipment): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="py-3 px-6 font-medium text-gray-800"><?php echo htmlspecialchars($shipment['consignment_no']); ?></td>
                                                <td class="py-3 px-6 text-gray-600"><?php echo htmlspecialchars($shipment['destination']); ?></td>
                                                <td class="py-3 px-6 text-gray-600"><i class="far fa-clock mr-1 text-emerald-500"></i> <?php echo htmlspecialchars(date("d M Y, h:i A", strtotime($shipment['delivery_date']))); ?></td>
                                                <td class="py-3 px-6 text-right">
                                                    <a href="view_shipment_details.php?id=<?php echo $shipment['id']; ?>" class="text-emerald-600 hover:text-emerald-800 font-medium hover:underline">View Details</a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                             </div>
                        </div>
                    </div>
                </div>
            
                <div x-show="isModalOpen" @keydown.escape.window="isModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                    <div class="flex items-center justify-center min-h-screen px-4 text-center sm:block sm:p-0">
                        <div @click="isModalOpen = false" class="fixed inset-0 transition-opacity" aria-hidden="true">
                            <div class="absolute inset-0 bg-slate-900 opacity-75 backdrop-blur-sm"></div>
                        </div>
                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-100">
                            
                            <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                                    <i class="fas fa-edit"></i> Update Status
                                </h3>
                                <button @click="isModalOpen = false" class="text-indigo-200 hover:text-white transition"><i class="fas fa-times"></i></button>
                            </div>

                            <form method="post">
                                <input type="hidden" name="update_shipment_id" :value="shipmentId">
                                <div class="px-6 py-6 space-y-5">
                                    
                                    <div class="flex items-center justify-between bg-indigo-50 p-3 rounded-lg border border-indigo-100">
                                        <span class="text-xs font-bold text-indigo-500 uppercase tracking-wide">Consignment</span>
                                        <span x-text="consignmentNo" class="font-bold text-indigo-900 text-lg"></span>
                                    </div>

                                    <div id="update_warning" class="hidden p-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2">
                                        <i class="fas fa-exclamation-triangle"></i> <span id="update_warning_text"></span>
                                    </div>

                                    <div>
                                        <label for="new_status" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">New Status</label>
                                        <select id="new_status" name="new_status" @change="updateLocationSuggestion()" class="block w-full py-2.5 px-3 border border-gray-300 bg-white rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required></select>
                                    </div>
                                    
                                    <div>
                                        <label for="location" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Current Location</label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-map-marker-alt text-gray-400"></i></div>
                                            <input type="text" name="location" id="location" class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="e.g. Guwahati Checkgate" required>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label for="remarks" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Remarks (Optional)</label>
                                        <textarea name="remarks" id="remarks" rows="2" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Any delay reasons or notes..."></textarea>
                                    </div>
                                </div>
                                <div class="bg-gray-50 px-6 py-4 sm:flex sm:flex-row-reverse border-t border-gray-100">
                                    <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-6 py-2.5 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm transition">Save Update</button>
                                    <button type="button" @click="isModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2.5 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm transition">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php include 'footer.php'; ?>
        </main>
    </div>
</div>

<script>
    function trackingApp() {
        return {
            activeTab: '<?php echo $active_tab; ?>',
            isModalOpen: false,
            shipmentId: null,
            consignmentNo: '',
            currentStatus: '',
            shipmentOrigin: '',
            shipmentLastLocation: '',
            
            openModal(event) {
                const btn = event.currentTarget;
                this.shipmentId = btn.dataset.id;
                this.consignmentNo = btn.dataset.cn;
                this.currentStatus = btn.dataset.status;
                this.shipmentOrigin = btn.dataset.origin;
                this.shipmentLastLocation = btn.dataset.lastLocation;

                this.populateStatusDropdown(this.currentStatus);
                this.updateLocationSuggestion(); 
                this.checkTodaysUpdates();
                this.isModalOpen = true;
            },
            
            populateStatusDropdown(currentStatus) {
                const statusSelect = document.getElementById('new_status');
                statusSelect.innerHTML = '';
                let options = [];
                if (['Booked', 'Billed', 'Pending Payment', 'Reverify'].includes(currentStatus)) {
                    options = ['In Transit'];
                } else if (currentStatus === 'In Transit') {
                    options = ['In Transit', 'Reached'];
                } else if (currentStatus === 'Reached') {
                    options = ['Delivered'];
                }
                options.forEach(opt => {
                    const optionEl = document.createElement('option');
                    optionEl.value = opt;
                    optionEl.textContent = opt;
                    statusSelect.appendChild(optionEl);
                });
            },
            
            updateLocationSuggestion() {
                const newStatus = document.getElementById('new_status').value;
                const locationInput = document.getElementById('location');
                let suggestedLocation = '';

                if (newStatus === 'In Transit' && ['Booked', 'Billed', 'Pending Payment', 'Reverify'].includes(this.currentStatus)) {
                    suggestedLocation = this.shipmentOrigin;
                } else if (this.shipmentLastLocation) {
                    suggestedLocation = this.shipmentLastLocation;
                }
                locationInput.value = suggestedLocation;
            },
            
            async checkTodaysUpdates() {
                const warningDiv = document.getElementById('update_warning');
                const warningText = document.getElementById('update_warning_text');
                warningDiv.classList.add('hidden');
                try {
                    const response = await fetch(`check_updates.php?shipment_id=${this.shipmentId}`);
                    const data = await response.json();
                    if (data.count >= 2) {
                        warningText.textContent = `Warning: This consignment has already been updated ${data.count} times today.`;
                        warningDiv.classList.remove('hidden');
                    }
                } catch (error) { console.error("Could not check for updates:", error); }
            },
            
            init() {
                // Initial logic if needed
                const urlParams = new URLSearchParams(window.location.search);
                const tab = urlParams.get('tab');
                if (tab) this.activeTab = tab;
            }
        };
    }

    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('sidebar-close'); // Fix ID

    function toggleSidebar() {
        const wrapper = document.getElementById('sidebar-wrapper');
        const overlay = document.getElementById('sidebar-overlay');
        if(wrapper.classList.contains('hidden')){
            wrapper.classList.remove('hidden');
            wrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
            overlay.classList.remove('hidden');
        } else {
            wrapper.classList.add('hidden');
            wrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
            overlay.classList.add('hidden');
        }
    }

    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
    
    // Hide loader
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) {
            setTimeout(() => {
                loader.style.opacity = '0';
                setTimeout(() => loader.style.display = 'none', 300);
            }, 300);
        }
    };
</script>
</body>
</html>