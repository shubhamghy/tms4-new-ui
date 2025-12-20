<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_manage = in_array($user_role, ['admin', 'manager']);

if (!$can_manage) {
    header("location: dashboard.php");
    exit;
}


// --- Validation Checks ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: manage_invoices.php");
    exit;
}

if (!isset($_POST['shipment_ids']) || !is_array($_POST['shipment_ids']) || count($_POST['shipment_ids']) === 0) {
    header("location: manage_invoices.php?error=" . urlencode("Please select at least one shipment to include in the invoice."));
    exit;
}

if (!isset($_POST['consignor_id']) || intval($_POST['consignor_id']) === 0) {
    header("location: manage_invoices.php?error=" . urlencode("A consignor must be selected to generate an invoice. Please filter first."));
    exit;
}

if (!isset($_POST['invoice_no']) || empty(trim($_POST['invoice_no']))) {
    header("location: manage_invoices.php?error=" . urlencode("Invoice number cannot be empty."));
    exit;
}

// --- All checks passed, proceed with invoice generation ---

$shipment_ids = $_POST['shipment_ids'];
$consignor_id = intval($_POST['consignor_id']);
$invoice_no = trim($_POST['invoice_no']);
$from_date = $_POST['date_from'] ?? null;
$to_date = $_POST['date_to'] ?? null;
$created_by_id = $_SESSION['id'];
$invoice_date = date('Y-m-d');
$total_amount = 0;

// Check for duplicate invoice number
$check_sql = "SELECT id FROM invoices WHERE invoice_no = ?";
if ($check_stmt = $mysqli->prepare($check_sql)) {
    $check_stmt->bind_param("s", $invoice_no);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        header("location: manage_invoices.php?error=" . urlencode("This Invoice Number is already in use."));
        exit;
    }
    $check_stmt->close();
}

$mysqli->begin_transaction();

try {
    // Calculate total amount from selected shipments
    $id_placeholders = implode(',', array_fill(0, count($shipment_ids), '?'));
    $sql_amount = "SELECT SUM(sp.amount) as total FROM shipment_payments sp WHERE sp.payment_type = 'Billing Rate' AND sp.shipment_id IN ($id_placeholders)";
    $stmt_amount = $mysqli->prepare($sql_amount);
    $stmt_amount->bind_param(str_repeat('i', count($shipment_ids)), ...$shipment_ids);
    $stmt_amount->execute();
    $result_amount = $stmt_amount->get_result();
    $total_amount = $result_amount->fetch_assoc()['total'];
    $stmt_amount->close();

    if ($total_amount > 0) {
        // Insert into invoices table
        $sql_invoice = "INSERT INTO invoices (invoice_no, invoice_date, from_date, to_date, consignor_id, total_amount, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_invoice = $mysqli->prepare($sql_invoice);
        $stmt_invoice->bind_param("ssssidi", $invoice_no, $invoice_date, $from_date, $to_date, $consignor_id, $total_amount, $created_by_id);
        if (!$stmt_invoice->execute()) { throw new Exception("Error creating invoice record."); }
        $invoice_id = $stmt_invoice->insert_id;
        $stmt_invoice->close();

        // Insert into invoice_items table
        $sql_items = "INSERT INTO invoice_items (invoice_id, shipment_id) VALUES (?, ?)";
        $stmt_items = $mysqli->prepare($sql_items);
        foreach ($shipment_ids as $shipment_id) {
            $stmt_items->bind_param("ii", $invoice_id, $shipment_id);
            if (!$stmt_items->execute()) { throw new Exception("Error linking shipment to invoice."); }
        }
        $stmt_items->close();
    } else {
        throw new Exception("No billable amount found for selected shipments.");
    }

    $mysqli->commit();
    header("location: print_invoice.php?id=" . $invoice_id);
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    // Redirect back with an error message
    header("location: manage_invoices.php?error=" . urlencode($e->getMessage()));
    exit;
} 
?>
