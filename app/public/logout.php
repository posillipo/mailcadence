<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
logoutAdmin();
header('Location: /login.php');
exit;
