<?php
require_once __DIR__ . '/config/app.php';

logoutUser();
header('Location: login.php');
exit;
