<?php
// Prevent direct access
if (file_exists("config.php")) {
    require_once "config.php";
    $company_details = $mysqli->query("SELECT name, logo_path FROM company_details WHERE id = 1")->fetch_assoc();
}
?>
<aside id="sidebar" class="h-full w-64 bg-slate-900 text-slate-300 flex flex-col shadow-2xl border-r border-slate-800 transition-all duration-300 ease-in-out font-sans z-50">
    
    <div class="h-16 flex items-center px-6 bg-gradient-to-r from-indigo-900 to-slate-900 border-b border-indigo-500/30 relative overflow-hidden shrink-0">
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-indigo-500 to-blue-500"></div>
        
        <?php if(!empty($company_details['logo_path'])): ?>
            <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Logo" class="h-8 w-auto mr-3 object-contain drop-shadow-md">
        <?php else: ?>
            <div class="h-8 w-8 rounded-lg bg-indigo-600 flex items-center justify-center mr-3 shadow-lg shadow-indigo-500/50">
                <i class="fas fa-truck-fast text-white text-sm"></i>
            </div>
        <?php endif; ?>
        
        <span class="font-bold text-lg text-white tracking-wide truncate">
            <?php echo htmlspecialchars($company_details['name'] ?? 'TMS Pro'); ?>
        </span>

        <button id="close-sidebar-btn" class="md:hidden absolute right-4 text-slate-400 hover:text-white transition-colors">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="p-4 border-b border-slate-800/50 bg-slate-900/50">
        <div class="flex items-center gap-3 p-2 rounded-xl bg-slate-800/50 border border-slate-700/50 backdrop-blur-sm">
            <?php if(!empty($_SESSION['photo_path'])): ?>
                <img src="<?php echo htmlspecialchars($_SESSION['photo_path']); ?>" class="h-10 w-10 rounded-full object-cover border-2 border-slate-600">
            <?php else: ?>
                <div class="h-10 w-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center border-2 border-slate-700 shadow-inner">
                    <span class="text-white font-bold text-sm"><?php echo strtoupper(substr($_SESSION["username"] ?? 'U', 0, 1)); ?></span>
                </div>
            <?php endif; ?>
            
            <div class="overflow-hidden">
                <p class="text-sm font-bold text-white truncate"><?php echo htmlspecialchars($_SESSION["username"] ?? 'User'); ?></p>
                <p class="text-[10px] text-emerald-400 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Online
                </p>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 custom-scrollbar">
        <?php
            $current_page = basename($_SERVER['PHP_SELF']);
            $user_role = $_SESSION['role'] ?? '';
            $can_manage = in_array($user_role, ['admin', 'manager']);
            $is_admin = ($user_role === 'admin');
            
            // Define active groups
            $groups = [
                'billing' => ['manage_payments.php', 'manage_invoices.php', 'view_invoices.php', 'manage_reconciliation.php', 'unbilled_consignments.php', 'vehicle_settlements.php'],
                'accounting' => ['manage_expenses.php', 'accounts_ledger.php', 'reports.php', 'reports_ar_aging.php'],
                'hr' => ['manage_employees.php', 'manage_salary.php'],
                'fleet' => ['manage_fuel_logs.php', 'manage_maintenance.php', 'manage_tyres.php'],
                'manage' => ['manage_locations.php', 'manage_parties.php', 'manage_brokers.php', 'manage_drivers.php', 'manage_vehicles.php', 'manage_branches.php', 'manage_users.php', 'manage_company.php']
            ];

            // Helper to check active state
            function isActive($page, $current) {
                return $page === $current ? 'bg-gradient-to-r from-indigo-600 to-blue-600 text-white shadow-lg shadow-indigo-900/50 border-transparent' : 'text-slate-400 hover:bg-slate-800 hover:text-white border-transparent';
            }
            
            // Helper to output JS boolean
            function isGroupActivePHP($pages, $current) {
                return in_array($current, $pages) ? 'true' : 'false';
            }
        ?>

        <p class="px-3 text-[10px] uppercase font-bold text-slate-500 tracking-wider mb-2 mt-1">Main</p>
        
        <a href="dashboard.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 border-l-4 <?php echo isActive('dashboard.php', $current_page); ?>">
            <i class="fas fa-home w-6 text-center <?php echo $current_page == 'dashboard.php' ? 'text-white' : 'text-indigo-400'; ?>"></i> 
            <span class="ml-2">Dashboard</span>
        </a>
        
        <a href="booking.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 border-l-4 <?php echo isActive('booking.php', $current_page); ?>">
            <i class="fas fa-plus-circle w-6 text-center <?php echo $current_page == 'booking.php' ? 'text-white' : 'text-emerald-400'; ?>"></i> 
            <span class="ml-2">New Booking</span>
        </a>
        
        <a href="view_bookings.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 border-l-4 <?php echo isActive('view_bookings.php', $current_page); ?>">
            <i class="fas fa-list-alt w-6 text-center <?php echo $current_page == 'view_bookings.php' ? 'text-white' : 'text-blue-400'; ?>"></i> 
            <span class="ml-2">View Bookings</span>
        </a>

        <a href="update_tracking.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 border-l-4 <?php echo isActive('update_tracking.php', $current_page); ?>">
            <i class="fas fa-map-marked-alt w-6 text-center <?php echo $current_page == 'update_tracking.php' ? 'text-white' : 'text-orange-400'; ?>"></i> 
            <span class="ml-2">Tracking</span>
        </a>

        <a href="manage_pod.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 border-l-4 <?php echo isActive('manage_pod.php', $current_page); ?>">
            <i class="fas fa-file-signature w-6 text-center <?php echo $current_page == 'manage_pod.php' ? 'text-white' : 'text-pink-400'; ?>"></i> 
            <span class="ml-2">POD Manager</span>
        </a>

        <?php if ($can_manage): ?>
        
        <p class="px-3 text-[10px] uppercase font-bold text-slate-500 tracking-wider mb-2 mt-6">Operations</p>

        <div class="relative" 
             x-data="{ 
                 open: localStorage.getItem('menu_billing') === 'true' || <?php echo isGroupActivePHP($groups['billing'], $current_page); ?> 
             }" 
             x-init="$watch('open', val => localStorage.setItem('menu_billing', val)); if(<?php echo isGroupActivePHP($groups['billing'], $current_page); ?>) localStorage.setItem('menu_billing', 'true');">
            
            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors group">
                <div class="flex items-center">
                    <i class="fas fa-file-invoice-dollar w-6 text-center text-teal-400 group-hover:text-teal-300 transition-colors"></i>
                    <span class="ml-2">Billing & Finance</span>
                </div>
                <i class="fas fa-chevron-right text-xs transition-transform duration-200" :class="{'rotate-90': open}"></i>
            </button>
            <div x-show="open" x-collapse class="pl-9 pr-2 space-y-1 mt-1">
                <a href="manage_payments.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_payments.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Payments</a>
                <a href="manage_invoices.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_invoices.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Generate Invoice</a>
                <a href="view_invoices.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'view_invoices.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">View Invoices</a>
                <a href="manage_reconciliation.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_reconciliation.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Reconciliation</a> <a href="unbilled_consignments.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'unbilled_consignments.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Unbilled Report</a>
                <a href="vehicle_settlements.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'vehicle_settlements.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Vehicle Settlements</a>
            </div>
        </div>

        <div class="relative" 
             x-data="{ 
                 open: localStorage.getItem('menu_accounting') === 'true' || <?php echo isGroupActivePHP($groups['accounting'], $current_page); ?> 
             }" 
             x-init="$watch('open', val => localStorage.setItem('menu_accounting', val)); if(<?php echo isGroupActivePHP($groups['accounting'], $current_page); ?>) localStorage.setItem('menu_accounting', 'true');">
            
            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors group">
                <div class="flex items-center">
                    <i class="fas fa-calculator w-6 text-center text-rose-400 group-hover:text-rose-300 transition-colors"></i>
                    <span class="ml-2">Accounting</span>
                </div>
                <i class="fas fa-chevron-right text-xs transition-transform duration-200" :class="{'rotate-90': open}"></i>
            </button>
            <div x-show="open" x-collapse class="pl-9 pr-2 space-y-1 mt-1">
                <a href="manage_expenses.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_expenses.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Expenses</a>
                <a href="accounts_ledger.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'accounts_ledger.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Party Ledger</a>
                <a href="reports.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'reports.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">P&L Report</a>
                <a href="reports_ar_aging.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'reports_ar_aging.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">A/R Aging</a>
            </div>
        </div>

        <div class="relative" 
             x-data="{ 
                 open: localStorage.getItem('menu_hr') === 'true' || <?php echo isGroupActivePHP($groups['hr'], $current_page); ?> 
             }" 
             x-init="$watch('open', val => localStorage.setItem('menu_hr', val)); if(<?php echo isGroupActivePHP($groups['hr'], $current_page); ?>) localStorage.setItem('menu_hr', 'true');">
            
            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors group">
                <div class="flex items-center">
                    <i class="fas fa-users w-6 text-center text-cyan-400 group-hover:text-cyan-300 transition-colors"></i>
                    <span class="ml-2">HR & Payroll</span>
                </div>
                <i class="fas fa-chevron-right text-xs transition-transform duration-200" :class="{'rotate-90': open}"></i>
            </button>
            <div x-show="open" x-collapse class="pl-9 pr-2 space-y-1 mt-1">
                <a href="manage_employees.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_employees.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Employees</a> <a href="manage_salary.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_salary.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Salary & Payroll</a> </div>
        </div>

        <div class="relative" 
             x-data="{ 
                 open: localStorage.getItem('menu_fleet') === 'true' || <?php echo isGroupActivePHP($groups['fleet'], $current_page); ?> 
             }" 
             x-init="$watch('open', val => localStorage.setItem('menu_fleet', val)); if(<?php echo isGroupActivePHP($groups['fleet'], $current_page); ?>) localStorage.setItem('menu_fleet', 'true');">
            
            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors group">
                <div class="flex items-center">
                    <i class="fas fa-truck w-6 text-center text-amber-400 group-hover:text-amber-300 transition-colors"></i>
                    <span class="ml-2">Fleet Manager</span>
                </div>
                <i class="fas fa-chevron-right text-xs transition-transform duration-200" :class="{'rotate-90': open}"></i>
            </button>
            <div x-show="open" x-collapse class="pl-9 pr-2 space-y-1 mt-1">
                <a href="manage_fuel_logs.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_fuel_logs.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Fuel Logs</a>
                <a href="manage_maintenance.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_maintenance.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Maintenance</a>
                <a href="manage_tyres.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_tyres.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Tyres</a>
            </div>
        </div>

        <div class="relative" 
             x-data="{ 
                 open: localStorage.getItem('menu_manage') === 'true' || <?php echo isGroupActivePHP($groups['manage'], $current_page); ?> 
             }" 
             x-init="$watch('open', val => localStorage.setItem('menu_manage', val)); if(<?php echo isGroupActivePHP($groups['manage'], $current_page); ?>) localStorage.setItem('menu_manage', 'true');">
            
            <button @click="open = !open" class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors group">
                <div class="flex items-center">
                    <i class="fas fa-database w-6 text-center text-purple-400 group-hover:text-purple-300 transition-colors"></i>
                    <span class="ml-2">Master Data</span>
                </div>
                <i class="fas fa-chevron-right text-xs transition-transform duration-200" :class="{'rotate-90': open}"></i>
            </button>
            <div x-show="open" x-collapse class="pl-9 pr-2 space-y-1 mt-1">
                <a href="manage_locations.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_locations.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Locations</a>
                <a href="manage_parties.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_parties.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Parties</a>
                <a href="manage_drivers.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_drivers.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Drivers</a>
                <a href="manage_vehicles.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_vehicles.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Vehicles</a>
                <a href="manage_brokers.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_brokers.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Brokers</a>
                <?php if ($is_admin): ?>
                <a href="manage_branches.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_branches.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Branches</a>
                <a href="manage_users.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_users.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Users</a>
                <a href="manage_company.php" class="block px-3 py-2 text-xs rounded-md <?php echo $current_page == 'manage_company.php' ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">Company</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </nav>

    <div class="p-4 border-t border-slate-800 bg-slate-900">
        <a href="logout.php" class="flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-white bg-red-600/10 hover:bg-red-600 border border-red-600/30 rounded-lg transition-all duration-200 group">
            <i class="fas fa-sign-out-alt mr-2 group-hover:animate-pulse"></i> Logout
        </a>
    </div>
</aside>

<style>
    /* Custom Scrollbar for Sidebar */
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #0f172a; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
</style>

<script>
// Handle Mobile Sidebar Close
document.addEventListener('DOMContentLoaded', function() {
    const closeBtn = document.getElementById('close-sidebar-btn');
    if(closeBtn) {
        closeBtn.addEventListener('click', function() {
            const wrapper = document.getElementById('sidebar-wrapper');
            const overlay = document.getElementById('sidebar-overlay');
            if(wrapper) {
                wrapper.classList.add('hidden');
                wrapper.classList.remove('block', 'fixed', 'inset-y-0', 'left-0');
            }
            if(overlay) { overlay.classList.add('hidden'); }
        });
    }
});
</script>