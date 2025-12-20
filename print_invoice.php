<?php
session_start();
require_once "config.php";

// 1. --- Include DOMPDF ---
require_once __DIR__ . '/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// 2. --- Security and Validation ---
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
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id === 0) {
    die("Error: No invoice ID provided.");
}

$orientation = isset($_GET['orientation']) && $_GET['orientation'] === 'landscape' ? 'landscape' : 'portrait';

// 3. --- Data Fetching ---
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();

$invoice_sql = "SELECT i.*, p.name as consignor_name, p.address as consignor_address, p.gst_no as consignor_gst, s.name as place_of_supply
                FROM invoices i
                JOIN parties p ON i.consignor_id = p.id
                LEFT JOIN states s ON p.state = s.id
                WHERE i.id = ?";
$stmt_invoice = $mysqli->prepare($invoice_sql);
$stmt_invoice->bind_param("i", $invoice_id);
$stmt_invoice->execute();
$invoice = $stmt_invoice->get_result()->fetch_assoc();
$stmt_invoice->close();
if (!$invoice) {
    die("Error: Invoice not found.");
}

$branch_details = [];
$branch_id_to_fetch = $invoice['branch_id'] ?? 1;
$stmt_branch = $mysqli->prepare("SELECT bank_ac_name, bank_ac_no, bank_name, bank_ifsc FROM branches WHERE id = ?");
$stmt_branch->bind_param("i", $branch_id_to_fetch);
$stmt_branch->execute();
$branch_details = $stmt_branch->get_result()->fetch_assoc();
$stmt_branch->close();

$items_sql = "SELECT
                s.consignment_no, s.consignment_date, s.destination, s.quantity,
                s.net_weight, s.net_weight_unit,
                v.vehicle_number, sp.amount as freight_amount, sp.billing_method,
                sp.rate,
                si.commercial_invoice_no,
                det.detention_amount,
                det.detention_remarks
              FROM invoice_items ii
              JOIN shipments s ON ii.shipment_id = s.id
              LEFT JOIN vehicles v ON s.vehicle_id = v.id
              LEFT JOIN shipment_payments sp ON s.id = sp.shipment_id AND sp.payment_type = 'Billing Rate'
              LEFT JOIN (
                  SELECT shipment_id, GROUP_CONCAT(invoice_no SEPARATOR ', ') as commercial_invoice_no 
                  FROM shipment_invoices GROUP BY shipment_id
              ) si ON s.id = si.shipment_id
              LEFT JOIN (
                  SELECT shipment_id, amount as detention_amount, remarks as detention_remarks
                  FROM shipment_payments
                  WHERE payment_type = 'Detention'
              ) det ON s.id = det.shipment_id
              WHERE ii.invoice_id = ?
              ORDER BY s.consignment_date ASC, s.id ASC";
              
$stmt_items = $mysqli->prepare($items_sql);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$invoice_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();

// 4. --- Helper Function (Amount in Words) ---
function getAmountInWords(float $number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $hundred = null;
    $digits_length = strlen($no);
    $i = 0;
    $str = array();
    $words = array(0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty', 30 => 'thirty', 40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety');
    $digits = array('', 'hundred', 'thousand', 'lakh', 'crore');
    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += $divider == 10 ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
        } else $str[] = null;
    }
    $Rupees = implode('', array_reverse($str));
    $paise = ($decimal > 0) ? " and " . ($words[floor($decimal / 10) * 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
    return ($Rupees ? 'Rupees ' . ucwords($Rupees) : '') . $paise . " Only";
}

// 5. --- Pre-computation for Conditional Columns and Totals ---
$hasDetention = false;
$totalDetention = 0;
$grandTotal = $invoice['total_amount']; 

foreach ($invoice_items as $item) {
    if (isset($item['detention_amount']) && $item['detention_amount'] > 0) {
        $hasDetention = true;
        $totalDetention += $item['detention_amount'];
    }
}

if ($hasDetention) {
    $grandTotal += $totalDetention;
}

$colCount = $hasDetention ? 12 : 11;
$totalLabelColspan = $hasDetention ? 10 : 10;
$grandTotalLabelColspan = 11;
$footerRowColspan = $hasDetention ? 12 : 11;

$total_in_words = getAmountInWords($grandTotal);

// 6. --- Start HTML Output Buffer ---
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice - <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
    <style>
        @page { margin: 40px 40px 140px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #333; font-size: 9px; }
        .footer { position: fixed; width: 100%; bottom: -120px; left: 0px; right: 0px; font-size: 10px; }
        .page-number:before { content: "Page " counter(page); }
        .main-table { width: 100%; border-collapse: collapse; }
        .main-table th, .main-table td { padding: 4px; border: 1px solid #777; text-align: left; vertical-align: top; }
        .main-table thead { display: table-header-group; }
        .main-table thead th { background-color: #EAEAEA; font-weight: bold; font-size: 9px; text-transform: uppercase; }
        .no-border, .no-border td, .no-border th { border: none; padding: 0; vertical-align: top; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .w-50 { width: 50%; }
        .w-100 { width: 100%; }
        .line-height-1-4 { line-height: 1.4; }
        .line-height-1-5 { line-height: 1.5; }
        .no-wrap { white-space: nowrap; }

        /* --- DYNAMIC COLUMN WIDTHS --- */
        <?php if ($hasDetention): ?>
            .col-sno { width: 3%; } .col-lr { width: 8%; } .col-date { width: 6%; }
            .col-vehicle { width: 8%; } .col-dest { width: 10%; } .col-inv { width: 14%; }
            .col-qty { width: 7%; } .col-weight { width: 8%; } .col-rate { width: 11%; }
            .col-freight { width: 10%; } .col-detention { width: 8%; } .col-remarks { width: 7%; }
        <?php else: ?>
            .col-sno { width: 3%; } .col-lr { width: 8%; } .col-date { width: 7%; }
            .col-vehicle { width: 9%; } .col-dest { width: 12%; } .col-inv { width: 15%; }
            .col-qty { width: 8%; } .col-weight { width: 10%; } .col-rate { width: 10%; }
            .col-freight { width: 10%; } .col-remarks { width: 8%; }
        <?php endif; ?>
    </style>
</head>
<body>

    <div class="footer">
        <table class="w-100 no-border" style="border-top: 1px solid #777; padding-top: 5px;">
            <tr>
                <td style="vertical-align: bottom;">
                    <strong>Bank Details:</strong><br>
                    A/c Name: <?php echo htmlspecialchars($branch_details['bank_ac_name'] ?? $company_details['name']); ?>, A/c No: <?php echo htmlspecialchars($branch_details['bank_ac_no'] ?? 'N/A'); ?><br>
                    IFSC: <?php echo htmlspecialchars($branch_details['bank_ifsc'] ?? 'N/A'); ?>, Bank: <?php echo htmlspecialchars($branch_details['bank_name'] ?? 'N/A'); ?>
                </td>
                <td class="text-right" style="vertical-align: bottom;">
                    For <strong><?php echo htmlspecialchars($company_details['name']); ?></strong><br><br><br>
                    (Authorised Signatory)
                </td>
            </tr>
        </table>
        <div class="page-number" style="text-align: center; margin-top: 5px;"></div>
    </div>

    <table class="main-table">
        
        <thead>
            <tr>
                <th colspan="<?php echo $colCount; ?>" class="no-border">
                    <h2 style="text-align:center; padding: 2px; border: 1px solid #333; background-color: #EAEAEA; text-transform:uppercase; margin-bottom: 5px; font-size:14px;">Tax Invoice</h2>
        
                    <table class="w-100 no-border">
                        <tr>
                            <td class="w-50" style="padding-right: 10px; height: 80px;">
                                <?php if (!empty($company_details['logo_path']) && file_exists($company_details['logo_path'])):
                                    $logo_base64 = 'data:image/' . pathinfo($company_details['logo_path'], PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($company_details['logo_path']));
                                ?>
                                    <img src="<?php echo $logo_base64; ?>" style="width: 120px; height: auto;">
                                <?php else: ?>
                                    <h3 style="margin:0; font-size:14px;"><?php echo htmlspecialchars($company_details['name']); ?></h3>
                                <?php endif; ?>
                            </td>
                            <td class="w-50 text-right line-height-1-4" style="padding-left: 10px; height: 80px; font-size: 11px;">
                                <strong style="font-size: 16px;"><?php echo htmlspecialchars($company_details['name']); ?></strong><br>
                                <?php echo htmlspecialchars($company_details['address']); ?><br>
                                <strong>GSTIN:</strong> <?php echo htmlspecialchars($company_details['gst_no']); ?><br>
                                <strong>Email:</strong> <?php echo htmlspecialchars($company_details['email']); ?><br>
                                <strong>Contact:</strong> <?php echo htmlspecialchars($company_details['contact_number_1']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #777; padding: 5px; padding-right: 10px;">
                                <strong>Bill To:</strong><br>
                                <strong style="font-size: 12px;"><?php echo htmlspecialchars($invoice['consignor_name']); ?></strong><br>
                                <?php echo nl2br(htmlspecialchars($invoice['consignor_address'])); ?><br>
                                <strong>GSTIN:</strong> <?php echo htmlspecialchars($invoice['consignor_gst']); ?>
                            </td>
                            <td style="border: 1px solid #777; padding: 5px; padding-left: 10px; line-height: 1.5;">
                                <strong>Invoice No.:</strong> &nbsp; <?php echo htmlspecialchars($invoice['invoice_no']); ?><br>
                                <strong>Invoice Date:</strong> &nbsp; <?php echo date("d-M-Y", strtotime($invoice['invoice_date'])); ?><br>
                                <strong>Date Range:</strong> &nbsp; <?php echo date("d-M-Y", strtotime($invoice['from_date'])) . ' to ' . date("d-M-Y", strtotime($invoice['to_date'])); ?><br>
                                <strong>Place of Supply:</strong> &nbsp; <?php echo htmlspecialchars($invoice['place_of_supply']); ?>
                            </td>
                        </tr>
                    </table>
                    <div style="height: 10px;"></div>
                </th>
            </tr>
            <tr>
                <th class="text-center col-sno">S.No</th>
                <th class="col-lr">LR No.</th>
                <th class="col-date">Date</th>
                <th class="col-vehicle">Vehicle No.</th>
                <th class="col-dest">Destination</th>
                <th class="col-inv">Invoice(s)</th>
                <th class="col-qty">Qty</th>
                <th class="col-weight">Weight</th>
                <th class="text-right col-rate">Rate</th>
                <th class="text-right col-freight">Freight</th>
                <?php if ($hasDetention): ?>
                <th class="text-right col-detention">Detention</th>
                <?php endif; ?>
                <th class="col-remarks">Remarks</th>
            </tr>
        </thead>
        
        <tbody>
            <?php
            $sno = 1;
            foreach ($invoice_items as $item):
            ?>
            <tr style="page-break-inside: avoid;">
                <td class="text-center"><?php echo $sno++; ?></td>
                <td><?php echo htmlspecialchars($item['consignment_no']); ?></td>
                <td><?php echo date("d-m-y", strtotime($item['consignment_date'])); ?></td>
                <td><?php echo htmlspecialchars($item['vehicle_number']); ?></td>
                <td><?php echo htmlspecialchars($item['destination']); ?></td>
                <td style="font-size: 8px; line-height: 1.2;">
                    <?php echo htmlspecialchars($item['commercial_invoice_no'] ?? ''); ?>
                </td>
                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                <td class="no-wrap">
                    <?php echo htmlspecialchars($item['net_weight']) . ' ' . htmlspecialchars($item['net_weight_unit']); ?>
                </td>
                <td class="text-right no-wrap">
                    <?php
                        if (strtolower($item['billing_method']) == 'fixed') {
                            echo '&#8377; ' . number_format($item['rate'], 2) . ' / Fixed';
                        } else if ($item['billing_method']) {
                            echo '&#8377; ' . number_format($item['rate'], 2) . ' / ' . htmlspecialchars(ucfirst($item['billing_method']));
                        } else {
                            echo '&#8377; ' . number_format($item['rate'], 2);
                        }
                    ?>
                </td>
                <td class="text-right no-wrap">&#8377; <?php echo number_format($item['freight_amount'], 2); ?></td>
                
                <?php if ($hasDetention): ?>
                <td class="text-right no-wrap">
                    <?php echo (isset($item['detention_amount']) && $item['detention_amount'] > 0) ? '&#8377; ' . number_format($item['detention_amount'], 2) : ''; ?>
                </td>
                <?php endif; ?>
                
                <td style="font-size: 8px;">
                    <?php echo htmlspecialchars($item['detention_remarks'] ?? ''); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if ($hasDetention): ?>
                <tr>
                    <td colspan="<?php echo $totalLabelColspan; ?>" class="text-right font-bold">FREIGHT TOTAL</td>
                    <td class="text-right font-bold no-wrap">&#8377; <?php echo htmlspecialchars(number_format($invoice['total_amount'], 2)); ?></td>
                    <td class="text-right font-bold no-wrap" colspan="2">&#8377; <?php echo htmlspecialchars(number_format($totalDetention, 2)); ?></td>
                </tr>
                <tr>
                    <td colspan="<?php echo $grandTotalLabelColspan; ?>" class="text-right font-bold">GRAND TOTAL</td>
                    <td class="text-right font-bold no-wrap">&#8377; <?php echo htmlspecialchars(number_format($grandTotal, 2)); ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="<?php echo $totalLabelColspan; ?>" class="text-right font-bold">TOTAL</td>
                    <td class="text-right font-bold no-wrap">&#8377; <?php echo htmlspecialchars(number_format($grandTotal, 2)); ?></td>
                </tr>
            <?php endif; ?>
            
            <tr>
                <td colspan="<?php echo $footerRowColspan; ?>">
                    <span class="font-bold">Amount in Words:</span> <?php echo htmlspecialchars($total_in_words); ?>
                </td>
            </tr>
            <tr>
                <td colspan="<?php echo $footerRowColspan; ?>" style="font-size: 10px; border-top: 2px solid #555;">
                    <strong style="font-size: 11px;">Terms & Conditions:</strong>
                    <ol style="margin: 5px 0 0 15px; padding: 0;">
                        <li>Please make payment by Cheque/NEFT in favour of 'STC LOGISTICS'.</li>
                        <li>All disputes are subject to Durgapur jurisdiction only.</li>
                        <li>Interest @24% p.a. will be charged if the bill is not paid within the due date.</li>
                    </ol>
                </td>
            </tr>
        </tbody>
    </table>

</body>
</html>

<?php
// 7. --- Generate PDF ---
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', $orientation);
$dompdf->render();

$filename = "Invoice-" . preg_replace('/[^A-Za-z0-9\-]/', '', $invoice['invoice_no']) . ".pdf";
$dompdf->stream($filename, ["Attachment" => 0]);
?>