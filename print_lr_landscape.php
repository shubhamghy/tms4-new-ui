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

// Fetch all shipment data
$sql = "SELECT s.*, 
        consignor.name AS consignor_name, consignor.address AS consignor_address,
        consignee.name AS consignee_name, consignee.address AS consignee_billing_address,
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
$main_invoice = $invoices[0] ?? ['invoice_date' => '', 'eway_bill_no' => '', 'eway_bill_expiry' => ''];
$total_invoice_value = array_sum(array_column($invoices, 'invoice_amount'));

// Determine shipping details AFTER fetching shipment data
$display_shipping_name = $shipment['is_shipping_different'] ? $shipment['shipping_name'] : $shipment['consignee_name'];
$display_shipping_address = $shipment['is_shipping_different'] ? $shipment['shipping_address'] : $shipment['consignee_billing_address'];


function render_lr_copy($copy_title, $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address) {
    $main_invoice = $invoices[0] ?? ['invoice_date' => '', 'eway_bill_no' => '', 'eway_bill_expiry' => ''];
    $total_invoice_value = array_sum(array_column($invoices, 'invoice_amount'));
    
    // --- FIX FOR "Array" bug in remarks ---
    $remarks_text = $shipment['pod_remarks'] ?? '';
    if (is_array($remarks_text)) {
        $remarks_text = implode(', ', $remarks_text);
    }
    
    ?>
    <div class="lr-copy bg-white p-4 border-2 border-black mb-8 shadow-lg font-sans text-xs">
        
        <div class="watermark-container">
            <?php if(!empty($company_details['logo_path']) && file_exists($company_details['logo_path'])): ?>
                <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Watermark">
            <?php endif; ?>
        </div>

        <header class="text-center border-b-4 border-black pb-2">
            <p class="text-sm font-semibold">All Disputes and Suits are Subject to Durgapur Jurisdiction only</p>
            
            <div class="relative flex justify-center items-center">
                <div class="absolute left-7 ">
                    <?php if(!empty($company_details['logo_path']) && file_exists($company_details['logo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Company Logo" class="h-20 w-auto">
                    <?php endif; ?>
                </div>
                <div class="text-center">
                    <h1 class="text-3xl font-bold text-blue-800"><?php echo htmlspecialchars($company_details['name']); ?></h1>
                    <p class="font-semibold">FLEET OWNERS & TRANSPORT CONTRACTORS</p>
                    <p class="text-[10px]">Regd. Office: <?php echo htmlspecialchars($company_details['address']); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-6 gap-x-6 text-[10px] mt-1 px-1">
                <span><strong>GSTIN:</strong> <?php echo htmlspecialchars($company_details['gst_no']); ?></span>
                <span><strong>PAN:</strong> <?php echo htmlspecialchars($company_details['pan_no']); ?></span>
                <span><strong>FSSAI:</strong> <?php echo htmlspecialchars($company_details['fssai_no']); ?></span>
                <span><strong>Email:</strong> <?php echo htmlspecialchars($company_details['email']); ?></span>
                <span><strong>Web:</strong> <?php echo htmlspecialchars($company_details['website']); ?></span>
                <span><strong>Contact:</strong> <?php echo htmlspecialchars($company_details['contact_number_1']); ?></span>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="grid grid-cols-12 gap-px mt-1">
                <div class="col-span-8 pr-1">
                    <div class="border border-black">
                        <div class="grid <?php echo $shipment['is_shipping_different'] ? 'grid-cols-3' : 'grid-cols-2'; ?> divide-x divide-black">
                            <div class="p-1">
                                <p class="font-bold">Consignor's Name & Address</p>
                                <p><?php echo htmlspecialchars($shipment['consignor_name']); ?></p>
                                <p><?php echo nl2br(htmlspecialchars($shipment['consignor_address'])); ?></p>
                            </div>
                            <div class="p-1">
                                <p class="font-bold">Consignee's Name & Address (Billing)</p>
                                <p><?php echo htmlspecialchars($shipment['consignee_name']); ?></p>
                                <p><?php echo nl2br(htmlspecialchars($shipment['consignee_billing_address'])); ?></p>
                            </div>
                            <?php if ($shipment['is_shipping_different']): ?>
                            <div class="p-1 bg-yellow-50">
                                <p class="font-bold">Shipping Name & Address</p>
                                <p><?php echo htmlspecialchars($display_shipping_name); ?></p>
                                <p><?php echo nl2br(htmlspecialchars($display_shipping_address)); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="border border-black mt-1">
                        <div class="grid grid-cols-2 divide-x divide-black">
                            <div class="p-1"><strong class="font-bold">From:</strong> <?php echo htmlspecialchars($shipment['origin']); ?></div>
                            <div class="p-1"><strong class="font-bold">To:</strong> <?php echo htmlspecialchars($shipment['destination']); ?></div>
                        </div>
                    </div>
                    <div class="border border-black mt-1">
                        <div class="grid grid-cols-2 divide-x divide-black">
                            <div class="p-1">
                                <p class="font-bold">Insurance Details:</p>
                                <p>The customer has stated that: he has not insured the consignment OR he has insured the consignment.</p>
                                <p>Company................................................................................................................</p>
                                <p>Policy Number...........................................................................................................</p>
                                <p>Amount........................................................................................................................</p>
                                <p>Validity..........................................................................................................................</p>
                            </div>
                            <div class="p-1">
                                <p class="font-bold">Driver's Details:</p>
                                <p><strong class="font-bold">Name:</strong> <?php echo htmlspecialchars($shipment['driver_name']); ?></p>
                                <p><strong class="font-bold">DL No.:</strong> <?php echo htmlspecialchars($shipment['driver_license']); ?></p>
                                <p><strong class="font-bold">Contact No.:</strong> <?php echo htmlspecialchars($shipment['driver_contact']); ?></p>
                                <p><strong class="font-bold">Address:</strong> <?php echo htmlspecialchars($shipment['driver_address']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-span-4 pl-1">
                    <div class="border-4 border-black p-1 text-center">
                        <p>AT OWNER'S RISK</p>
                        <p class="font-bold">CONSIGNMENT NOTE</p>
                        <p class="font-bold text-red-600 text-xl tracking-wider"><?php echo htmlspecialchars($shipment['consignment_no']); ?></p>
                    </div>
                    <div class="qr-code mx-auto my-2 w-20 h-20" data-cn="<?php echo htmlspecialchars($shipment['consignment_no']); ?>"></div>
                    <div class="border border-black text-center">
                        <div class="grid grid-cols-2 divide-x divide-black">
                            <div class="p-1"><strong class="font-bold">Date:</strong><br><?php echo date("d-m-Y", strtotime($shipment['consignment_date'])); ?></div>
                            <div class="p-1"><strong class="font-bold">Vehicle No:</strong><br><?php echo htmlspecialchars($shipment['vehicle_number']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="border border-black mt-1">
                <div class="grid grid-cols-2 divide-x divide-black">
                    <div class="p-1">
                        <table class="w-full border-black border-collapse">
                            <thead class="font-bold">
                                <tr class="text-left">
                                    <th class="border border-black p-1">Invoice No.</th>
                                    <th class="border border-black p-1">Invoice Date</th>
                                    <th class="border border-black p-1">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($invoices as $inv): ?>
                                <tr>
                                    <td class="border border-black p-1"><?php echo htmlspecialchars($inv['invoice_no']); ?></td>
                                    <td class="border border-black p-1"><?php echo $inv['invoice_date'] ? date("d-m-Y", strtotime($inv['invoice_date'])) : ''; ?></td>
                                    <td class="border border-black p-1"><?php echo number_format($inv['invoice_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-1">
                        <table class="w-full border border-black border-collapse">
                            <thead class="font-bold">
                                <tr class="text-left">
                                    <th class="border border-black p-1">E-Way Bill No.</th>
                                    <th class="border border-black p-1">Valid Upto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($invoices as $inv): ?>
                                <tr>
                                    <td class="border border-black p-1"><?php echo htmlspecialchars($inv['eway_bill_no']); ?></td>
                                    <td class="border border-black p-1"><?php echo $inv['eway_bill_expiry'] ? date("d-m-Y", strtotime($inv['eway_bill_expiry'])) : ''; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <table class="w-full mt-1 border-collapse border border-black text-center">
                <thead>
                    <tr class="font-bold"><td class="border border-black p-1">Quantity</td><td class="border border-black p-1">Description (Said to Contain)</td><td class="border border-black p-1">Actual Weight</td><td class="border border-black p-1">Chargeable Weight</td><td class="border border-black p-1">Rate</td><td class="border border-black p-1">Invoice Value</td><td class="border border-black p-1">Paid / To Pay / To be Billed At</td></tr>
                </thead>
                <tbody>
                    <tr class="h-12 align-top"><td class="border border-black p-1"><?php echo htmlspecialchars($shipment['quantity']); ?> <?php echo htmlspecialchars($shipment['package_type']); ?></td><td class="border border-black p-1"><?php echo htmlspecialchars($shipment['description_text']); ?></td><td class="border border-black p-1"><?php echo htmlspecialchars($shipment['net_weight'].' '.$shipment['net_weight_unit']); ?></td><td class="border border-black p-1"><?php echo htmlspecialchars($shipment['chargeable_weight'].' '.$shipment['chargeable_weight_unit']); ?></td><td class="border border-black p-1">FIXED</td><td class="border border-black p-1">&#8377;<?php echo number_format($total_invoice_value, 2); ?></td><td class="border border-black p-1"><?php echo htmlspecialchars($shipment['billing_type']); ?></td></tr>
                </tbody>
            </table>
            
            <div class="border border-black mt-1">
                <div class="grid grid-cols-2 divide-x divide-black">
                    <div class="p-1 border border-black">
                        <p class="font-bold">Declaration:</p>
                        <p>I, Driver of above said vehicle do hereby declare that the material of this consignment are fully and acurately described in attached LR / Invoice copy. If any other/undescribed content found with the material,then all responsibility will be on Driver of the vehicle.</p>
                    </div>
                    <div class="p-1 h-20 border border-black"><p class="font-bold">Receiver's Seal & Signature:</p></div>
                    
                </div>
            </div>
            
            <footer class="text-[9px] mt-1">
                <p><strong>CAUTION:</strong> This consignment will not be detained, diverted, re-routed or rebooked without Consignee Bank's written Permission. The consignment covered under this special Lorry Receipt shall, upon arrival at the destination, remain in the custody and control of the Transport Operator and shall be released only to the consignee, or to such person or entity as may be duly authorized by the consignee. In no event shall delivery be effected to any third party without the presentation of a valid written authority, either duly endorsed upon the consignee’s copy of the Lorry Receipt or furnished through a separate letter of authorization executed by the consignee.</p>
                <div class="flex justify-between items-end mt-2">
                    <p><strong>GST PAYABLE BY:</strong> CONSIGNOR</p>
                    <div class="text-center">
                        <br>
                        <br>
                        <p class="border-t border-black font-semibold">FOR <?php echo htmlspecialchars($company_details['name']); ?></p>
                    </div>
                </div>
                <p class="text-center font-bold mt-2"><?php echo $copy_title; ?> Copy / Generated by: <?php echo htmlspecialchars($_SESSION["username"]); ?> on <?php echo date("d-m-Y h:i A"); ?></p>
            </footer>
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
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        body { background-color: #e5e7eb; font-family: Arial, sans-serif; }
        
        /* --- MODIFIED SCREEN STYLES (for html2canvas) --- */
        .lr-copy { 
            width: 290mm; /* Slightly less than A4 landscape to prevent cut-off */
            height: auto; /* Let content define height */
            min-height: 200mm;
            margin-left: auto; 
            margin-right: auto;
            position: relative;
            padding: 5mm; /* Add padding to create a safe area */
            box-sizing: border-box; /* Include padding in width/height */
        }

        .watermark-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 0;
        }
        .watermark-container img {
            opacity: 0.1;
            width: 50%;
            height: auto;
            max-height: 80%;
            object-fit: contain;
        }
        .content-wrapper {
            position: relative;
            z-index: 1;
        }
        
        @page {
            size: A4 landscape;
            margin: 0;
        }

        @media print {
            html, body {
                width: 100%;
                height: 100%;
                margin: 0 !important;
                padding: 0 !important;
                background-color: #fff;
            }

            .no-print { display: none; }

            .lr-copy {
                box-sizing: border-box; 
                width: 287mm; /* Width with padding for print */
                margin: 0 auto;
                height: 200mm; /* Fixed height for printing */
                padding: 5mm;
                border: none;
                box-shadow: none;
                page-break-after: always;
                overflow: hidden; 
            }

            .lr-copy:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body class="p-2 md-p-8">
    <div class="max-w-7xl mx-auto">
        <div class="text-center mb-8 no-print">
            <div class="bg-white p-4 rounded-lg shadow-md">
                <h1 class="text-2xl font-bold">Lorry Receipt Preview (Landscape)</h1>
                <div class="mt-4 flex flex-wrap justify-center gap-4">
                    <button onclick="window.print()" class="bg-indigo-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-indigo-700"><i class="fas fa-print mr-2"></i> Print All</button>
                    <button id="download-pdf-btn" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-green-700"><i class="fas fa-file-pdf mr-2"></i> Download PDF</button>
                    <a href="booking.php" class="bg-gray-200 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-300"><i class="fas fa-plus-circle mr-2"></i> New Booking</a>
                </div>
            </div>
        </div>
        <div id="pdf-container">
            <?php
                render_lr_copy("Consignee", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
                render_lr_copy("Consignor", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
                render_lr_copy("Driver", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
                render_lr_copy("Office", $shipment, $invoices, $company_details, $display_shipping_name, $display_shipping_address);
            ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.qr-code').forEach(el => {
                new QRCode(el, { text: `https://test.stclogistics.in/track.php?cn=${el.dataset.cn}`, width: 80, height: 80 });
            });
            
            document.getElementById('download-pdf-btn').addEventListener('click', function () {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                const pdfContainer = document.getElementById('pdf-container');
                const lrCopies = pdfContainer.querySelectorAll('.lr-copy');
                
                let promises = Array.from(lrCopies).map(copy => html2canvas(copy, { scale: 2, useCORS: true }));

                Promise.all(promises).then((canvases) => {
                    canvases.forEach((canvas, index) => {
                        if (index > 0) {
                            pdf.addPage();
                        }
                        const imgData = canvas.toDataURL('image/png');
                        const pdfWidth = 297; // A4 landscape width
                        const pdfHeight = 210; // A4 landscape height
                        
                        // --- MODIFIED: Add image with a 5mm margin ---
                        const margin = 5;
                        const imgWidth = pdfWidth - (margin * 2);
                        const imgHeight = (canvas.height * imgWidth) / canvas.width;
                        const yPos = (pdfHeight - imgHeight) / 2; // Center vertically
                        
                        pdf.addImage(imgData, 'PNG', margin, yPos, imgWidth, imgHeight);
                    });
                    pdf.save(`LR-<?php echo htmlspecialchars($shipment['consignment_no']); ?>.pdf`);
                });
            });
        });
    </script>
</body>
</html>