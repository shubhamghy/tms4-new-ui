<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$form_message = "";
$parties_list = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignor', 'Both') AND is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$party_id = isset($_GET['party_id']) ? intval($_GET['party_id']) : 0;
$pending_invoices = [];

// --- Logic to Fetch Pending Invoices for Reconciliation ---
if ($party_id > 0) {
    // Query to find invoices with an outstanding balance
    $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.total_amount,
            COALESCE((SELECT SUM(amount_received) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0) as paid_amount
            FROM invoices i
            WHERE i.consignor_id = ?
            AND i.total_amount > COALESCE((SELECT SUM(amount_received) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0)
            ORDER BY i.invoice_date ASC";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $party_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['balance_due'] = $row['total_amount'] - $row['paid_amount'];
            $pending_invoices[] = $row;
        }
        $stmt->close();
    }
}

// --- Handle Reconciliation Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reconcile_payment'])) {
    $voucher_no = trim($_POST['voucher_no']);
    $voucher_date = $_POST['voucher_date'];
    $party_id_post = intval($_POST['party_id']);
    $payment_mode = $_POST['payment_mode'];
    $total_amount_received = (float)$_POST['total_amount_received'];
    $reference_no = trim($_POST['reference_no']);
    $allocations = $_POST['allocations'] ?? [];
    $created_by_id = $_SESSION['id'];

    if (empty($voucher_no) || empty($voucher_date) || $party_id_post <= 0 || $total_amount_received <= 0) {
        $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Please fill all required voucher fields.</div>';
    } else {
        $mysqli->begin_transaction();
        try {
            $total_allocated = 0;
            // Validate allocations and calculate total allocated amount
            foreach ($allocations as $invoice_id => $amount) {
                $amount_allocated = (float)$amount;
                if ($amount_allocated > 0) {
                    // Basic sanity check: prevent over-allocation (client-side validation is also needed)
                    $total_allocated += $amount_allocated;
                }
            }

            if (abs($total_allocated - $total_amount_received) > 0.01) {
                throw new Exception("Allocation mismatch: Total allocated amount (" . number_format($total_allocated, 2) . ") does not match Total received amount (" . number_format($total_amount_received, 2) . ").");
            }
            
            // 1. Insert into reconciliation_vouchers
            $sql_voucher = "INSERT INTO reconciliation_vouchers (voucher_no, voucher_date, party_id, payment_mode, total_amount, reference_no, reconciled_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_voucher = $mysqli->prepare($sql_voucher);
            $stmt_voucher->bind_param("ssisdsi", $voucher_no, $voucher_date, $party_id_post, $payment_mode, $total_amount_received, $reference_no, $created_by_id);
            if (!$stmt_voucher->execute()) { throw new Exception("Error creating reconciliation voucher: " . $stmt_voucher->error); }
            $voucher_id = $stmt_voucher->insert_id;
            $stmt_voucher->close();

            // 2. Insert into invoice_payments for each allocated invoice
            $sql_payment = "INSERT INTO invoice_payments (invoice_id, reconciliation_voucher_id, payment_date, amount_received, payment_mode, reference_no, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_payment = $mysqli->prepare($sql_payment);
            $payment_mode_val = $payment_mode; // Save payment mode to the invoice payment record
            
            foreach ($allocations as $invoice_id => $amount) {
                $amount_allocated = (float)$amount;
                if ($amount_allocated > 0) {
                    $stmt_payment->bind_param("iisdssi", $invoice_id, $voucher_id, $voucher_date, $amount_allocated, $payment_mode_val, $reference_no, $created_by_id);
                    if (!$stmt_payment->execute()) { throw new Exception("Error allocating payment to invoice ID: " . $invoice_id); }
                    
                    // 3. Update the invoice status (same logic as view_invoice_details.php)
                    $sql_total = "SELECT total_amount, (SELECT SUM(amount_received) FROM invoice_payments WHERE invoice_id = ?) as total_paid FROM invoices WHERE id = ?";
                    $stmt_total = $mysqli->prepare($sql_total);
                    $stmt_total->bind_param("ii", $invoice_id, $invoice_id);
                    $stmt_total->execute();
                    $totals = $stmt_total->get_result()->fetch_assoc();
                    $stmt_total->close();

                    $total_inv_amount = $totals['total_amount'];
                    $total_paid = $totals['total_paid'] ?? 0;
                    $new_status = (abs($total_paid - $total_inv_amount) < 0.01) ? 'Paid' : 'Partially Paid';
                    
                    $sql_update = "UPDATE invoices SET status = ? WHERE id = ?";
                    $stmt_update = $mysqli->prepare($sql_update);
                    $stmt_update->bind_param("si", $new_status, $invoice_id);
                    $stmt_update->execute();
                    $stmt_update->close();
                }
            }
            $stmt_payment->close();

            $mysqli->commit();
            header("location: manage_reconciliation.php?party_id=" . $party_id_post . "&status=success&vno=" . urlencode($voucher_no));
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Reconciliation Error: ' . $e->getMessage() . '</div>';
            // Need to reload the page with post data if an error occurs to re-populate the form
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Reconciliation - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.375rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px; padding-left: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 overflow-y-auto">
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <h1 class="text-xl font-semibold text-gray-800">Payment Reconciliation</h1>
                        <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>
            <main class="p-4 md:p-8">
                <?php 
                    if(!empty($form_message)) echo $form_message; 
                    if (isset($_GET['status']) && $_GET['status'] == 'success') {
                        echo '<div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50">Reconciliation Voucher ' . htmlspecialchars($_GET['vno']) . ' created and payments allocated successfully.</div>';
                    }
                ?>
                <div class="bg-white p-8 rounded-lg shadow-md mb-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">Select Party for Reconciliation</h2>
                    <form method="GET" class="bg-gray-50 p-4 rounded-lg">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2">
                                <label for="party_id" class="block text-sm font-medium text-gray-700">Consignor / Client</label>
                                <select name="party_id" id="party_id" class="searchable-select mt-1 block w-full" required>
                                    <option value="">Select Consignor</option>
                                    <?php foreach($parties_list as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php if($party_id == $c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="py-2 px-6 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 w-full">Load Invoices</button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($party_id > 0 && !empty($pending_invoices)): ?>
                <div class="bg-white p-8 rounded-lg shadow-md" x-data="reconciliationApp()">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">Payment Allocation</h2>
                    <form method="POST" @submit.prevent="submitForm">
                        <input type="hidden" name="reconcile_payment" value="1">
                        <input type="hidden" name="party_id" value="<?php echo $party_id; ?>">

                        <!-- Payment Voucher Details -->
                        <div class="border p-4 rounded-lg bg-gray-50 grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div><label class="block text-sm font-medium">Voucher No. <span class="text-red-500">*</span></label><input type="text" name="voucher_no" required class="mt-1 block w-full p-2 border rounded-md"></div>
                            <div><label class="block text-sm font-medium">Date <span class="text-red-500">*</span></label><input type="date" name="voucher_date" value="<?php echo date('Y-m-d'); ?>" required class="mt-1 block w-full p-2 border rounded-md"></div>
                            <div><label class="block text-sm font-medium">Payment Mode</label><select name="payment_mode" class="mt-1 block w-full p-2 border rounded-md bg-white"><option>Bank Transfer</option><option>Cheque</option><option>Cash</option><option>Other</option></select></div>
                            <div><label class="block text-sm font-medium">Reference No. (Txn/Chq)</label><input type="text" name="reference_no" class="mt-1 block w-full p-2 border rounded-md"></div>
                            <div class="md:col-span-4"><label class="block text-sm font-medium">Total Amount Received <span class="text-red-500">*</span></label><input type="number" step="0.01" name="total_amount_received" x-model.number="totalReceived" @keyup="updateAllocationWarning" required class="mt-1 block w-full p-2 border rounded-md font-bold text-lg"></div>
                        </div>

                        <!-- Allocation Table -->
                        <div class="overflow-x-auto mb-6">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Invoice No.</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Date</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Total Amt</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Paid</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Balance Due</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-indigo-600">Amount to Allocate</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($pending_invoices as $inv): ?>
                                    <tr class="hover:bg-indigo-50/50">
                                        <td class="px-4 py-3 font-medium"><a href="view_invoice_details.php?id=<?php echo $inv['id']; ?>" class="text-indigo-600 hover:underline"><?php echo htmlspecialchars($inv['invoice_no']); ?></a></td>
                                        <td class="px-4 py-3"><?php echo date("d-m-Y", strtotime($inv['invoice_date'])); ?></td>
                                        <td class="px-4 py-3 text-right"><?php echo number_format($inv['total_amount'], 2); ?></td>
                                        <td class="px-4 py-3 text-right text-green-600"><?php echo number_format($inv['paid_amount'], 2); ?></td>
                                        <td class="px-4 py-3 text-right font-semibold text-red-600" data-balance="<?php echo $inv['balance_due']; ?>"><?php echo number_format($inv['balance_due'], 2); ?></td>
                                        <td class="px-4 py-3 text-right">
                                            <input type="number" step="0.01" 
                                                   name="allocations[<?php echo $inv['id']; ?>]" 
                                                   x-model.number="allocations[<?php echo $inv['id']; ?>]"
                                                   @keyup="updateTotalAllocated"
                                                   @change="validateAllocation(<?php echo $inv['id']; ?>, $event.target.value, <?php echo $inv['balance_due']; ?>)"
                                                   class="w-32 p-1 border rounded-md text-right text-indigo-700 focus:ring-indigo-500 focus:border-indigo-500">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary Totals -->
                        <div class="flex justify-between items-end border-t pt-4">
                            <div class="text-lg font-bold">
                                <p :class="{'text-red-600': allocationWarning, 'text-gray-800': !allocationWarning}" x-text="allocationWarning ? allocationWarning : 'Total Allocated: ₹' + totalAllocated.toFixed(2)"></p>
                            </div>
                            <button type="submit" 
                                    :disabled="allocationWarning !== ''" 
                                    class="py-3 px-8 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-400">
                                Reconcile Payment
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('reconciliationApp', () => ({
            totalReceived: 0,
            allocations: {},
            totalAllocated: 0,
            allocationWarning: '',

            init() {
                // Initialize allocations array with keys from PHP data
                <?php foreach ($pending_invoices as $inv): ?>
                    this.allocations[<?php echo $inv['id']; ?>] = 0.00;
                <?php endforeach; ?>
            },

            updateTotalAllocated() {
                let total = 0;
                for (const id in this.allocations) {
                    const amount = parseFloat(this.allocations[id]) || 0;
                    if (!isNaN(amount) && amount > 0) {
                        total += amount;
                    }
                }
                this.totalAllocated = total;
                this.updateAllocationWarning();
            },

            validateAllocation(invoiceId, value, balanceDue) {
                const amount = parseFloat(value) || 0;
                if (amount > balanceDue + 0.01) { // Adding a small tolerance for floating point issues
                    this.allocations[invoiceId] = balanceDue.toFixed(2);
                    alert(`Amount cannot exceed balance due of ₹${balanceDue.toFixed(2)}.`);
                }
                this.updateTotalAllocated();
            },

            updateAllocationWarning() {
                const difference = Math.abs(this.totalReceived - this.totalAllocated);
                
                if (this.totalReceived === 0) {
                     this.allocationWarning = 'Enter Total Amount Received first.';
                } else if (difference > 0.01) {
                    if (this.totalAllocated > this.totalReceived) {
                        this.allocationWarning = `Over-allocated by: ₹${(this.totalAllocated - this.totalReceived).toFixed(2)}`;
                    } else {
                        this.allocationWarning = `Under-allocated by: ₹${(this.totalReceived - this.totalAllocated).toFixed(2)}`;
                    }
                } else {
                    this.allocationWarning = '';
                }
            },

            submitForm(event) {
                this.updateTotalAllocated(); 
                if (this.allocationWarning !== '') {
                    alert('Cannot reconcile: Allocation amount must exactly match Total Amount Received.');
                    return;
                }
                event.target.submit();
            }
        }));
    });

    $(document).ready(function() {
        $('.searchable-select').select2({ width: '100%' });
    });
</script>
</body>
</html>
