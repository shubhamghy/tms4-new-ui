<?php
session_start();
require_once "config.php";

// ===================================================================================
// --- SECTION 1: PAGE SETUP AND DATA FETCHING ---
// ===================================================================================

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$payslip_id = intval($_GET['id'] ?? 0);
if ($payslip_id === 0) {
    die("Invalid payslip ID provided.");
}

// --- Security and Data Fetching ---
$user_role = $_SESSION['role'] ?? null;
$branch_id = $_SESSION['branch_id'] ?? 0;
$payslip_data = null;

$sql = "SELECT 
            ps.*, 
            e.full_name, e.employee_code, e.designation, e.pan_no, e.bank_account_no,
            b.name as branch_name, b.address as branch_address,
            cd.name as company_name, cd.logo_path, cd.address as company_address,
            ss.basic_salary, ss.hra, ss.conveyance_allowance, ss.special_allowance, 
            ss.pf_employee_contribution, ss.esi_employee_contribution, ss.professional_tax, ss.tds
        FROM payslips ps
        JOIN employees e ON ps.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        LEFT JOIN salary_structures ss ON ss.id = (
            SELECT id FROM salary_structures 
            WHERE employee_id = e.id AND effective_date <= LAST_DAY(CONCAT(ps.month_year, '-01'))
            ORDER BY effective_date DESC 
            LIMIT 1
        )
        JOIN company_details cd ON cd.id = 1
        WHERE ps.id = ?";

// Add branch filter for non-admins
if ($user_role !== 'admin') {
    $sql .= " AND e.branch_id = ?";
}

$stmt = $mysqli->prepare($sql);

if ($user_role !== 'admin') {
    $stmt->bind_param("ii", $payslip_id, $branch_id);
} else {
    $stmt->bind_param("i", $payslip_id);
}

$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $payslip_data = $result->fetch_assoc();
} else {
    die("Payslip not found or you do not have permission to view it.");
}
$stmt->close();

// Helper function to convert number to Indian currency words
function numberToWords($number) {
    // ... [Function omitted for brevity, but included in the full code below]
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $hundred = null; $digits_1 = strlen($no); $i = 0; $str = array();
    $words = array('0' => '', '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five', '6' => 'six', '7' => 'seven', '8' => 'eight', '9' => 'nine', '10' => 'ten', '11' => 'eleven', '12' => 'twelve', '13' => 'thirteen', '14' => 'fourteen', '15' => 'fifteen', '16' => 'sixteen', '17' => 'seventeen', '18' => 'eighteen', '19' =>'nineteen', '20' => 'twenty', '30' => 'thirty', '40' => 'forty', '50' => 'fifty', '60' => 'sixty', '70' => 'seventy', '80' => 'eighty', '90' => 'ninety');
    $digits = array('', 'hundred', 'thousand', 'lakh', 'crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number] . " " . $digits[$counter] . $plural . " " . $hundred : $words[floor($number / 10) * 10] . " " . $words[$number % 10] . " " . $digits[$counter] . $plural . " " . $hundred;
        } else $str[] = null;
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ? "." . $words[$point / 10] . " " . $words[$point = $point % 10] : '';
    return 'Rupees ' . ucwords($result) . "Only";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip for <?php echo htmlspecialchars($payslip_data['full_name']); ?> - <?php echo date("F Y", strtotime($payslip_data['month_year'])); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { font-family: 'Inter', sans-serif; background-color: #e5e7eb; }
        .payslip-container { box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        @media print {
            body { background-color: #fff; }
            .no-print { display: none; }
            .payslip-container { box-shadow: none; border: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container mx-auto p-4 md:p-8">
        <div class="mb-6 text-center no-print">
            <a href="javascript:window.history.back()" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg">
                <i class="fas fa-arrow-left mr-2"></i> Back
            </a>
            <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg">
                <i class="fas fa-print mr-2"></i> Print Payslip
            </button>
        </div>
        
        <div class="payslip-container max-w-4xl mx-auto bg-white p-8 border border-gray-200 rounded-lg">
            <div class="flex justify-between items-center border-b pb-4">
                <div>
                    <?php if(!empty($payslip_data['logo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($payslip_data['logo_path']); ?>" alt="Company Logo" class="h-16">
                    <?php endif; ?>
                    <h1 class="text-2xl font-bold mt-2"><?php echo htmlspecialchars($payslip_data['company_name']); ?></h1>
                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($payslip_data['company_address']); ?></p>
                </div>
                <div class="text-right">
                    <h2 class="text-xl font-semibold">Payslip</h2>
                    <p class="text-sm text-gray-600">For the month of <?php echo date("F Y", strtotime($payslip_data['month_year'])); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 text-sm">
                <div><strong class="block text-gray-500">Employee Name</strong><p><?php echo htmlspecialchars($payslip_data['full_name']); ?></p></div>
                <div><strong class="block text-gray-500">Employee Code</strong><p><?php echo htmlspecialchars($payslip_data['employee_code']); ?></p></div>
                <div><strong class="block text-gray-500">Designation</strong><p><?php echo htmlspecialchars($payslip_data['designation']); ?></p></div>
                <div><strong class="block text-gray-500">Payable Days</strong><p><?php echo htmlspecialchars($payslip_data['payable_days']); ?></p></div>
                <div><strong class="block text-gray-500">PAN</strong><p><?php echo htmlspecialchars($payslip_data['pan_no']); ?></p></div>
                <div><strong class="block text-gray-500">Bank Account No.</strong><p><?php echo htmlspecialchars($payslip_data['bank_account_no']); ?></p></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-6">
                <div>
                    <h3 class="font-bold text-lg bg-gray-100 p-2 rounded-t-md">Earnings</h3>
                    <table class="min-w-full text-sm border">
                        <tbody class="divide-y">
                            <tr><td class="p-2">Basic Salary</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['basic_salary'], 2); ?></td></tr>
                            <tr><td class="p-2">House Rent Allowance (HRA)</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['hra'], 2); ?></td></tr>
                            <tr><td class="p-2">Conveyance Allowance</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['conveyance_allowance'], 2); ?></td></tr>
                            <tr><td class="p-2">Special Allowance</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['special_allowance'], 2); ?></td></tr>
                        </tbody>
                        <tfoot class="bg-gray-50 font-bold">
                            <tr><td class="p-2">Gross Earnings</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['gross_earnings'], 2); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
                <div>
                    <h3 class="font-bold text-lg bg-gray-100 p-2 rounded-t-md">Deductions</h3>
                    <table class="min-w-full text-sm border">
                        <tbody class="divide-y">
                            <tr><td class="p-2">Provident Fund (PF)</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['pf_employee_contribution'], 2); ?></td></tr>
                            <tr><td class="p-2">Employee State Insurance (ESI)</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['esi_employee_contribution'], 2); ?></td></tr>
                            <tr><td class="p-2">Professional Tax</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['professional_tax'], 2); ?></td></tr>
                            <tr><td class="p-2">Income Tax (TDS)</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['tds'], 2); ?></td></tr>
                        </tbody>
                        <tfoot class="bg-gray-50 font-bold">
                            <tr><td class="p-2">Total Deductions</td><td class="p-2 text-right">₹<?php echo number_format($payslip_data['total_deductions'], 2); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="mt-6 bg-indigo-50 p-4 rounded-lg text-center">
                <p class="font-bold text-xl">Net Salary Paid: 
                    <span class="text-indigo-600">₹<?php echo number_format($payslip_data['net_salary'], 2); ?></span>
                </p>
                <p class="text-sm font-semibold text-gray-700 mt-1">
                    (<?php echo htmlspecialchars(numberToWords($payslip_data['net_salary'])); ?>)
                </p>
            </div>

            <div class="mt-12 text-center text-xs text-gray-500">
                <p>This is a computer-generated payslip and does not require a signature.</p>
            </div>
        </div>
    </div>
</body>
</html>