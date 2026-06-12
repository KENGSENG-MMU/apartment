<?php
require_once 'security.php';

if (function_exists('log_audit')) {
    log_audit('LOGOUT', 'User logged out from the system');
}

session_unset();
session_destroy();

header("Location: ../public/login.php");
exit;