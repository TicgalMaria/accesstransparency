<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

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
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `users_id` INT {$default_key_sign} NOT NULL default 0,
            `path` varchar(255),
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        )ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
        COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }

    public static function uninstall(Migration $migration): bool
    {
        $migration->dropTable(self::getTable());
        return true;
    }

    public static function registerInteraction(string $path): void
    {
        $user_id = Session::getLoginUserID();
        if (!$user_id) {
            return;
        }

        $item = new self();
        $item->add([
            'users_id'  => $user_id,
            'path' => $path,
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

        if ($iterator && $iterator->count()) {
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
            if ($task) {
                $task->log(__('No logs purged (keep_all setting)', 'accesstransparency'));
                $task->addVolume(0);
            }
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
            if ($task) {
                $task->log(__('No logs to purge', 'accesstransparency'));
                $task->addVolume(0);
            }
            return 1;
        }

        $deleted = 0;
        foreach ($rows as $row) {
            $self = new self();
            if ($self->delete(['id' => $row['id']])) {
                $deleted++;
            }
        }

        if ($task) {
            $task->addVolume($deleted);
            $task->log(sprintf(__('Purged %d interaction logs', 'accesstransparency'), $deleted)); // texto del log
        }

        return 1;
    }
}
