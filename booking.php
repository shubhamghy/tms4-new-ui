<?php
// --- For Debugging: Temporarily add these lines to see detailed errors ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// --- Page State Management ---
$form_message = "";
$edit_mode = false;
$booking_data = [];
$booking_invoices = [];

// Handle GET request for editing
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_mode = true;
    $shipment_id = intval($_GET['id']);
    $stmt = $mysqli->prepare("SELECT * FROM shipments WHERE id = ?");
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $booking_data = $result->fetch_assoc();
    }
    $stmt->close();

    $stmt_invoices = $mysqli->prepare("SELECT * FROM shipment_invoices WHERE shipment_id = ?");
    $stmt_invoices->bind_param("i", $shipment_id);
    $stmt_invoices->execute();
    $result_invoices = $stmt_invoices->get_result();
    while ($row = $result_invoices->fetch_assoc()) {
        $booking_invoices[] = $row;
    }
    $stmt_invoices->close();
}

// --- Data Fetching for Dropdowns with Branch Filtering ---
$parties = []; $brokers = []; $drivers = []; $vehicles = []; $cities = []; $descriptions = []; $states = [];
$countries = []; $modal_cities = []; 

$user_role = $_SESSION['role'] ?? null;
$branch_id = $_SESSION['branch_id'] ?? 0;

$branch_filter_clause = "";
if ($user_role !== 'admin' && $branch_id > 0) {
    $branch_filter_clause = " AND (branch_id IS NULL OR branch_id = " . intval($branch_id) . ")";
}

$broker_branch_clause = "";
if ($user_role !== 'admin' && $branch_id > 0) {
    $broker_branch_clause = " AND (visibility_type = 'global' OR branch_id = " . intval($branch_id) . ")";
}

// Fetch Data
if ($result = $mysqli->query("SELECT id, name, address, city, party_type FROM parties WHERE is_active = 1{$branch_filter_clause} ORDER BY name ASC")) { $parties = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name FROM brokers WHERE is_active = 1{$broker_branch_clause} ORDER BY name ASC")) { $brokers = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name FROM drivers WHERE is_active = 1{$branch_filter_clause} ORDER BY name ASC")) { $drivers = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1{$branch_filter_clause} ORDER BY vehicle_number ASC")) { $vehicles = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, description FROM consignment_descriptions WHERE is_active = 1 ORDER BY description ASC")) { $descriptions = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name FROM countries ORDER BY name ASC")) { $countries = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name, country_id FROM states ORDER BY name ASC")) { $states = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name, state_id FROM cities ORDER BY name ASC")) { $modal_cities = $result->fetch_all(MYSQLI_ASSOC); }
if ($result = $mysqli->query("SELECT id, name FROM cities ORDER BY name ASC")) { $cities = $result->fetch_all(MYSQLI_ASSOC); }

// --- Form Processing ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $shipment_id = intval($_POST['shipment_id']);
    $consignment_no = trim($_POST['consignment_no']);
    $is_duplicate = false;

    // Check for duplicate consignment number
    $check_sql = "SELECT id FROM shipments WHERE consignment_no = ?";
    if ($check_stmt = $mysqli->prepare($check_sql)) {
        $check_stmt->bind_param("s", $consignment_no);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
            if ($shipment_id > 0) { // In edit mode
                $check_stmt->bind_result($found_id);
                $check_stmt->fetch();
                if ($found_id != $shipment_id) { $is_duplicate = true; }
            } else { // In create mode
                $is_duplicate = true;
            }
        }
        $check_stmt->close();
    }

    if ($is_duplicate) {
        $form_message = '<div class="p-4 mb-4 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: This Consignment Number is already in use.</div>';
    } else {
        $mysqli->begin_transaction();
        try {
            // Variables
            $is_shipping_different = isset($_POST['is_shipping_different']) ? 1 : 0;
            $shipping_name = $is_shipping_different ? trim($_POST['shipping_name']) : null;
            $shipping_address = $is_shipping_different ? trim($_POST['shipping_address']) : null;
            $net_weight = isset($_POST['net_weight_ftl']) ? 'FTL' : trim($_POST['net_weight']);
            $chargeable_weight = isset($_POST['chargeable_weight_ftl']) ? 'FTL' : trim($_POST['chargeable_weight']);
            $net_weight_unit = isset($_POST['net_weight_ftl']) ? '' : $_POST['net_weight_unit'];
            $chargeable_weight_unit = isset($_POST['chargeable_weight_ftl']) ? '' : $_POST['chargeable_weight_unit'];
            
            $description_id = !empty($_POST['description_id']) ? intval($_POST['description_id']) : null;
            $broker_id = !empty($_POST['broker_id']) ? intval($_POST['broker_id']) : null;
            $driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
            $vehicle_id = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
            
            $created_by_id = $_SESSION['id'];
            $branch_id = $_SESSION['branch_id'] ?? 1;

            if ($shipment_id > 0) { // UPDATE
                $sql = "UPDATE shipments SET consignment_no=?, consignment_date=?, consignor_id=?, consignee_id=?, is_shipping_different=?, shipping_name=?, shipping_address=?, origin=?, destination=?, description_id=?, quantity=?, package_type=?, net_weight=?, net_weight_unit=?, chargeable_weight=?, chargeable_weight_unit=?, billing_type=?, broker_id=?, driver_id=?, vehicle_id=?, payment_entry_status='Reverify' WHERE id=?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssiiissssisssssssiisi", $consignment_no, $_POST['consignment_date'], $_POST['consignor_id'], $_POST['consignee_id'], $is_shipping_different, $shipping_name, $shipping_address, $_POST['origin'], $_POST['destination'], $description_id, $_POST['quantity'], $_POST['package_type'], $net_weight, $net_weight_unit, $chargeable_weight, $chargeable_weight_unit, $_POST['billing_type'], $broker_id, $driver_id, $vehicle_id, $shipment_id);
            } else { // INSERT
                $status = 'Booked';
                $payment_entry_status = 'Pending';
                $sql = "INSERT INTO shipments (consignment_no, consignment_date, consignor_id, consignee_id, is_shipping_different, shipping_name, shipping_address, origin, destination, description_id, quantity, package_type, net_weight, net_weight_unit, chargeable_weight, chargeable_weight_unit, billing_type, broker_id, driver_id, vehicle_id, created_by_id, branch_id, status, payment_entry_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssiiissssisssssssiiiiiss", $consignment_no, $_POST['consignment_date'], $_POST['consignor_id'], $_POST['consignee_id'], $is_shipping_different, $shipping_name, $shipping_address, $_POST['origin'], $_POST['destination'], $description_id, $_POST['quantity'], $_POST['package_type'], $net_weight, $net_weight_unit, $chargeable_weight, $chargeable_weight_unit, $_POST['billing_type'], $broker_id, $driver_id, $vehicle_id, $created_by_id, $branch_id, $status, $payment_entry_status);
            }
            
            if (!$stmt->execute()) { throw new Exception("Error saving shipment: " . $stmt->error); }
            if ($shipment_id == 0) { $shipment_id = $stmt->insert_id; }
            $stmt->close();
            
            // Invoices
            $mysqli->query("DELETE FROM shipment_invoices WHERE shipment_id = $shipment_id");
            if (isset($_POST['invoices']) && is_array($_POST['invoices'])) {
                $invoice_sql = "INSERT INTO shipment_invoices (shipment_id, invoice_no, invoice_date, invoice_amount, eway_bill_no, eway_bill_expiry) VALUES (?, ?, ?, ?, ?, ?)";
                $invoice_stmt = $mysqli->prepare($invoice_sql);
                foreach ($_POST['invoices'] as $invoice) {
                    $invoice_no = trim($invoice['number']);
                    $invoice_date = !empty($invoice['date']) ? $invoice['date'] : null;
                    $invoice_amount = !empty($invoice['amount']) ? (float)$invoice['amount'] : null;
                    $eway_no = trim($invoice['eway_no']);
                    $eway_expiry = !empty($invoice['eway_expiry']) ? $invoice['eway_expiry'] : null;
                    if (!empty($invoice_no)) {
                        $invoice_stmt->bind_param("issdss", $shipment_id, $invoice_no, $invoice_date, $invoice_amount, $eway_no, $eway_expiry);
                        $invoice_stmt->execute();
                    }
                }
                $invoice_stmt->close();
            }

            $mysqli->commit();
            header("location: print_lr_landscape.php?id=" . $shipment_id);
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $form_message = '<div class="p-4 mb-4 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error: ' . $e->getMessage() . '</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit' : 'New'; ?> Shipment Booking - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.5rem; background-color: #f9fafb; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 42px; padding-left: 0.75rem; color: #1f2937; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; }
        .select2-container--default.select2-container--disabled .select2-selection--single { background-color: #f3f4f6; cursor: not-allowed; }
        
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f5f9; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        [x-cloak] { display: none; }
    </style>
</head>
<body class="bg-gray-50">

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

    <div class="flex flex-col flex-1 overflow-y-auto w-full relative">
        
        <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                            <i class="fas fa-shipping-fast opacity-80"></i> <?php echo $edit_mode ? 'Edit Booking' : 'New Booking'; ?>
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
        
        <main class="p-4 md:p-8 w-full bg-gray-50" x-data="bookingApp()">
            
            <?php if(!empty($form_message)) echo $form_message; ?>
            
            <form id="booking-form" method="post" class="space-y-6 max-w-7xl mx-auto" x-data="{ activeSection: 1 }">
                <input type="hidden" name="shipment_id" value="<?php echo $booking_data['id'] ?? ''; ?>">
                
                <div class="border border-indigo-100 rounded-xl bg-white shadow-sm overflow-hidden">
                    <div @click="activeSection = (activeSection === 1 ? 0 : 1)" class="px-6 py-4 bg-indigo-50/50 cursor-pointer flex justify-between items-center border-b border-indigo-100 hover:bg-indigo-50 transition">
                        <h3 class="font-bold text-lg text-indigo-900 flex items-center gap-2">
                            <span class="bg-indigo-100 text-indigo-700 w-8 h-8 rounded-full flex items-center justify-center text-sm border border-indigo-200">1</span> Party & Route
                        </h3>
                        <i class="fas text-indigo-400" :class="activeSection === 1 ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    </div>
                    <div x-show="activeSection === 1" x-transition class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="consignment_no" class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Consignment No.</label>
                                <input type="text" name="consignment_no" id="consignment_no" value="<?php echo htmlspecialchars($booking_data['consignment_no'] ?? ''); ?>" required class="block w-full px-3 py-2.5 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 text-gray-900">
                                <p id="cn-status" class="mt-1 text-xs font-medium"></p>
                            </div>
                            <div>
                                <label for="consignment_date" class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Date</label>
                                <input type="date" name="consignment_date" id="consignment_date" value="<?php echo htmlspecialchars($booking_data['consignment_date'] ?? date('Y-m-d')); ?>" required class="block w-full px-3 py-2.5 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 text-gray-900">
                            </div>
                        </div>
                        <div class="border-t border-gray-100 pt-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <label class="block text-xs font-semibold text-indigo-600 uppercase tracking-wide mb-2">Consignor (Sender)</label>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-grow"><select name="consignor_id" id="consignor_id" class="searchable-select block w-full" required><option value="">Select Consignor</option><?php foreach ($parties as $party): if(in_array($party['party_type'], ['Consignor', 'Both'])): ?><option value="<?php echo $party['id']; ?>"><?php echo htmlspecialchars($party['name']); ?></option><?php endif; endforeach; ?></select></div>
                                        <button type="button" @click="openModal('party', 'Consignor', '#consignor_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-indigo-200 text-indigo-600 rounded-lg hover:bg-indigo-50 shadow-sm transition"><i class="fas fa-plus"></i></button>
                                    </div>
                                    <div id="consignor_address" class="mt-3 text-sm text-gray-500 p-2 border-l-2 border-indigo-200 pl-3 min-h-[40px] italic"></div>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <label class="block text-xs font-semibold text-blue-600 uppercase tracking-wide mb-2">Consignee (Receiver)</label>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-grow"><select name="consignee_id" id="consignee_id" class="searchable-select block w-full" required><option value="">Select Consignee</option><?php foreach ($parties as $party): if(in_array($party['party_type'], ['Consignee', 'Both'])): ?><option value="<?php echo $party['id']; ?>"><?php echo htmlspecialchars($party['name']); ?></option><?php endif; endforeach; ?></select></div>
                                        <button type="button" @click="openModal('party', 'Consignee', '#consignee_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 shadow-sm transition"><i class="fas fa-plus"></i></button>
                                    </div>
                                    <div id="consignee_address" class="mt-3 text-sm text-gray-500 p-2 border-l-2 border-blue-200 pl-3 min-h-[40px] italic"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                             <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Origin</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="origin" id="origin" class="searchable-select block w-full" required><option value="">Select Origin</option><?php foreach ($cities as $city): ?><option value="<?php echo htmlspecialchars($city['name']); ?>"><?php echo htmlspecialchars($city['name']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('city', 'City', '#origin')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-map-marker-alt"></i></button>
                                </div>
                            </div>
                             <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Destination</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="destination" id="destination" class="searchable-select block w-full" required><option value="">Select Destination</option><?php foreach ($cities as $city): ?><option value="<?php echo htmlspecialchars($city['name']); ?>"><?php echo htmlspecialchars($city['name']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('city', 'City', '#destination')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-map-marker-alt"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="md:col-span-2 bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                             <label for="is_shipping_different" class="flex items-center cursor-pointer mb-2"><input type="checkbox" id="is_shipping_different" name="is_shipping_different" class="h-4 w-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500" <?php if(!empty($booking_data['is_shipping_different'])) echo 'checked'; ?>><span class="ml-2 text-sm font-semibold text-yellow-800">Different Shipping Address?</span></label>
                            <div id="shipping-address-fields" class="<?php echo empty($booking_data['is_shipping_different']) ? 'hidden' : ''; ?> grid grid-cols-1 gap-4 mt-2 transition-all">
                                <div><input type="text" name="shipping_name" placeholder="Shipping Name" value="<?php echo htmlspecialchars($booking_data['shipping_name'] ?? ''); ?>" class="block w-full px-3 py-2 border border-yellow-300 rounded-md bg-white"></div>
                                <div><textarea name="shipping_address" placeholder="Shipping Address" rows="2" class="block w-full px-3 py-2 border border-yellow-300 rounded-md bg-white"><?php echo htmlspecialchars($booking_data['shipping_address'] ?? ''); ?></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border border-emerald-100 rounded-xl bg-white shadow-sm overflow-hidden">
                    <div @click="activeSection = (activeSection === 2 ? 0 : 2)" class="px-6 py-4 bg-emerald-50/50 cursor-pointer flex justify-between items-center border-b border-emerald-100 hover:bg-emerald-50 transition">
                        <h3 class="font-bold text-lg text-emerald-900 flex items-center gap-2">
                            <span class="bg-emerald-100 text-emerald-700 w-8 h-8 rounded-full flex items-center justify-center text-sm border border-emerald-200">2</span> Goods & Invoice
                        </h3>
                        <i class="fas text-emerald-400" :class="activeSection === 2 ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    </div>
                    <div x-show="activeSection === 2" x-transition class="p-6 space-y-6">
                         <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Invoices & E-Way Bills</h4>
                                <button type="button" id="add-invoice-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-bold rounded-md text-emerald-700 bg-emerald-100 hover:bg-emerald-200 transition"><i class="fas fa-plus mr-1"></i> Add Row</button>
                            </div>
                            <div id="invoice-list" class="space-y-3"></div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Description</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="description_id" id="description_id" class="searchable-select block w-full"><option value="">Select Description</option><?php foreach ($descriptions as $desc): ?><option value="<?php echo $desc['id']; ?>"><?php echo htmlspecialchars($desc['description']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('description', 'Description', '#description_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Quantity</label>
                                <input type="text" name="quantity" id="quantity" value="<?php echo htmlspecialchars($booking_data['quantity'] ?? ''); ?>" class="block w-full px-3 py-2.5 border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500 bg-gray-50">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Package Type</label>
                                <select name="package_type" id="package_type" class="block w-full px-3 py-2.5 border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500 bg-gray-50"><option value="">Select Type</option><option value="Cartons">Cartons</option><option value="Cartons/Pieces">Cartons/Pieces</option><option value="Packets">Packets</option><option value="Pieces">Pieces</option><option value="Loose">Loose</option></select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Net Weight</label>
                                <div class="flex rounded-lg shadow-sm">
                                    <input type="text" name="net_weight" id="net_weight" value="<?php echo htmlspecialchars($booking_data['net_weight'] ?? ''); ?>" class="flex-1 block w-full min-w-0 rounded-l-lg border-gray-300 px-3 py-2.5 bg-gray-50 focus:ring-emerald-500 focus:border-emerald-500">
                                    <select name="net_weight_unit" id="net_weight_unit" class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-gray-300 bg-gray-100 text-gray-600 text-sm font-medium"><option>Kg</option><option>Quintal</option><option>Ton</option></select>
                                </div>
                                <div class="mt-2 flex items-center"><input id="net_weight_ftl" name="net_weight_ftl" type="checkbox" class="h-4 w-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500"><label for="net_weight_ftl" class="ml-2 block text-sm text-gray-700">Mark as FTL</label></div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Chargeable Weight</label>
                                <div class="flex rounded-lg shadow-sm">
                                    <input type="text" name="chargeable_weight" id="chargeable_weight" value="<?php echo htmlspecialchars($booking_data['chargeable_weight'] ?? ''); ?>" class="flex-1 block w-full min-w-0 rounded-l-lg border-gray-300 px-3 py-2.5 bg-gray-50 focus:ring-emerald-500 focus:border-emerald-500">
                                    <select name="chargeable_weight_unit" id="chargeable_weight_unit" class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-gray-300 bg-gray-100 text-gray-600 text-sm font-medium"><option>Kg</option><option>Quintal</option><option>Ton</option></select>
                                </div>
                                <div class="mt-2 flex items-center"><input id="chargeable_weight_ftl" name="chargeable_weight_ftl" type="checkbox" class="h-4 w-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500"><label for="chargeable_weight_ftl" class="ml-2 block text-sm text-gray-700">Mark as FTL</label></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border border-amber-100 rounded-xl bg-white shadow-sm overflow-hidden">
                    <div @click="activeSection = (activeSection === 3 ? 0 : 3)" class="px-6 py-4 bg-amber-50/50 cursor-pointer flex justify-between items-center border-b border-amber-100 hover:bg-amber-50 transition">
                        <h3 class="font-bold text-lg text-amber-900 flex items-center gap-2">
                            <span class="bg-amber-100 text-amber-700 w-8 h-8 rounded-full flex items-center justify-center text-sm border border-amber-200">3</span> Vehicle & Billing
                        </h3>
                        <i class="fas text-amber-400" :class="activeSection === 3 ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    </div>
                    <div x-show="activeSection === 3" x-transition class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Vehicle</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="vehicle_id" id="vehicle_id" class="searchable-select block w-full"><option value="">Select Vehicle</option><?php foreach ($vehicles as $vehicle): ?><option value="<?php echo $vehicle['id']; ?>"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('vehicle', 'Vehicle', '#vehicle_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                             <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Broker/Owner</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="broker_id" id="broker_id" class="searchable-select block w-full"><option value="">Select Broker</option><?php foreach ($brokers as $broker): ?><option value="<?php echo $broker['id']; ?>"><?php echo htmlspecialchars($broker['name']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('broker', 'Broker', '#broker_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Driver</label>
                                <div class="flex items-center gap-2">
                                    <div class="flex-grow"><select name="driver_id" id="driver_id" class="searchable-select block w-full"><option value="">Select Driver</option><?php foreach ($drivers as $driver): ?><option value="<?php echo $driver['id']; ?>"><?php echo htmlspecialchars($driver['name']); ?></option><?php endforeach; ?></select></div>
                                    <button type="button" @click="openModal('driver', 'Driver', '#driver_id')" class="flex-shrink-0 w-10 h-10 bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 shadow-sm"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Billing Type</label>
                                <select name="billing_type" id="billing_type" class="block w-full px-3 py-2.5 border-gray-300 bg-white rounded-lg shadow-sm focus:ring-amber-500 focus:border-amber-500" required><option>To Pay</option><option>Paid</option><option>To be Billed</option></select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 pb-12 flex justify-end gap-3">
                     <a href="view_bookings.php" class="px-6 py-3 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition shadow-sm text-center">Cancel</a>
                    <button type="submit" id="submit-btn" class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white font-bold rounded-lg hover:from-indigo-700 hover:to-blue-700 shadow-lg hover:shadow-xl transition transform hover:-translate-y-0.5">
                        <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update Consignment' : 'Save & Print LR'; ?>
                    </button>
                </div>
            </form>

            <div x-show="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
                <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <div @click="isModalOpen = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
                    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                    <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-100">
                         <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                            <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fas fa-plus-circle"></i> <span x-text="`Add New ${modalTitle}`"></span></h3>
                            <button @click="isModalOpen = false" class="text-indigo-200 hover:text-white"><i class="fas fa-times"></i></button>
                        </div>
                        <form id="quick-add-form" @submit.prevent="submitQuickAdd">
                            <div class="px-6 py-6">
                                <div id="modal-fields" class="space-y-4"></div>
                                <div id="modal-error" class="text-red-600 text-xs font-bold mt-3 bg-red-50 p-2 rounded hidden"></div>
                            </div>
                            <div class="bg-gray-50 px-6 py-3 flex justify-end space-x-3 border-t border-gray-100">
                                <button type="button" @click="isModalOpen = false" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 text-gray-700">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-bold hover:bg-indigo-700 shadow-md">Save Record</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php include 'footer.php'; ?>
        </main>
    </div>
</div>
    
<script>
    // Pass PHP arrays to JavaScript
    const countriesData = <?php echo json_encode($countries); ?>;
    const statesData = <?php echo json_encode($states); ?>;
    const modalCitiesData = <?php echo json_encode($modal_cities); ?>;
    let partiesData = <?php echo json_encode($parties); ?>;

    function bookingApp() {
        return {
            isModalOpen: false, modalTitle: '', modalType: '', targetSelect: '',
            openModal(type, title, target) {
                this.modalTitle = title; this.modalType = type; this.targetSelect = target; this.isModalOpen = true;
                const errorDiv = document.getElementById('modal-error');
                if(errorDiv) { errorDiv.textContent = ''; errorDiv.classList.add('hidden'); }
                
                const fieldsContainer = document.getElementById('modal-fields');
                let fieldsHtml = '';
                
                if (type === 'party') {
                    let countryOptions = countriesData.map(country => `<option value="${country.id}">${country.name}</option>`).join('');
                    fieldsHtml = `
                        <input type="hidden" name="party_type" value="${title}">
                        <input type="hidden" name="city" id="modal_city_name"> 
                        <div><label class="block text-xs font-bold text-gray-500 uppercase">Name</label><input type="text" name="name" required class="mt-1 w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">Address</label><textarea name="address" required class="mt-1 w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" rows="2"></textarea></div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div><label class="block text-xs font-bold text-gray-500 uppercase">Country</label><select name="country_id" id="modal_country" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"><option value="">Select Country</option>${countryOptions}</select></div>
                            <div><label class="block text-xs font-bold text-gray-500 uppercase">State</label><select name="state_id" id="modal_state" required class="mt-1 w-full p-2 border border-gray-300 rounded-md" disabled><option value="">Select Country first</option></select></div>
                        </div>
                        <div class="mt-3"><label class="block text-xs font-bold text-gray-500 uppercase">City</label><select id="modal_city" required class="mt-1 w-full p-2 border border-gray-300 rounded-md" disabled><option value="">Select State first</option></select></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">GST No.</label><input type="text" name="gst_no" class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>`;
                
                } else if (type === 'broker') {
                     fieldsHtml = `
                        <div><label class="block text-xs font-bold text-gray-500 uppercase">Name</label><input type="text" name="name" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">Address</label><textarea name="address" class="mt-1 w-full p-2 border border-gray-300 rounded-md" rows="2"></textarea></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">Contact No.</label><input type="text" name="contact_number" class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>`;
                
                } else if (type === 'vehicle') {
                     fieldsHtml = `<div><label class="block text-xs font-bold text-gray-500 uppercase">Vehicle Number</label><input type="text" name="vehicle_number" required class="mt-1 w-full p-2 border border-gray-300 rounded-md uppercase" placeholder="XX-00-XX-0000"></div>`;
                
                } else if (type === 'driver') {
                     fieldsHtml = `
                        <div><label class="block text-xs font-bold text-gray-500 uppercase">Driver Name</label><input type="text" name="name" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">License Number</label><input type="text" name="license_number" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>`;
                
                } else if (type === 'description') {
                     fieldsHtml = `<div><label class="block text-xs font-bold text-gray-500 uppercase">Consignment Description</label><input type="text" name="description" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>`;
                
                } else if (type === 'city') {
                    let stateOptions = statesData.map(state => `<option value="${state.id}">${state.name}</option>`).join('');
                    fieldsHtml = `
                        <div><label class="block text-xs font-bold text-gray-500 uppercase">City Name</label><input type="text" name="name" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mt-3">State</label><select name="state_id" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"><option value="">Select State</option>${stateOptions}</select></div>`;
                }
                fieldsContainer.innerHTML = fieldsHtml;
            },
            
            async submitQuickAdd() {
                const form = document.getElementById('quick-add-form');
                const formData = new FormData(form);
                const errorDiv = document.getElementById('modal-error');
                
                if (this.modalType === 'party') {
                    const selectedCityName = $('#modal_city').find('option:selected').text();
                    if (selectedCityName && selectedCityName !== "Select State first" && selectedCityName !== "No cities found") {
                        formData.set('city', selectedCityName);
                    }
                }

                try {
                    const response = await fetch(`quick_add.php?type=${this.modalType}`, { method: 'POST', body: formData });
                    if (!response.ok) throw new Error('Server returned an error.');
                    const data = await response.json();
                    if (data.success) {
                        if (this.modalType === 'city') {
                            const newCityOption = new Option(data.name, data.name);
                            $('#origin').append(newCityOption.cloneNode(true));
                            $('#destination').append(newCityOption.cloneNode(true));
                            $(this.targetSelect).val(data.name).trigger('change');
                        } else {
                            const newOption = new Option(data.name, data.id, true, true);
                            $(this.targetSelect).append(newOption).trigger('change');
                        }
                        if (this.modalType === 'party') {
                            partiesData.push({id: data.id, name: data.name, address: data.address, city: data.city, party_type: data.party_type});
                        }
                        this.isModalOpen = false;
                    } else {
                         errorDiv.textContent = data.message || 'An unknown error occurred.';
                         errorDiv.classList.remove('hidden');
                    }
                } catch (error) {
                    console.error('Quick Add Error:', error);
                    errorDiv.textContent = 'A server error occurred.';
                    errorDiv.classList.remove('hidden');
                }
            }
        };
    }

    jQuery(function($) {
        // Toggle Sidebar Logic
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
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        
        $('.searchable-select').select2({ width: '100%' });
        
        const bookingInvoices = <?php echo json_encode($booking_invoices); ?>;

        function updateAddress(selectedId, addressDiv, targetLocationSelect) {
            const party = partiesData.find(p => p.id == selectedId);
            if (party) {
                addressDiv.html(party.address ? party.address.replace(/\n/g, '<br>') : '');
                if (party.city && $(targetLocationSelect).length) {
                    if ($(targetLocationSelect).find(`option[value='${party.city}']`).length > 0) {
                        $(targetLocationSelect).val(party.city).trigger('change');
                    } else {
                        const newCityOption = new Option(party.city, party.city, true, true);
                        $(targetLocationSelect).append(newCityOption).trigger('change');
                    }
                }
            } else { addressDiv.html(''); }
        }
        
        async function setAssignedDriver() {
            const vehicleId = $('#vehicle_id').val();
            const driverSelect = $('#driver_id');
            if (!vehicleId) { 
                driverSelect.val("").trigger('change').prop('disabled', false).select2({ disabled: false });
                return; 
            }
            try {
                const response = await fetch(`get_vehicle_details.php?vehicle_id=${vehicleId}`);
                const data = await response.json();
                if (data.driver_id) {
                    driverSelect.val(data.driver_id).trigger('change').prop('disabled', true).select2({ disabled: true });
                } else {
                    driverSelect.val("").trigger('change').prop('disabled', false).select2({ disabled: false });
                }
            } catch (error) { driverSelect.prop('disabled', false).select2({ disabled: false }); }
        }
        
        $('#booking-form').on('submit', function() { $('#driver_id').prop('disabled', false); });
        
        let invoiceCounter = 0;
        function addInvoiceRow(invoice = {}) {
            invoiceCounter++;
            const invoiceRowHtml = `
            <div class="invoice-row grid grid-cols-1 md:grid-cols-12 gap-3 items-end border border-emerald-100 bg-emerald-50/30 p-3 rounded-lg relative group">
                <div class="md:col-span-3"><label class="text-[10px] uppercase font-bold text-emerald-600">Invoice No</label><input type="text" name="invoices[${invoiceCounter}][number]" value="${invoice.invoice_no || ''}" class="block w-full px-2 py-1.5 border-gray-300 rounded text-sm focus:ring-emerald-500 focus:border-emerald-500"></div>
                <div class="md:col-span-2"><label class="text-[10px] uppercase font-bold text-emerald-600">Date</label><input type="date" name="invoices[${invoiceCounter}][date]" value="${invoice.invoice_date || ''}" class="block w-full px-2 py-1.5 border-gray-300 rounded text-sm focus:ring-emerald-500 focus:border-emerald-500"></div>
                <div class="md:col-span-2"><label class="text-[10px] uppercase font-bold text-emerald-600">Amount</label><input type="number" step="0.01" name="invoices[${invoiceCounter}][amount]" value="${invoice.invoice_amount || ''}" class="block w-full px-2 py-1.5 border-gray-300 rounded text-sm focus:ring-emerald-500 focus:border-emerald-500"></div>
                <div class="md:col-span-2"><label class="text-[10px] uppercase font-bold text-emerald-600">E-Way No</label><input type="text" name="invoices[${invoiceCounter}][eway_no]" value="${invoice.eway_bill_no || ''}" class="block w-full px-2 py-1.5 border-gray-300 rounded text-sm focus:ring-emerald-500 focus:border-emerald-500"></div>
                <div class="md:col-span-2"><label class="text-[10px] uppercase font-bold text-emerald-600">Expiry</label><input type="date" name="invoices[${invoiceCounter}][eway_expiry]" value="${invoice.eway_bill_expiry || ''}" class="block w-full px-2 py-1.5 border-gray-300 rounded text-sm focus:ring-emerald-500 focus:border-emerald-500"></div>
                <div class="md:col-span-1 text-center"><button type="button" class="remove-invoice-btn text-red-400 hover:text-red-600 p-1"><i class="fas fa-times-circle"></i></button></div>
            </div>`;
            $('#invoice-list').append(invoiceRowHtml);
        }
        
        $('#add-invoice-btn').on('click', () => addInvoiceRow());
        $('#invoice-list').on('click', '.remove-invoice-btn', function() { if ($('.invoice-row').length > 1) { $(this).closest('.invoice-row').remove(); } });
        
        function handleFtlCheckbox(checkbox, weightInput, unitSelect) {
            if (checkbox.is(':checked')) {
                weightInput.val('FTL').prop('disabled', true).addClass('bg-gray-100');
                unitSelect.prop('disabled', true).addClass('bg-gray-100');
            } else {
                if (weightInput.val() === 'FTL') { weightInput.val(''); }
                weightInput.prop('disabled', false).removeClass('bg-gray-100');
                unitSelect.prop('disabled', false).removeClass('bg-gray-100');
            }
        }
        $('#net_weight_ftl').on('change', function() { handleFtlCheckbox($(this), $('#net_weight'), $('#net_weight_unit')); });
        $('#chargeable_weight_ftl').on('change', function() { handleFtlCheckbox($(this), $('#chargeable_weight'), $('#chargeable_weight_unit')); });
        
        $('#consignment_no').on('blur', async function() {
            const cnInput = $(this);
            const cnStatus = $('#cn-status');
            const submitBtn = $('#submit-btn');
            const consignmentNo = cnInput.val().trim();
            const shipmentId = $('input[name="shipment_id"]').val();
            
            if (consignmentNo === '') { cnStatus.text(''); submitBtn.prop('disabled', false).removeClass('opacity-50 cursor-not-allowed'); return; }

            try {
                let url = `check_consignment.php?consignment_no=${encodeURIComponent(consignmentNo)}`;
                if (shipmentId) { url += `&id=${shipmentId}`; }
                const response = await fetch(url);
                const data = await response.json();
                if (data.exists) {
                    cnStatus.html('<i class="fas fa-times-circle"></i> CN already exists').removeClass('text-green-600').addClass('text-red-600');
                    submitBtn.prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
                } else {
                    cnStatus.html('<i class="fas fa-check-circle"></i> Available').removeClass('text-red-600').addClass('text-green-600');
                    submitBtn.prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
                }
            } catch (error) { console.error(error); }
        });

        $('#consignor_id').on('change', function() { updateAddress($(this).val(), $('#consignor_address'), '#origin'); });
        $('#consignee_id').on('change', function() { updateAddress($(this).val(), $('#consignee_address'), '#destination'); });
        $('#vehicle_id').on('change', setAssignedDriver);
        $('#is_shipping_different').on('change', function() { $('#shipping-address-fields').toggleClass('hidden', !$(this).is(':checked')); });

        if (bookingInvoices.length > 0) {
            $('#invoice-list').empty();
            bookingInvoices.forEach(invoice => addInvoiceRow(invoice));
        } else { addInvoiceRow(); }

        $(document).on('change', '#modal_country', function() {
            const countryId = $(this).val();
            const stateSelect = $('#modal_state');
            const citySelect = $('#modal_city');
            stateSelect.empty().append('<option value="">Loading...</option>');
            citySelect.empty().append('<option value="">Select State first</option>').prop('disabled', true);

            if (!countryId) { stateSelect.empty().append('<option value="">Select Country first</option>').prop('disabled', true); return; }
            const filteredStates = statesData.filter(state => state.country_id == countryId);
            stateSelect.empty().append('<option value="">Select State</option>');
            if (filteredStates.length > 0) {
                filteredStates.forEach(state => { stateSelect.append(`<option value="${state.id}">${state.name}</option>`); });
                stateSelect.prop('disabled', false);
            } else { stateSelect.empty().append('<option value="">No states found</option>').prop('disabled', true); }
        });

        $(document).on('change', '#modal_state', function() {
            const stateId = $(this).val();
            const citySelect = $('#modal_city');
            citySelect.empty().append('<option value="">Loading...</option>');
            if (!stateId) { citySelect.empty().append('<option value="">Select State first</option>').prop('disabled', true); return; }
            const filteredCities = modalCitiesData.filter(city => city.state_id == stateId);
            citySelect.empty().append('<option value="">Select City</option>');
            if (filteredCities.length > 0) {
                filteredCities.forEach(city => { citySelect.append(`<option value="${city.id}">${city.name}</option>`); });
                citySelect.prop('disabled', false);
            } else { citySelect.empty().append('<option value="">No cities found</option>').prop('disabled', true); }
        });
        
        <?php if ($edit_mode): ?>
        $('#consignor_id').val('<?php echo $booking_data['consignor_id']; ?>').trigger('change');
        $('#consignee_id').val('<?php echo $booking_data['consignee_id']; ?>').trigger('change');
        $('#origin').val('<?php echo $booking_data['origin']; ?>').trigger('change');
        $('#destination').val('<?php echo $booking_data['destination']; ?>').trigger('change');
        $('#description_id').val('<?php echo $booking_data['description_id']; ?>').trigger('change');
        $('#package_type').val('<?php echo $booking_data['package_type']; ?>');
        $('#billing_type').val('<?php echo $booking_data['billing_type']; ?>');
        $('#broker_id').val('<?php echo $booking_data['broker_id']; ?>').trigger('change');
        $('#vehicle_id').val('<?php echo $booking_data['vehicle_id']; ?>').trigger('change');
        if ('<?php echo $booking_data['net_weight']; ?>' === 'FTL') { $('#net_weight_ftl').prop('checked', true); }
        $('#net_weight_unit').val('<?php echo $booking_data['net_weight_unit']; ?>');
        handleFtlCheckbox($('#net_weight_ftl'), $('#net_weight'), $('#net_weight_unit'));
        if ('<?php echo $booking_data['chargeable_weight']; ?>' === 'FTL') { $('#chargeable_weight_ftl').prop('checked', true); }
        $('#chargeable_weight_unit').val('<?php echo $booking_data['chargeable_weight_unit']; ?>');
        handleFtlCheckbox($('#chargeable_weight_ftl'), $('#chargeable_weight'), $('#chargeable_weight_unit'));
        <?php endif; ?>
    });

    window.onload = function() { const loader = document.getElementById('loader'); if (loader) { loader.style.display = 'none'; } };
</script>
</body>
</html>