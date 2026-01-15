<?php

/**
 * -------------------------------------------------------------------------
 * AccessTransparency plugin for GLPI
 * Copyright (C) 2025 by the TICGAL Team.
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
 * @copyright Copyright (c) 2025 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2025
 * -------------------------------------------------------------------------
 */

include("../../../inc/includes.php");

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

if (!Plugin::isPluginActive('accesstransparency')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Session::checkLoginUser();

$action = $_POST['action'] ?? '';
$ruta = $_POST['ruta'] ?? '';

if ($action === 'register' && $ruta) {
    $path = substr($ruta, 0, 255);

    /** @var \DBmysql $DB */
    global $DB;

    try {
        PluginAccesstransparencyUserinteractions::registerInteraction($path, $_POST['documents_id']);

        echo json_encode(['status' => 'ok', 'ruta' => $path]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'Error al guardar en la base de datos.',
            'detalle' => $e->getMessage(),
        ]);
    }
}
