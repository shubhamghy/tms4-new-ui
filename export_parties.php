<?php
// Start the session and check if the user is logged in
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// Include the database configuration file
require_once "config.php";

// Get the requested format
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// Fetch all parties data from the database
$parties_list = [];
$sql = "SELECT name, party_type, address, city, state, country, gst_no, pan_no, contact_number, contact_person, is_active FROM parties ORDER BY name ASC";
if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $parties_list[] = $row;
    }
    $result->free();
}

$filename = "parties_export_" . date('Y-m-d') . "." . $format;

switch ($format) {
    case 'csv':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        // Add header row
        fputcsv($output, array('Name', 'Party Type', 'Address', 'City', 'State', 'Country', 'GST No', 'PAN No', 'Contact Number', 'Contact Person', 'Status'));
        // Add data rows
        foreach ($parties_list as $party) {
            $status = $party['is_active'] ? 'Active' : 'Inactive';
            fputcsv($output, array($party['name'], $party['party_type'], $party['address'], $party['city'], $party['state'], $party['country'], $party['gst_no'], $party['pan_no'], $party['contact_number'], $party['contact_person'], $status));
        }
        fclose($output);
        break;

    case 'excel':
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo '<table border="1">';
        echo '<tr><th>Name</th><th>Party Type</th><th>Address</th><th>City</th><th>State</th><th>Country</th><th>GST No</th><th>PAN No</th><th>Contact Number</th><th>Contact Person</th><th>Status</th></tr>';
        foreach ($parties_list as $party) {
            $status = $party['is_active'] ? 'Active' : 'Inactive';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($party['name']) . '</td>';
            echo '<td>' . htmlspecialchars($party['party_type']) . '</td>';
            echo '<td>' . htmlspecialchars($party['address']) . '</td>';
            echo '<td>' . htmlspecialchars($party['city']) . '</td>';
            echo '<td>' . htmlspecialchars($party['state']) . '</td>';
            echo '<td>' . htmlspecialchars($party['country']) . '</td>';
            echo '<td>' . htmlspecialchars($party['gst_no']) . '</td>';
            echo '<td>' . htmlspecialchars($party['pan_no']) . '</td>';
            echo '<td>' . htmlspecialchars($party['contact_number']) . '</td>';
            echo '<td>' . htmlspecialchars($party['contact_person']) . '</td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        break;

    case 'pdf':
        // This generates a clean, printable HTML page. The user can then print to PDF from their browser.
        echo '<!DOCTYPE html><html lang="en"><head><title>Print Parties</title>';
        echo '<style>body{font-family: sans-serif;} table{width: 100%; border-collapse: collapse;} th, td{border: 1px solid #ddd; padding: 8px;} th{background-color: #f2f2f2;}</style>';
        echo '</head><body>';
        echo '<h1>Parties List</h1>';
        echo '<table>';
        echo '<thead><tr><th>Name</th><th>Type</th><th>Location</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        foreach ($parties_list as $party) {
             $status = $party['is_active'] ? 'Active' : 'Inactive';
             $location = htmlspecialchars($party['city'] . ', ' . $party['state']);
             echo '<tr>';
             echo '<td>' . htmlspecialchars($party['name']) . '</td>';
             echo '<td>' . htmlspecialchars($party['party_type']) . '</td>';
             echo '<td>' . $location . '</td>';
             echo '<td>' . $status . '</td>';
             echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<script>window.print();</script>'; // Automatically trigger print dialog
        echo '</body></html>';
        break;
}

$mysqli->close();
exit;
?>
