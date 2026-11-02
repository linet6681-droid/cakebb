<?php
// Logout is a POST with a CSRF token (see logout_form()). Opening this address directly just goes home and does NOT log anyone out.
require_once 'includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
if (!current_user()) redirect('login.php');   // already logged out
csrf_check();
$wasAdmin = (current_user()['role'] ?? '') === 'admin';
destroy_session();
session_start(); session_regenerate_id(true);
flash('You have been logged out.');
if (!headers_sent()) { header('Clear-Site-Data: "cache"'); header('Cache-Control: no-store'); }
redirect($wasAdmin ? 'admin/login.php' : 'login.php');
