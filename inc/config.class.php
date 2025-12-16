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

use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use Twig\TwigFunction;
use Twig\TwigFilter;
use Twig\Markup;

class PluginAccesstransparencyConfig extends CommonDBTM
{
    public static $rightname = 'config';
    private static ?self $instance = null;

    public const DELETE_ALL = 'delete_all';
    public const KEEP_ALL   = 'keep_all';
    public $fields = ['log_retention_minutes'];

    public function __construct()
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

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
        foreach ($this->fields as $key => $value) {
            if (isset($input[$key]) && $input[$key] != $value) {
                Log::history(1, Config::class, [1, $key . ' ' . $value, $input[$key]]);
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

            Session::checkLoginUser();
            $_SESSION['glpicsrf_token'] = Session::getNewCSRFToken();
            return self::createTabEntry(self::getTypeName(1));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        switch ($item::getType()) {
            case Config::getType():
                return self::showFormConfig();
        }
        return false;
    }

    public static function showFormConfig(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = self::getInstance();
        $pluginTemplatePath = GLPI_ROOT . '/plugins/accesstransparency/templates';
        $coreTemplatePath   = GLPI_ROOT . '/templates';

        if (isset($_SESSION['accesstransparency']['log_retention_minutes'])) {
            $value = $_SESSION['accesstransparency']['log_retention_minutes'];

            if ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => 1]])->count()) {
                $DB->update(
                    self::getTable(),
                    ['log_retention_minutes' => $value],
                    ['id' => 1],
                );
            } else {
                $DB->insert(self::getTable(), [
                    'id' => 1,
                    'log_retention_minutes' => $value,
                ]);
            }

            $config->setLogRetentionMinutes($value);

            Session::addMessageAfterRedirect(
                __('Configuration saved successfully', 'accesstransparency'),
                true,
                INFO,
            );
        }

        $loader = new FilesystemLoader([$pluginTemplatePath, $coreTemplatePath]);
        $twig = new Environment($loader);

        $twig->addFunction(new TwigFunction('idor_token', function ($name = '_glpi_csrf_token') {
            $token = Session::getNewCSRFToken();
            return new Markup('<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($token) . '">', 'UTF-8');
        }));

        $twig->addFunction(new TwigFunction('call', function ($fn, $args = []) {
            return is_callable($fn) ? call_user_func_array($fn, $args) : null;
        }));

        $twig->addFunction(new TwigFunction('session', fn($key) => null));
        $twig->addFunction(new TwigFunction('render_illustration', fn($item = null, $options = []) => '<!-- illustration -->'));
        $twig->addFunction(new TwigFunction('__', fn($text, $domain = '') => $text));
        $twig->addFunction(new TwigFunction('_x', fn($text, $context = '') => $text));
        $twig->addFunction(new TwigFunction('csrf_token', function ($token_id = '_glpi_csrf_token') {
            return new Markup('<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">', 'UTF-8');
        }));
        $twig->addFunction(new TwigFunction('get_current_locale', fn() => 'en_US'));
        $twig->addFunction(new TwigFunction('config', fn($key, $default = null) => $default));

        $twig->addFilter(new TwigFilter('safe_html', fn($string) => $string));
        $twig->addFilter(new TwigFilter('safe_dom_id', fn($value) => $value));
        $twig->addFilter(new TwigFilter('itemtype_dropdown', fn($value) => $value));
        $twig->addFilter(new TwigFilter('itemtype_form_path', fn($value) => '#'));

        echo $twig->render('pages/config.html.twig', [
            'item' => $config,
            'log_retention_minutes' => $config->getLogRetentionMinutes(),
        ]);
        return true;
    }

    public function getLogRetentionMinutes(): string
    {
        if (isset($this->fields['log_retention_minutes'])) {
            return $this->fields['log_retention_minutes'];
        }

        $cfg = Config::getConfigurationValues('plugin:accesstransparency');
        if (!empty($cfg['log_retention_minutes'])) {
            return $cfg['log_retention_minutes'];
        }

        return self::KEEP_ALL;
    }

    public function setLogRetentionMinutes(string $value): void
    {
        $this->fields['log_retention_minutes'] = $value;
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

        $tableConfig = self::getTable();
        if (!$DB->tableExists($tableConfig)) {
            $migration->displayMessage("Installing $tableConfig");
            $DB->doQuery("CREATE TABLE `$tableConfig` (
                `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `log_retention_minutes` VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
        }

        if (!$DB->request(['SELECT' => ['id'], 'FROM' => $tableConfig, 'LIMIT' => 1])->count()) {
            $DB->insert($tableConfig, ['id' => 1, 'log_retention_minutes' => null]);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $tableConfig = self::getTable();

        if ($DB->tableExists($tableConfig)) {
            $migration->displayMessage("Dropping table $tableConfig");
            $DB->doQuery("DROP TABLE `$tableConfig`;");
        }

        Config::deleteConfigurationValues('plugin:accesstransparency');
    }
}
