<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    exit("Access Denied.");
}

$party_id = isset($_GET['party_id']) ? intval($_GET['party_id']) : 0;

if ($party_id === 0) {
    exit("No party selected.");
}

// Fetch party details for filename
$party_stmt = $mysqli->prepare("SELECT name FROM parties WHERE id = ?");
$party_stmt->bind_param("i", $party_id);
$party_stmt->execute();
$party_details = $party_stmt->get_result()->fetch_assoc();
$party_name = preg_replace('/[^A-Za-z0-9\-]/', '', $party_details['name']); // Sanitize name for filename
$party_stmt->close();


// --- Fetch and process transaction data (same logic as accounts_ledger.php) ---
$transactions = [];

// Fetch Invoices (Debits)
$invoice_sql = "SELECT id, invoice_no, invoice_date AS date, total_amount FROM invoices WHERE consignor_id = ? ORDER BY invoice_date ASC";
$invoice_stmt = $mysqli->prepare($invoice_sql);
$invoice_stmt->bind_param("i", $party_id);
$invoice_stmt->execute();
$invoices = $invoice_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$invoice_stmt->close();

foreach ($invoices as $invoice) {
    $transactions[] = [
        'date' => $invoice['date'],
        'particulars' => 'Invoice Generated',
        'invoice_no' => $invoice['invoice_no'],
        'debit' => $invoice['total_amount'],
        'credit' => 0
    ];
}

// Fetch Payments (Credits)
$payment_sql = "SELECT p.payment_date AS date, p.amount_received, p.payment_mode, p.reference_no, i.invoice_no 
                FROM invoice_payments p 
                JOIN invoices i ON p.invoice_id = i.id 
                WHERE i.consignor_id = ? ORDER BY p.payment_date ASC";
$payment_stmt = $mysqli->prepare($payment_sql);
$payment_stmt->bind_param("i", $party_id);
$payment_stmt->execute();
$payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$payment_stmt->close();

foreach ($payments as $payment) {
    $transactions[] = [
        'date' => $payment['date'],
        'particulars' => 'Payment Received (' . $payment['payment_mode'] . ($payment['reference_no'] ? ' - ' . $payment['reference_no'] : '') . ')',
        'invoice_no' => $payment['invoice_no'],
        'debit' => 0,
        'credit' => $payment['amount_received']
    ];
}

// Sort all transactions by date
if (!empty($transactions)) {
    usort($transactions, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}

// --- Generate CSV ---
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="ledger-'.$party_name.'-'.date('Y-m-d').'.csv"');

$output = fopen('php://output', 'w');

// Add header row
fputcsv($output, ['Date', 'Particulars', 'Invoice No.', 'Debit', 'Credit', 'Balance']);

// Add data rows
$balance = 0;
foreach ($transactions as $t) {
    $balance = $balance + $t['debit'] - $t['credit'];
    fputcsv($output, [
        date('d-m-Y', strtotime($t['date'])),
        $t['particulars'],
        $t['invoice_no'],
        $t['debit'] > 0 ? $t['debit'] : '',
        $t['credit'] > 0 ? $t['credit'] : '',
        number_format(abs($balance), 2) . ($balance >= 0 ? ' Dr' : ' Cr')
    ]);
}

fclose($output);
exit();
?>