<?php

namespace GlpiPlugin\Duplicate;

use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class DuplicateChecker
{
    public static function getAssetTypes(): array
    {
        return [
            'Computer'         => ['table' => 'glpi_computers',         'has_uuid' => true],
            'Phone'            => ['table' => 'glpi_phones',            'has_uuid' => false],
            'Printer'          => ['table' => 'glpi_printers',          'has_uuid' => false],
            'Monitor'          => ['table' => 'glpi_monitors',          'has_uuid' => false],
            'NetworkEquipment' => ['table' => 'glpi_networkequipments', 'has_uuid' => true],
            'Peripheral'       => ['table' => 'glpi_peripherals',       'has_uuid' => false],
        ];
    }

    public static function getComparableFields(string $itemtype): array
    {
        $fields = [
            ['field' => 'name',             'label' => __('Name',             'duplicate'), 'type' => 'text'],
            ['field' => 'serial',           'label' => __('Serial',           'duplicate'), 'type' => 'text'],
            ['field' => 'otherserial',      'label' => __('Inventory Number', 'duplicate'), 'type' => 'text'],
            ['field' => 'states_id',        'label' => __('Status',           'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_states'],
            ['field' => 'locations_id',     'label' => __('Location',         'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_locations'],
            ['field' => 'manufacturers_id', 'label' => __('Manufacturer',     'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_manufacturers'],
            ['field' => 'users_id',         'label' => __('User',             'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_users'],
            ['field' => 'users_id_tech',    'label' => __('Technician',       'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_users'],
            ['field' => 'groups_id_tech',   'label' => __('Tech Group',       'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_groups'],
            ['field' => 'contact',          'label' => __('Contact',          'duplicate'), 'type' => 'text'],
            ['field' => 'contact_num',      'label' => __('Contact Number',   'duplicate'), 'type' => 'text'],
            ['field' => 'comment',          'label' => __('Comment',          'duplicate'), 'type' => 'text'],
            ['field' => 'date_creation',    'label' => __('Date Created',     'duplicate'), 'type' => 'readonly'],
            ['field' => 'date_mod',         'label' => __('Last Modified',    'duplicate'), 'type' => 'readonly'],
        ];

        $typeSpecific = match ($itemtype) {
            'Computer'         => [
                ['field' => 'computertypes_id',         'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_computertypes'],
                ['field' => 'computermodels_id',        'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_computermodels'],
            ],
            'Monitor'          => [
                ['field' => 'monitortypes_id',          'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_monitortypes'],
                ['field' => 'monitormodels_id',         'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_monitormodels'],
            ],
            'Phone'            => [
                ['field' => 'phonetypes_id',            'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_phonetypes'],
                ['field' => 'phonemodels_id',           'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_phonemodels'],
            ],
            'Printer'          => [
                ['field' => 'printertypes_id',          'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_printertypes'],
                ['field' => 'printermodels_id',         'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_printermodels'],
            ],
            'NetworkEquipment' => [
                ['field' => 'networkequipmenttypes_id',  'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_networkequipmenttypes'],
                ['field' => 'networkequipmentmodels_id', 'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_networkequipmentmodels'],
            ],
            'Peripheral'       => [
                ['field' => 'peripheraltypes_id',       'label' => __('Type',  'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_peripheraltypes'],
                ['field' => 'peripheralmodels_id',      'label' => __('Model', 'duplicate'), 'type' => 'fk', 'fk_table' => 'glpi_peripheralmodels'],
            ],
            default => [],
        };

        // Insert type+model after manufacturers_id
        $insertAt = array_search('manufacturers_id', array_column($fields, 'field')) + 1;
        array_splice($fields, $insertAt, 0, $typeSpecific);

        $types = self::getAssetTypes();
        if (isset($types[$itemtype]) && $types[$itemtype]['has_uuid']) {
            array_splice($fields, 2, 0, [
                ['field' => 'uuid', 'label' => __('UUID', 'duplicate'), 'type' => 'text'],
            ]);
        }

        return $fields;
    }

    private const FK_WITH_COMPLETENAME = ['glpi_locations', 'glpi_groups', 'glpi_entities'];

    private static function getFkDisplayValue(string $fk_table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        global $DB;

        if ($fk_table === 'glpi_users') {
            $row = $DB->request([
                'SELECT' => ['name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ])->current();
            if (!$row) {
                return "ID: $id";
            }
            $full = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            return $full ?: ($row['name'] ?? "ID: $id");
        }

        $select = in_array($fk_table, self::FK_WITH_COMPLETENAME, true)
            ? ['name', 'completename']
            : ['name'];

        $row = $DB->request([
            'SELECT' => $select,
            'FROM'   => $fk_table,
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        if (!$row) {
            return "ID: $id";
        }

        return (string) ($row['completename'] ?? $row['name'] ?? "ID: $id");
    }

    public static function getFkDisplayBatch(string $fk_table, array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $result = [];
        if ($fk_table === 'glpi_users') {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]]) as $row) {
                $full = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                $result[(int) $row['id']] = $full ?: ($row['name'] ?? "ID: {$row['id']}");
            }
        } else {
            $select = in_array($fk_table, self::FK_WITH_COMPLETENAME, true)
                ? ['id', 'name', 'completename']
                : ['id', 'name'];
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $fk_table, 'WHERE' => ['id' => $ids]]) as $row) {
                $result[(int) $row['id']] = (string) ($row['completename'] ?? $row['name'] ?? "ID: {$row['id']}");
            }
        }
        return $result;
    }

    public static function getFieldDisplayValue(array $fieldDef, $value, array $fk_cache = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($fieldDef['type'] === 'fk') {
            $id    = (int) $value;
            $table = $fieldDef['fk_table'];
            if ($id > 0 && isset($fk_cache[$table][$id])) {
                return $fk_cache[$table][$id];
            }
            return self::getFkDisplayValue($table, $id);
        }

        return (string) $value;
    }

    public static function isAgentManaged(string $itemtype, int $id): bool
    {
        global $DB;

        if (!$DB->tableExists('glpi_agents')) {
            return false;
        }

        $count = $DB->request([
            'COUNT' => 'id',
            'FROM'  => 'glpi_agents',
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id],
        ])->current()['id'] ?? 0;

        return $count > 0;
    }

    public static function getFrontUrl(string $itemtype, int $id): string
    {
        global $CFG_GLPI;

        $map = [
            'Computer'         => '/front/computer.form.php',
            'Phone'            => '/front/phone.form.php',
            'Printer'          => '/front/printer.form.php',
            'Monitor'          => '/front/monitor.form.php',
            'NetworkEquipment' => '/front/networkequipment.form.php',
            'Peripheral'       => '/front/peripheral.form.php',
        ];

        $path = $map[$itemtype] ?? '';
        return $CFG_GLPI['root_doc'] . $path . '?id=' . $id;
    }

    public static function getItemData(string $itemtype, int $id): array
    {
        global $DB;

        $types = self::getAssetTypes();
        if (!isset($types[$itemtype])) {
            return [];
        }

        $row = $DB->request([
            'FROM'  => $types[$itemtype]['table'],
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        return $row ?: [];
    }

    public static function getItemsBatch(string $itemtype, array $ids): array
    {
        global $DB;
        $types = self::getAssetTypes();
        if (!isset($types[$itemtype]) || empty($ids)) {
            return [];
        }
        $result = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => $types[$itemtype]['table'],
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    public static function getAgentManagedBatch(string $itemtype, array $ids): array
    {
        global $DB;
        if (empty($ids) || !$DB->tableExists('glpi_agents')) {
            return array_fill_keys($ids, false);
        }
        $managed = array_fill_keys($ids, false);
        foreach ($DB->request([
            'SELECT' => ['items_id'],
            'FROM'   => 'glpi_agents',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids],
        ]) as $row) {
            $managed[(int) $row['items_id']] = true;
        }
        return $managed;
    }

    /**
     * Find all duplicate pairs for a given itemtype.
     */
    public static function findDuplicates(string $itemtype): array
    {
        global $DB;

        $types = self::getAssetTypes();
        if (!isset($types[$itemtype])) {
            return [];
        }

        $table    = $types[$itemtype]['table'];
        $has_uuid = $types[$itemtype]['has_uuid'];
        $pairs      = [];
        $seen_pairs = [];

        $ignored = [];
        foreach ($DB->request(['SELECT' => ['items_id_a', 'items_id_b', 'match_reason'], 'FROM' => 'glpi_plugin_duplicate_ignored', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $ignored[$row['items_id_a'] . '-' . $row['items_id_b'] . '-' . $row['match_reason']] = true;
        }

        $candidates = [
            ['reason' => 'serial',      'field' => 'serial',      'skip_empty' => true,  'skip_values' => []],
            ['reason' => 'otherserial', 'field' => 'otherserial', 'skip_empty' => true,  'skip_values' => ['No Asset Information', 'Chassis Asset Tag', 'To be filled by O.E.M']],
            ['reason' => 'name',        'field' => 'name',        'skip_empty' => false, 'skip_values' => ['PF-'], 'skip_types' => ['Monitor', 'Peripheral']],
        ];
        if ($has_uuid) {
            $candidates[] = ['reason' => 'uuid', 'field' => 'uuid', 'skip_empty' => true];
        }

        $checks = array_filter($candidates, fn($c) => $DB->fieldExists($table, $c['field']) && !in_array($itemtype, $c['skip_types'] ?? [], true));

        if (empty($checks)) {
            return [];
        }

        $select   = array_merge(['id'], array_column($checks, 'field'));
        $iterator = $DB->request([
            'SELECT' => array_unique($select),
            'FROM'   => $table,
            'WHERE'  => ['is_deleted' => 0],
        ]);

        $groups = [];
        foreach ($iterator as $row) {
            foreach ($checks as $c) {
                $val = (string) ($row[$c['field']] ?? '');
                if ($c['skip_empty'] && $val === '') {
                    continue;
                }
                if (!empty($c['skip_values']) && in_array($val, $c['skip_values'], true)) {
                    continue;
                }
                $groups[$c['reason']][$val][] = (int) $row['id'];
            }
        }

        foreach ($groups as $reason => $value_map) {
            foreach ($value_map as $match_value => $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                $n = count($ids);
                for ($i = 0; $i < $n - 1; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $id_a = min($ids[$i], $ids[$j]);
                        $id_b = max($ids[$i], $ids[$j]);
                        $key      = "{$id_a}-{$id_b}-{$reason}";
                        $pair_key = "{$id_a}-{$id_b}";

                        if (isset($seen_pairs[$pair_key]) || isset($ignored[$key])) {
                            continue;
                        }

                        $seen_pairs[$pair_key] = true;
                        $pairs[$key] = [
                            'itemtype'    => $itemtype,
                            'ids'         => [$id_a, $id_b],
                            'reason'      => $reason,
                            'match_value' => $match_value,
                        ];
                    }
                }
            }
        }
        unset($groups, $ignored);

        return array_values($pairs);
    }

    public static function ignorePair(string $itemtype, int $id_a, int $id_b, string $reason): bool
    {
        global $DB;

        $min_id = min($id_a, $id_b);
        $max_id = max($id_a, $id_b);

        $exists = $DB->request([
            'COUNT' => 'id',
            'FROM'  => 'glpi_plugin_duplicate_ignored',
            'WHERE' => ['itemtype' => $itemtype, 'items_id_a' => $min_id, 'items_id_b' => $max_id, 'match_reason' => $reason],
        ])->current()['id'] ?? 0;

        if ($exists) {
            return true;
        }

        $DB->insert('glpi_plugin_duplicate_ignored', [
            'itemtype'      => $itemtype,
            'items_id_a'    => $min_id,
            'items_id_b'    => $max_id,
            'match_reason'  => $reason,
            'ignored_by'    => (int) Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public static function keepOne(string $itemtype, int $winner_id, array $loser_ids): array
    {
        return self::merge($itemtype, $winner_id, $loser_ids, []);
    }

    // -------------------------------------------------------------------------
    // Linked-table config & discovery
    // -------------------------------------------------------------------------

    public static function getNotepadTable(): string
    {
        return class_exists(\Notepad::class) ? \Notepad::getTable() : 'glpi_notepad';
    }

    /**
     * Full config for all GLPI core linked tables.
     *
     * fk_dedup    — many rows per item, each row links item to another entity via FK;
     *               duplicate FK values across winner/loser are collapsed to one.
     *               Keys: fk (column), target (FK target table), label_col (display column),
     *               tab_label (human name shown in UI).
     *
     * repoint_all — many rows per item, no FK dedup; items_id is simply re-pointed.
     *               Keys: tab_label, silent (true = auto-migrate, don't show in per-record UI).
     *
     * one_per_item — at most one row per item; user chooses which record to keep.
     *               Keys: tab_label.
     */
    private static function getCoreLinkedTableConfig(): array
    {
        return [
            'fk_dedup' => [
                'glpi_items_tickets' => [
                    'fk'        => 'tickets_id',
                    'target'    => 'glpi_tickets',
                    'label_col' => 'name',
                    'tab_label' => 'Tickets',
                ],
                'glpi_documents_items' => [
                    'fk'        => 'documents_id',
                    'target'    => 'glpi_documents',
                    'label_col' => 'name',
                    'tab_label' => 'Documents',
                ],
                'glpi_contracts_items' => [
                    'fk'        => 'contracts_id',
                    'target'    => 'glpi_contracts',
                    'label_col' => 'name',
                    'tab_label' => 'Contracts',
                ],
                'glpi_items_problems' => [
                    'fk'        => 'problems_id',
                    'target'    => 'glpi_problems',
                    'label_col' => 'name',
                    'tab_label' => 'Problems',
                ],
                'glpi_items_changes' => [
                    'fk'        => 'changes_id',
                    'target'    => 'glpi_changes',
                    'label_col' => 'name',
                    'tab_label' => 'Changes',
                ],
                'glpi_certificates_items' => [
                    'fk'        => 'certificates_id',
                    'target'    => 'glpi_certificates',
                    'label_col' => 'name',
                    'tab_label' => 'Certificates',
                ],
                'glpi_domains_items' => [
                    'fk'        => 'domains_id',
                    'target'    => 'glpi_domains',
                    'label_col' => 'name',
                    'tab_label' => 'Domains',
                ],
                'glpi_appliances_items' => [
                    'fk'        => 'appliances_id',
                    'target'    => 'glpi_appliances',
                    'label_col' => 'name',
                    'tab_label' => 'Appliances',
                ],
                'glpi_knowbaseitems_items' => [
                    'fk'        => 'knowbaseitems_id',
                    'target'    => 'glpi_knowbaseitems',
                    'label_col' => 'name',
                    'tab_label' => 'Knowledge Base',
                ],
                'glpi_items_softwareversions' => [
                    'fk'        => 'softwareversions_id',
                    'target'    => 'glpi_softwareversions',
                    'label_col' => 'name',
                    'tab_label' => 'Software',
                ],
            ],
            'repoint_all' => [
                'glpi_logs'         => ['tab_label' => 'History',       'silent' => true],
                self::getNotepadTable() => ['tab_label' => 'Notes',         'silent' => false],
                'glpi_networkports' => ['tab_label' => 'Network Ports', 'silent' => false],
            ],
            'one_per_item' => [
                'glpi_infocoms' => ['tab_label' => 'Financial Information (Management)'],
            ],
        ];
    }

    /**
     * Discover glpi_plugin_* tables linking items via itemtype + items_id.
     * Returns [table_name => 'one_per_item'|'repoint_all'].
     * Result is cached per request.
     */
    private static function discoverPluginLinkedTables(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        global $DB;

        $candidates = [];
        foreach ($DB->request([
            'SELECT' => ['TABLE_NAME'],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => ['LIKE', 'glpi_plugin_%'],
            ],
        ]) as $row) {
            $table = $row['TABLE_NAME'];
            if (str_starts_with($table, 'glpi_plugin_duplicate_')) {
                continue;
            }
            if ($DB->fieldExists($table, 'itemtype') && $DB->fieldExists($table, 'items_id')) {
                $candidates[] = $table;
            }
        }

        if (empty($candidates)) {
            return $cache = [];
        }

        // Detect unique index on (itemtype, items_id) → classify as one_per_item
        $unique_index_cols = [];
        foreach ($DB->request([
            'SELECT' => ['TABLE_NAME', 'INDEX_NAME', 'COLUMN_NAME'],
            'FROM'   => 'information_schema.STATISTICS',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => $candidates,
                'NON_UNIQUE'   => 0,
                'COLUMN_NAME'  => ['itemtype', 'items_id'],
            ],
        ]) as $row) {
            $unique_index_cols[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
        }

        $one_per_item = [];
        foreach ($unique_index_cols as $table => $indexes) {
            foreach ($indexes as $cols) {
                if (in_array('itemtype', $cols, true) && in_array('items_id', $cols, true)) {
                    $one_per_item[] = $table;
                    break;
                }
            }
        }

        $result = [];
        foreach ($candidates as $table) {
            $result[$table] = in_array($table, $one_per_item, true) ? 'one_per_item' : 'repoint_all';
        }

        return $cache = $result;
    }

    /**
     * Return all one-per-item tables (core + discovered plugins) with display labels.
     */
    public static function getOnePerItemTables(): array
    {
        $config = self::getCoreLinkedTableConfig();
        $tables = [];

        foreach ($config['one_per_item'] as $table => $cfg) {
            $tables[$table] = $cfg['tab_label'];
        }

        foreach (self::discoverPluginLinkedTables() as $table => $strategy) {
            if ($strategy === 'one_per_item') {
                $tables[$table] = self::formatTableLabel($table);
            }
        }

        return $tables;
    }

    private static function formatTableLabel(string $table): string
    {
        $name = preg_replace('/^glpi_(?:plugin_)?/', '', $table);
        return ucwords(str_replace('_', ' ', $name));
    }

    // -------------------------------------------------------------------------
    // Per-record linked tab data (for compare.php)
    // -------------------------------------------------------------------------

    /**
     * Build per-record data for every linked table that has data in either item.
     * Excludes one_per_item tables (handled separately) and silent tables (glpi_logs).
     *
     * Returns:
     *   [table_name => [
     *     'label'    => string,
     *     'strategy' => 'fk_dedup'|'repoint_all',
     *     'rows'     => [
     *       ['id_a' => int|null, 'id_b' => int|null, 'origin' => 'a'|'b'|'both', 'label' => string],
     *       ...
     *     ]
     *   ]]
     *
     * origin='a'    — only on item A (winner if winner=A, else loser)
     * origin='b'    — only on item B
     * origin='both' — exists on both; winner's copy survives, loser's is deleted automatically
     */
    public static function getLinkedTabData(string $itemtype, int $id_a, int $id_b): array
    {
        $config = self::getCoreLinkedTableConfig();
        $plugin = self::discoverPluginLinkedTables();
        $result = [];

        foreach ($config['fk_dedup'] as $table => $cfg) {
            $rows = self::buildFkDedupRows(
                $table, $cfg['fk'], $cfg['target'], $cfg['label_col'],
                $itemtype, $id_a, $id_b
            );
            if (!empty($rows)) {
                $result[$table] = ['label' => $cfg['tab_label'], 'strategy' => 'fk_dedup', 'rows' => $rows];
            }
        }

        foreach ($config['repoint_all'] as $table => $cfg) {
            if (!empty($cfg['silent'])) {
                continue;
            }
            $rows = self::buildRepointRows($table, $itemtype, $id_a, $id_b);
            if (!empty($rows)) {
                $result[$table] = ['label' => $cfg['tab_label'], 'strategy' => 'repoint_all', 'rows' => $rows];
            }
        }

        foreach ($plugin as $table => $strategy) {
            if ($strategy !== 'repoint_all') {
                continue;
            }
            $rows = self::buildRepointRows($table, $itemtype, $id_a, $id_b);
            if (!empty($rows)) {
                $result[$table] = ['label' => self::formatTableLabel($table), 'strategy' => 'repoint_all', 'rows' => $rows];
            }
        }

        return $result;
    }

    /**
     * Build rows for an fk_dedup table.
     * Records sharing the same FK value in both items produce a single origin='both' row.
     */
    private static function buildFkDedupRows(
        string $table,
        string $fk_col,
        string $target_table,
        string $label_col,
        string $itemtype,
        int    $id_a,
        int    $id_b
    ): array {
        global $DB;

        if (!$DB->tableExists($table) || !$DB->tableExists($target_table)) {
            return [];
        }

        // Load records from A: fk_val → link_row_id
        $a_recs = [];
        foreach ($DB->request([
            'SELECT' => ['id', $fk_col],
            'FROM'   => $table,
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $id_a],
        ]) as $row) {
            $a_recs[(int) $row[$fk_col]] = (int) $row['id'];
        }

        // Load records from B: fk_val → link_row_id
        $b_recs = [];
        foreach ($DB->request([
            'SELECT' => ['id', $fk_col],
            'FROM'   => $table,
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $id_b],
        ]) as $row) {
            $b_recs[(int) $row[$fk_col]] = (int) $row['id'];
        }

        if (empty($a_recs) && empty($b_recs)) {
            return [];
        }

        // Batch-load human-readable labels from FK target table
        $all_fk_ids = array_unique(array_merge(array_keys($a_recs), array_keys($b_recs)));
        $fk_labels  = [];
        foreach ($DB->request([
            'SELECT' => ['id', $label_col],
            'FROM'   => $target_table,
            'WHERE'  => ['id' => $all_fk_ids],
        ]) as $row) {
            $fk_labels[(int) $row['id']] = (string) ($row[$label_col] ?? '');
        }

        // Build unified rows
        $rows = [];
        foreach (array_unique(array_merge(array_keys($a_recs), array_keys($b_recs))) as $fk_val) {
            $in_a  = isset($a_recs[$fk_val]);
            $in_b  = isset($b_recs[$fk_val]);
            $name  = $fk_labels[$fk_val] ?? '';
            $rows[] = [
                'id_a'   => $in_a ? $a_recs[$fk_val] : null,
                'id_b'   => $in_b ? $b_recs[$fk_val] : null,
                'origin' => ($in_a && $in_b) ? 'both' : ($in_a ? 'a' : 'b'),
                'label'  => $name ? "#{$fk_val}: {$name}" : "#{$fk_val}",
            ];
        }

        return $rows;
    }

    /**
     * Build rows for a repoint_all table.
     * Every record is independent — no FK deduplication, so no origin='both'.
     */
    private static function buildRepointRows(
        string $table,
        string $itemtype,
        int    $id_a,
        int    $id_b
    ): array {
        global $DB;

        if (!$DB->tableExists($table)) {
            return [];
        }

        $rows = [];

        foreach ($DB->request([
            'FROM'  => $table,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id_a],
        ]) as $row) {
            $rows[] = [
                'id_a'   => (int) $row['id'],
                'id_b'   => null,
                'origin' => 'a',
                'label'  => self::buildRepointLabel($table, $row),
            ];
        }

        foreach ($DB->request([
            'FROM'  => $table,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $id_b],
        ]) as $row) {
            $rows[] = [
                'id_a'   => null,
                'id_b'   => (int) $row['id'],
                'origin' => 'b',
                'label'  => self::buildRepointLabel($table, $row),
            ];
        }

        return $rows;
    }

    private static function buildRepointLabel(string $table, array $row): string
    {
        switch ($table) {
            case self::getNotepadTable():
                $content = strip_tags(trim((string) ($row['content'] ?? '')));
                return 'Note: ' . (strlen($content) > 80 ? substr($content, 0, 80) . '…' : ($content ?: ''));
            case 'glpi_networkports':
                $name = $row['name'] ?? '';
                $mac  = $row['mac']  ?? '';
                return 'Port: ' . ($name ?: '#' . $row['id']) . ($mac ? " — $mac" : '');
        }

        // Generic: try name, then first non-system text column
        if (!empty($row['name'])) {
            return (string) $row['name'];
        }

        $skip = ['id', 'items_id', 'itemtype', 'entities_id', 'is_deleted',
                 'is_template', 'is_recursive', 'date_creation', 'date_mod'];
        foreach ($row as $col => $val) {
            if (in_array($col, $skip, true) || str_ends_with($col, '_id')) {
                continue;
            }
            if ($val === null || $val === '' || $val === '0' || $val === 0) {
                continue;
            }
            $str = (string) $val;
            return strlen($str) > 80 ? substr($str, 0, 80) . '…' : $str;
        }

        return 'Record #' . ($row['id'] ?? '?');
    }

    // -------------------------------------------------------------------------
    // Infocoms field definitions (for compare.php field-by-field display)
    // -------------------------------------------------------------------------

    public static function getInfocomsFields(): array
    {
        return [
            ['field' => 'order_number',      'label' => 'Order Number',       'type' => 'text'],
            ['field' => 'delivery_number',    'label' => 'Delivery Number',    'type' => 'text'],
            ['field' => 'immo_number',        'label' => 'Immo. Number',       'type' => 'text'],
            ['field' => 'value',              'label' => 'Purchase Value',     'type' => 'text'],
            ['field' => 'buy_date',           'label' => 'Purchase Date',      'type' => 'text'],
            ['field' => 'delivery_date',      'label' => 'Delivery Date',      'type' => 'text'],
            ['field' => 'use_date',           'label' => 'Startup Date',       'type' => 'text'],
            ['field' => 'warranty_date',      'label' => 'Warranty Expiry',    'type' => 'text'],
            ['field' => 'warranty_duration',  'label' => 'Warranty Duration',  'type' => 'text'],
            ['field' => 'warranty_info',      'label' => 'Warranty Contact',   'type' => 'text'],
            ['field' => 'suppliers_id',       'label' => 'Supplier',           'type' => 'fk', 'fk_table' => 'glpi_suppliers'],
            ['field' => 'sink_time',          'label' => 'Amortiz. Duration',  'type' => 'text'],
            ['field' => 'sink_coefficient',   'label' => 'Amortiz. Coeff.',    'type' => 'text'],
            ['field' => 'comment',            'label' => 'Comment',            'type' => 'text'],
            ['field' => 'decommission_date',  'label' => 'Decommission Date',  'type' => 'text'],
        ];
    }

    public static function getInfocomsFieldDisplayValue(array $fieldDef, $value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === '0000-00-00') {
            return '';
        }

        if ($fieldDef['type'] === 'fk') {
            return self::getFkDisplayValue($fieldDef['fk_table'], (int) $value);
        }

        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // One-per-item helpers (for compare.php tab-conflict section)
    // -------------------------------------------------------------------------

    public static function getLinkedRecord(string $table, string $itemtype, int $items_id): array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT' => 1,
        ])->current();

        return $row ?: [];
    }

    public static function getLinkedRecordBatch(string $table, string $itemtype, array $ids): array
    {
        global $DB;
        $result = array_fill_keys($ids, null);
        if (empty($ids)) {
            return $result;
        }
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $ids]]) as $row) {
            $result[(int) $row['items_id']] = $row;
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Merge
    // -------------------------------------------------------------------------

    /**
     * @param array $tab_choices     ['table' => 'a'|'b'] for one_per_item conflicts
     * @param array $record_choices  ['table' => ['keep_winner' => [id,...], 'keep_loser' => [id,...]]]
     *                               Only tables present in this array have explicit per-record control.
     *                               Absent tables fall back to default behaviour (migrate all with dedup).
     */
    public static function merge(
        string $itemtype,
        int    $winner_id,
        array  $loser_ids,
        array  $field_choices,
        array  $tab_choices          = [],
        array  $record_choices       = [],
        array  $infocom_field_choices = []
    ): array {
        global $DB;

        if (!Session::haveRight('plugin_duplicate_check', UPDATE)) {
            return ['success' => false, 'error' => 'Permission denied'];
        }

        $types = self::getAssetTypes();
        if (!isset($types[$itemtype])) {
            return ['success' => false, 'error' => 'Invalid itemtype'];
        }
        $table = $types[$itemtype]['table'];

        // Apply field overrides to winner
        $readonly_fields = ['date_creation', 'date_mod', 'id'];
        $allowed_fields  = array_diff(array_column(self::getComparableFields($itemtype), 'field'), $readonly_fields);
        $field_updates   = array_filter(
            $field_choices,
            fn($f) => in_array($f, $allowed_fields, true),
            ARRAY_FILTER_USE_KEY
        );
        if (!empty($field_updates)) {
            $DB->update($table, $field_updates, ['id' => $winner_id]);
        }

        // Build full linked-table lists
        $core   = self::getCoreLinkedTableConfig();
        $plugin = self::discoverPluginLinkedTables();

        $repoint_tables   = $core['repoint_all'];
        $one_per_item_tbl = array_keys($core['one_per_item']);

        foreach ($plugin as $ptable => $strategy) {
            if ($strategy === 'repoint_all') {
                $repoint_tables[$ptable] = ['silent' => false];
            } elseif ($strategy === 'one_per_item') {
                $one_per_item_tbl[] = $ptable;
            }
        }

        foreach ($loser_ids as $loser_id) {

            // ── 1. FK-dedup tables ───────────────────────────────────────────
            foreach ($core['fk_dedup'] as $link_table => $cfg) {
                $fk_col  = $cfg['fk'];
                if (!$DB->tableExists($link_table)) {
                    continue;
                }

                $choices = $record_choices[$link_table] ?? null;

                // Load winner's records: rec_id → fk_val
                $winner_recs = [];
                foreach ($DB->request([
                    'SELECT' => ['id', $fk_col],
                    'FROM'   => $link_table,
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $winner_id],
                ]) as $row) {
                    $winner_recs[(int) $row['id']] = (int) $row[$fk_col];
                }
                $winner_fk_to_id = array_flip($winner_recs);

                // Load loser's records: rec_id → fk_val
                $loser_recs = [];
                foreach ($DB->request([
                    'SELECT' => ['id', $fk_col],
                    'FROM'   => $link_table,
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $loser_id],
                ]) as $row) {
                    $loser_recs[(int) $row['id']] = (int) $row[$fk_col];
                }

                if ($choices !== null) {
                    $keep_winner = $choices['keep_winner'];
                    $keep_loser  = $choices['keep_loser'];

                    foreach ($winner_recs as $rec_id => $fk_val) {
                        if (!in_array($rec_id, $keep_winner, true)) {
                            $DB->delete($link_table, ['id' => $rec_id]);
                        }
                    }
                    foreach ($loser_recs as $rec_id => $fk_val) {
                        if (in_array($rec_id, $keep_loser, true)) {
                            $DB->update($link_table, ['items_id' => $winner_id], ['id' => $rec_id]);
                        } else {
                            $DB->delete($link_table, ['id' => $rec_id]);
                        }
                    }
                } else {
                    // Default: migrate loser's records, skip FK conflicts
                    foreach ($loser_recs as $rec_id => $fk_val) {
                        if (isset($winner_fk_to_id[$fk_val])) {
                            $DB->delete($link_table, ['id' => $rec_id]);
                        } else {
                            $DB->update($link_table, ['items_id' => $winner_id], ['id' => $rec_id]);
                        }
                    }
                }
            }

            // ── 2. Repoint-all tables ─────────────────────────────────────────
            foreach ($repoint_tables as $link_table => $cfg) {
                if (!$DB->tableExists($link_table)) {
                    continue;
                }

                if (!empty($cfg['silent'])) {
                    // Always bulk-repoint; never shown in per-record UI
                    $DB->update($link_table, ['items_id' => $winner_id],
                                ['itemtype' => $itemtype, 'items_id' => $loser_id]);
                    continue;
                }

                $choices = $record_choices[$link_table] ?? null;

                if ($choices !== null) {
                    $keep_winner = $choices['keep_winner'];
                    $keep_loser  = $choices['keep_loser'];

                    foreach ($DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => $link_table,
                        'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $winner_id],
                    ]) as $row) {
                        if (!in_array((int) $row['id'], $keep_winner, true)) {
                            $DB->delete($link_table, ['id' => $row['id']]);
                        }
                    }
                    foreach ($DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => $link_table,
                        'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $loser_id],
                    ]) as $row) {
                        if (in_array((int) $row['id'], $keep_loser, true)) {
                            $DB->update($link_table, ['items_id' => $winner_id], ['id' => $row['id']]);
                        } else {
                            $DB->delete($link_table, ['id' => $row['id']]);
                        }
                    }
                } else {
                    $DB->update($link_table, ['items_id' => $winner_id],
                                ['itemtype' => $itemtype, 'items_id' => $loser_id]);
                }
            }

            // ── 3. One-per-item tables ────────────────────────────────────────
            foreach ($one_per_item_tbl as $link_table) {
                if (!$DB->tableExists($link_table)) {
                    continue;
                }

                $winner_has = (($DB->request([
                    'COUNT' => 'id',
                    'FROM'  => $link_table,
                    'WHERE' => ['itemtype' => $itemtype, 'items_id' => $winner_id],
                ])->current()['id'] ?? 0) > 0);

                $loser_has = (($DB->request([
                    'COUNT' => 'id',
                    'FROM'  => $link_table,
                    'WHERE' => ['itemtype' => $itemtype, 'items_id' => $loser_id],
                ])->current()['id'] ?? 0) > 0);

                if (!$loser_has) {
                    continue;
                }

                if (!$winner_has) {
                    $DB->update($link_table, ['items_id' => $winner_id],
                                ['itemtype' => $itemtype, 'items_id' => $loser_id]);
                    continue;
                }

                // Both have a record — honour user choice (default: keep winner = 'a')
                $choice = $tab_choices[$link_table] ?? 'a';
                if ($choice === 'b') {
                    $DB->delete($link_table, ['itemtype' => $itemtype, 'items_id' => $winner_id]);
                    $DB->update($link_table, ['items_id' => $winner_id],
                                ['itemtype' => $itemtype, 'items_id' => $loser_id]);
                } else {
                    $DB->delete($link_table, ['itemtype' => $itemtype, 'items_id' => $loser_id]);
                }
            }

            // ── 4. Soft-delete loser ──────────────────────────────────────────
            $DB->update($table, ['is_deleted' => 1], ['id' => $loser_id]);

            // ── 5. Clean up ignored-pair records ─────────────────────────────
            $min_id = min($winner_id, $loser_id);
            $max_id = max($winner_id, $loser_id);
            $DB->delete('glpi_plugin_duplicate_ignored', [
                'itemtype'   => $itemtype,
                'items_id_a' => $min_id,
                'items_id_b' => $max_id,
            ]);
        }

        // ── Apply infocom field-level overrides ───────────────────────────────
        if (!empty($infocom_field_choices) && $DB->tableExists('glpi_infocoms')) {
            $allowed = array_column(self::getInfocomsFields(), 'field');
            $updates = array_filter(
                $infocom_field_choices,
                fn($f) => in_array($f, $allowed, true),
                ARRAY_FILTER_USE_KEY
            );
            if (!empty($updates)) {
                $DB->update('glpi_infocoms', $updates, [
                    'itemtype' => $itemtype,
                    'items_id' => $winner_id,
                ]);
            }
        }

        return ['success' => true];
    }
}
