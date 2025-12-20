<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$shipment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($shipment_id === 0) { die("Error: No shipment ID provided."); }

// Fetch Company Details
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();

// Fetch main shipment data, including broker and full driver details
$sql = "SELECT s.*, 
        consignor.name AS consignor_name, consignor.address AS consignor_address, consignor.gst_no AS consignor_gst,
        consignee.name AS consignee_name, consignee.address AS consignee_billing_address, consignee.gst_no AS consignee_gst,
        b.name AS broker_name,
        d.name AS driver_name, d.license_number AS driver_license, d.contact_number as driver_contact, d.address as driver_address,
        v.vehicle_number, v.owner_name as vehicle_owner,
        descrip.description AS description_text
        FROM shipments s
        LEFT JOIN parties consignor ON s.consignor_id = consignor.id
        LEFT JOIN parties consignee ON s.consignee_id = consignee.id
        LEFT JOIN brokers b ON s.broker_id = b.id
        LEFT JOIN drivers d ON s.driver_id = d.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN consignment_descriptions descrip ON s.description_id = descrip.id
        WHERE s.id = ?";

$shipment = null;
if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $shipment = $result->fetch_assoc();
    $stmt->close();
}

if ($shipment === null) { die("Error: Shipment not found."); }

$display_shipping_name = $shipment['is_shipping_different'] ? $shipment['shipping_name'] : $shipment['consignee_name'];
$display_shipping_address = $shipment['is_shipping_different'] ? $shipment['shipping_address'] : $shipment['consignee_billing_address'];

// Fetch invoice data
$invoices = [];
$sql_invoices = "SELECT * FROM shipment_invoices WHERE shipment_id = ?";
if ($stmt_invoices = $mysqli->prepare($sql_invoices)) {
    $stmt_invoices->bind_param("i", $shipment_id);
    $stmt_invoices->execute();
    $result_invoices = $stmt_invoices->get_result();
    $invoices = $result_invoices->fetch_all(MYSQLI_ASSOC);
    $stmt_invoices->close();
}

function render_lr_copy_B($copy_title, $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address) {
    $total_invoice_value = array_sum(array_column($invoices, 'invoice_amount'));
    ?>
    <div class="lr-copy bg-white p-4 border-2 border-black mb-8 shadow-lg font-serif">
        <div class="text-center border-b-2 border-black pb-2">
            <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($company_details['name']); ?></h1>
            <p class="text-xs"><?php echo htmlspecialchars($company_details['address']); ?></p>
            <p class="text-xs font-semibold">GSTIN: <?php echo htmlspecialchars($company_details['gst_no']); ?></p>
        </div>
        <div class="flex justify-between items-center text-sm font-bold my-1">
            <p>CONSIGNMENT NOTE</p>
            <p><?php echo $copy_title; ?> COPY</p>
        </div>
        <div class="grid grid-cols-5 border-t-2 border-b-2 border-black text-xs">
            <div class="border-r border-black p-1"><strong>LR No:</strong><br><?php echo htmlspecialchars($shipment['consignment_no']); ?></div>
            <div class="border-r border-black p-1"><strong>Date:</strong><br><?php echo date("d-m-Y", strtotime($shipment['consignment_date'])); ?></div>
            <div class="border-r border-black p-1"><strong>From:</strong><br><?php echo htmlspecialchars($shipment['origin']); ?></div>
            <div class="border-r border-black p-1"><strong>To:</strong><br><?php echo htmlspecialchars($shipment['destination']); ?></div>
            <div class="p-1"><strong>Vehicle:</strong><br><?php echo htmlspecialchars($shipment['vehicle_number']); ?></div>
        </div>
        <div class="grid grid-cols-2 gap-px text-xs mt-1">
            <div class="border-2 border-black p-2">
                <h3 class="font-bold underline">CONSIGNOR</h3>
                <p class="font-semibold"><?php echo htmlspecialchars($shipment['consignor_name']); ?></p>
                <p><?php echo nl2br(htmlspecialchars($shipment['consignor_address'])); ?></p>
            </div>
            <div class="border-2 border-black p-2">
                <h3 class="font-bold underline">CONSIGNEE</h3>
                <p class="font-semibold"><?php echo htmlspecialchars($display_shipping_name); ?></p>
                <p><?php echo nl2br(htmlspecialchars($display_shipping_address)); ?></p>
            </div>
        </div>
        <table class="w-full mt-1 text-xs border-collapse border-2 border-black">
            <thead>
                <tr class="text-center font-bold"><td class="border-r border-black p-1">Qty</td><td class="border-r border-black p-1">Package</td><td class="border-r border-black p-1 w-1/2">Description</td><td class="border-r border-black p-1">Actual Wt.</td><td class="p-1">Charged Wt.</td></tr>
            </thead>
            <tbody>
                <tr class="text-center h-16 align-top">
                    <td class="border-r border-t border-black p-1"><?php echo htmlspecialchars($shipment['quantity']); ?></td>
                    <td class="border-r border-t border-black p-1"><?php echo htmlspecialchars($shipment['package_type']); ?></td>
                    <td class="border-r border-t border-black p-1 text-left"><?php echo htmlspecialchars($shipment['description_text']); ?></td>
                    <td class="border-r border-t border-black p-1"><?php echo htmlspecialchars($shipment['net_weight'] . ' ' . $shipment['net_weight_unit']); ?></td>
                    <td class="border-t border-black p-1"><?php echo htmlspecialchars($shipment['chargeable_weight'] . ' ' . $shipment['chargeable_weight_unit']); ?></td>
                </tr>
                 <tr class="font-bold"><td class="border-t-2 border-black p-1" colspan="5">Payment Type: <?php echo htmlspecialchars($shipment['billing_type']); ?></td></tr>
            </tbody>
        </table>
        
        <div class="mt-1 text-xs border-2 border-black p-1">
            <h3 class="font-bold underline text-center mb-1">INVOICE & E-WAY BILL DETAILS</h3>
            <table class="w-full text-left">
                <thead><tr class="font-semibold"><td class="py-0">INVOICE NO</td><td class="py-0">DATE</td><td class="py-0">VALUE</td><td class="py-0">E-WAY BILL NO</td><td class="py-0">VALID UPTO</td></tr></thead>
                <tbody>
                    <?php foreach($invoices as $invoice): ?>
                    <tr><td><?php echo htmlspecialchars($invoice['invoice_no']); ?></td><td><?php echo date("d-m-Y", strtotime($invoice['invoice_date'])); ?></td><td><?php echo htmlspecialchars(number_format($invoice['invoice_amount'], 2)); ?></td><td><?php echo htmlspecialchars($invoice['eway_bill_no']); ?></td><td><?php echo date("d-m-Y", strtotime($invoice['eway_bill_expiry'])); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="font-bold border-t border-black"><td class="pt-1" colspan="2">TOTAL VALUE</td><td class="pt-1" colspan="3">Rs. <?php echo htmlspecialchars(number_format($total_invoice_value, 2)); ?>/-</td></tr></tfoot>
            </table>
        </div>

        <div class="grid grid-cols-2 gap-px text-xs mt-1">
            <div class="border-2 border-black p-2">
                <h3 class="font-bold underline">DRIVER DETAILS</h3>
                <p><strong>Driver:</strong> <?php echo htmlspecialchars($shipment['driver_name']); ?></p>
                <p><strong>DL No:</strong> <?php echo htmlspecialchars($shipment['driver_license']); ?></p>
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($shipment['driver_contact']); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($shipment['driver_address']); ?></p>
                <p><strong>Broker:</strong> <?php echo htmlspecialchars($shipment['broker_name']); ?></p>
            </div>
            <div class="border-2 border-black p-2 flex flex-col justify-center items-center">
                <div class="qr-code" data-cn="<?php echo htmlspecialchars($shipment['consignment_no']); ?>"></div>
                <p class="text-xs mt-1">Scan to Track</p>
            </div>
        </div>

        <div class="border-2 border-black p-2 my-1 text-xs">
            <h3 class="font-bold text-center">DRIVER'S DECLARATION</h3>
            <p class="text-center italic">I hereby declare that the contents of this consignment are fully and accurately described in the attached invoice/e-way bill.</p>
        </div>
        <div class="text-xs mt-1 border-2 border-black p-2">
            <p><strong>CONSIGNMENT CAUTION:</strong> This consignment shall be stored at the destination under our control and shall be delivered to the consignee. It will under no circumstance be delivered to any other party without a separate letter of authority.</p>
        </div>

        <div class="flex justify-between items-end mt-12 text-xs">
            <div class="w-1/3 text-center"><p class="border-t border-gray-400 pt-1">Shipper's Signature</p></div>
            <div class="w-1/3 text-center"><p class="border-t border-gray-400 pt-1">For <?php echo htmlspecialchars($company_details['name']); ?></p></div>
            <div class="w-1/3 text-center"><p class="border-t border-gray-400 pt-1">Receiver's Signature</p></div>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print LR - <?php echo htmlspecialchars($shipment['consignment_no']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Slab:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        body { background-color: #e5e7eb; }
        .font-serif { font-family: 'Roboto Slab', serif; }
        .lr-copy { width: 210mm; min-height: 290mm; margin-left: auto; margin-right: auto; }
        @page { size: A4; margin: 0; }
        @media print {
            body { background-color: #fff; font-family: 'Roboto Slab', serif; }
            .no-print { display: none; }
            .lr-copy { border: none; box-shadow: none; margin: 0; padding: 10mm; page-break-after: always; }
            .lr-copy:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body class="p-2 md:p-8">
    <div class="max-w-4xl mx-auto">
        <div class="text-center mb-8 no-print">
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h1 class="text-2xl font-bold">Lorry Receipt Preview</h1>
                <p class="text-gray-600">LR No: <?php echo htmlspecialchars($shipment['consignment_no']); ?></p>
                <div class="mt-4 flex flex-wrap justify-center gap-4">
                    <button onclick="window.print()" class="bg-indigo-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-indigo-700"><i class="fas fa-print mr-2"></i> Print All</button>
                    <button id="download-pdf-btn" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-green-700"><i class="fas fa-file-pdf mr-2"></i> Download PDF</button>
                    <a href="booking.php" class="bg-gray-200 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-300"><i class="fas fa-plus-circle mr-2"></i> New Booking</a>
                </div>
            </div>
        </div>
        <div id="pdf-container">
            <?php
                render_lr_copy_B("CONSIGNOR", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
                render_lr_copy_B("CONSIGNEE", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
                render_lr_copy_B("DRIVER", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
            ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.qr-code').forEach(el => {
                new QRCode(el, { text: `https://test.stclogistics.in/track.php?cn=${el.dataset.cn}`, width: 64, height: 64 });
            });
            
            document.getElementById('download-pdf-btn').addEventListener('click', function () {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const pdfContainer = document.getElementById('pdf-container');
                const lrCopies = pdfContainer.querySelectorAll('.lr-copy');
                const a4Width = 210;
                const a4Height = 297;

                let promises = [];
                lrCopies.forEach(copy => {
                    promises.push(html2canvas(copy, { scale: 2 }));
                });

                Promise.all(promises).then((canvases) => {
                    canvases.forEach((canvas, index) => {
                        if (index > 0) {
                            pdf.addPage();
                        }
                        const imgData = canvas.toDataURL('image/png');
                        const imgProps = pdf.getImageProperties(imgData);
                        const pdfWidth = a4Width;
                        const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
                        
                        let height = pdfHeight;
                        if(height > a4Height) height = a4Height;
                        
                        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, height);
                    });
                    pdf.save(`LR-<?php echo htmlspecialchars($shipment['consignment_no']); ?>.pdf`);
                });
            });
        });
    </script>
</body>
</html>