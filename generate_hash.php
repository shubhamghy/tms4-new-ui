<?php
// --- Password Hash Generator ---
// Use this script to generate a secure password hash for your users table.

// Set the password you want to hash
$passwordToHash = 'Stc@123';

// Generate the hash using PHP's recommended algorithm
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

// Display the result
echo "<h1>Password Hash Generator</h1>";
echo "<p><strong>Password:</strong> " . htmlspecialchars($passwordToHash) . "</p>";
echo "<p><strong>Generated Hash (copy this value):</strong></p>";
echo "<textarea rows='3' style='width: 100%; font-family: monospace; padding: 8px; border: 1px solid #ccc; border-radius: 4px;'>" . htmlspecialchars($hashedPassword) . "</textarea>";

?>
