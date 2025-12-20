<?php
// --- START: ADDED ANTI-CACHING HEADERS ---
// These headers command the browser to always fetch a fresh copy of the page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
// --- END: ADDED ANTI-CACHING HEADERS ---

session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
if ($user_role !== 'admin') {
    header("location: dashboard.php");
    exit;
}

// Display messages based on the status from the URL
$form_message = "";
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $form_message = '<div class="p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center"><i class="fas fa-check-circle mr-2"></i> Company details updated successfully!</div>';
    } elseif ($_GET['status'] == 'error') {
        $form_message = '<div class="p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Error updating details.</div>';
    }
}

// Helper function for logo upload
function upload_logo($file_input_name) {
    $target_dir = "uploads/company/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        $file_name = "logo." . strtolower(pathinfo($_FILES[$file_input_name]["name"], PATHINFO_EXTENSION));
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file)) {
            return $target_file;
        }
    }
    return null;
}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $sql = "UPDATE company_details SET name=?, slogan=?, address=?, gst_no=?, fssai_no=?, pan_no=?, email=?, website=?, contact_number_1=?, contact_number_2=? WHERE id=1";
    
    if($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ssssssssss", 
            $_POST['name'], $_POST['slogan'], $_POST['address'], $_POST['gst_no'], $_POST['fssai_no'], 
            $_POST['pan_no'], $_POST['email'], $_POST['website'], $_POST['contact_number_1'], $_POST['contact_number_2']
        );

        if ($stmt->execute()) {
            $logo_path = upload_logo('logo');
            if ($logo_path) {
                $logo_stmt = $mysqli->prepare("UPDATE company_details SET logo_path = ? WHERE id = 1");
                $logo_stmt->bind_param("s", $logo_path);
                $logo_stmt->execute();
                $logo_stmt->close();
            }
            
            // --- START: ADDED TIMESTAMP TO REDIRECT ---
            // This makes the URL unique, forcing the browser to reload
            $timestamp = time();
            header("Location: manage_company.php?status=success&t=" . $timestamp);
            exit;
            // --- END: ADDED TIMESTAMP TO REDIRECT ---

        } else {
            header("Location: manage_company.php?status=error");
            exit;
        }
        $stmt->close();
    }
}

// Fetch current company details
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Company - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 h-screen w-screen overflow-hidden">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
        <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
    </div>

    <div class="flex h-full w-full bg-gray-50">
        
        <div class="md:hidden fixed inset-0 z-40 hidden transition-opacity duration-300 bg-gray-900 bg-opacity-50" id="sidebar-overlay"></div>
        
        <div id="sidebar-wrapper" class="z-50 md:relative md:z-0 md:block hidden h-full shadow-xl">
             <?php include 'sidebar.php'; ?>
        </div>

        <div class="flex flex-col flex-1 h-full overflow-hidden relative">
            <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden"></div>
            
            <header class="bg-gradient-to-r from-indigo-800 to-blue-700 shadow-lg z-10 flex-shrink-0 text-white">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <div class="flex items-center gap-3">
                            <button id="sidebar-toggle" class="text-indigo-100 hover:text-white md:hidden focus:outline-none p-2 rounded-md hover:bg-white/10 transition">
                                <i class="fas fa-bars fa-lg"></i>
                            </button>
                            <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                                <i class="fas fa-building opacity-80"></i> Company Settings
                            </h1>
                        </div>
                        <div class="flex items-center gap-4">
                             <span class="text-indigo-100 text-sm hidden md:inline-block bg-white/10 px-3 py-1 rounded-full border border-white/10">
                                <i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?>
                             </span>
                            <a href="logout.php" class="text-indigo-200 hover:text-white hover:bg-white/10 p-2 rounded-full transition-colors" title="Logout">
                                <i class="fas fa-sign-out-alt fa-lg"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <main class="flex-1 overflow-y-auto overflow-x-hidden bg-gray-50 p-4 md:p-8 space-y-6">
                <?php if(!empty($form_message)) echo $form_message; ?>
                
                <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden max-w-5xl mx-auto">
                    <div class="bg-indigo-50/50 px-8 py-5 border-b border-indigo-100 flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-bold text-indigo-900 flex items-center gap-2">
                                <i class="fas fa-info-circle text-indigo-500"></i> Organization Details
                            </h2>
                            <p class="text-xs text-gray-500 mt-1">Manage your company branding and contact information.</p>
                        </div>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="p-8 space-y-8">
                        
                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Brand Identity</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Company Name</label><input type="text" name="name" value="<?php echo htmlspecialchars($company_details['name'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Slogan / Tagline</label><input type="text" name="slogan" value="<?php echo htmlspecialchars($company_details['slogan'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div class="md:col-span-2"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Registered Address</label><textarea name="address" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"><?php echo htmlspecialchars($company_details['address'] ?? ''); ?></textarea></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Contact Information</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Primary Contact</label><input type="text" name="contact_number_1" value="<?php echo htmlspecialchars($company_details['contact_number_1'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Secondary Contact</label><input type="text" name="contact_number_2" value="<?php echo htmlspecialchars($company_details['contact_number_2'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email Address</label><input type="email" name="email" value="<?php echo htmlspecialchars($company_details['email'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Website URL</label><input type="text" name="website" value="<?php echo htmlspecialchars($company_details['website'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                            </div>
                        </fieldset>

                        <fieldset class="border border-gray-200 p-5 rounded-xl bg-gray-50/30">
                            <legend class="text-sm font-bold text-indigo-600 px-2 bg-gray-50 rounded">Legal & Tax Details</legend>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">GST Number</label><input type="text" name="gst_no" value="<?php echo htmlspecialchars($company_details['gst_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">PAN Number</label><input type="text" name="pan_no" value="<?php echo htmlspecialchars($company_details['pan_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                                <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">FSSAI License</label><input type="text" name="fssai_no" value="<?php echo htmlspecialchars($company_details['fssai_no'] ?? ''); ?>" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"></div>
                            </div>
                        </fieldset>

                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex items-center justify-between">
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Company Logo</label>
                                <input type="file" name="logo" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                <p class="text-xs text-gray-400 mt-1">Recommended size: 200x200px (PNG/JPG)</p>
                            </div>
                            <?php if (!empty($company_details['logo_path'])): ?>
                                <div class="ml-4 border p-1 bg-white rounded shadow-sm">
                                    <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Current Logo" class="h-16 w-16 object-contain">
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex justify-end pt-6 border-t border-gray-100">
                            <button type="submit" class="inline-flex items-center px-8 py-3 bg-indigo-600 border border-transparent rounded-xl font-bold text-sm text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl transition transform hover:-translate-y-0.5">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>
    <script>
    // --- Mobile Sidebar Toggle Logic ---
    document.addEventListener('DOMContentLoaded', () => {
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('close-sidebar-btn');

        function toggleSidebar() {
            if (sidebarWrapper.classList.contains('hidden')) {
                sidebarWrapper.classList.remove('hidden');
                sidebarWrapper.classList.add('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.remove('hidden');
            } else {
                sidebarWrapper.classList.add('hidden');
                sidebarWrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
                sidebarOverlay.classList.add('hidden');
            }
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
    });

    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) { loader.style.display = 'none'; }
    };
    </script>
</body>
</html>