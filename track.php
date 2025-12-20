<?php
require_once "config.php";

// Fetch Company Details
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();

$consignment_no = isset($_GET['cn']) ? trim($_GET['cn']) : '';
$shipment_data = null;
$tracking_history = [];
$error_message = "";

if (!empty($consignment_no)) {
    // Fetch shipment details
    $sql = "SELECT s.id, s.consignment_no, s.status, s.origin, s.destination, s.consignment_date, p_consignor.name as consignor_name, p_consignee.name as consignee_name 
            FROM shipments s 
            JOIN parties p_consignor ON s.consignor_id = p_consignor.id
            JOIN parties p_consignee ON s.consignee_id = p_consignee.id
            WHERE s.consignment_no = ?";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $consignment_no);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $shipment_data = $result->fetch_assoc();
            
            // Fetch tracking history
            $shipment_id = $shipment_data['id'];
            $history_stmt = $mysqli->prepare("SELECT location, remarks, created_at FROM shipment_tracking WHERE shipment_id = ? ORDER BY created_at ASC");
            $history_stmt->bind_param("i", $shipment_id);
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();
            while($row = $history_result->fetch_assoc()){
                $tracking_history[] = $row;
            }
            $history_stmt->close();
        } else {
            $error_message = "No shipment found with that Consignment Number.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Consignment - <?php echo htmlspecialchars($company_details['name'] ?? 'TMS'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: 1.25rem;
            top: 1.25rem;
            bottom: -1.25rem;
            width: 2px;
            background-color: #e5e7eb; /* gray-200 */
            transform: translateX(-50%);
        }
        .timeline-item:last-child:before {
            display: none;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 md:p-8 max-w-4xl">
        <div class="text-center mb-8">
            <?php if(!empty($company_details['logo_path'])): ?>
                <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="<?php echo htmlspecialchars($company_details['name'] ?? ''); ?> Logo" class="h-24 mx-auto mb-4">
            <?php endif; ?>
            <h1 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($company_details['name'] ?? 'Track Your Shipment'); ?></h1>
            <p class="text-gray-600"><?php echo htmlspecialchars($company_details['slogan'] ?? 'Enter your Consignment Number (LR No.) to see the status of your shipment.'); ?></p>
        </div>

        <div class="bg-white p-8 rounded-lg shadow-md">
            <form method="GET" action="track.php" class="flex items-center">
                <input type="text" name="cn" placeholder="Enter Consignment Number" value="<?php echo htmlspecialchars($consignment_no); ?>" class="flex-grow px-4 py-3 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                <button type="submit" class="bg-indigo-600 text-white font-bold px-6 py-3 rounded-r-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"><i class="fas fa-search"></i></button>
            </form>
        </div>

        <?php if ($error_message): ?>
            <div class="mt-8 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md" role="alert">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($shipment_data): ?>
        <div class="mt-8 bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Shipment Details</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-6 text-sm">
                <div><strong class="block text-gray-500">Consignment No:</strong> <?php echo htmlspecialchars($shipment_data['consignment_no']); ?></div>
                <div><strong class="block text-gray-500">Status:</strong> <span class="font-semibold text-green-600"><?php echo htmlspecialchars($shipment_data['status']); ?></span></div>
                <div><strong class="block text-gray-500">Booked On:</strong> <?php echo date("d M, Y", strtotime($shipment_data['consignment_date'])); ?></div>
                <div><strong class="block text-gray-500">From:</strong> <?php echo htmlspecialchars($shipment_data['origin']); ?></div>
                <div><strong class="block text-gray-500">To:</strong> <?php echo htmlspecialchars($shipment_data['destination']); ?></div>
            </div>
            <div class="border-t my-6"></div>
            <h3 class="text-xl font-bold text-gray-800 mb-4">Tracking History</h3>
            <div class="relative pl-8">
                <!-- Booked Status -->
                <div class="timeline-item relative pb-8">
                    <div class="absolute left-0 top-0 h-10 w-10 bg-indigo-600 rounded-full flex items-center justify-center transform -translate-x-1/2">
                        <i class="fas fa-box-open text-white"></i>
                    </div>
                    <div class="ml-8">
                        <p class="font-semibold">Booked</p>
                        <p class="text-sm text-gray-600">Shipment has been booked on <?php echo date("d M, Y", strtotime($shipment_data['consignment_date'])); ?></p>
                    </div>
                </div>
                <!-- Dynamic History -->
                <?php foreach($tracking_history as $history): ?>
                <div class="timeline-item relative pb-8">
                    <div class="absolute left-0 top-0 h-10 w-10 bg-indigo-600 rounded-full flex items-center justify-center transform -translate-x-1/2">
                        <i class="fas fa-truck text-white"></i>
                    </div>
                    <div class="ml-8">
                        <p class="font-semibold"><?php echo htmlspecialchars($history['location']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($history['remarks']); ?></p>
                        <p class="text-xs text-gray-400 mt-1"><?php echo date("d M, Y h:i A", strtotime($history['created_at'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                 <!-- Delivered Status -->
                <?php if ($shipment_data['status'] === 'Delivered'): ?>
                <div class="relative">
                    <div class="absolute left-0 top-0 h-10 w-10 bg-green-600 rounded-full flex items-center justify-center transform -translate-x-1/2">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                    <div class="ml-8">
                        <p class="font-semibold">Delivered</p>
                        <p class="text-sm text-gray-600">Shipment has been successfully delivered.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php include 'footer.php'; ?>
    </div>
</body>
</html>
