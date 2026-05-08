<?php

include('../../../inc/includes.php');

use GlpiPlugin\Duplicate\DuplicateChecker;
use GlpiPlugin\Duplicate\DuplicateManager;

// Load plugin translations
Plugin::loadLang('duplicate');

Session::checkLoginUser();

if (!Session::haveRight('plugin_duplicate_check', READ)) {
    Html::displayRightError();
    exit;
}

$itemtype = $_GET['itemtype'] ?? '';
$id_a     = (int) ($_GET['id_a'] ?? 0);
$id_b     = (int) ($_GET['id_b'] ?? 0);
$reason   = $_GET['reason'] ?? '';

$valid_types = array_keys(DuplicateChecker::getAssetTypes());
if (!in_array($itemtype, $valid_types, true) || $id_a <= 0 || $id_b <= 0) {
    Session::addMessageAfterRedirect(__('Invalid parameters', 'duplicate'), false, ERROR);
    Html::redirect(Plugin::getWebDir('duplicate') . '/front/index.php');
    exit;
}

$valid_reasons = ['serial', 'uuid', 'otherserial', 'name'];
if (!in_array($reason, $valid_reasons, true)) {
    Session::addMessageAfterRedirect(__('Invalid parameters', 'duplicate'), false, ERROR);
    Html::redirect(Plugin::getWebDir('duplicate') . '/front/index.php');
    exit;
}

$data_a = DuplicateChecker::getItemData($itemtype, $id_a);
$data_b = DuplicateChecker::getItemData($itemtype, $id_b);

if (empty($data_a) || empty($data_b)) {
    Session::addMessageAfterRedirect(__('One or both items not found', 'duplicate'), false, ERROR);
    Html::redirect(Plugin::getWebDir('duplicate') . '/front/index.php');
    exit;
}

$fields      = DuplicateChecker::getComparableFields($itemtype);
$can_edit    = Session::haveRight('plugin_duplicate_check', UPDATE);

// Infocoms field-by-field data — batch load both items in one query
if ($can_edit) {
    $infocoms  = DuplicateChecker::getLinkedRecordBatch('glpi_infocoms', $itemtype, [$id_a, $id_b]);
    $infocom_a = $infocoms[$id_a];
    $infocom_b = $infocoms[$id_b];
} else {
    $infocom_a = null;
    $infocom_b = null;
}
$infocom_fields = DuplicateChecker::getInfocomsFields();
$show_infocoms  = $can_edit && ($infocom_a || $infocom_b);

// Notes only — all other tab sections are silently auto-merged in the backend
$linked_tab_data = $can_edit
    ? array_filter(
        DuplicateChecker::getLinkedTabData($itemtype, $id_a, $id_b),
        fn($k) => $k === 'glpi_notepad',
        ARRAY_FILTER_USE_KEY
      )
    : [];
$index_url   = Plugin::getWebDir('duplicate') . '/front/index.php';
$ajax_url    = Plugin::getWebDir('duplicate') . '/ajax/merge.php';
$csrf_token  = Session::getNewCSRFToken();

$name_a   = htmlspecialchars($data_a['name'] ?? "Item #$id_a", ENT_QUOTES);
$name_b   = htmlspecialchars($data_b['name'] ?? "Item #$id_b", ENT_QUOTES);
$agents   = DuplicateChecker::getAgentManagedBatch($itemtype, [$id_a, $id_b]);
$agent_a  = $agents[$id_a] ?? false;
$agent_b  = $agents[$id_b] ?? false;
$agent_icon = '<i class="ti ti-robot text-info ms-1" title="' . __('Imported by GLPI Agent', 'duplicate') . '"></i>';

Html::header(
    __('Compare Duplicates', 'duplicate') . ' — ' . DuplicateManager::getTypeName(),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Duplicate\\DuplicateManager'
);

?>
<style>
.dup-compare-table th, .dup-compare-table td { vertical-align: middle; }
.dup-compare-table .field-label { font-weight: 600; color: #374151; white-space: nowrap; width: 160px; }
.dup-diff-row { background: #fffbeb !important; }
.dup-diff-row td { border-left: 3px solid #f59e0b; }
.dup-diff-row td:first-child { border-left: none; }
.dup-radio-cell { display: flex; align-items: flex-start; gap: 8px; }
.dup-radio-cell input[type="radio"] { margin-top: 3px; flex-shrink: 0; }
.dup-val-text { word-break: break-all; }
.dup-readonly-val { color: #6b7280; font-style: italic; font-size: 0.875em; }
.dup-winner-badge { font-size: 0.75em; padding: 2px 8px; }
.dup-col-a { background: rgba(59,130,246,0.04); }
.dup-col-b { background: rgba(16,185,129,0.04); }
.dup-action-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e5e7eb; padding: 12px 0; z-index: 10; }
</style>

<div class="container-fluid mt-3">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= htmlspecialchars($index_url, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="ti ti-arrow-left"></i> <?= __('Back to list', 'duplicate') ?>
        </a>
        <div class="ms-1">
            <h2 class="mb-0"><?= sprintf(__('Compare %s Duplicates', 'duplicate'), htmlspecialchars($itemtype, ENT_QUOTES)) ?></h2>
            <small class="text-muted"><?= __('Match by', 'duplicate') ?> <strong><?= htmlspecialchars(strtoupper($reason), ENT_QUOTES) ?></strong> — <?= __('rows highlighted in yellow have different values', 'duplicate') ?></small>
        </div>
    </div>

    <?php if (!$can_edit): ?>
        <div class="alert alert-info mb-3">
            <i class="ti ti-info-circle"></i> <?= __('You have read-only access. Contact an administrator to merge or delete duplicate records.', 'duplicate') ?>
        </div>
    <?php endif; ?>

    <!-- Winner selection -->
    <?php if ($can_edit): ?>
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <strong class="text-muted me-1"><?= __('Base record:', 'duplicate') ?></strong>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="radio" name="winner_radio" id="winner_a" value="a" checked>
                    <label class="form-check-label" for="winner_a">
                        <span class="badge bg-primary dup-winner-badge">A</span>
                        <?= $name_a ?> <small class="text-muted">#<?= $id_a ?></small>
                        <?php if ($agent_a): ?><?= $agent_icon ?><?php endif; ?>
                    </label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="radio" name="winner_radio" id="winner_b" value="b">
                    <label class="form-check-label" for="winner_b">
                        <span class="badge bg-success dup-winner-badge">B</span>
                        <?= $name_b ?> <small class="text-muted">#<?= $id_b ?></small>
                        <?php if ($agent_b): ?><?= $agent_icon ?><?php endif; ?>
                    </label>
                </div>
                <small class="text-muted ms-2"><?= __('The base record is kept; its ID survives. For differing fields, choose which value to use below.', 'duplicate') ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Comparison table -->
    <div class="card mb-3">
        <div class="card-body p-0">
            <table class="table table-bordered dup-compare-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="field-label"><?= __('Field', 'duplicate') ?></th>
                        <th class="dup-col-a">
                            <span class="badge bg-primary dup-winner-badge me-1">A</span>
                            <?= $name_a ?>
                            <?php if ($agent_a): ?><?= $agent_icon ?><?php endif; ?>
                            <small class="text-muted d-block fw-normal">ID: <?= $id_a ?> &mdash;
                                <a href="<?= htmlspecialchars(DuplicateChecker::getFrontUrl($itemtype, $id_a), ENT_QUOTES) ?>" target="_blank">
                                    <?= __('View', 'duplicate') ?> <i class="ti ti-external-link"></i>
                                </a>
                            </small>
                        </th>
                        <th class="dup-col-b">
                            <span class="badge bg-success dup-winner-badge me-1">B</span>
                            <?= $name_b ?>
                            <?php if ($agent_b): ?><?= $agent_icon ?><?php endif; ?>
                            <small class="text-muted d-block fw-normal">ID: <?= $id_b ?> &mdash;
                                <a href="<?= htmlspecialchars(DuplicateChecker::getFrontUrl($itemtype, $id_b), ENT_QUOTES) ?>" target="_blank">
                                    <?= __('View', 'duplicate') ?> <i class="ti ti-external-link"></i>
                                </a>
                            </small>
                        </th>
                    </tr>
                </thead>
                <?php
                // Batch-load all FK display values for both items before the loop
                $fk_tables_needed = [];
                foreach ($fields as $fieldDef) {
                    if ($fieldDef['type'] === 'fk') {
                        foreach ([(int)($data_a[$fieldDef['field']] ?? 0), (int)($data_b[$fieldDef['field']] ?? 0)] as $fk_id) {
                            if ($fk_id > 0) {
                                $fk_tables_needed[$fieldDef['fk_table']][] = $fk_id;
                            }
                        }
                    }
                }
                $fk_cache = [];
                foreach ($fk_tables_needed as $fk_table => $fk_ids) {
                    $fk_cache[$fk_table] = DuplicateChecker::getFkDisplayBatch($fk_table, $fk_ids);
                }
                ?>
                <tbody>
                    <?php foreach ($fields as $fieldDef):
                        $f       = $fieldDef['field'];
                        $label   = $fieldDef['label'];
                        $type    = $fieldDef['type'];
                        $raw_a   = $data_a[$f] ?? null;
                        $raw_b   = $data_b[$f] ?? null;
                        $is_diff = ((string) $raw_a !== (string) $raw_b);
                        $disp_a  = DuplicateChecker::getFieldDisplayValue($fieldDef, $raw_a, $fk_cache);
                        $disp_b  = DuplicateChecker::getFieldDisplayValue($fieldDef, $raw_b, $fk_cache);
                        $row_class = ($is_diff && $type !== 'readonly') ? 'dup-diff-row' : '';
                    ?>
                    <tr class="<?= $row_class ?>" data-field="<?= htmlspecialchars($f, ENT_QUOTES) ?>" data-has-diff="<?= ($is_diff && $type !== 'readonly') ? '1' : '0' ?>">
                        <td class="field-label">
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                            <?php if ($is_diff && $type !== 'readonly'): ?>
                                <i class="ti ti-alert-circle text-warning ms-1" title="<?= __('Values differ', 'duplicate') ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="dup-col-a">
                            <?php if ($is_diff && $type !== 'readonly' && $can_edit): ?>
                                <div class="dup-radio-cell">
                                    <input type="radio"
                                           name="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>"
                                           value="<?= htmlspecialchars((string)($raw_a ?? ''), ENT_QUOTES) ?>"
                                           data-side="a"
                                           id="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>_a"
                                           checked>
                                    <label for="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>_a" class="dup-val-text">
                                        <?= $disp_a !== '' ? htmlspecialchars($disp_a, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <span class="<?= $type === 'readonly' ? 'dup-readonly-val' : 'dup-val-text' ?>">
                                    <?= $disp_a !== '' ? htmlspecialchars($disp_a, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="dup-col-b">
                            <?php if ($is_diff && $type !== 'readonly' && $can_edit): ?>
                                <div class="dup-radio-cell">
                                    <input type="radio"
                                           name="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>"
                                           value="<?= htmlspecialchars((string)($raw_b ?? ''), ENT_QUOTES) ?>"
                                           data-side="b"
                                           id="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>_b">
                                    <label for="field_<?= htmlspecialchars($f, ENT_QUOTES) ?>_b" class="dup-val-text">
                                        <?= $disp_b !== '' ? htmlspecialchars($disp_b, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <span class="<?= $type === 'readonly' ? 'dup-readonly-val' : 'dup-val-text' ?>">
                                    <?= $disp_b !== '' ? htmlspecialchars($disp_b, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Infocoms field-by-field comparison -->
    <?php if ($show_infocoms): ?>
    <div class="card mb-3 <?= ($infocom_a && $infocom_b) ? 'border-warning' : '' ?>">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <i class="ti ti-currency-dollar"></i>
            <strong><?= __('Financial Information (Management)', 'duplicate') ?></strong>
            <?php if ($infocom_a && $infocom_b): ?>
                <small class="text-muted ms-2"><?= __('Highlighted rows differ — select A or B for each field', 'duplicate') ?></small>
            <?php elseif ($infocom_a): ?>
                <small class="text-muted ms-2"><?= __('Only Item A has financial data — carried over automatically', 'duplicate') ?></small>
            <?php else: ?>
                <small class="text-muted ms-2"><?= __('Only Item B has financial data — carried over automatically', 'duplicate') ?></small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered dup-compare-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="field-label"><?= __('Field', 'duplicate') ?></th>
                        <th class="dup-col-a">
                            <span class="badge bg-primary dup-winner-badge me-1">A</span>
                            <?= $name_a ?>
                        </th>
                        <th class="dup-col-b">
                            <span class="badge bg-success dup-winner-badge me-1">B</span>
                            <?= $name_b ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($infocom_fields as $iField):
                        $raw_ia  = $infocom_a[$iField['field']] ?? null;
                        $raw_ib  = $infocom_b[$iField['field']] ?? null;
                        $disp_ia = DuplicateChecker::getInfocomsFieldDisplayValue($iField, $raw_ia);
                        $disp_ib = DuplicateChecker::getInfocomsFieldDisplayValue($iField, $raw_ib);
                        $is_infocom_diff = $infocom_a && $infocom_b && ((string)$raw_ia !== (string)$raw_ib);
                        $infocom_row_class = $is_infocom_diff ? 'dup-diff-row dup-infocom-diff-row' : '';
                        $safe_ifield = htmlspecialchars($iField['field'], ENT_QUOTES);
                    ?>
                    <tr class="<?= $infocom_row_class ?>"
                        <?= $is_infocom_diff ? 'data-infocom-field="' . $safe_ifield . '"' : '' ?>>
                        <td class="field-label">
                            <?= htmlspecialchars($iField['label'], ENT_QUOTES) ?>
                            <?php if ($is_infocom_diff): ?>
                                <i class="ti ti-alert-circle text-warning ms-1" title="<?= __('Values differ', 'duplicate') ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="dup-col-a">
                            <?php if ($is_infocom_diff && $can_edit): ?>
                                <div class="dup-radio-cell">
                                    <input type="radio"
                                           name="infocom_field_<?= $safe_ifield ?>"
                                           value="<?= htmlspecialchars((string)($raw_ia ?? ''), ENT_QUOTES) ?>"
                                           data-side="a"
                                           id="infocom_field_<?= $safe_ifield ?>_a"
                                           checked>
                                    <label for="infocom_field_<?= $safe_ifield ?>_a" class="dup-val-text">
                                        <?= $disp_ia !== '' ? htmlspecialchars($disp_ia, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <?= $disp_ia !== '' ? htmlspecialchars($disp_ia, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                            <?php endif; ?>
                        </td>
                        <td class="dup-col-b">
                            <?php if ($is_infocom_diff && $can_edit): ?>
                                <div class="dup-radio-cell">
                                    <input type="radio"
                                           name="infocom_field_<?= $safe_ifield ?>"
                                           value="<?= htmlspecialchars((string)($raw_ib ?? ''), ENT_QUOTES) ?>"
                                           data-side="b"
                                           id="infocom_field_<?= $safe_ifield ?>_b">
                                    <label for="infocom_field_<?= $safe_ifield ?>_b" class="dup-val-text">
                                        <?= $disp_ib !== '' ? htmlspecialchars($disp_ib, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <?= $disp_ib !== '' ? htmlspecialchars($disp_ib, ENT_QUOTES) : '<em class="text-muted">empty</em>' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Per-record linked data sections -->
    <?php if ($can_edit && !empty($linked_tab_data)): ?>
    <div class="mb-1">
        <h6 class="text-muted mb-2 px-1">
            <i class="ti ti-link me-1"></i><?= __('Linked Records', 'duplicate') ?>
            <small class="fw-normal ms-2"><?= __('Check records to include in the merged item; uncheck to exclude', 'duplicate') ?></small>
        </h6>
    </div>

    <?php foreach ($linked_tab_data as $lnk_table => $lnk_section):
        $row_count = count($lnk_section['rows']);
        $safe_table = htmlspecialchars($lnk_table, ENT_QUOTES);
    ?>
    <div class="card mb-3 dup-linked-section" data-table="<?= $safe_table ?>">
        <div class="card-header d-flex align-items-center gap-2">
            <strong><?= htmlspecialchars($lnk_section['label'], ENT_QUOTES) ?></strong>
            <span class="badge bg-secondary ms-1"><?= $row_count ?> <?= _n('record', 'records', $row_count, 'duplicate') ?></span>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm dup-check-all" data-table="<?= $safe_table ?>">
                    <?= __('Check all', 'duplicate') ?>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm dup-uncheck-all" data-table="<?= $safe_table ?>">
                    <?= __('Uncheck all', 'duplicate') ?>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px" class="text-center"><?= __('Keep', 'duplicate') ?></th>
                        <th style="width:56px" class="text-center"><?= __('From', 'duplicate') ?></th>
                        <th><?= __('Record', 'duplicate') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lnk_section['rows'] as $lnk_row):
                        $origin     = $lnk_row['origin'];
                        $row_class  = $origin === 'a' ? 'dup-col-a' : ($origin === 'b' ? 'dup-col-b' : '');
                        $lnk_label  = htmlspecialchars($lnk_row['label'], ENT_QUOTES);
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="text-center align-middle">
                            <?php if ($origin === 'both'): ?>
                                <span class="text-muted" title="<?= __('Exists on both — kept once automatically', 'duplicate') ?>">─</span>
                            <?php else: ?>
                                <input type="checkbox"
                                       class="form-check-input dup-record-check"
                                       data-item="<?= $origin === 'a' ? 'a' : 'b' ?>"
                                       data-id="<?= $origin === 'a' ? (int)$lnk_row['id_a'] : (int)$lnk_row['id_b'] ?>"
                                       checked>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($origin === 'a'): ?>
                                <span class="badge bg-primary dup-winner-badge">A</span>
                            <?php elseif ($origin === 'b'): ?>
                                <span class="badge bg-success dup-winner-badge">B</span>
                            <?php else: ?>
                                <span class="badge bg-secondary dup-winner-badge">A+B</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle">
                            <?= $lnk_label ?>
                            <?php if ($origin === 'both'): ?>
                                <small class="text-muted ms-1">(<?= __('duplicate — kept once', 'duplicate') ?>)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Action bar -->
    <?php if ($can_edit): ?>
    <div class="dup-action-bar">
        <div class="d-flex gap-2 flex-wrap align-items-center px-2">
            <button id="btn-keep-a" class="btn btn-primary">
                <i class="ti ti-badge-a"></i> <?= __('Keep A, delete B', 'duplicate') ?>
            </button>
            <button id="btn-keep-b" class="btn btn-success">
                <i class="ti ti-badge-b"></i> <?= __('Keep B, delete A', 'duplicate') ?>
            </button>
            <button id="btn-merge" class="btn btn-warning text-dark">
                <i class="ti ti-git-merge"></i> <?= __('Merge with selected values', 'duplicate') ?>
            </button>
            <div class="vr mx-1"></div>
            <button id="btn-ignore" class="btn btn-outline-secondary">
                <i class="ti ti-eye-off"></i> <?= __('Not a duplicate', 'duplicate') ?>
            </button>
            <a href="<?= htmlspecialchars($index_url, ENT_QUOTES) ?>" class="btn btn-link text-muted ms-auto">
                <?= __('Cancel', 'duplicate') ?>
            </a>
        </div>
    </div>

    <div id="dup-toast" class="position-fixed bottom-0 end-0 p-3" style="z-index:9999; display:none">
        <div class="toast show" id="dup-toast-inner">
            <div class="toast-body" id="dup-toast-msg"></div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($can_edit):
    $js_i18n = [
        'processing'      => __('Processing…', 'duplicate'),
        'done'            => __('Done! Redirecting…', 'duplicate'),
        'errorDefault'    => __('An error occurred', 'duplicate'),
        'networkError'    => __('Network error', 'duplicate'),
        'confirmKeepA'    => __('Keep Item A (#%d) and delete Item B (#%d)?', 'duplicate'),
        'confirmKeepB'    => __('Keep Item B (#%d) and delete Item A (#%d)?', 'duplicate'),
        'confirmMerge'    => __('Merge and keep Item %s (#%d) with the selected field values?', 'duplicate'),
        'confirmIgnore'   => __('Mark this pair as not a duplicate? It will no longer appear in the list.', 'duplicate'),
    ];
?>
<script>
(function() {
    var idA       = <?= $id_a ?>;
    var idB       = <?= $id_b ?>;
    var itemtype  = <?= json_encode($itemtype) ?>;
    var reason    = <?= json_encode($reason) ?>;
    var ajaxUrl   = <?= json_encode($ajax_url) ?>;
    var indexUrl  = <?= json_encode($index_url) ?>;
    var csrfToken = <?= json_encode($csrf_token) ?>;
    var dup_i18n  = <?= json_encode($js_i18n, JSON_HEX_TAG) ?>;

    // When winner radio changes, auto-select that side for all differing field rows
    // including infocom diff rows (per-record checkboxes are independent of winner choice)
    document.querySelectorAll('input[name="winner_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var side = this.value;
            document.querySelectorAll('tr[data-has-diff="1"], tr.dup-infocom-diff-row').forEach(function(row) {
                var sideRadio = row.querySelector('input[data-side="' + side + '"]');
                if (sideRadio) sideRadio.checked = true;
            });
        });
    });

    // Check-all / Uncheck-all buttons for linked-record sections
    document.querySelectorAll('.dup-check-all').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.dup-linked-section[data-table="' + this.dataset.table + '"] .dup-record-check')
                .forEach(function(cb) { cb.checked = true; });
        });
    });
    document.querySelectorAll('.dup-uncheck-all').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.dup-linked-section[data-table="' + this.dataset.table + '"] .dup-record-check')
                .forEach(function(cb) { cb.checked = false; });
        });
    });

    function getWinner() {
        var radio = document.querySelector('input[name="winner_radio"]:checked');
        return radio ? radio.value : 'a';
    }

    // postAction accepts an array of [key, value] pairs to support repeated keys (PHP arrays)
    function postAction(action, winner_id, loser_ids, pairs) {
        var data = new FormData();
        data.append('action',           action);
        data.append('itemtype',         itemtype);
        data.append('id_a',             idA);
        data.append('id_b',             idB);
        data.append('reason',           reason);
        data.append('winner_id',        winner_id);
        data.append('_glpi_csrf_token', csrfToken);
        loser_ids.forEach(function(id) { data.append('loser_ids[]', id); });
        if (pairs) {
            pairs.forEach(function(kv) { data.append(kv[0], kv[1]); });
        }
        return fetch(ajaxUrl, {method: 'POST', body: data}).then(function(r) { return r.json(); });
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.dataset.origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="ti ti-loader-2 ti-spin"></i> ' + dup_i18n.processing;
            btn.disabled = true;
            document.querySelectorAll('#btn-keep-a,#btn-keep-b,#btn-merge,#btn-ignore').forEach(function(b) { b.disabled = true; });
        } else {
            btn.innerHTML = btn.dataset.origHtml || btn.innerHTML;
            document.querySelectorAll('#btn-keep-a,#btn-keep-b,#btn-merge,#btn-ignore').forEach(function(b) { b.disabled = false; });
        }
    }

    function showToast(msg, isError) {
        var t     = document.getElementById('dup-toast');
        var m     = document.getElementById('dup-toast-msg');
        var inner = document.getElementById('dup-toast-inner');
        m.textContent = msg;
        inner.className = 'toast show ' + (isError ? 'bg-danger text-white' : 'bg-success text-white');
        t.style.display = 'block';
        setTimeout(function() { t.style.display = 'none'; }, 3000);
    }

    function onSuccess() {
        showToast(dup_i18n.done, false);
        setTimeout(function() { window.location.href = indexUrl; }, 1000);
    }

    function onError(msg) {
        showToast(msg || dup_i18n.errorDefault, true);
    }

    document.getElementById('btn-keep-a').addEventListener('click', function() {
        if (!confirm(dup_i18n.confirmKeepA.replace('%d', idA).replace('%d', idB))) return;
        var btn = this;
        setLoading(btn, true);
        postAction('keepone', idA, [idB])
            .then(function(resp) { resp.success ? onSuccess() : onError(resp.error); setLoading(btn, false); })
            .catch(function() { onError(dup_i18n.networkError); setLoading(btn, false); });
    });

    document.getElementById('btn-keep-b').addEventListener('click', function() {
        if (!confirm(dup_i18n.confirmKeepB.replace('%d', idB).replace('%d', idA))) return;
        var btn = this;
        setLoading(btn, true);
        postAction('keepone', idB, [idA])
            .then(function(resp) { resp.success ? onSuccess() : onError(resp.error); setLoading(btn, false); })
            .catch(function() { onError(dup_i18n.networkError); setLoading(btn, false); });
    });

    document.getElementById('btn-merge').addEventListener('click', function() {
        var side      = getWinner();
        var winner_id = side === 'a' ? idA : idB;
        var loser_ids = side === 'a' ? [idB] : [idA];

        if (!confirm(dup_i18n.confirmMerge.replace('%s', side.toUpperCase()).replace('%d', winner_id))) return;

        var pairs = [];

        // Per-field radio choices (main fields)
        document.querySelectorAll('tr[data-has-diff="1"]').forEach(function(row) {
            var checked = row.querySelector('input[type="radio"]:checked');
            if (checked && row.dataset.field) {
                pairs.push(['field_' + row.dataset.field, checked.value]);
            }
        });

        // Infocom per-field choices
        document.querySelectorAll('tr.dup-infocom-diff-row').forEach(function(row) {
            var checked = row.querySelector('input[type="radio"]:checked');
            if (checked && row.dataset.infocomField) {
                pairs.push(['infocom_field_' + row.dataset.infocomField, checked.value]);
            }
        });

        // Per-record linked table choices
        document.querySelectorAll('.dup-linked-section').forEach(function(section) {
            var tbl = section.dataset.table;
            pairs.push(['linked_shown[]', tbl]);
            section.querySelectorAll('.dup-record-check:checked').forEach(function(cb) {
                pairs.push(['linked_' + cb.dataset.item + '_' + tbl + '[]', cb.dataset.id]);
            });
        });

        var btn = this;
        setLoading(btn, true);
        postAction('merge', winner_id, loser_ids, pairs)
            .then(function(resp) { resp.success ? onSuccess() : onError(resp.error); setLoading(btn, false); })
            .catch(function() { onError(dup_i18n.networkError); setLoading(btn, false); });
    });

    document.getElementById('btn-ignore').addEventListener('click', function() {
        if (!confirm(dup_i18n.confirmIgnore)) return;
        var btn = this;
        setLoading(btn, true);
        postAction('ignore', idA, [idB])
            .then(function(resp) { resp.success ? onSuccess() : onError(resp.error); setLoading(btn, false); })
            .catch(function() { onError(dup_i18n.networkError); setLoading(btn, false); });
    });
})();
</script>
<?php endif; ?>

<?php
Html::footer();
