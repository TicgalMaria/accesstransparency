<?php

include("../../../inc/includes.php");

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

$action = $_POST['action'] ?? '';
$ruta = $_POST['ruta'] ?? '';

if ($action === 'register' && $ruta) {
    $path = substr($ruta, 0, 255);
    $userId = Session::getLoginUserID();

    /** @var \DBmysql $DB */
    global $DB;

    try {
        $DB->insert('glpi_plugin_accesstransparency_userinteractions', [
            'users_id'      => $userId,
            'path'          => $path,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        echo json_encode(['status' => 'ok', 'ruta' => $path]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'Error al guardar en la base de datos.',
            'detalle' => $e->getMessage(),
        ]);
    }
}
