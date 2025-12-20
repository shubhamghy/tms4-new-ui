<?php
// Initialize the session
session_start();
 
// Check if the user is already logged in
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}
 
require_once "config.php";

// Fetch Company Details (Added 'slogan' to the query)
$company_details = $mysqli->query("SELECT name, logo_path, slogan FROM company_details WHERE id = 1")->fetch_assoc();
 
$username = "";
$password = "";
$login_err = "";
 
if(isset($_COOKIE["remember_user"])) {
    $username = $_COOKIE["remember_user"];
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    if(empty(trim($_POST["username"]))){
        $login_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    if(empty(trim($_POST["password"]))){
        $login_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    if(empty($login_err)){
        $sql = "SELECT id, username, password, role, branch_id, photo_path, last_login FROM users WHERE username = ? AND is_active = 1";
        
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param("s", $param_username);
            $param_username = $username;
            
            if($stmt->execute()){
                $stmt->store_result();
                
                if($stmt->num_rows == 1){                    
                    $stmt->bind_result($id, $username, $hashed_password, $role, $branch_id, $photo_path, $last_login);
                    if($stmt->fetch()){
                        if(password_verify($password, $hashed_password)){
                            session_regenerate_id();
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role"] = $role;
                            $_SESSION["branch_id"] = $branch_id;
                            $_SESSION["photo_path"] = $photo_path;
                            $_SESSION["last_login"] = $last_login;

                            if(isset($_POST["remember"])) {
                                setcookie("remember_user", $username, time() + (86400 * 30), "/"); 
                            } else {
                                if(isset($_COOKIE["remember_user"])) {
                                    setcookie("remember_user", "", time() - 3600, "/");
                                }
                            }

                            $current_time = date("Y-m-d H:i:s");
                            $update_stmt = $mysqli->prepare("UPDATE users SET last_login = ? WHERE id = ?");
                            $update_stmt->bind_param("si", $current_time, $id);
                            $update_stmt->execute();
                            $update_stmt->close();
                            
                            header("location: dashboard.php");
                            exit;
                        } else{
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else{
                    $login_err = "Invalid username or password.";
                }
            } else{
                echo "Oops! Something went wrong.";
            }
            $stmt->close();
        }
    }
    $mysqli->close();
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - <?php echo htmlspecialchars($company_details['name'] ?? 'TMS'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(-45deg, #0f172a, #1e1b4b, #312e81, #0f172a);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            height: 100vh;
            overflow: hidden;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }
        .input-group:focus-within label {
            color: #818cf8;
        }
        .input-group:focus-within i {
            color: #818cf8;
        }
        .input-field {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        .input-field:focus {
            background: rgba(15, 23, 42, 0.8);
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        .custom-checkbox:checked {
            background-color: #6366f1;
            border-color: #6366f1;
        }
        .reveal-up {
            animation: revealUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        @keyframes revealUp {
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

    <div class="fixed top-0 left-0 w-full h-full overflow-hidden -z-10">
        <div class="absolute top-[-10%] left-[-10%] w-96 h-96 bg-indigo-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
        <div class="absolute top-[-10%] right-[-10%] w-96 h-96 bg-purple-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
        <div class="absolute bottom-[-10%] left-[20%] w-96 h-96 bg-pink-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
    </div>

    <div class="w-full max-w-[420px] glass-card rounded-3xl p-8 sm:p-10 reveal-up relative overflow-hidden">
        
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-indigo-500 to-transparent opacity-50"></div>

        <div class="text-center mb-8">
            <?php if(!empty($company_details['logo_path'])): ?>
                <div class="inline-flex p-3 rounded-2xl bg-white/5 border border-white/10 mb-4 shadow-lg backdrop-blur-sm">
                    <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Logo" class="h-12 w-auto object-contain">
                </div>
            <?php else: ?>
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 mb-4 shadow-lg shadow-indigo-500/30">
                    <i class="fas fa-truck-fast text-2xl text-white"></i>
                </div>
            <?php endif; ?>
            
            <h1 class="text-2xl font-bold text-white tracking-tight">
                <?php echo htmlspecialchars($company_details['name'] ?? 'TMS Pro'); ?>
            </h1>
            
            <?php if(!empty($company_details['slogan'])): ?>
                <p class="text-indigo-200 text-sm mt-1 font-medium italic opacity-90">
                    "<?php echo htmlspecialchars($company_details['slogan']); ?>"
                </p>
            <?php else: ?>
                <p class="text-slate-400 text-sm mt-2">Sign in to manage operations</p>
            <?php endif; ?>
        </div>

        <?php if(!empty($login_err)): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center gap-3 text-red-200 text-sm animate-pulse">
                <i class="fas fa-exclamation-circle text-red-400"></i>
                <span><?php echo $login_err; ?></span>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-5" id="loginForm">
            
            <div class="input-group">
                <label for="username" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Username</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-user text-slate-500 transition-colors"></i>
                    </div>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required
                           class="input-field block w-full pl-11 pr-4 py-3 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none"
                           placeholder="Enter your username">
                </div>
            </div>

            <div class="input-group">
                <label for="password" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-500 transition-colors"></i>
                    </div>
                    <input type="password" id="password" name="password" required
                           class="input-field block w-full pl-11 pr-11 py-3 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none"
                           placeholder="••••••••">
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-white transition-colors cursor-pointer focus:outline-none">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between mt-2">
                <label class="flex items-center cursor-pointer group">
                    <input type="checkbox" name="remember" class="custom-checkbox w-4 h-4 rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-offset-0 focus:ring-indigo-500 transition-colors" <?php if(!empty($username)) echo "checked"; ?>>
                    <span class="ml-2 text-sm text-slate-400 group-hover:text-slate-300 transition-colors">Remember me</span>
                </label>
            </div>

            <button type="submit" id="submitBtn" class="w-full py-3.5 px-4 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-bold rounded-xl shadow-lg shadow-indigo-600/30 transform hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 flex justify-center items-center gap-2">
                <span id="btnText">Sign In</span>
                <i class="fas fa-arrow-right text-sm" id="btnIcon"></i>
                <svg id="btnLoader" class="hidden animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </form>

        <div class="mt-8 text-center border-t border-white/10 pt-6">
            <p class="text-xs text-slate-500">
                &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($company_details['name'] ?? 'TMS'); ?>. <br>Secure Transport Management System.
            </p>
        </div>
    </div>

    <script>
        // Password Visibility Toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        const icon = togglePassword.querySelector('i');

        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });

        // Button Loading State
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');
        const btnLoader = document.getElementById('btnLoader');

        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            btnText.textContent = 'Signing in...';
            btnIcon.classList.add('hidden');
            btnLoader.classList.remove('hidden');
            submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
        });
    </script>
    
    <style>
        /* Custom Blob Animation */
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        .animate-blob {
            animation: blob 7s infinite;
        }
        .animation-delay-2000 {
            animation-delay: 2s;
        }
        .animation-delay-4000 {
            animation-delay: 4s;
        }
    </style>
</body>
</html>