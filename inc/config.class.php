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

use Glpi\Application\View\TemplateRenderer;

class PluginAccesstransparencyConfig extends CommonDBTM
{
    public static $rightname = 'config';
    private static ?self $instance = null;
    public const DELETE_ALL = 'delete_all';
    public const KEEP_ALL   = 'keep_all';

    /**
     * Campos gestionados por el plugin
     */
    private array $managed_fields = [
        'log_retention_minutes',
        'file_log_retention_minutes'
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Access Transparency';
    }

    public static function getIcon(): string
    {
        return 'fa-solid fa-cube';
    }

    public static function getInstance(int $n = 1): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if (!self::$instance->getFromDB($n)) {
                self::$instance->getEmpty();
            }
        }
        return self::$instance;
    }

    public function prepareInputForUpdate($input): false|array
    {
        foreach ($this->managed_fields as $field) {
            if (isset($input[$field]) && isset($this->fields[$field]) && $this->fields[$field] != $input[$field]) {
                Log::history(
                    1,
                    Config::class,
                    [1, $field . ' ' . $this->fields[$field], $input[$field]]
                );
            }
        }
        return $input;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item::getType() === Config::getType()) {
            if (isset($_POST['log_retention_minutes'])) {
                $_SESSION['accesstransparency']['log_retention_minutes'] = $_POST['log_retention_minutes'];
            }

            if (isset($_POST['file_log_retention_minutes'])) {
                $_SESSION['accesstransparency']['file_log_retention_minutes'] = $_POST['file_log_retention_minutes'];
            }

            if (isset($_POST['log_limit'])) {
                $_SESSION['accesstransparency']['log_limit'] = $_POST['log_limit'];
            }

            Session::checkLoginUser();
            $_SESSION['glpicsrf_token'] = Session::getNewCSRFToken();

            return self::createTabEntry(self::getTypeName(1));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        if ($item::getType() === Config::getType()) {
            return self::showFormConfig();
        }

        return false;
    }

    public static function showFormConfig(): bool
    {
        global $DB;

        $config = self::getInstance();
        $lastLog = $DB->request([
            'SELECT' => ['last_log'],
            'FROM'   => 'glpi_plugin_accesstransparency_lastLog',
            'WHERE'  => ['id' => 1],
            'LIMIT'  => 1
        ]);

        $latest_id = 0;
        if ($lastLog->count() > 0) {
            $latest_id = (int)$lastLog->current()['last_log'];
        }

        $maxLog = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_logs',
            'ORDER'  => ['id DESC'],
            'LIMIT'  => 1
        ]);

        $last_glpi_log_id = 0;
        if ($maxLog->count() > 0) {
            $last_glpi_log_id = (int)$maxLog->current()['id'];
        }

        if (!empty($_SESSION['accesstransparency'])) {
            $session_data = $_SESSION['accesstransparency'];
            $lastLog = $DB->request([
                'SELECT' => ['interval', 'id'],
                'FROM'   => 'glpi_plugin_accesstransparency_lastLog',
                'LIMIT'  => 1
            ]);

            $interval = $lastLog->count() > 0 ? (int)$lastLog->current()['interval'] : 5000;

            if (isset($session_data['log_limit']) && (int)$session_data['log_limit'] !== $interval) {
                if ($lastLog->count() > 0) {
                    $DB->update(
                        'glpi_plugin_accesstransparency_lastLog',
                        ['interval' => (int)$session_data['log_limit']],
                        ['id' => $lastLog->current()['id']]
                    );
                } else {
                    $DB->insert(
                        'glpi_plugin_accesstransparency_lastLog',
                        ['interval' => (int)$session_data['log_limit']]
                    );
                }

                $interval = (int)$session_data['log_limit'];
            }

            $data = [
                'log_retention_minutes'      => $session_data['log_retention_minutes'] ?? null,
                'file_log_retention_minutes' => $session_data['file_log_retention_minutes'] ?? null
            ];

            if ($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['id' => 1]
            ])->count()) {
                $DB->update(self::getTable(), $data, ['id' => 1]);
            } else {
                $data['id'] = 1;
                $DB->insert(self::getTable(), $data);
            }

            $lastInserted = $DB->request([
                'SELECT' => ['last_log'],
                'FROM'   => 'glpi_plugin_accesstransparency_lastLog',
                'LIMIT'  => 1
            ]);

            $last_id_inserted = $lastInserted->count() > 0
                ? (int)$lastInserted->current()['last_log']
                : 0;

            $logs = PluginAccesstransparencyUser::getDataLogs($last_id_inserted, $interval);
            $latest_id = $last_id_inserted;
            $inserted = 0;

            foreach ($logs as $log) {
                if ($log['id'] <= $last_id_inserted) continue;

                $date = !empty($log['date']) ? date('Y-m-d H:i:s', strtotime($log['date'])) : null;
                $user_id = 0;
                if (!empty($log['user_name']) && preg_match('/\((\d+)\)/', $log['user_name'], $matches)) {
                    $user_id = (int)$matches[1];
                }

                $DB->insert(
                    'glpi_plugin_accesstransparency_logevents',
                    [
                        'date_creation' => $date,
                        'users_id'      => $user_id ?: null,
                        'itemtype'      => $log['itemtype'] ?? null,
                        'field'         => $log['field'] ?? null,
                        'message'       => strip_tags($log['change']) ?? null,
                        'source'        => $log['source'] ?? null,
                    ]
                );

                $latest_id = $log['id'];
                $inserted++;

                unset($_SESSION['accesstransparency']);
                if ($inserted >= $interval) break;
            }

            if ($latest_id > $last_id_inserted) {
                $DB->update(
                    'glpi_plugin_accesstransparency_lastLog',
                    [
                        'last_log'    => $latest_id,
                        'date_update' => date('Y-m-d H:i:s'),
                    ],
                    ['id' => 1]
                );
            }

            Session::addMessageAfterRedirect(__('Configuration and logs saved successfully!', 'accesstransparency'), true, INFO);
        } else {
            $lastLog = $DB->request([
                'SELECT' => ['interval'],
                'FROM'   => 'glpi_plugin_accesstransparency_lastLog',
                'LIMIT'  => 1
            ]);
            $interval = $lastLog->count() > 0 ? (int)$lastLog->current()['interval'] : 5000;
        }

        $config->getFromDB(1);

        TemplateRenderer::getInstance()->display(
            '@accesstransparency/pages/config.html.twig',
            [
                'item'                       => $config,
                'log_retention_minutes'      => $config->getLogRetentionMinutes(),
                'file_log_retention_minutes' => $config->getFileLogRetentionMinutes(),
                'interval'                   => $interval,
                'latest_id'                  => $latest_id,
                'last_glpi_log_id'           => $last_glpi_log_id,
            ]
        );

        return true;
    }

    public function getLogRetentionMinutes(): string
    {
        return $this->fields['log_retention_minutes'] ?? self::KEEP_ALL;
    }

    public function getFileLogRetentionMinutes(): string
    {
        return $this->fields['file_log_retention_minutes'] ?? self::KEEP_ALL;
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();
        $tableConfig = self::getTable();

        if (!$DB->tableExists($tableConfig)) {
            $DB->doQuery("
                CREATE TABLE `$tableConfig` (
                    `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `log_retention_minutes` VARCHAR(50) DEFAULT NULL,
                    `file_log_retention_minutes` VARCHAR(50) DEFAULT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB
                DEFAULT CHARSET={$default_charset}
                COLLATE={$default_collation}
                ROW_FORMAT=DYNAMIC;
            ");
        }

        if (!$DB->request(['SELECT' => ['id'], 'FROM'   => $tableConfig, 'LIMIT'  => 1])->count()) {
            $DB->insert($tableConfig, [
                'id' => 1,
                'log_retention_minutes' => null,
                'file_log_retention_minutes' => null
            ]);
        }

        if (!$DB->tableExists('glpi_plugin_accesstransparency_lastLog')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_accesstransparency_lastLog` (
                    `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `last_log` INT DEFAULT NULL,
                    `interval` INT DEFAULT 5000,
                    `date_update` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB
                DEFAULT CHARSET={$default_charset}
                COLLATE={$default_collation}
                ROW_FORMAT=DYNAMIC;
            ");
        }

        if (!$DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_accesstransparency_lastLog',
            'LIMIT'  => 1
        ])->count()) {

            $DB->insert('glpi_plugin_accesstransparency_lastLog', [
                'id' => 1,
                'last_log' => 0,
                'interval' => 5000,
                'date_update' => null
            ]);
        }
    }

    public static function cronInfo($name)
    {
        if (strtolower($name) === 'purgeaccesstransparencylogs') {
            return [
                'description' => __('Purge old logs', 'accesstransparency'),
                'parameter'   => __('Retention period (in months)', 'accesstransparency'),
            ];
        }

        return [];
    }

    public static function cronPurgeAccessTransparencyLogs(CronTask $task)
    {
        global $DB;

        $config = self::getInstance();
        $param  = $config->getLogRetentionMinutes();
        $table = 'glpi_plugin_accesstransparency_logevents';

        if ($param === self::KEEP_ALL) {
            $task->log(__('No logs purged (keep_all setting)', 'accesstransparency'));
            $task->addVolume(1);
            return 1;
        }

        if ($param === self::DELETE_ALL) {
            $deleted = $DB->delete($table, ['id' => ['>', 0]]);
            $task->addVolume($deleted);
            return 1;
        }

        $months = (int)$param;
        $deleted = $DB->delete($table, [['date_creation', '<', date('Y-m-d H:i:s', strtotime("-$months months"))]]);

        $task->addVolume($deleted);
        $task->log(sprintf(__('Purged %d logs', 'accesstransparency'), $deleted));

        return 1;
    }

    public static function uninstall(Migration $migration): void
    {
        global $DB;
        $tableConfig = self::getTable();

        if ($DB->tableExists($tableConfig)) {
            $DB->doQuery("DROP TABLE `$tableConfig`;");
        }

        if ($DB->tableExists('glpi_plugin_accesstransparency_lastLog')) {
            $DB->doQuery("DROP TABLE `glpi_plugin_accesstransparency_lastLog`;");
        }

        Config::deleteConfigurationValues('plugin:accesstransparency');
    }
}
