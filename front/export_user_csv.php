<?php

ob_clean();
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', '../../..');
}

include_once GLPI_ROOT . '/inc/includes.php';
Session::checkLoginUser();

$userId = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($userId === '') {
    die("Invalid user ID");
} elseif ($userId <= 0) {
    die("Invalid user ID");
}

$user = new User();
if (!$user->getFromDB($userId)) {
    die("User not found");
}

require_once GLPI_ROOT . '/plugins/accesstransparency/inc/user.class.php';

$combinedArray = PluginAccesstransparencyUser::showFormUser($user, true);
$friendlyName = $user->getFriendlyName();

foreach ($combinedArray as &$row) {
    if (isset($row['userNameRow'])) {
        $row['userNameRow'] = $user->fields['name'];
    }

    if (!empty($row['change'])) {
        $row['change'] = preg_replace('#</?(ins|del)>#i', '', $row['change']);
    }
}
unset($row);

PluginAccesstransparencyUser::exportData($combinedArray, $user->fields['name'], $userId);
exit;
