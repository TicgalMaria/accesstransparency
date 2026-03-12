<?php
/**
 * -------------------------------------------------------------------------
 * AccessTransparency plugin for GLPI
 * Copyright (C) 2026 by the TICGAL Team.
 * https://www.tic.gal
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the AccessTransparency plugin.
 * AccessTransparency plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * AccessTransparency plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with AccessTransparency. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   accesstransparency
 * @author    the TICGAL team
 * @copyright Copyright (c) 2026 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2026
 * -------------------------------------------------------------------------
 */

ob_start();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', '../../..');
}
include_once GLPI_ROOT . '/inc/includes.php';

if (!Plugin::isPluginActive('accesstransparency')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Session::checkLoginUser();

$userId = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($userId === '' || $userId <= 0) {
    die("Invalid user ID");
}

$user = new User();
if (!$user->getFromDB((int) $userId)) {
    die("User not found");
}

if (file_exists(GLPI_ROOT . '/plugins/accesstransparency/inc/user.class.php')) {
    require_once GLPI_ROOT . '/plugins/accesstransparency/inc/user.class.php';
} else {
    require_once GLPI_ROOT . '/marketplace/accesstransparency/inc/user.class.php';
}

$filters = $_SESSION['accesstransparency']['filters'] ?? [];

$result = PluginAccesstransparencyUser::arrayData($user, $filters, 0);
$combinedArray = $result['mergedArrays'] ?? [];

$friendlyName = $user->fields['name'];

foreach ($combinedArray as &$row) {
    if (!empty($row['change'])) {
        $row['change'] = preg_replace('#<div\b[^>]*>.*?</div>#si', '', $row['change']);
        $row['change'] = preg_replace('#</?(ins|del)>#i', '', $row['change']);
        $row['change'] = trim($row['change']);
    }
}
unset($row);

ob_clean();
PluginAccesstransparencyUser::exportData($combinedArray, $friendlyName, $userId);
exit;
