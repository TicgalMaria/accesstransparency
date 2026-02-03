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

use Glpi\Csv\CsvResponse;
use Glpi\Csv\ExportToCsvInterface;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Search\SearchOption;
use Glpi\RichText\RichText;

class PluginAccesstransparencyUser extends CommonDBTM
{
    public static $rightname = 'plugin_accesstransparency_view';
    private static $userCache = [];
    private static $translationCache = [];
    private static $followingTranslationCache = [];

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0): string
    {
        return 'Access Transparency';
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
        if ($item::getType() === User::getType() && Session::haveRight(self::$rightname, READ)) {

            if (isset($_GET['filters'])) {
                $_SESSION['accesstransparency']['filters'] = $_GET['filters'];
            }

            if (isset($_GET['clear_filters'])) {
                unset($_SESSION['accesstransparency']['filters']);
            }

            /** @var User $user */
            $user = $item;

            Session::checkLoginUser();
            $_SESSION['glpicsrf_token'] = Session::getNewCSRFToken();

            $result = self::arrayData($user);
            $number = $result['count'];
            return self::createTabEntry(self::getTypeName(1), $number);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item::getType() === User::getType() && Session::haveRight(self::$rightname, READ)) {
            return self::showFormUser($item);
        }
        return false;
    }

    public static function arrayData(User $user, array $filters = [], int $start = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $userid    = $user->getID();
        $login     = $user->fields['name'];
        $surName   = $user->fields['realname'];
        $firstName = $user->fields['firstname'];
        $table     = PluginAccesstransparencyUserinteractions::getTable();
        $limit     = $_SESSION['glpilist_limit'] ?? 20;
        $list      = [];

        $logConditions   = [];
        $eventConditions = [];

        foreach ([$login, $firstName, $surName] as $name) {
            if (!empty($name)) {
                $logConditions[]   = "`user_name` LIKE '%$name%($userid)%'";
                $eventConditions[] = "`message` LIKE '%$name%'";
            }
        }

        $logWhere         = !empty($logConditions) ? '(' . implode(' OR ', $logConditions) . ')' : '';
        $eventWhere       = !empty($eventConditions) ? '(' . implode(' OR ', $eventConditions) . ')' : '';
        $interactionWhere = "`users_id` = $userid";

        if (!empty($filters['date'])) {
            $date = date('Y-m-d', strtotime($filters['date']));

            if (!empty($logWhere)) {
                $logWhere = "($logWhere) AND `date_mod` LIKE '$date%'";
            } else {
                $logWhere = "`date_mod` LIKE '$date%'";
            }

            if (!empty($eventWhere)) {
                $eventWhere = "($eventWhere) AND `date` LIKE '$date%'";
            } else {
                $eventWhere = "`date` LIKE '$date%'";
            }

            $interactionWhere .= " AND DATE(`date_creation`) = '$date'";
        }

        $includeInteraction = false;

        if (!empty($filters['itemtype'])) {
            $itemtypes = array_map('strtolower', (array)$filters['itemtype']);

            if (in_array('__interaction__', $itemtypes)) {
                $includeInteraction = true;
            }

            if (!empty($logWhere)) {
                $logWhere = "($logWhere) AND LOWER(`itemtype`) IN ('" . implode("','", $itemtypes) . "')";
            } else {
                $logWhere = "LOWER(`itemtype`) IN ('" . implode("','", $itemtypes) . "')";
            }

            if (!empty($eventWhere)) {
                $eventWhere = "($eventWhere) AND LOWER(`type`) IN ('" . implode("','", $itemtypes) . "')";
            } else {
                $eventWhere = "LOWER(`type`) IN ('" . implode("','", $itemtypes) . "')";
            }
        }

        if (!empty($filters['field'])) {
            $fields = array_map(function ($v) {
                return strtolower($v);
            }, (array)$filters['field']);

            if (!empty($logWhere)) {
                $logWhere = "($logWhere) AND LOWER(`id_search_option`) IN ('" . implode("','", $fields) . "')";
            } else {
                $logWhere = "LOWER(`id_search_option`) IN ('" . implode("','", $fields) . "')";
            }

            if (!empty($eventWhere)) {
                $eventWhere = "($eventWhere) AND LOWER(`service`) IN ('" . implode("','", $fields) . "')";
            } else {
                $eventWhere = "LOWER(`service`) IN ('" . implode("','", $fields) . "')";
            }
        }

        $logWhere   = !empty($logWhere) ? "WHERE $logWhere" : '';
        $eventWhere = !empty($eventWhere) ? "WHERE $eventWhere" : '';
        if (!$includeInteraction) {
            $interactionWhere = '';
        } else {
            $interactionWhere = !empty($interactionWhere) ? "WHERE $interactionWhere" : '';
        }

        $sql = "
        (SELECT id, itemtype, items_id, user_name AS name, date_mod AS date, 'log' AS source
        FROM glpi_logs $logWhere)
        UNION ALL
        (SELECT id, type AS itemtype, message AS items_id, service AS name, date, 'events' AS source
        FROM glpi_events $eventWhere)
        " . ($includeInteraction ? "
        UNION ALL
        (SELECT id AS id_doc, 'File open' AS itemtype, documents_id AS items_id, '' AS name, date_creation AS date, 'interaction' AS source
        FROM `$table` $interactionWhere)
        " : "") . "
        ORDER BY date DESC
        LIMIT $start, $limit
        ";

        $iterator = $DB->doQuery($sql);
        while ($row = $iterator->fetch_assoc()) {
            $data = [
                'id'       => $row['id'] ?? $row['id_doc'],
                'date'     => date('Y-m-d H:i', strtotime($row['date'])),
                'itemtype' => $row['itemtype'],
                'items_id' => $row['items_id'],
                'field'    => '',
                'change'   => '',
                'source'   => $row['source'],
            ];

            if ($row['source'] === 'log' && class_exists($row['itemtype']) && method_exists($row['itemtype'], 'getById')) {
                if ($item = $row['itemtype']::getById($row['items_id'])) {
                    $data['url']    = $item->getLinkURL();
                    $loginfo        = self::getHistory($item, $row['id']);
                    $data['field']  = $loginfo['field'];
                    $data['change'] = $loginfo['change'];
                }
            } elseif ($row['source'] === 'events') {
                $data['field']  = $row['name'];
                $data['change'] = $row['items_id'];
            } elseif ($row['source'] === 'interaction') {
                $data['change'] = __('Document') . ': ' . Document::getFriendlyNameById($row['items_id']);
            }

            $list[] = $data;
        }

        $logsTotal         = $DB->doQuery("SELECT COUNT(*) AS total FROM glpi_logs $logWhere")->fetch_assoc()['total'] ?? 0;
        $eventsTotal       = $DB->doQuery("SELECT COUNT(*) AS total FROM glpi_events $eventWhere")->fetch_assoc()['total'] ?? 0;
        $interactionsTotal = $DB->doQuery("SELECT COUNT(*) AS total FROM `$table` $interactionWhere")->fetch_assoc()['total'] ?? 0;
        $count = $logsTotal + $eventsTotal + $interactionsTotal;

        $sql_filters = "
        (SELECT DISTINCT itemtype, id_search_option, 'log' AS source FROM glpi_logs $logWhere)
        UNION ALL
        (SELECT DISTINCT type AS itemtype, service AS items_id, 'events' AS source FROM glpi_events $eventWhere)
        UNION ALL
        (SELECT DISTINCT 'File open' AS itemtype, NULL AS items_id, 'interaction' AS source FROM `$table` $interactionWhere)";

        $iterator2 = $DB->doQuery($sql_filters);

        $itemtypes = [];
        $fields    = [];
        $events    = [];

        while ($row = $iterator2->fetch_assoc()) {
            $itemtype = $row['itemtype'] ??  null;
            if (empty($itemtype)) {
                continue;
            }

            if ($itemtype !== 'Plugin' && str_starts_with($itemtype, 'Plugin')) {
                continue;
            }
            $itemtypes[$itemtype] = $itemtype;

            switch ($row['source']) {
                case 'events':
                    $id = $row['id_search_option'] ?? null;
                    if (!empty($id)) {
                        $events[$id] = true;
                    }
                    break;
                case 'log':
                    $searchId = $row['id_search_option'] ?? null;
                    if (is_numeric($searchId)) {
                        $searchOptions = Search::getOptions($itemtype);
                        if (!empty($searchOptions[$searchId]['name'])) {
                            $fields[$itemtype][$searchId] = __($searchOptions[$searchId]['name']);
                        }
                    }
                    break;
            }
        }

        $events = array_keys($events);

        $configIterator  = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => ['name' => 'language'],
        ]);
        $defaultLanguage = $configIterator->current()['value'] ?? '';
        $values          = [];
        $languageIterator = $DB->request([
            'SELECT' => ['old_value', 'new_value'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => ['id_search_option' => 17, 'itemtype' => 'User', 'items_id' => $userid],
        ]);
        foreach ($languageIterator as $log) {
            if (!empty($log['old_value'])) $values[$log['old_value']] = true;
            if (!empty($log['new_value'])) $values[$log['new_value']] = true;
        }
        $allLanguages = array_keys($values);
        if (!empty($defaultLanguage) && !in_array($defaultLanguage, $allLanguages, true)) {
            array_unshift($allLanguages, $defaultLanguage);
        }

        return [
            'allLanguages' => $allLanguages,
            'mergedArrays' => $list,
            'itemtypes'    => $itemtypes,
            'fields'       => $fields,
            'count'        => $count,
            'events'       => $events,
        ];
    }

    public static function applyFilters(array $data, array $filters): array
    {
        $filters = array_values($filters);

        return array_filter($data, function ($row) use ($filters) {
            if (empty($filters)) {
                return true;
            }

            $rowValue = null;

            if ($row['source'] === 'log') {
                $rowValue = $row['filter'] ?? null;
            } elseif ($row['source'] === 'events') {
                $rowValue = $row['filter'] ?? null;
            }

            if ($rowValue === null) {
                return false;
            }

            $valuesToCheck = is_array($rowValue) ? $rowValue : [$rowValue];

            foreach ($valuesToCheck as $v) {
                foreach ($filters as $f) {
                    if (stripos($v, $f) !== false) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    public static function getHistory($item, int $logId): array
    {
        $DBread = DBConnection::getReadConnection();
        $itemtable = $item->getTable();
        $SEARCHOPTION = SearchOption::getOptionsForItemtype($item::getType());

        $log = new Log();
        $tmp = [];

        $tmp['field']           = "";
        $tmp['change']          = "";
        $tmp['datatype']        = "";

        if ($log->getFromDB($logId)) {

            // This is an internal device ?
            if ($log->fields["linked_action"]) {
                $action_label = Log::getLinkedActionLabel($log->fields["linked_action"]);

                // Yes it is an internal device
                switch ($log->fields["linked_action"]) {
                    case Log::HISTORY_CREATE_ITEM:
                    case Log::HISTORY_DELETE_ITEM:
                    case Log::HISTORY_LOCK_ITEM:
                    case Log::HISTORY_UNLOCK_ITEM:
                    case Log::HISTORY_RESTORE_ITEM:
                        $tmp['change'] = htmlescape($action_label);
                        break;

                    case Log::HISTORY_ADD_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the component name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );
                        break;

                    case Log::HISTORY_UPDATE_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        $linktype_field = explode('#', $log->fields["itemtype_link"]);
                        $linktype       = $linktype_field[0];
                        $field          = $linktype_field[1];
                        $devicetype     = $linktype::getDeviceType();
                        $tmp['field']   = $devicetype;
                        $specif_fields  = $linktype::getSpecificities();
                        if (isset($specif_fields[$field]['short name'])) {
                            $tmp['field']   = $devicetype;
                            $tmp['field']  .= " (" . $specif_fields[$field]['short name'] . ")";
                        }
                        //TRANS: %1$s is the old_value, %2$s is the new_value
                        $tmp['change']  = sprintf(
                            __s('%1$s: %2$s'),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($action_label),
                                htmlescape($tmp['field'])
                            ),
                            sprintf(
                                __s('%1$s by %2$s'),
                                htmlescape($log->fields["old_value"]),
                                htmlescape($log->fields["new_value"])
                            )
                        );
                        break;

                    case Log::HISTORY_DELETE_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the component name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_LOCK_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the component name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_UNLOCK_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the component name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );
                        break;

                    case Log::HISTORY_INSTALL_SOFTWARE:
                        $tmp['field']  = _n('Software', 'Software', 1);
                        //TRANS: %s is the software name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );
                        break;

                    case Log::HISTORY_UNINSTALL_SOFTWARE:
                        $tmp['field']  = _n('Software', 'Software', 1);
                        //TRANS: %s is the software name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_DISCONNECT_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the item name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_CONNECT_DEVICE:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            if ($item2 instanceof Item_Devices) {
                                $tmp['field'] = $item2->getDeviceTypeName(1);
                            } else {
                                $tmp['field'] = $item2->getTypeName(1);
                            }
                        }
                        //TRANS: %s is the item name
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );
                        break;

                    case Log::HISTORY_LOG_SIMPLE_MESSAGE:
                        $tmp['field']  = "";
                        $tmp['change'] = htmlescape($log->fields["new_value"]);
                        break;

                    case Log::HISTORY_ADD_RELATION:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );

                        if ($log->fields['itemtype'] == 'Ticket') {
                            /** @var CommonITILObject $item */
                            if ($log->fields['id_search_option']) { // Recent record - see CommonITILObject::getSearchOptionsActors()
                                $as = $SEARCHOPTION[$log->fields['id_search_option']]['name'];
                            } else { // Old record
                                $is = $isr = $isa = $iso = false;
                                switch ($log->fields['itemtype_link']) {
                                    case 'Group':
                                        $is = 'isGroup';
                                        break;

                                    case 'User':
                                        $is = 'isUser';
                                        break;

                                    case 'Supplier':
                                        $is = 'isSupplier';
                                        break;
                                }
                                if ($is) {
                                    $iditem = intval(substr($log->fields['new_value'], strrpos($log->fields['new_value'], '(') + 1)); // This is terrible idea
                                    $isr = $item->$is(CommonITILActor::REQUESTER, $iditem);
                                    $isa = $item->$is(CommonITILActor::ASSIGN, $iditem);
                                    $iso = $item->$is(CommonITILActor::OBSERVER, $iditem);
                                }
                                // Simple Heuristic, of course not enough
                                if ($isr && !$isa && !$iso) {
                                    $as = _n('Requester', 'Requesters', 1);
                                } elseif (!$isr && $isa && !$iso) {
                                    $as = __('Assigned to');
                                } elseif (!$isr && !$isa && $iso) {
                                    $as = _n('Observer', 'Observers', 1);
                                } else {
                                    // Deleted or Ambiguous
                                    $as = false;
                                }
                            }
                            if ($as) {
                                $tmp['change'] = sprintf(
                                    __s('%1$s: %2$s'),
                                    htmlescape($action_label),
                                    sprintf(
                                        __s('%1$s (%2$s)'),
                                        htmlescape($log->fields["new_value"]),
                                        htmlescape($as)
                                    )
                                );
                            } else {
                                $tmp['change'] = sprintf(
                                    __s('%1$s: %2$s'),
                                    htmlescape($action_label),
                                    htmlescape($log->fields["new_value"])
                                );
                            }
                        }
                        break;

                    case Log::HISTORY_UPDATE_RELATION:
                        $linktype_field = explode('#', $log->fields["itemtype_link"]);
                        $linktype     = $linktype_field[0];
                        $tmp['field'] = is_a($linktype, CommonGLPI::class, true) ? $linktype::getTypeName() : $linktype;
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($log->fields["old_value"]),
                                htmlescape($log->fields["new_value"])
                            )
                        );
                        break;

                    case Log::HISTORY_DEL_RELATION:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_LOCK_RELATION:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["old_value"])
                        );
                        break;

                    case Log::HISTORY_UNLOCK_RELATION:
                        $tmp['field'] = NOT_AVAILABLE;
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($log->fields["new_value"])
                        );
                        break;

                    case Log::HISTORY_ADD_SUBITEM:
                        $tmp['field'] = '';
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($tmp['field']),
                                htmlescape($log->fields["new_value"])
                            )
                        );

                        break;

                    case Log::HISTORY_UPDATE_SUBITEM:
                        $tmp['field'] = '';
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        if (empty($log->fields["new_value"])) {
                            $tmp['change'] = sprintf(
                                __s('%1$s: %2$s'),
                                htmlescape($action_label),
                                htmlescape($tmp['field']),
                            );
                        } else {
                            $tmp['change'] = sprintf(
                                __s('%1$s: %2$s'),
                                htmlescape($action_label),
                                sprintf(
                                    __s('%1$s (%2$s)'),
                                    htmlescape($tmp['field']),
                                    htmlescape($log->fields["new_value"])
                                )
                            );
                        }

                        break;

                    case Log::HISTORY_DELETE_SUBITEM:
                        $tmp['field'] = '';
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($tmp['field']),
                                htmlescape($log->fields["old_value"])
                            )
                        );
                        break;

                    case Log::HISTORY_LOCK_SUBITEM:
                        $tmp['field'] = '';
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($tmp['field']),
                                htmlescape($log->fields["old_value"])
                            )
                        );
                        break;

                    case Log::HISTORY_UNLOCK_SUBITEM:
                        $tmp['field'] = '';
                        if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                            $tmp['field'] = $item2->getTypeName(1);
                        }
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (%2$s)'),
                                htmlescape($tmp['field']),
                                htmlescape($log->fields["new_value"])
                            )
                        );
                        break;

                    case Log::HISTORY_SEND_WEBHOOK:
                        $tmp['change'] = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            sprintf(
                                __s('%1$s (Status %2$s -> %3$s)'),
                                htmlescape($log->fields["itemtype_link"]),
                                htmlescape($log->fields["old_value"]),
                                htmlescape($log->fields["new_value"])
                            )
                        );
                        break;

                    default:
                        $fct = [$log->fields['itemtype_link'], 'getHistoryEntry'];
                        if (
                            ($log->fields['linked_action'] >= Log::HISTORY_PLUGIN)
                            && $log->fields['itemtype_link']
                            && is_callable($fct)
                        ) {
                            $tmp['field']  = $log->fields['itemtype_link']::getTypeName(1);
                            $tmp['change'] = call_user_func($fct, $log->fields);
                        }
                        $tmp['display_history'] = !empty($tmp['change']);
                }
            } else {
                $searchopt = [];
                $tablename = '';
                // It's not an internal device
                foreach ($SEARCHOPTION as $key2 => $val2) {
                    if ($key2 === $log->fields["id_search_option"]) {
                        $tmp['field'] =  $val2["name"];
                        $tablename    =  $val2["table"];
                        $searchopt    = $val2;
                        if (isset($val2['datatype'])) {
                            $tmp['datatype'] = $val2["datatype"];
                        }
                        break;
                    }
                }
                if (
                    ($itemtable == $tablename)
                    || ($tmp['datatype'] == 'right')
                ) {
                    switch ($tmp['datatype']) {
                        // specific case for text field
                        case 'text':
                            $tmp['change'] = __s('Update of the field');
                            break;

                        default:
                            $log->fields["old_value"] = RichText::getTextFromHtml($item->getValueToDisplay($searchopt, $log->fields["old_value"]) ?? '', false, true);
                            $log->fields["new_value"] = RichText::getTextFromHtml($item->getValueToDisplay($searchopt, $log->fields["new_value"]) ?? '', false, true);
                            break;
                    }
                }

                if (empty($tmp['change'])) {
                    $newval = $log->fields["new_value"];
                    $oldval = $log->fields["old_value"];

                    if ($log->fields['id_search_option'] == '70') {
                        $newval_expl = explode(' ', $newval);
                        $oldval_expl = explode(' ', $oldval);

                        if ($oldval_expl[0] == '&nbsp;') {
                            $oldval = $log->fields["old_value"];
                        } else {
                            $old_iterator = $DBread->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $oldval_expl[0]]]);
                            foreach ($old_iterator as $val) {
                                $oldval = sprintf(
                                    __('%1$s %2$s'),
                                    formatUserName(
                                        $val['id'],
                                        $oldval_expl[0],
                                        $val['realname'],
                                        $val['firstname']
                                    ),
                                    ($oldval_expl[1] ?? "0")
                                );
                            }
                        }

                        if ($newval_expl[0] == '&nbsp;') {
                            $newval = $log->fields["new_value"];
                        } else {
                            $new_iterator = $DBread->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $newval_expl[0]]]);
                            foreach ($new_iterator as $val) {
                                $newval = sprintf(
                                    __('%1$s %2$s'),
                                    formatUserName(
                                        $val['id'],
                                        $newval_expl[0],
                                        $val['realname'],
                                        $val['firstname']
                                    ),
                                    ($newval_expl[1] ?? "0")
                                );
                            }
                        }
                    }
                    $tmp['change'] = sprintf(
                        __s('Change %1$s to %2$s'),
                        '<del>' . htmlescape($oldval) . '</del>',
                        '<ins>' . htmlescape($newval) . '</ins>'
                    );
                }
            }
        }
        return $tmp;
    }

    public static function exportData(array $data, string $friendlyName = '', string $userId = '')
    {
        $documents = [];
        foreach ($data as $row) {
            if (($row['source'] ?? '') === 'document') {
                $documents[$row['id_document']] = $row['name'] ?? '';
            }
        }

        $export = new class($data, $friendlyName, $userId, $documents) implements ExportToCsvInterface {
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
                return ['ID', 'Date', 'User', 'User_ID', 'Itemtype', 'Field', 'Changes'];
            }

            public function getFileContent(): array
            {
                $rows = [];
                foreach ($this->data as $row) {
                    $source = $row['source'] ?? '';
                    $id = $row['id'] ?? $row['id_document'] ?? '';
                    $date = isset($row['date']) ? date('Y-m-d H:i', strtotime($row['date'])) : '';
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
                        case 'interaction':
                            $interactionId = $row['id'] ?? $row['id_doc'] ?? '';
                            $docName = '';

                            if (!empty($interactionId)) {
                                global $DB;

                                $iterator = $DB->request([
                                    'SELECT' => ['*'],
                                    'FROM'   => 'glpi_plugin_accesstransparency_userinteractions',
                                    'WHERE'  => ['id' => $interactionId]
                                ]);

                                $array = iterator_to_array($iterator);
                                $path = '';

                                if (!empty($array)) {
                                    $firstRow = reset($array);
                                    $path = $firstRow['path'] ?? '';
                                    $docid = '';
                                    if (preg_match('/docid=([0-9]+)/', $path, $matches)) {
                                        $docid = (int) $matches[1];
                                        $docName = Document::getFriendlyNameById($docid);
                                    }
                                }
                            }

                            $rows[] = [
                                $interactionId,
                                $date,
                                $userName,
                                $userIdRow,
                                'File_Open',
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
            'glpi_cartridgeitems'      => 'name',
            'glpi_consumableitems'     => 'name',
            'glpi_racks'               => 'name',
            'glpi_enclosures'          => 'name',
            'glpi_pdus'                => 'name',
            'glpi_passivedcequipments' => 'name',
            'glpi_unmanageds'          => 'name',
            'glpi_cables'              => 'name',
        ];

        if ($DB->tableExists('glpi_assets_assets')) {
            $assetTables += [
                'glpi_assets_assets'       => 'name',
            ];
        }

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
                    $clean = trim(preg_replace('/\s*' . preg_quote($doc, '/') . '$/i', '', $clean));
                    break;
                }
            }

            if (!$data['element']) {
                foreach ($tickets as $ticket) {
                    if (preg_match('/\b' . preg_quote($ticket, '/') . '$/i', $clean)) {
                        $data['element'] = $ticket;
                        $data['element_type'] = 'ticket';
                        $clean = trim(preg_replace('/\s*' . preg_quote($ticket, '/') . '$/i', '', $clean));
                        break;
                    }
                }
            }

            foreach ($assets as $asset) {
                if (mb_strlen($asset) < 4) {
                    continue;
                }

                if (preg_match('/\b' . preg_quote($asset, '/') . '\b/i', $clean, $match, PREG_OFFSET_CAPTURE)) {
                    $data['asset'] = $asset;
                    $pos = $match[0][1];
                    $beforeAsset = substr($clean, 0, $pos);

                    if ($beforeAsset) {
                        preg_match('/(\S+\s+)?(\S+)$/u', trim($beforeAsset), $matches);
                        $data['action'] = $matches[0] ?? null;
                    }

                    $clean = trim(preg_replace('/\b' . preg_quote($asset, '/') . '\b/i', '', $clean));
                    $clean = trim(preg_replace('/\s+/', ' ', $clean));
                    break;
                }
            }

            $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            $restWords = array_slice($words, 1);

            foreach ($restWords as $index => $word) {
                if (preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $word) && strtoupper($word) !== 'IP') {
                    $pluginWords = [$word];
                    $wordsUsed = 1;

                    $nextIndex = $index + 1;
                    if (isset($restWords[$nextIndex]) && preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $restWords[$nextIndex])) {
                        $pluginWords[] = $restWords[$nextIndex];
                        $wordsUsed++;
                    }

                    $data['plugin'] = implode(' ', $pluginWords);

                    $realIndex = $index + $wordsUsed;
                    $remainingWords = array_slice($words, $realIndex + 1);

                    $clean = implode(' ', $remainingWords);
                    $clean = preg_replace('/\(\s*[^\)]*\s*\)/', '', $clean);
                    $clean = trim(preg_replace('/\s+/', ' ', $clean));
                    break;
                }
            }

            if (preg_match('/executes the\s+([^\s]+)/i', $clean, $actionMatch)) {
                $data['action'] = $actionMatch[1];
                $clean = trim(str_ireplace($actionMatch[0], '', $clean));
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
        $filters = $_GET['filters'] ?? [];
        $start = max(0, intval($_GET['start'] ?? 0));

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
        $result = self::arrayData($user, $filters, $start);
        $allLanguages = $result['allLanguages'] ?? [];
        $combinedArray = $result['mergedArrays'] ?? [];
        $terms = ['add', 'delete', 'update', 'purge'];
        $allTranslations = [];

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

        usort($combinedArray, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });

        $message = [];
        $todos = [];
        foreach ($combinedArray as $row) {
            if ($row['source'] === 'events') {
                $todos[] = $row['change'];
            }
        }

        $result = self::cleanMessages($todos, $nameLastname, $lastnameName, $friendlyName, $userid);

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
                } elseif (stripos($msg['longest_fragment'], 'failed') !== false || stripos($msg['longest_fragment'], 'failed') !== false) {
                    $filter = 'failed';
                } elseif (stripos($msg['longest_fragment'], 'log in') !== false || stripos($msg['longest_fragment'], 'log in') !== false) {
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
                $translation = self::editMessage($msgid, $friendlyName, $msg['asset'] ?? '');
            } else {
                $translation = self::editMessage($msgid, $friendlyName);
            }

            $allTranslations[$i] = [
                'translation' => $translation,
                'filter' => $filter,
            ];
        }

        $translationIndex = 0;
        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'events') {
                if (isset($allTranslations[$translationIndex])) {
                    $row['change'] = $allTranslations[$translationIndex]['translation'];
                    $row['filter'] = $allTranslations[$translationIndex]['filter'] ?? null;
                }
                $translationIndex++;
            }
        }
        unset($row);

        if (isset($filters['change'])) {
            var_dump($filters['change']);
            $combinedArray = self::applyFilters($combinedArray, $filters['change']);
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

        $href = Toolbox::getItemTypeSearchURL(Preference::class) . '?forcetab=PluginAccess$1';

        $twig = TemplateRenderer::getInstance();
        $twig->getEnvironment()->enableAutoReload();

        $itemtypes = [];
        $fields = [];
        $exclude = 'File Open';

        foreach ($combinedArray as &$row) {
            if ($row['source'] === 'events') {
                if (!empty($row['itemtype']) && is_string($row['itemtype'])) {
                    $row['itemtype'] = mb_strtoupper(mb_substr($row['itemtype'], 0, 1), 'UTF-8')
                        . mb_substr($row['itemtype'], 1, null, 'UTF-8');
                }
                if (!empty($row['field']) && is_string($row['field'])) {
                    $row['field'] = mb_strtoupper(mb_substr($row['field'], 0, 1), 'UTF-8')
                        . mb_substr($row['field'], 1, null, 'UTF-8');
                }
            }

            if (!empty($row['itemtype']) && is_string($row['itemtype']) && mb_strtolower($row['itemtype'], 'UTF-8') !== mb_strtolower($exclude, 'UTF-8')) {
                if (!isset($itemtypes[$row['itemtype']])) {
                    $itemtypes[$row['itemtype']] = $row['source'] === 'log' && method_exists($row['itemtype'], 'getTypeName')
                        ? $row['itemtype']::getTypeName(1)
                        : $row['itemtype'];
                }
            }

            if (!empty($row['field']) && is_string($row['field'])) {
                if (!isset($fields[$row['field']])) {
                    $fields[$row['field']] = $row['field'];
                }
            }
        }
        unset($row);

        $result = self::arrayData($user);
        $total_number = $result['count'];
        $itemtypesRaw = $result['itemtypes'];
        $fields_log = $result['fields'];
        $fields_event = $result['events'];
        $filtered_number = count($combinedArray);
        $itemtypes = [];

        foreach ($itemtypesRaw as $id => $name) {
            if ($id === '__interaction__') {
                $itemtypes[$id] = __('File open');
            } elseif ($name == 'Plugin') {
                $itemtypes[$id] = $name;
            } else {
                $itemtypes[$id] = __($name);
            }
        }

        foreach ($fields_log as $options) {
            foreach ($options as $label) {
                $allFields[] = $label;
            }
        }

        $twig->display('@accesstransparency/pages/access.html.twig', [
            'userId'            => $userid,
            'friendlyName'      => $friendlyName,
            'combined'          => $combinedArray,
            'itemtypes'         => $itemtypes,
            'fields'            => $fields_event,
            'filters'           => $filters,
            'total_number'      => $total_number,
            'start'             => $start,
            'href'              => $href,
            'is_tab'            => true,
            'filtered_number'   => $filtered_number,
        ]);
        return true;
    }
}
