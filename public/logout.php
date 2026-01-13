<?php
// public/logout.php
session_start();
session_destroy(); // Tüm oturum bilgilerini sil
header("Location: login.php");
exit;
?>