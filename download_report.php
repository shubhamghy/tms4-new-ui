<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    exit("Access Denied.");
}

$is_admin = $_SESSION['role'] === 'admin';

// --- Filter Handling (from GET parameters) ---
$filter_start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_branch_id = $is_admin ? ($_GET['branch_id'] ?? '') : $_SESSION['branch_id'];

// --- Data Fetching (same logic as reports.php) ---
$report_data = [];
$total_revenue = 0;
$total_expenses = 0;
$total_profit = 0;

$sql = "
    SELECT 
        s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, br.name as branch_name,
        COALESCE((SELECT sp.amount FROM shipment_payments sp WHERE sp.shipment_id = s.id AND sp.payment_type = 'Billing Rate'), 0) AS income,
        COALESCE((SELECT sp.amount FROM shipment_payments sp WHERE sp.shipment_id = s.id AND sp.payment_type = 'Lorry Hire'), 0) AS lorry_hire,
        COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.shipment_id = s.id), 0) AS other_expenses
    FROM shipments s
    LEFT JOIN branches br ON s.branch_id = br.id
";

$where_clauses = [];
$params = [];
$types = "";

$where_clauses[] = "s.consignment_date BETWEEN ? AND ?";
$params[] = $filter_start_date;
$params[] = $filter_end_date;
$types .= "ss";

if ($is_admin) {
    if (!empty($filter_branch_id)) {
        $where_clauses[] = "s.branch_id = ?";
        $params[] = $filter_branch_id;
        $types .= "i";
    }
} else {
    $where_clauses[] = "s.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
    $types .= "i";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY s.consignment_date DESC";

$stmt = $mysqli->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- Generate CSV ---
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="profit-loss-report-'.date('Y-m-d').'.csv"');

$output = fopen('php://output', 'w');

// Add header row
fputcsv($output, ['LR No', 'Date', 'Branch', 'Income', 'Lorry Hire', 'Other Expenses', 'Total Expenses', 'Profit/Loss']);

// Add data rows
while ($row = $result->fetch_assoc()) {
    $row['total_expenses'] = $row['lorry_hire'] + $row['other_expenses'];
    $row['profit_loss'] = $row['income'] - $row['total_expenses'];
    fputcsv($output, [
        $row['consignment_no'],
        date('d-m-Y', strtotime($row['consignment_date'])),
        $row['branch_name'],
        $row['income'],
        $row['lorry_hire'],
        $row['other_expenses'],
        $row['total_expenses'],
        $row['profit_loss']
    ]);
}
$stmt->close();
fclose($output);
exit();
?>