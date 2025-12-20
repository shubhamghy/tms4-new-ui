<?php
// Start the session and check if the user is logged in
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}
require_once "config.php";
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_manage = in_array($user_role, ['admin', 'manager']);

// --- Form Processing ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $type = $_POST['type'];
    $name = trim($_POST['name']);
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($type === 'country') {
        if ($id > 0) { // Update
            $stmt = $mysqli->prepare("UPDATE countries SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
        } else { // Insert
            $stmt = $mysqli->prepare("INSERT INTO countries (name) VALUES (?)");
            $stmt->bind_param("s", $name);
        }
    } elseif ($type === 'state') {
        $country_id = intval($_POST['country_id']);
        if ($id > 0) { // Update
            $stmt = $mysqli->prepare("UPDATE states SET name = ?, country_id = ? WHERE id = ?");
            $stmt->bind_param("sii", $name, $country_id, $id);
        } else { // Insert
            $stmt = $mysqli->prepare("INSERT INTO states (name, country_id) VALUES (?, ?)");
            $stmt->bind_param("si", $name, $country_id);
        }
    } elseif ($type === 'city') {
        $state_id = intval($_POST['state_id']);
        if ($id > 0) { // Update
            $stmt = $mysqli->prepare("UPDATE cities SET name = ?, state_id = ? WHERE id = ?");
            $stmt->bind_param("sii", $name, $state_id, $id);
        } else { // Insert
            $stmt = $mysqli->prepare("INSERT INTO cities (name, state_id) VALUES (?, ?)");
            $stmt->bind_param("si", $name, $state_id);
        }
    }

    if (isset($stmt) && $stmt->execute()) {
        $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-check-circle mr-2'></i> Location saved successfully.</div>";
    } else {
        $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error saving location.</div>";
    }
    if(isset($stmt)) $stmt->close();
}

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $can_manage) {
    $type = $_GET['type'];
    $id = intval($_GET['id']);
    $table = '';
    if($type === 'country') $table = 'countries';
    if($type === 'state') $table = 'states';
    if($type === 'city') $table = 'cities';

    if($table){
        $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()){
            $message = "<div class='p-4 mb-6 text-sm text-green-700 bg-green-100 border-l-4 border-green-500 rounded shadow-sm flex items-center'><i class='fas fa-trash-alt mr-2'></i> Location deleted successfully.</div>";
        } else {
            $message = "<div class='p-4 mb-6 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded shadow-sm flex items-center'><i class='fas fa-exclamation-circle mr-2'></i> Error deleting location. It might be in use.</div>";
        }
        $stmt->close();
    }
}


// --- Data Fetching ---
$countries = $mysqli->query("SELECT * FROM countries ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$states = $mysqli->query("SELECT s.id, s.name, c.name as country_name FROM states s JOIN countries c ON s.country_id = c.id ORDER BY c.name, s.name ASC")->fetch_all(MYSQLI_ASSOC);
$cities = $mysqli->query("SELECT ci.id, ci.name, s.name as state_name FROM cities ci JOIN states s ON ci.state_id = s.id ORDER BY s.name, ci.name ASC")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Locations - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 0.75rem; color: #374151; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; top: 1px; }
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
                                <i class="fas fa-map-marked-alt opacity-80"></i> Manage Locations
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
                <?php if(!empty($message)) echo $message; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden flex flex-col h-full">
                        <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100">
                            <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2"><i class="fas fa-globe-americas"></i> Countries</h2>
                        </div>
                        <div class="p-6 flex-1 flex flex-col">
                            <?php if($can_manage): ?>
                            <form method="POST" class="mb-6 space-y-3">
                                <input type="hidden" name="type" value="country">
                                <input type="text" name="name" placeholder="New Country Name" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required>
                                <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-lg text-sm font-bold text-white shadow-sm hover:bg-indigo-700 transition">
                                    <i class="fas fa-plus mr-2"></i> Add Country
                                </button>
                            </form>
                            <?php endif; ?>
                            
                            <div class="overflow-y-auto flex-1 max-h-[400px] pr-2 custom-scrollbar">
                                <ul class="divide-y divide-gray-100">
                                    <?php foreach($countries as $c): ?>
                                    <li class="py-3 flex justify-between items-center group hover:bg-gray-50 px-2 rounded-lg transition">
                                        <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($c['name']); ?></span>
                                        <?php if($can_manage): ?>
                                        <a href="?action=delete&type=country&id=<?php echo $c['id']; ?>" onclick="return confirm('Are you sure?')" class="text-gray-400 hover:text-red-600 transition p-1"><i class="fas fa-trash-alt"></i></a>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden flex flex-col h-full">
                        <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100">
                            <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2"><i class="fas fa-map"></i> States</h2>
                        </div>
                        <div class="p-6 flex-1 flex flex-col">
                            <?php if($can_manage): ?>
                            <form method="POST" class="mb-6 space-y-3">
                                <input type="hidden" name="type" value="state">
                                <select name="country_id" class="searchable-select block w-full" required>
                                    <option value="">Select Country</option>
                                    <?php foreach($countries as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="name" placeholder="New State Name" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required>
                                <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-lg text-sm font-bold text-white shadow-sm hover:bg-indigo-700 transition">
                                    <i class="fas fa-plus mr-2"></i> Add State
                                </button>
                            </form>
                            <?php endif; ?>

                            <div class="overflow-y-auto flex-1 max-h-[400px] pr-2 custom-scrollbar">
                                <ul class="divide-y divide-gray-100">
                                    <?php foreach($states as $s): ?>
                                    <li class="py-3 flex justify-between items-center group hover:bg-gray-50 px-2 rounded-lg transition">
                                        <div class="flex flex-col">
                                            <span class="text-gray-800 font-medium"><?php echo htmlspecialchars($s['name']); ?></span>
                                            <span class="text-xs text-gray-400"><?php echo htmlspecialchars($s['country_name']); ?></span>
                                        </div>
                                        <?php if($can_manage): ?>
                                        <a href="?action=delete&type=state&id=<?php echo $s['id']; ?>" onclick="return confirm('Are you sure?')" class="text-gray-400 hover:text-red-600 transition p-1"><i class="fas fa-trash-alt"></i></a>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden flex flex-col h-full">
                        <div class="bg-indigo-50/50 px-6 py-4 border-b border-indigo-100">
                            <h2 class="text-lg font-bold text-indigo-900 flex items-center gap-2"><i class="fas fa-city"></i> Cities</h2>
                        </div>
                        <div class="p-6 flex-1 flex flex-col">
                            <?php if($can_manage): ?>
                            <form method="POST" class="mb-6 space-y-3">
                                <input type="hidden" name="type" value="city">
                                <select name="state_id" class="searchable-select block w-full" required>
                                    <option value="">Select State</option>
                                    <?php foreach($states as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="name" placeholder="New City Name" class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" required>
                                <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-lg text-sm font-bold text-white shadow-sm hover:bg-indigo-700 transition">
                                    <i class="fas fa-plus mr-2"></i> Add City
                                </button>
                            </form>
                            <?php endif; ?>

                            <div class="overflow-y-auto flex-1 max-h-[400px] pr-2 custom-scrollbar">
                                <ul class="divide-y divide-gray-100">
                                    <?php foreach($cities as $ci): ?>
                                    <li class="py-3 flex justify-between items-center group hover:bg-gray-50 px-2 rounded-lg transition">
                                        <div class="flex flex-col">
                                            <span class="text-gray-800 font-medium"><?php echo htmlspecialchars($ci['name']); ?></span>
                                            <span class="text-xs text-gray-400"><?php echo htmlspecialchars($ci['state_name']); ?></span>
                                        </div>
                                        <?php if($can_manage): ?>
                                        <a href="?action=delete&type=city&id=<?php echo $ci['id']; ?>" onclick="return confirm('Are you sure?')" class="text-gray-400 hover:text-red-600 transition p-1"><i class="fas fa-trash-alt"></i></a>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        $('.searchable-select').select2({ width: '100%' });
        
        // --- Sidebar Toggle Logic ---
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

    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if(loader) loader.style.display = 'none';
    });
    </script>
</body>
</html>