<?php

use GlpiPlugin\Duplicate\DuplicateChecker;

include('../../../inc/includes.php');

// Load plugin translations
Plugin::loadLang('duplicate');

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

$action   = $_POST['action']   ?? '';
$itemtype = $_POST['itemtype'] ?? '';
$id_a     = (int) ($_POST['id_a'] ?? 0);
$id_b     = (int) ($_POST['id_b'] ?? 0);
$reason   = $_POST['reason']   ?? '';

try {
    // Validate itemtype
    $valid_types = array_keys(DuplicateChecker::getAssetTypes());
    if (!in_array($itemtype, $valid_types, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid itemtype']);
        exit;
    }

    if ($action === 'ignore') {
        if (!Session::haveRight('plugin_duplicate_check', READ)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $result = DuplicateChecker::ignorePair($itemtype, $id_a, $id_b, $reason);
        echo json_encode(['success' => $result]);
        exit;
    }

    if ($action === 'keepone') {
        if (!Session::haveRight('plugin_duplicate_check', UPDATE)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $winner_id = (int) ($_POST['winner_id'] ?? 0);
        $loser_ids = array_map('intval', (array) ($_POST['loser_ids'] ?? []));

        if (!$winner_id || empty($loser_ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing winner or loser IDs']);
            exit;
        }

        $result = DuplicateChecker::keepOne($itemtype, $winner_id, $loser_ids);
        echo json_encode($result);
        exit;
    }

    if ($action === 'merge') {
        if (!Session::haveRight('plugin_duplicate_check', UPDATE)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $winner_id = (int) ($_POST['winner_id'] ?? 0);
        $loser_ids = array_map('intval', (array) ($_POST['loser_ids'] ?? []));

        if (!$winner_id || empty($loser_ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing winner or loser IDs']);
            exit;
        }

        // Collect field choices — only for known fields
        $known_fields  = array_column(DuplicateChecker::getComparableFields($itemtype), 'field');
        $field_choices = [];
        foreach ($known_fields as $f) {
            $key = 'field_' . $f;
            if (isset($_POST[$key])) {
                $field_choices[$f] = $_POST[$key];
            }
        }

        $tab_choices = [];
        foreach (array_keys(DuplicateChecker::getOnePerItemTables()) as $tab_table) {
            $key = 'tab_' . $tab_table;
            if (isset($_POST[$key]) && in_array($_POST[$key], ['a', 'b'], true)) {
                $tab_choices[$tab_table] = $_POST[$key];
            }
        }

        // Per-record choices: tables listed in linked_shown[] have explicit keep lists.
        // linked_a_{table}[] = IDs of item-A records the user checked (keep/import)
        // linked_b_{table}[] = IDs of item-B records the user checked (keep/import)
        // The winner may be A or B; we map a/b → winner/loser here so merge() stays clean.
        $winner_is_a    = ($winner_id === $id_a);
        $shown_tables   = array_filter((array) ($_POST['linked_shown'] ?? []), 'strlen');
        $record_choices = [];
        foreach ($shown_tables as $lnk_table) {
            $a_ids = array_map('intval', (array) ($_POST['linked_a_' . $lnk_table] ?? []));
            $b_ids = array_map('intval', (array) ($_POST['linked_b_' . $lnk_table] ?? []));
            $record_choices[$lnk_table] = [
                'keep_winner' => $winner_is_a ? $a_ids : $b_ids,
                'keep_loser'  => $winner_is_a ? $b_ids : $a_ids,
            ];
        }

        $infocom_known = array_column(DuplicateChecker::getInfocomsFields(), 'field');
        $infocom_field_choices = [];
        foreach ($infocom_known as $f) {
            $key = 'infocom_field_' . $f;
            if (isset($_POST[$key])) {
                $infocom_field_choices[$f] = $_POST[$key];
            }
        }

        $result = DuplicateChecker::merge($itemtype, $winner_id, $loser_ids, $field_choices, $tab_choices, $record_choices, $infocom_field_choices);
        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);

} catch (\Exception $e) {
    error_log('duplicate plugin merge.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
