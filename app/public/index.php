<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
redirect(isLoggedIn() ? '/dashboard.php' : '/login.php');
