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

class PluginAccesstransparencyDocument extends CommonDBTM
{
    public static $rightname = 'plugin_accesstransparency_view';
    public static function getTypeName($nb = 0): string
    {
        return __('Access Transparency', 'accesstransparency');
    }
    public static function getIcon(): string
    {
        return 'fa-solid fa-cube';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item::getType() === Document::getType()) {
            /** @var Document $doc */
            $doc = $item;
            if (!Session::haveRight(self::$rightname, READ)) {
                return '';
            }
            $number = count(self::getUserInteractionsRaw($doc));
            return self::createTabEntry(self::getTypeName(1), $number);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item::getType() === Document::getType()) {
            if (!Session::haveRight(self::$rightname, READ)) {
                return false;
            }
            /** @var Document $doc */
            $doc = $item;
            self::displayUserInteractionsForDocument($doc);
            return true;
        }

        return false;
    }

    public static function getUserInteractionsRaw(\Document $doc): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $document_id = intval($doc->getID());
        $table = 'glpi_plugin_accesstransparency_userinteractions';

        $result = $DB->request([
            'SELECT' => ['users_id', 'date_creation'],
            'FROM'   => $table,
            'WHERE'  => ["path LIKE '%=" . $document_id . "'"],
        ]);

        return iterator_to_array($result);
    }

    public static function displayUserInteractionsForDocument(\Document $doc)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $result = self::getUserInteractionsRaw($doc);

        foreach ($result as &$row) {
            $row['name'] = [];

            if (!empty($row['users_id'])) {
                $ids = is_array($row['users_id']) ? $row['users_id'] : explode(',', $row['users_id']);
                $ids = array_map('intval', array_filter($ids));

                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $users_result = $DB->request(
                        [
                            'SELECT' => ['id', 'name'],
                            'FROM'   => 'glpi_users',
                            'WHERE'  => ['id' => $ids],
                        ],
                    );

                    foreach ($users_result as $user) {
                        $row['name'][$user['id']] = $user['name'];
                    }
                }
            }
        }

        $pluginTemplatePath = GLPI_ROOT . '/plugins/accesstransparency/templates';
        $coreTemplatePath = GLPI_ROOT . '/templates';

        $loader = new FilesystemLoader([
            $pluginTemplatePath,
            $coreTemplatePath,
        ]);

        $twig = new Environment($loader);

        $twig->addFunction(new TwigFunction('__', function ($string) {
            return __($string);
        }));

        $twig->addFunction(new TwigFunction('php_config', function ($option) {
            return ini_get($option);
        }));

        $twig->addFunction(new TwigFunction('user_pref', function ($key, $default = null) {
            return $_SESSION['glpilist_limit'] ?? $default;
        }));

        $twig->addFilter(new \Twig\TwigFilter('safe_dom_id', function ($string) {
            return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $string);
        }));

        echo $twig->render('pages/document.html.twig', [
            'combined' => $result,
        ]);

        return true;
    }
}
