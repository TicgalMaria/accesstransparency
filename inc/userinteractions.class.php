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

class PluginAccesstransparencyUserinteractions extends CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_accesstransparency_userinteractions';
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL default 0,
                `path` varchar(255),
                `documents_id` INT UNSIGNED NOT NULL default 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `documents_id` (`documents_id`)
            )ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        } else {
            $migration->addField($table, 'documents_id', 'integer');
            $migration->addKey($table, 'documents_id', 'documents_id');
            $migration->addKey($table, 'users_id', 'users_id');

            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall(Migration $migration): bool
    {
        $migration->dropTable(self::getTable());
        return true;
    }

    public static function registerInteraction(string $path, $documents_id): void
    {
        $user_id = Session::getLoginUserID();
        if (!$user_id) {
            return;
        }

        $item = new self();
        $item->add([
            'users_id'  => $user_id,
            'path' => $path,
            'documents_id' => $documents_id,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function showForUser(User $user): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['date_creation', 'path'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $user->getID()],
            'ORDER'  => ['date_creation' => 'DESC'],
        ]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th>Fecha</th><th>Tipo</th><th>ID del ítem</th></tr>";

        if ($iterator->count()) {
            foreach ($iterator as $row) {
                echo "<tr>";
                echo "<td>" . Html::convDateTime($row['date_creation']) . "</td>";
                echo "<td>" . htmlspecialchars($row['path']) . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='3'>No se encontraron interacciones.</td></tr>";
        }
        echo "</table>";
    }

    public static function cronInfo($name)
    {
        switch (strtolower($name)) {
            case 'purgeinteractionlogs':
                return [
                    'description' => __('Purge old interaction logs', 'accesstransparency'),
                    'parameter'   => __('Retention period (in months)', 'accesstransparency'),
                ];
        }
        return [];
    }

    public static function cronPurgeInteractionLogs(CronTask $task)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = PluginAccesstransparencyConfig::getInstance();
        $param  = $config->getLogRetentionMinutes();

        if ($param === 'keep_all') {
            $task->log(__('No logs purged (keep_all setting)', 'accesstransparency'));
            $task->addVolume(0);
            return 1;
        }

        $table = self::getTable();
        $query = [];

        if ($param === 'delete_all') {
            $query = ['FROM' => $table];
        } else {
            $months = (int) $param;
            $query = [
                'FROM'  => $table,
                'WHERE' => [
                    "date_creation <= DATE_SUB(NOW(), INTERVAL $months MONTH)",
                ],
            ];
        }

        $iterator = $DB->request($query);
        $rows = iterator_to_array($iterator);
        $count = count($rows);

        if ($count == 0) {
            $task->log(__('No logs to purge', 'accesstransparency'));
            $task->addVolume(0);
            return 1;
        }

        $deleted = 0;
        foreach ($rows as $row) {
            $self = new self();
            if ($self->delete(['id' => $row['id']])) {
                $deleted++;
            }
        }

        $task->addVolume($deleted);
        $task->log(sprintf(__('Purged %d interaction logs', 'accesstransparency'), $deleted));
        return 1;
    }
}
