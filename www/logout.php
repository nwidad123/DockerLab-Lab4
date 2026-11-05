<?php
require 'db.php';

if (isset($_SESSION['userID'])) {
    // Write logout timestamp and reset Status back to 0
    $update = $pdo->prepare("UPDATE users SET LogoutDatetime = NOW(), Status = 0 WHERE userID = ?");
    $update->execute([$_SESSION['userID']]);
}

// Destroy the tracking session entirely
$_SESSION = array();
session_destroy();

header("Location: login.php");
exit;
?>