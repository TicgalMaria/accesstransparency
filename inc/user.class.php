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
use Glpi\Csv\CsvResponse;
use Glpi\Csv\ExportToCsvInterface;
use Glpi\Application\View\TemplateRenderer;


require_once __DIR__ . '/userinteractions.class.php';

class PluginAccesstransparencyUser extends CommonDBTM
{
    public static $rightname = 'plugin_accesstransparency_view';
    private static ?self $instance = null;
    private static $userCache = [];
    private static $translationCache = [];
    private static $followingTranslationCache = [];

    /**
     * {@inheritDoc}
     */
    public function __construct()
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0): string
    {
        return 'Access Transparency';
    }

    /**
     * getInstance
     * @param  int $n
     * @return PluginAccesstransparencyUser
     */
    public static function getInstance(int $n = 1): PluginAccesstransparencyUser
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if (!self::$instance->getFromDB($n)) {
                self::$instance->getEmpty();
            }
        }
        return self::$instance;
    }

    /**
     * {@inheritDoc}
     */
    public function prepareInputForUpdate($input): false|array
    {
        foreach ($this->fields as $key => $value) {
            if (isset($input[$key]) && $input[$key] != $value) {
                Log::history(1, User::class, [1, $key . ' ' . $value, $input[$key]]);
            }
        }
        return $input;
    }

    /**
     * @return string|null The configuration value or null if not found.
     */
    public static function getItemType(): ?string
    {
        return User::class;
    }

    public static function getIcon(): string
    {
        return 'fa-solid fa-cube';
    }

    /**
     * {@inheritDoc}
     */

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item::getType() === User::getType()) {
            if (!Session::haveRight(self::$rightname, READ)) {
                return '';
            }

            /** @var User $user */
            $user = $item;

            if (isset($_GET['filters'])) {
                $_SESSION['accesstransparency']['filters'] = $_GET['filters'];
            }

            if (isset($_GET['clear_filters'])) {
                unset($_SESSION['accesstransparency']['filters']);
            }

            Session::checkLoginUser();
            $_SESSION['glpicsrf_token'] = Session::getNewCSRFToken();

            $result = self::arrayData($user);
            $number = count($rawCombinedArray = $result['mergedArrays']);
            return self::createTabEntry(self::getTypeName(1), $number);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item::getType() === User::getType()) {
            if (!Session::haveRight(self::$rightname, READ)) {
                return false;
            }
            /** @var User $user */
            $user = $item;
            return self::showFormUser($user);
        }
        return false;
    }

    public static function arrayData(User $user): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $userid       = $user->getID();
        $nameLastname = $user->getFriendlyName();
        $realName     = $user->fields['realname'] ?? '';
        $firstName    = $user->fields['firstname'] ?? '';
        $lastnameName = $nameLastname;
        $friendlyName = $user->fields['name'];
        $table = \PluginAccesstransparencyUserinteractions::getTable();

        if (str_starts_with($nameLastname, $realName)) {
            $lastnameName = trim($firstName . ' ' . $realName);
        } elseif (str_starts_with($nameLastname, $firstName)) {
            $lastnameName = trim($realName . ' ' . $firstName);
        }

        $logsIterator = $DB->request([
            'SELECT' => ['*'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'OR' => [
                    ['user_name' => ['LIKE', $friendlyName . ' (' . $userid . ')%']],
                    ['user_name' => ['LIKE', $nameLastname . ' (' . $userid . ')%']],
                    ['user_name' => ['LIKE', $lastnameName . ' (' . $userid . ')%']],
                ],
            ],
        ]);

        $eventsIterator = $DB->request([
            'SELECT' => ['*'],
            'FROM'   => 'glpi_events',
            'WHERE'  => [
                'OR' => [
                    ['message' => ['LIKE', '%' . $friendlyName . '%']],
                    ['message' => ['LIKE', '%' . $nameLastname . ' (' . $userid . ')%']],
                    ['message' => ['LIKE', '%' . $lastnameName . ' (' . $userid . ')%']],
                ],
            ],
        ]);

        $interactionIterator = $DB->request([
            'SELECT' => ['id as id_doc', 'path', 'date_creation'],
            'FROM'   => $table,
            'WHERE'  => ['users_id' => $userid],
        ]);

        $documentsIterator = $DB->request([
            'SELECT' => ['id as id_document', 'name', 'date_mod as fecha'],
            'FROM'   => 'glpi_documents',
        ]);

        $configIterator = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => ['name' => 'language'],
        ]);

        $defaultLanguage = '';
        if ($config = $configIterator->current()) {
            $defaultLanguage = $config['value'];
        }

        $values = [];
        $languageIterator = $DB->request([
            'SELECT' => ['old_value', 'new_value'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'id_search_option' => 17,
                'itemtype'         => 'User',
                'items_id'         => $userid,
            ],
        ]);

        foreach ($languageIterator as $log) {
            if (!empty($log['old_value'])) {
                $values[$log['old_value']] = true;
            }
            if (!empty($log['new_value'])) {
                $values[$log['new_value']] = true;
            }
        }

        $allLanguages = array_keys($values);

        if (!empty($defaultLanguage) && !in_array($defaultLanguage, $allLanguages)) {
            array_unshift($allLanguages, $defaultLanguage);
        }

        $logsArray = iterator_to_array($logsIterator);
        $eventsArray = iterator_to_array($eventsIterator);
        $interactionArray = iterator_to_array($interactionIterator);
        $documentsArray = iterator_to_array($documentsIterator);

        return [
            'allLanguages' => $allLanguages,
            'mergedArrays' => array_merge($logsArray, $eventsArray, $interactionArray, $documentsArray),
        ];
    }

    public static function applyFilters(array $data, array $filters, array $itemtypes, array $translations_map = []): array
    {
        return array_filter($data, function ($row) use ($filters, $itemtypes) {

            if (!empty($filters['keyword'])) {
                $keyword = mb_strtolower(trim($filters['keyword']));
                $found = false;
                foreach ($row as $clave => $valor) {
                    if ($clave === 'itemtype' && !empty($valor)) {
                        $label = $itemtypes[$valor] ?? '';
                        if (mb_strpos(mb_strtolower($label), $keyword) !== false) {
                            $found = true;
                            break;
                        }
                    }
                    if (is_scalar($valor) && mb_strpos(mb_strtolower((string) $valor), $keyword) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }

            if (!empty($filters['fecha'])) {
                $filterTimestamp = strtotime($filters['fecha']);
                $rowTimestamp = isset($row['fecha']) ? strtotime($row['fecha']) : false;
                if (!$rowTimestamp || date('Y-m-d', $rowTimestamp) != date('Y-m-d', $filterTimestamp)) {
                    return false;
                }
            }

            if (!empty($filters['itemtype']) && is_array($filters['itemtype'])) {
                $itemtypeFilter = $filters['itemtype'];
                if ($row['source'] === 'interaction') {
                    if (!in_array('__interaction__', $itemtypeFilter)) {
                        return false;
                    }
                } elseif ($row['source'] === 'log') {
                    if (!in_array($row['itemtype'] ?? '', $itemtypeFilter)) {
                        return false;
                    }
                } elseif ($row['source'] === 'events') {
                    if (!in_array($row['type'] ?? '', $itemtypeFilter)) {
                        return false;
                    }
                }
            }

            if (!empty($filters['field']) && is_array($filters['field'])) {
                if ($row['source'] === 'log') {
                    if (!in_array($row['field'] ?? '', $filters['field'])) {
                        return false;
                    }
                } elseif ($row['source'] === 'events') {
                    if (!in_array($row['service'] ?? '', $filters['field'])) {
                        return false;
                    }
                }
            }

            if (!empty($filters['change']) && is_array($filters['change'])) {
                foreach ($filters['change'] as $filterValue) {
                    $rowValue = null;
                    if ($row['source'] === 'log') {
                        $rowValue = $row['filter'] ?? null;
                    } elseif ($row['source'] === 'events') {
                        $rowValue = $row['message']['filter'] ?? null;
                    }

                    $found = false;

                    if (is_array($rowValue)) {
                        foreach ($rowValue as $v) {
                            if ($v === $filterValue) {
                                $found = true;
                                break;
                            }
                        }
                    } else {
                        if ($rowValue === $filterValue) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    private static function hasValidFilters(array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                if (count(array_filter($value)) > 0) {
                    return true;
                }
            } else {
                if (!empty($value)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function exportData(array $data, string $friendlyName = '', string $userId = '')
    {
        $documents = [];
        foreach ($data as $row) {
            if (($row['source'] ?? '') === 'document') {
                $documents[$row['id_document']] = $row['name'] ?? '';
            }
        }

        $export = new class ($data, $friendlyName, $userId, $documents) implements ExportToCsvInterface {
            private $data;
            private $friendlyName;
            private $userId;
            private $documents;

            public function __construct(array $data, string $friendlyName, string $userId, array $documents)
            {
                $this->data = $data;
                $this->friendlyName = $friendlyName;
                $this->userId = $userId;
                $this->documents = $documents;
            }

            public function getFileHeader(): array
            {
                return ['ID', 'Date', 'User', 'User ID', 'Itemtype', 'Field', 'Changes'];
            }

            public function getFileContent(): array
            {
                $rows = [];
                foreach ($this->data as $row) {
                    $source = $row['source'] ?? '';
                    $id = $row['id'] ?? $row['id_document'] ?? '';
                    $date = isset($row['fecha']) ? date('Y-m-d H:i', strtotime($row['fecha'])) : '';
                    $userName = $this->friendlyName;
                    $userIdRow = $this->userId;

                    switch ($source) {
                        case 'log':
                            $change = preg_replace('#</?(ins|del)>#i', '', $row['change'] ?? '');
                            $rows[] = [
                                $id,
                                $date,
                                $userName,
                                $userIdRow,
                                $row['itemtype'] ?? '',
                                $row['field'] ?? '',
                                $change,
                            ];
                            break;
                        case 'events':
                            $message = preg_replace('#</?(ins|del)>#i', '', $row['message']['translation'] ?? '');
                            $rows[] = [
                                $id,
                                $date,
                                $userName,
                                $userIdRow,
                                $row['type'] ?? '',
                                $row['service'] ?? '',
                                $message,
                            ];
                            break;
                        case 'interaction':
                            $interactionId = $row['id_doc'] ?? '';
                            $docName = '';
                            if (isset($row['path']) && preg_match('/docid=([^&]+)/', $row['path'], $matches)) {
                                $docid = $matches[1];
                                if (!empty($this->documents[$docid])) {
                                    $docName = $this->documents[$docid];
                                }
                            }
                            $rows[] = [
                                $interactionId,
                                $date,
                                $userName,
                                $userIdRow,
                                'Interactions',
                                '',
                                $docName,
                            ];
                            break;
                        default:
                            break;
                    }
                }
                return $rows;
            }

            public function getFileName(): string
            {
                return 'accessTransparency.csv';
            }
        };
        CsvResponse::output($export);
    }

    private static function getUserTickets(int $userid): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $tickets = [];

        try {
            $result = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['users_id_recipient' => $userid],
            ]);

            foreach ($result as $row) {
                if (!empty($row['id'])) {
                    $tickets[] = (string) $row['id'];
                }
            }
        } catch (Exception $e) {
            error_log('Error al obtener tickets del usuario (' . $userid . '): ' . $e->getMessage());
        }

        return $tickets;
    }

    private static function getUserDocuments(int $userid): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $documents = [];

        try {
            $result = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_documents',
                'WHERE'  => ['users_id' => $userid],
            ]);

            foreach ($result as $row) {
                if (!empty($row['name'])) {
                    $documents[] = trim($row['name']);
                }
            }
        } catch (Exception $e) {
            error_log('Error al obtener documentos del usuario (' . $userid . '): ' . $e->getMessage());
        }
        return $documents;
    }

    private static function userExistsInDatabase(string $name): bool
    {
        if (isset(self::$userCache[$name])) {
            return self::$userCache[$name];
        }

        /** @var \DBmysql $DB */
        global $DB;
        try {
            $result = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['name' => $name],
            ]);
            $exists = count($result) > 0;
            self::$userCache[$name] = $exists;
            return $exists;
        } catch (Exception $e) {
            error_log('Error al verificar usuario en DB: ' . $e->getMessage());
            return false;
        }
    }

    private static function getAllAssets(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $assets = [];
        $assetTables = [
            'glpi_computers'           => 'name',
            'glpi_monitors'            => 'name',
            'glpi_networkequipments'   => 'name',
            'glpi_printers'            => 'name',
            'glpi_peripherals'         => 'name',
            'glpi_softwares'           => 'name',
            'glpi_phones'              => 'name',
            'glpi_assets_assets'       => 'name',
            'glpi_cartridgeitems'      => 'name',
            'glpi_consumables'         => 'name',
            'glpi_racks'               => 'name',
            'glpi_enclosures'          => 'name',
            'glpi_pdus'                => 'name',
            'glpi_passivedcequipments' => 'name',
            'glpi_unmanageds'          => 'name',
            'glpi_cables'              => 'name',
        ];

        foreach ($assetTables as $table => $field) {
            try {
                $result = $DB->request([
                    'SELECT' => [$field],
                    'FROM'   => $table,
                ]);

                foreach ($result as $row) {
                    if (!empty($row[$field])) {
                        $assets[] = trim($row[$field]);
                    }
                }
            } catch (Exception $e) {
                error_log("Error al obtener activos de la tabla {$table}: " . $e->getMessage());
            }
        }
        return $assets;
    }

    public static function cleanMessages(array $messages, $nameLastname, $lastnameName, $friendlyName, $userid): array
    {
        $extractedData = [];
        $documents = self::getUserDocuments($userid);
        $tickets   = self::getUserTickets($userid);
        $assets    = self::getAllAssets();

        foreach ($messages as $message) {
            $data = [
                'ip' => null,
                'acting_user' => $friendlyName,
                'second_user' => null,
                'plugin' => null,
                'longest_fragment' => null,
                'element' => null,
                'element_type' => null,
                'asset' => null,
                'action' => null,
                'filter' => null,
            ];

            $clean = $message;

            if (preg_match('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $clean, $ipMatch)) {
                $data['ip'] = $ipMatch[0];
                $clean = str_replace($data['ip'], '', $clean);
            }

            foreach ([$nameLastname, $lastnameName, $friendlyName, $userid] as $term) {
                if ($term && stripos($clean, $term) !== false) {
                    $parts = preg_split('/\b' . preg_quote($term, '/') . '\b/i', $clean);
                    if (count($parts) > 1) {
                        $clean = strlen(trim($parts[0])) >= strlen(trim($parts[1])) ? trim($parts[0]) : trim($parts[1]);
                    } else {
                        $clean = trim($parts[0]);
                    }
                }
            }

            $clean = trim(preg_replace('/\s+/', ' ', $clean));

            foreach ($documents as $doc) {
                if (preg_match('/\b' . preg_quote($doc, '/') . '$/i', $clean)) {
                    $data['element'] = $doc;
                    $data['element_type'] = 'document';
                    $clean = preg_replace('/\s*' . preg_quote($doc, '/') . '$/i', '', $clean);
                    $clean = trim($clean);
                    break;
                }
            }

            if (!$data['element']) {
                foreach ($tickets as $ticket) {
                    if (preg_match('/\b' . preg_quote($ticket, '/') . '$/i', $clean)) {
                        $data['element'] = $ticket;
                        $data['element_type'] = 'ticket';
                        $clean = preg_replace('/\s*' . preg_quote($ticket, '/') . '$/i', '', $clean);
                        $clean = trim($clean);
                        break;
                    }
                }
            }

            foreach ($assets as $asset) {
                if (stripos($clean, $asset) !== false) {
                    $data['asset'] = $asset;

                    $pos = stripos($clean, $asset);
                    $beforeAsset = substr($clean, 0, $pos);
                    if ($beforeAsset) {
                        preg_match('/(\S+\s+)?(\S+)$/u', trim($beforeAsset), $matches);
                        $data['action'] = $matches[0] ?? null;
                    }

                    $clean = str_ireplace($asset, '', $clean);
                    $clean = trim(preg_replace('/\s+/', ' ', $clean));
                    break;
                }
            }

            $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            $restWords = array_slice($words, 1);

            foreach ($restWords as $index => $word) {
                if (preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $word) && strtoupper($word) !== 'IP') {
                    $data['plugin'] = $word;

                    $realIndex = $index + 1;
                    $remainingWords = array_slice($words, $realIndex + 1);
                    $clean = implode(' ', $remainingWords);
                    $clean = preg_replace('/\(\s*[^\)]*\s*\)/', '', $clean);
                    $clean = trim(preg_replace('/\s+/', ' ', $clean));
                    break;
                }
            }

            if (preg_match('/executes the\s+([^\s]+)/i', $clean, $actionMatch)) {
                $data['action'] = $actionMatch[1];
                $clean = str_ireplace($actionMatch[0], '', $clean);
                $clean = trim($clean);
            }

            $fragments = preg_split('/[,.]| {2,}/', $clean, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($fragments)) {
                usort($fragments, fn($a, $b) => strlen($b) <=> strlen($a));
                $data['longest_fragment'] = trim($fragments[0]);
            }

            $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($words)) {
                $lastWordWithPunct = array_pop($words);
                $lastWordClean = trim($lastWordWithPunct, ".,()[]{}\"'");

                $exclusions = array_filter([$nameLastname, $lastnameName, $friendlyName, $userid]);
                if (!in_array($lastWordClean, $exclusions, true) && self::userExistsInDatabase($lastWordClean)) {
                    $data['second_user'] = $lastWordClean;
                    $data['filter'] = 'impersonate';
                    $clean = implode(' ', $words);
                    $data['longest_fragment'] = $clean;
                }
            }
            $extractedData[] = $data;
        }
        return $extractedData;
    }

    public static function compareTranslationMessage(string $message, string $msgstr): bool
    {
        $normalize = function ($text) {
            return strtolower(trim(str_replace(["\n", "\r", '"', "'"], '', $text)));
        };

        $normalizedMessage = $normalize($message);
        $normalizedMsgstr = $normalize($msgstr);
        return strpos($normalizedMsgstr, $normalizedMessage) !== false;
    }

    public static function getPreviousTranslationLine(?string $message, array $translationFiles): ?string
    {
        try {
            if (empty($message)) {
                return '';
            }

            $baseDir = GLPI_ROOT . '/locales/';

            foreach ($translationFiles as $file) {
                if (!isset(self::$translationCache[$file])) {
                    $filePath = $baseDir . $file . '.po';
                    self::$translationCache[$file] = self::parsePoFile($filePath);
                }

                foreach (self::$translationCache[$file] as $msgstr => $msgid) {
                    if (self::compareTranslationMessage($message, $msgstr)) {
                        return 'msgid "' . $msgid . '"';
                    }
                }
            }

            return $message;
        } catch (\Throwable $e) {
            return '';
        }
    }


    private static function parsePoFile(string $filePath): array
    {
        $translations = [];
        if (!file_exists($filePath)) {
            return $translations;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        $msgid = $msgstr = null;
        foreach ($lines as $line) {
            if (preg_match('/^msgid\s+"(.*)"/', $line, $m)) {
                $msgid = $m[1];
            } elseif (preg_match('/^msgstr\s+"(.*)"/', $line, $m) && $msgid) {
                $msgstr = $m[1];
                $translations[$msgstr] = $msgid;
                $msgid = $msgstr = null;
            }
        }
        return $translations;
    }

    public static function getFollowingTranslationLine(string $message, string $translationFile): string
    {
        try {
            if ($message === '') {
                return '';
            }

            $filePath = GLPI_ROOT . '/locales/' . $translationFile . '.po';

            if (!file_exists($filePath)) {
                return $message;
            }

            if (preg_match('/msgid\s+"(.*)"/s', $message, $matches)) {
                $message = stripcslashes($matches[1]);
            }

            if (!isset(self::$followingTranslationCache[$translationFile])) {
                self::$followingTranslationCache[$translationFile] = self::parsePoFileForFollowing($filePath);
            }

            foreach (self::$followingTranslationCache[$translationFile] as $msgid => $msgstr) {
                if (stripos($msgid, $message) !== false) {
                    return $msgstr ?: $message;
                }
            }

            return $message;
        } catch (\Throwable $e) {
            return '';
        }
    }


    private static function parsePoFileForFollowing(string $filePath): array
    {
        $translations = [];
        if (!file_exists($filePath)) {
            return $translations;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        $msgid = $msgstr = '';
        $parsingId = $parsingStr = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^msgid\s+"(.*)"/', $line, $m)) {
                $msgid = $m[1];
                $msgstr = '';
                $parsingId = true;
                $parsingStr = false;
                continue;
            }

            if ($parsingId && preg_match('/^"(.*)"/', $line, $m)) {
                $msgid .= $m[1];
                continue;
            }

            if (preg_match('/^msgstr\s+"(.*)"/', $line, $m)) {
                $msgstr = $m[1];
                $parsingStr = true;
                $parsingId = false;
                continue;
            }

            if ($parsingStr && preg_match('/^"(.*)"/', $line, $m)) {
                $msgstr .= $m[1];
                continue;
            }

            if ($line === '' || strpos($line, 'msgid "') === 0) {
                if ($msgid !== '') {
                    $translations[$msgid] = $msgstr;
                }
                $msgid = $msgstr = '';
                $parsingId = $parsingStr = false;
            }
        }

        if ($msgid !== '') {
            $translations[$msgid] = $msgstr;
        }
        return $translations;
    }

    private static function editMessage(string $msgid, $var1 = '', $var2 = null, $var3 = null): string
    {
        $vars = [$var1 ?? '', $var2 ?? '', $var3 ?? ''];
        $placeholderCount = preg_match_all('/%(\d+\$)?s/', $msgid);

        while (count($vars) < $placeholderCount) {
            $vars[] = '';
        }

        $vars = array_slice($vars, 0, $placeholderCount);
        $edited = vsprintf($msgid, $vars);
        return trim(str_replace('msgid', '', $edited));
    }

    public static function getTermTranslationsFromFiles(string $term, array $translationFiles): array
    {
        $translations = [];

        foreach ($translationFiles as $file) {
            $translations[$file] = self::getFollowingTranslationLineExact($term, $file);
        }
        return $translations;
    }
    public static function getFollowingTranslationLineExact(string $msgid, string $translationFile): string
    {
        $filePath = GLPI_ROOT . '/locales/' . $translationFile . '.po';

        if (!file_exists($filePath)) {
            return $msgid;
        }

        if (!isset(self::$followingTranslationCache[$translationFile])) {
            self::$followingTranslationCache[$translationFile] = self::parsePoFileForFollowing($filePath);
        }

        $translations = self::$followingTranslationCache[$translationFile];

        foreach ($translations as $key => $msgstr) {
            $cleanKey = trim(str_replace('"', '', $key));
            if (strcasecmp($cleanKey, $msgid) === 0) {
                return $msgstr ?: $msgid;
            }
        }
        return $msgid;
    }

    public static function showFormUser(User $user, bool $forExport = false): bool | array
    {
        if (isset($_SESSION['accesstransparency']['filters'])) {
            $filtersprueba = $_SESSION['accesstransparency']['filters'];
        }

        $userid       = $user->getID();
        $realName     = $user->fields['realname'] ?? '';
        $firstName    = $user->fields['firstname'] ?? '';
        $nameLastname = $user->getFriendlyName();
        $lastnameName = $nameLastname;
        if (str_starts_with($nameLastname, $realName)) {
            $lastnameName = trim($firstName . ' ' . $realName);
        } elseif (str_starts_with($nameLastname, $firstName)) {
            $lastnameName = trim($realName . ' ' . $firstName);
        }
        $friendlyName = $user->fields['name'];
        $result = self::arrayData($user);
        $allLanguages = $result['allLanguages'] ?? [];
        $rawCombinedArray = $result['mergedArrays'] ?? [];

        $glpiVersion = GLPI_VERSION;
        $logsArray = [];
        $interactionsArray = [];
        $eventsArray = [];
        $documentsArray = [];

        foreach ($rawCombinedArray as $row) {
            if (isset($row['date_mod'])) {
                $row['source'] = 'log';
                $row['fecha'] = $row['date_mod'];

                $row['userNameRow'] = '';
                $row['userIdRow'] = '';
                if (isset($row['user_name']) && preg_match('/^([^(]+)\s*\((\d+)\)$/', $row['user_name'], $matches)) {
                    $row['userNameRow'] = trim($matches[1]);
                    $row['userIdRow'] = $matches[2];
                }

                $logsArray[] = $row;
            } elseif (isset($row['message'])) {
                $row['source'] = 'events';
                $row['fecha'] = $row['date'];

                $row['userNameRow'] = '';
                $row['userIdRow'] = '';
                if (isset($row['user_name']) && preg_match('/^([^(]+)\s*\((\d+)\)$/', $row['user_name'], $matches)) {
                    $row['userNameRow'] = trim($matches[1]);
                    $row['userIdRow'] = $matches[2];
                }

                $eventsArray[] = $row;
            } elseif (isset($row['path'])) {
                $row['source'] = 'interaction';
                $row['fecha'] = $row['date_creation'] ?? '';
                $row['userNameRow'] = $friendlyName;
                $row['userIdRow'] = $userid;

                $interactionsArray[] = $row;
            } elseif (isset($row['id_document'])) {
                $row['source'] = 'document';

                $documentsArray[] = $row;
            }
        }

        foreach ($eventsArray as &$event) {
            if ($event['type'] == 'ticket') {
                $event['type'] = 'Ticket';
            } elseif ($event['type'] == 'documents') {
                $event['type'] = 'Document';
            } elseif ($event['type'] == 'profiles') {
                $event['type'] = 'Profile';
            }
        }

        $combinedArray = array_merge($logsArray, $eventsArray, $interactionsArray, $documentsArray);
        $combinedArray = array_filter($combinedArray, function ($row) {
            if ($row['source'] === 'log') {
                $itemtype = $row['itemtype'] ?? null;
                $items_id = $row['items_id'] ?? null;

                if ($itemtype && $items_id && class_exists($itemtype)) {
                    $itemObject = $itemtype::getById($items_id);
                    return $itemObject && $itemObject->canView();
                }
                return false;
            } elseif ($row['source'] === 'events') {
                return true;
            } elseif ($row['source'] === 'interaction') {
                return true;
            } elseif ($row['source'] === 'document') {
                return true;
            }
            return false;
        });

        $terms = ['add', 'delete', 'update', 'purge'];
        $allTranslations = [];

        foreach ($terms as $term) {
            $translations = self::getTermTranslationsFromFiles($term, $allLanguages);

            foreach ($translations as $lang => $value) {
                $translations[$lang] = [$value];
            }

            if ($term === 'delete' && isset($translations['es_ES'])) {
                $translations['es_ES'] = array_merge($translations['es_ES'], ['Suprimir']);
            } elseif ($term === 'update' && isset($translations['es_ES'])) {
                $translations['es_ES'] = array_merge($translations['es_ES'], ['Cambiar']);
            }
            $allTranslations[$term] = $translations;
        }

        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'log') {
                $campo = '';
                $cambio = '';

                $itemtype = $row['itemtype'] ?? null;
                $items_id = $row['items_id'] ?? null;

                if ($itemtype && $items_id && class_exists($itemtype)) {
                    $itemObject = $itemtype::getById($items_id);

                    if ($itemObject) {
                        $a = Log::getHistoryData($itemObject, 0, 0, ['id' => $row['id']]);
                        $campo = $a[0]['field'] ?? $campo;
                        $cambio = $a[0]['change'] ?? '';

                        $row['item_url'] = $itemObject->getLinkURL();
                    }
                }

                $row['field'] = $campo;
                $row['change'] = $cambio;
                $row['filter'] = '';

                $firstWord = mb_strtolower(strtok(trim($cambio), " "));

                foreach ($terms as $term) {
                    if (!empty($allTranslations[$term])) {
                        foreach ($allTranslations[$term] as $lang => $words) {
                            foreach ($words as $word) {
                                if (mb_strtolower($word) === $firstWord) {
                                    $row['filter'] = $term;
                                    break 3;
                                }
                            }
                        }
                    }
                }
            }
        }
        unset($row);

        usort($combinedArray, function ($a, $b) {
            return strtotime($b['fecha']) <=> strtotime($a['fecha']);
        });

        $filters = $_GET['filters'] ?? [];
        if (empty($filters) && isset($_SESSION['plugin_filters'])) {
            $filters = $_SESSION['plugin_filters'];
        }
        if (isset($filtersprueba)) {
            $filters = $filtersprueba;
        }

        $is_filtered = self::hasValidFilters($filters);
        $showfilters = $is_filtered || (isset($_GET['showfilters']) && $_GET['showfilters'] == 1);

        $documents = [];
        foreach ($combinedArray as $row) {
            if ($row['source'] === 'document') {
                $documents[$row['id_document']] = $row['name'] ?? '';
            }
        }

        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'interaction') {
                $path = $row['path'] ?? '';
                preg_match('/docid=([^&]+)/', $path, $matches);
                $docid = $matches[1] ?? null;

                if ($docid && isset($documents[$docid])) {
                    $row['name'] = $documents[$docid];
                } else {
                    $row['name'] = '';
                }
            }
        }
        unset($row);

        $service = [];
        $message = [];
        $type = [];
        $todos = [];

        foreach ($combinedArray as $row) {
            if ($row['source'] === 'events') {
                if (!empty($row['service'])) {
                    $service[$row['service']] = $row['service'];
                }
                if (!empty($row['message'])) {
                    $message[$row['message']] = $row['message'];
                    $todos[] = $row['message'];
                }
                if (!empty($row['type'])) {
                    $type[$row['type']] = $row['type'];
                }
            }
        }

        $result = self::cleanMessages($todos, $nameLastname, $lastnameName, $friendlyName, $userid);
        $allTranslations = [];

        foreach ($result as $i => $msg) {
            $r = self::getPreviousTranslationLine($msg['longest_fragment'], $allLanguages);
            $msg['longest_fragment'] = $r;
            $filter = $msg['filter'] ?? null;

            if (!$filter) {
                if (stripos($msg['longest_fragment'], 'add') !== false) {
                    $filter = 'add';
                } elseif (stripos($msg['longest_fragment'], 'delete') !== false) {
                    $filter = 'delete';
                } elseif (stripos($msg['longest_fragment'], 'update') !== false) {
                    $filter = 'update';
                } elseif (stripos($msg['longest_fragment'], 'purge') !== false) {
                    $filter = 'purge';
                } elseif (stripos($msg['longest_fragment'], 'activated') !== false || stripos($msg['longest_fragment'], 'deactivated') !== false) {
                    $filter = 'active';
                } elseif (stripos($msg['longest_fragment'], 'install') !== false || stripos($msg['longest_fragment'], 'uninstall') !== false) {
                    $filter = 'install';
                } elseif (stripos($msg['longest_fragment'], 'login') !== false || stripos($msg['longest_fragment'], 'log in') !== false) {
                    $filter = 'log in';
                }
            }

            if ($allLanguages[0] != 'en_GB') {
                $defaultTranslation = self::getFollowingTranslationLine($msg['longest_fragment'], $allLanguages[0]);
                $msg['longest_fragment'] = $defaultTranslation;
            }

            $msgid = trim(str_replace(['msgid', '"'], '', $msg['longest_fragment']));
            $msgid = str_replace(['\"'], ['"'], $msgid);
            $translation = '';

            if (stripos($msgid, 'executes the') !== false) {
                if (preg_match('/executes the\s+([^\s]+)/i', $msgid, $actionMatch)) {
                    $action = $msg['action'];
                    $filter = $action;
                    $translation = self::editMessage($msgid, $friendlyName, $msg['action'], $msg['asset'] ?? '');
                } else {
                    $translation = self::editMessage($msgid, '');
                }
            } elseif ($msg['ip'] != null) {
                $translation = self::editMessage($msgid, $friendlyName, $msg['ip'] ?? '');
            } elseif ($msg['plugin'] != null) {
                $translation = self::editMessage($msgid, $msg['plugin'] ?? '', $friendlyName);
            } elseif ($msg['second_user'] != null) {
                $translation = self::editMessage($msgid, $friendlyName, $msg['second_user'] ?? '');
            } elseif ($msg['element'] != null) {
                $translation = self::editMessage($msgid, $friendlyName, $msg['element'] ?? '');
            } elseif ($msg['asset'] != null) {
                $translation = self::editMessage($msgid, $msg['asset'] ?? '');
            } else {
                $translation = self::editMessage($msgid, $friendlyName);
            }

            $allTranslations[$i] = [
                'translation' => $translation,
                'filter' => $filter,
            ];
        }

        $itemtypes = [];
        $fields = [];
        $changes = [];

        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'log') {
                if (!empty($row['itemtype'])) {
                    $itemtypes[$row['itemtype']] = $row['itemtype']::getTypeName(1);
                }
                if (!empty($row['field'])) {
                    $fields[$row['field']] = $row['field'];
                }
                if (!empty($row['change'])) {
                    $changes[$row['change']] = $row['change'];
                }
            }
        }

        $flag = 0;
        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'events') {
                $row['message'] = $allTranslations[$flag];
                $flag++;
            }
        }
        unset($row);

        if ($is_filtered) {
            $combinedArray = self::applyFilters($combinedArray, $filters, $itemtypes);
        }

        $total_number = count($combinedArray);
        $start = max(0, intval($_GET['start'] ?? 0));

        if (isset($_GET['glpilist_limit'])) {
            $limit = max(1, intval($_GET['glpilist_limit']));
            $_SESSION['glpilist_limit'] = $limit;
        } elseif (isset($_SESSION['glpilist_limit'])) {
            $limit = $_SESSION['glpilist_limit'];
        } else {
            $limit = 20;
        }

        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'interaction' && !empty($row['path'])) {
                $value = null;
                if (strpos($row['path'], '=') !== false) {
                    list(, $value) = explode('=', $row['path'], 2);
                    $row['path'] = '/front/document.form.php?id=' . $value;
                }
            }
        }

        if ($forExport) {
            return $combinedArray;
        }

        $combinedArrayPaged = array_slice($combinedArray, $start, $limit);

        $href = Toolbox::getItemTypeSearchURL(Preference::class) . '?forcetab=PluginAccess$1';
        $additional_params = $is_filtered ? http_build_query(['filters' => $filters]) : "";

        if(file_exists(GLPI_ROOT . '/plugins/accesstransparency/templates')) {
            $pluginTemplatePath = GLPI_ROOT . '/plugins/accesstransparency/templates';
        }else{
            $pluginTemplatePath = GLPI_ROOT . '/marketplace/accesstransparency/templates';
        }
        /*$coreTemplatePath = GLPI_ROOT . '/templates';

        $loader = new FilesystemLoader([
            $pluginTemplatePath,
            $coreTemplatePath,
        ]);*/
        /*
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
        */
        $twig = TemplateRenderer::getInstance();
        $twig->getEnvironment()->enableAutoReload();

        $fields = array_merge($fields, $service);
        $changes = array_merge($changes, $message);
        $itemtypes = array_merge($itemtypes, $type);
        $changes = array_filter($changes, function ($row_value) use ($combinedArrayPaged) {
            foreach ($combinedArrayPaged as $log_row) {
                if (isset($log_row['change']) && $row_value == $log_row['change']) {
                    return true;
                }
            }
            return false;
        });

        $translations_map = [];

        foreach ($combinedArray as $row) {
            if ($row['source'] === 'log') {
                $filter = $row['filter'] ?? 'unknown';
                $change = $row['change'] ?? '';
                if ($change) {
                    $translations_map[$change] = $filter;
                }
            } elseif ($row['source'] === 'events') {
                $filter = $row['message']['filter'] ?? null;
                $msg = $row['message']['translation'] ?? null;
                if ($msg && $filter) {
                    $translations_map[$msg] = $filter;
                }
            }
        }

        $changes = array_keys($translations_map);

        echo $twig->render('@accesstransparency/pages/access.html.twig', [
            'userId'            => $userid,
            'friendlyName'      => $friendlyName,
            'combined'          => $combinedArrayPaged,
            'itemtypes'         => $itemtypes,
            'fields'            => $fields,
            'changes'           => $changes,
            'filters'           => $filters,
            'isFiltered'        => $is_filtered,
            'showfilters'       => $showfilters,
            'total_number'      => $total_number,
            'limit'             => $limit,
            'start'             => $start,
            'href'              => $href,
            'additional_params' => $additional_params,
            'is_tab'            => true,
            'glpi_version'      => $glpiVersion,
            'translations_map'  => $translations_map,
        ]);
        return true;
    }
}
