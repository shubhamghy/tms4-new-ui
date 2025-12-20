<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_manage = in_array($user_role, ['admin', 'manager']);
$is_admin = ($user_role === 'admin');

if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}

// Get the user's branch ID from the session
$user_branch_id = intval($_SESSION['branch_id'] ?? 0);
$message = "";

// Handle Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && $is_admin) {
    $invoice_id_to_delete = intval($_GET['id']);
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM invoices WHERE id = ?");
        $stmt->bind_param("i", $invoice_id_to_delete);
        $stmt->execute();
        $stmt->close();
        $mysqli->commit();
        $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Invoice deleted successfully.</div>";
    } catch (Exception $e) {
        $mysqli->rollback();
        $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error deleting invoice: " . $e->getMessage() . "</div>";
    }
}


// --- Build query string for pagination to preserve any filters ---
$query_params = $_GET;
unset($query_params['page']); 
$query_string = http_build_query($query_params);


// --- Pagination Logic ---
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// --- Get total number of invoices (filtered by branch) ---
$sql_count = "SELECT COUNT(id) FROM invoices";
if (!$is_admin && $user_branch_id > 0) {
    $sql_count .= " WHERE branch_id = ?";
    $stmt_count = $mysqli->prepare($sql_count);
    $stmt_count->bind_param("i", $user_branch_id);
} else {
    $stmt_count = $mysqli->prepare($sql_count);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_row()[0];
$stmt_count->close();
$total_pages = ceil($total_records / $records_per_page);

// --- Fetch invoices (filtered by branch) ---
$invoices_list = [];
$sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.total_amount, i.status, p.name as consignor_name,
               (SELECT SUM(amount_received) FROM invoice_payments ip WHERE ip.invoice_id = i.id) as paid_amount
        FROM invoices i 
        JOIN parties p ON i.consignor_id = p.id";

$params = [];
$param_types = "";

if (!$is_admin && $user_branch_id > 0) {
    $sql .= " WHERE i.branch_id = ? "; 
    $params[] = $user_branch_id;
    $param_types .= "i";
}

$sql .= " ORDER BY i.invoice_date DESC, i.id DESC 
          LIMIT ?, ?";

$params[] = $offset;
$params[] = $records_per_page;
$param_types .= "ii";

if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $invoices_list[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Invoices - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        #printModal { z-index: 100; }

        /* --- MOBILE TABLE CARD VIEW --- */
        @media (max-width: 768px) {
            .mobile-hidden-thead thead { display: none; }
            .mobile-hidden-thead tbody tr {
                display: block;
                margin-bottom: 1rem;
                background-color: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 0.5rem;
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                padding: 1rem;
            }
            .mobile-hidden-thead tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .mobile-hidden-thead tbody td:last-child { 
                border-bottom: none; 
                padding-top: 1rem;
                justify-content: flex-end; /* Align actions to right */
                gap: 1rem;
            }
            .mobile-hidden-thead tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #6b7280;
                text-align: left;
                margin-right: 1rem;
                flex-shrink: 0;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
        }
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
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-file-invoice-dollar opacity-80"></i> View Invoices
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

            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8">
                <?php if(!empty($message)) echo $message; ?>
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="bg-gray-50/50 px-6 py-5 border-b border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-list text-indigo-500"></i> Generated Invoices
                        </h2>
                        <a href="manage_invoices.php" class="w-full md:w-auto inline-flex items-center justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i> Create New Invoice
                        </a>
                    </div>
                    
                    <div class="border-0 md:border rounded-lg mobile-hidden-thead">
                        <table class="w-full table-fixed divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide w-[15%]">Invoice No.</th>
                                    <th class="px-2 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide w-[12%]">Date</th>
                                    <th class="px-2 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wide w-[20%]">Consignor</th>
                                    <th class="px-2 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide w-[12%]">Total Amt</th>
                                    <th class="px-2 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide w-[12%]">Bal. Due</th>
                                    <th class="px-2 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wide w-[12%]">Status</th>
                                    <th class="px-2 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wide w-[17%]">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white md:divide-y divide-gray-200">
                                <?php foreach ($invoices_list as $invoice): ?>
                                    <?php
                                        $paid_amount = $invoice['paid_amount'] ?? 0;
                                        $balance_due = $invoice['total_amount'] - $paid_amount;
                                        $status = $invoice['status'];
                                        $status_class = 'bg-gray-100 text-gray-600 border-gray-200'; // Default
                                        
                                        if ($status === 'Paid') {
                                            $status_class = 'bg-green-50 text-green-700 border-green-200';
                                        } elseif ($status === 'Partially Paid') {
                                            $status_class = 'bg-amber-50 text-amber-700 border-amber-200';
                                        } elseif ($balance_due > 0 && $status !== 'Partially Paid') {
                                            $status = 'Unpaid';
                                            $status_class = 'bg-red-50 text-red-700 border-red-200';
                                        }
                                    ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-2 py-3 whitespace-normal break-words text-sm font-bold" data-label="Invoice No.">
                                        <a href="view_invoice_details.php?id=<?php echo $invoice['id']; ?>" class="text-indigo-600 hover:text-indigo-900 transition hover:underline">
                                            <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                                        </a>
                                    </td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-sm text-gray-500" data-label="Date"><?php echo date("d-m-Y", strtotime($invoice['invoice_date'])); ?></td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-sm text-gray-700 font-medium" data-label="Consignor"><?php echo htmlspecialchars($invoice['consignor_name']); ?></td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-sm text-gray-500 text-right" data-label="Total Amt">&#8377;<?php echo htmlspecialchars(number_format($invoice['total_amount'], 2)); ?></td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-sm font-bold text-red-600 text-right" data-label="Balance Due">&#8377;<?php echo htmlspecialchars(number_format($balance_due, 2)); ?></td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-center" data-label="Status">
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full border <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                    <td class="px-2 py-3 whitespace-normal break-words text-right text-sm font-medium" data-label="Actions">
                                        <button onclick="showPrintModal(<?php echo $invoice['id']; ?>)" class="text-blue-600 hover:text-blue-900 mr-3 transition" title="Print Invoice">
                                            <i class="fas fa-print fa-lg"></i>
                                        </button>
                                        <?php if ($is_admin): ?>
                                        <a href="view_invoices.php?action=delete&id=<?php echo $invoice['id']; ?>" class="text-red-600 hover:text-red-900 transition" onclick="return confirm('Are you sure you want to permanently delete this invoice?');" title="Delete">
                                            <i class="fas fa-trash-alt fa-lg"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($invoices_list)): ?>
                                    <tr><td colspan="7" class="text-center py-12 text-gray-400 italic">No invoices found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <span class="text-sm text-gray-500">
                            Showing <span class="font-bold text-gray-800"><?php echo $offset + 1; ?></span> to <span class="font-bold text-gray-800"><?php echo min($offset + $records_per_page, $total_records); ?></span> of <span class="font-bold text-gray-800"><?php echo $total_records; ?></span> results
                        </span>
                        <div class="inline-flex rounded-md shadow-sm">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&amp;<?php echo $query_string; ?>" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-l-lg hover:bg-gray-100 transition">Prev</a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&amp;<?php echo $query_string; ?>" class="px-4 py-2 text-sm font-medium border-t border-b border-gray-300 transition <?php echo $i == $page ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&amp;<?php echo $query_string; ?>" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-r-lg hover:bg-gray-100 transition">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div> 
    </div>

    <div id="printModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center hidden z-50 backdrop-blur-sm">
        <div class="relative p-8 bg-white w-full max-w-md m-auto flex-col flex rounded-xl shadow-2xl border border-gray-100">
            <h3 class="text-xl font-bold mb-4 text-gray-800">Select Print Format</h3>
            <p class="mb-6 text-gray-500 text-sm">Please choose an orientation for your PDF invoice.</p>
            
            <div class="flex gap-4">
                <a id="printPortraitLink" href="#" target="_blank" onclick="hidePrintModal()" class="flex-1 flex flex-col items-center justify-center py-4 px-4 bg-indigo-50 border border-indigo-100 text-indigo-700 font-bold rounded-xl hover:bg-indigo-100 transition shadow-sm group">
                    <i class="fas fa-file-pdf text-2xl mb-2 group-hover:scale-110 transition-transform"></i> Portrait
                </a>
                <a id="printLandscapeLink" href="#" target="_blank" onclick="hidePrintModal()" class="flex-1 flex flex-col items-center justify-center py-4 px-4 bg-gray-50 border border-gray-200 text-gray-700 font-bold rounded-xl hover:bg-gray-100 transition shadow-sm group">
                    <i class="fas fa-file-image text-2xl mb-2 group-hover:scale-110 transition-transform"></i> Landscape
                </a>
            </div>

            <button onclick="hidePrintModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
    </div>

    <script>
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) {
            loader.style.display = 'none';
        }
    };

    // --- Sidebar Toggle Logic ---
    const sidebarWrapper = document.getElementById('sidebar-wrapper');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarToggle = document.getElementById('sidebar-toggle');
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

    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }

    // Print Modal Logic
    const modal = document.getElementById('printModal');
    const portraitLink = document.getElementById('printPortraitLink');
    const landscapeLink = document.getElementById('printLandscapeLink');

    function showPrintModal(invoiceId) {
        portraitLink.href = `print_invoice.php?id=${invoiceId}&orientation=portrait`;
        landscapeLink.href = `print_invoice.php?id=${invoiceId}&orientation=landscape`;
        
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    function hidePrintModal() {
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                hidePrintModal();
            }
        });
    }
    </script>
</body>
</html>