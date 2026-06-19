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

$ajax_url  = Plugin::getWebDir('duplicate') . '/ajax/merge.php';
$compare_url = Plugin::getWebDir('duplicate') . '/front/compare.php';
$csrf_token = Session::getNewCSRFToken();

// Scan all asset types
$all_results = [];
$total_count = 0;

foreach (array_keys(DuplicateChecker::getAssetTypes()) as $itemtype) {
    try {
        $pairs = DuplicateChecker::findDuplicates($itemtype);
        if (!empty($pairs)) {
            $all_results[$itemtype] = $pairs;
            $total_count += count($pairs);
        }
    } catch (\Exception $e) {
        error_log('duplicate plugin scan error for ' . $itemtype . ': ' . $e->getMessage());
    }
}

// Filter inputs
$valid_reasons = ['serial', 'uuid', 'otherserial', 'name'];
$valid_types   = array_keys(DuplicateChecker::getAssetTypes());

$filter_search = trim((string)($_GET['filter_search'] ?? ''));
$filter_type   = in_array($_GET['filter_type'] ?? '', $valid_types, true) ? $_GET['filter_type'] : '';
$filter_reason = in_array($_GET['filter_reason'] ?? '', $valid_reasons, true) ? $_GET['filter_reason'] : '';

$has_filters = ($filter_search !== '' || $filter_type !== '' || $filter_reason !== '');

// Flatten pairs
$flat_pairs = [];
foreach ($all_results as $itemtype => $pairs) {
    foreach ($pairs as $pair) {
        $flat_pairs[] = ['itemtype' => $itemtype, 'pair' => $pair];
    }
}

// Apply filters before pagination
if ($has_filters) {
    $flat_pairs = array_values(array_filter($flat_pairs, function ($entry) use ($filter_search, $filter_type, $filter_reason) {
        if ($filter_type !== '' && $entry['itemtype'] !== $filter_type) return false;
        if ($filter_reason !== '' && $entry['pair']['reason'] !== $filter_reason) return false;
        if ($filter_search !== '' && stripos($entry['pair']['match_value'], $filter_search) === false) return false;
        return true;
    }));
}

$filtered_count = count($flat_pairs);

// Pagination (based on filtered count)
$allowed_per_page = [25, 50, 100];
$per_page_input   = (int)($_GET['per_page'] ?? 50);
$per_page         = in_array($per_page_input, $allowed_per_page) ? $per_page_input : 50;
$total_pages      = $filtered_count > 0 ? (int)ceil($filtered_count / $per_page) : 1;
$page             = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset           = ($page - 1) * $per_page;

$page_flat = array_slice($flat_pairs, $offset, $per_page);
$page_results = [];
foreach ($page_flat as $entry) {
    $page_results[$entry['itemtype']][] = $entry['pair'];
}

// Batch-fetch item names and agent status for all pairs on this page
$page_ids = [];
foreach ($page_flat as $entry) {
    $itype = $entry['itemtype'];
    $page_ids[$itype][] = $entry['pair']['ids'][0];
    $page_ids[$itype][] = $entry['pair']['ids'][1];
}
$batch_items  = [];
$batch_agents = [];
foreach ($page_ids as $itype => $ids) {
    $ids = array_unique($ids);
    $batch_items[$itype]  = DuplicateChecker::getItemsBatch($itype, $ids);
    $batch_agents[$itype] = DuplicateChecker::getAgentManagedBatch($itype, $ids);
}

Html::header(
    DuplicateManager::getTypeName(),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Duplicate\\DuplicateManager'
);

?>
<style>
.dup-badge-serial      { background: #f59e0b; color: #fff; }
.dup-badge-uuid        { background: #3b82f6; color: #fff; }
.dup-badge-otherserial { background: #8b5cf6; color: #fff; }
.dup-badge-name        { background: #6b7280; color: #fff; }
.dup-itemtype-card { margin-bottom: 1.5rem; }
.dup-ignore-form  { display: inline; }
.dup-row-link { cursor: pointer; }
</style>

<div class="container-fluid mt-3">

    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="ti ti-copy-off fs-2 text-muted"></i>
        <div>
            <h2 class="mb-0"><?= __('Inventory Duplicate Checker', 'duplicate') ?></h2>
            <medium class="text-muted"><?= __('Detects duplicates by serial number, UUID, and name', 'duplicate') ?></medium>
        </div>
        <div class="ms-auto">
            <a href="<?= htmlspecialchars(Plugin::getWebDir('duplicate') . '/front/index.php', ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="ti ti-refresh"></i> <?= __('Rescan', 'duplicate') ?>
            </a>
        </div>
    </div>

    <?php if ($total_count === 0): ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="ti ti-circle-check fs-4"></i>
            <div><strong><?= __('No duplicates detected', 'duplicate') ?></strong> — <?= __('all inventory items appear to be unique.', 'duplicate') ?></div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="ti ti-alert-triangle fs-4"></i>
            <div>
                <?php if ($has_filters): ?>
                    <strong><?= $filtered_count ?> <?= _n('duplicate pair', 'duplicate pairs', $filtered_count, 'duplicate') ?> <?= __('found', 'duplicate') ?></strong>
                    <span class="text-muted">(<?= sprintf(__('filtered from %d total', 'duplicate'), $total_count) ?>)</span>
                <?php else: ?>
                    <strong><?= $total_count ?> <?= _n('duplicate pair', 'duplicate pairs', $total_count, 'duplicate') ?> <?= __('found', 'duplicate') ?></strong>
                    <?= __('across', 'duplicate') ?> <?= count($all_results) ?> <?= _n('asset type', 'asset types', count($all_results), 'duplicate') ?>.
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" action="" class="card mb-3 p-3">
            <div class="row g-2 align-items-end">

                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1" for="dup-filter-search"><?= __('Match Value', 'duplicate') ?></label>
                    <input type="text"
                           id="dup-filter-search"
                           name="filter_search"
                           class="form-control form-control-sm"
                           placeholder="<?= htmlspecialchars(__('Search match value…', 'duplicate'), ENT_QUOTES) ?>"
                           value="<?= htmlspecialchars($filter_search, ENT_QUOTES) ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="dup-filter-type"><?= __('Asset type', 'duplicate') ?></label>
                    <select id="dup-filter-type" name="filter_type" class="form-select form-select-sm">
                        <option value=""><?= __('All types', 'duplicate') ?></option>
                        <?php foreach ($valid_types as $at): ?>
                            <option value="<?= htmlspecialchars($at, ENT_QUOTES) ?>"<?= $filter_type === $at ? ' selected' : '' ?>>
                                <?= htmlspecialchars($at, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="dup-filter-reason"><?= __('Match reason', 'duplicate') ?></label>
                    <select id="dup-filter-reason" name="filter_reason" class="form-select form-select-sm">
                        <option value=""><?= __('All reasons', 'duplicate') ?></option>
                        <option value="serial"<?= $filter_reason === 'serial' ? ' selected' : '' ?>><?= __('Serial number', 'duplicate') ?></option>
                        <option value="uuid"<?= $filter_reason === 'uuid' ? ' selected' : '' ?>><?= __('UUID match', 'duplicate') ?></option>
                        <option value="otherserial"<?= $filter_reason === 'otherserial' ? ' selected' : '' ?>><?= __('Inventory number', 'duplicate') ?></option>
                        <option value="name"<?= $filter_reason === 'name' ? ' selected' : '' ?>><?= __('Name match', 'duplicate') ?></option>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <input type="hidden" name="per_page" value="<?= $per_page ?>">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="ti ti-filter"></i> <?= __('Filter', 'duplicate') ?>
                    </button>
                    <?php if ($has_filters): ?>
                        <a href="<?= htmlspecialchars(Plugin::getWebDir('duplicate') . '/front/index.php', ENT_QUOTES) ?>"
                           class="btn btn-outline-secondary btn-sm"
                           title="<?= htmlspecialchars(__('Clear', 'duplicate'), ENT_QUOTES) ?>">
                            <i class="ti ti-x"></i> <?= __('Clear', 'duplicate') ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </form>

        <?php if ($filtered_count === 0 && $has_filters): ?>
            <div class="alert alert-info d-flex align-items-center gap-2">
                <i class="ti ti-search-off fs-4"></i>
                <div>
                    <?= __('No pairs match the current filters.', 'duplicate') ?>
                    <a href="<?= htmlspecialchars(Plugin::getWebDir('duplicate') . '/front/index.php', ENT_QUOTES) ?>" class="ms-2">
                        <?= __('Clear', 'duplicate') ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
        <?php foreach ($page_results as $itemtype => $pairs): ?>
            <div class="card dup-itemtype-card">
                <div class="card-header d-flex align-items-center gap-2">
                    <strong><?= htmlspecialchars($itemtype, ENT_QUOTES) ?></strong>
                    <span class="badge bg-danger ms-1"><?= count($pairs) ?> <?= (count($pairs) == 1 ? 'pair' : 'pairs') ?></span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:100px"><?= __('Match', 'duplicate') ?></th>
                                <th><?= __('Match Value', 'duplicate') ?></th>
                                <th><?= __('Item A', 'duplicate') ?></th>
                                <th><?= __('Item B', 'duplicate') ?></th>
                                <th style="width:200px"><?= __('Actions', 'duplicate') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pairs as $pair):
                                $id_a = $pair['ids'][0];
                                $id_b = $pair['ids'][1];
                                $reason = $pair['reason'];
                                $match_value = $pair['match_value'];

                                $data_a  = $batch_items[$itemtype][$id_a]  ?? [];
                                $data_b  = $batch_items[$itemtype][$id_b]  ?? [];
                                $name_a  = htmlspecialchars($data_a['name'] ?? "ID: $id_a", ENT_QUOTES);
                                $name_b  = htmlspecialchars($data_b['name'] ?? "ID: $id_b", ENT_QUOTES);
                                $agent_a = $batch_agents[$itemtype][$id_a] ?? false;
                                $agent_b = $batch_agents[$itemtype][$id_b] ?? false;

                                $badge_class = match($reason) {
                                    'serial'      => 'dup-badge-serial',
                                    'uuid'        => 'dup-badge-uuid',
                                    'otherserial' => 'dup-badge-otherserial',
                                    default       => 'dup-badge-name',
                                };

                                $compare_href = $compare_url
                                    . '?itemtype=' . urlencode($itemtype)
                                    . '&id_a=' . $id_a
                                    . '&id_b=' . $id_b
                                    . '&reason=' . urlencode($reason);
                            ?>
                            <tr class="dup-row-link" data-href="<?= htmlspecialchars($compare_href, ENT_QUOTES) ?>">
                                <td>
                                    <span class="badge <?= $badge_class ?>"><?= htmlspecialchars(strtoupper($reason), ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <code class="text-truncate d-inline-block" style="max-width:200px" title="<?= htmlspecialchars($match_value, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($match_value, ENT_QUOTES) ?>
                                    </code>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars(DuplicateChecker::getFrontUrl($itemtype, $id_a), ENT_QUOTES) ?>" target="_blank">
                                        <?= $name_a ?>
                                    </a>
                                    <small class="text-muted ms-1">#<?= $id_a ?></small>
                                    <?php if ($agent_a): ?>
                                        <i class="ti ti-robot text-info ms-1" title="<?= __('Imported by GLPI Agent', 'duplicate') ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars(DuplicateChecker::getFrontUrl($itemtype, $id_b), ENT_QUOTES) ?>" target="_blank">
                                        <?= $name_b ?>
                                    </a>
                                    <small class="text-muted ms-1">#<?= $id_b ?></small>
                                    <?php if ($agent_b): ?>
                                        <i class="ti ti-robot text-info ms-1" title="<?= __('Imported by GLPI Agent', 'duplicate') ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="<?= htmlspecialchars($compare_href, ENT_QUOTES) ?>"
                                           class="btn btn-sm btn-primary">
                                            <i class="ti ti-columns-2"></i> <?= __('Compare', 'duplicate') ?>
                                        </a>
                                        <form class="dup-ignore-form"
                                              data-ajax="<?= htmlspecialchars($ajax_url, ENT_QUOTES) ?>"
                                              data-itemtype="<?= htmlspecialchars($itemtype, ENT_QUOTES) ?>"
                                              data-id-a="<?= $id_a ?>"
                                              data-id-b="<?= $id_b ?>"
                                              data-reason="<?= htmlspecialchars($reason, ENT_QUOTES) ?>"
                                              data-csrf="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dup-ignore-btn"
                                                    title="<?= __('Mark as not a duplicate', 'duplicate') ?>">
                                                <i class="ti ti-eye-off"></i> <?= __('Ignore', 'duplicate') ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php
        $pagination_base = ['per_page' => $per_page];
        if ($filter_search !== '') $pagination_base['filter_search'] = $filter_search;
        if ($filter_type   !== '') $pagination_base['filter_type']   = $filter_type;
        if ($filter_reason !== '') $pagination_base['filter_reason'] = $filter_reason;
        ?>
        <?php if ($total_pages > 1 || $per_page !== 50 || $has_filters): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <small class="text-muted">
                <?= sprintf(__('Showing %d–%d of %d pairs', 'duplicate'), $offset + 1, min($offset + $per_page, $filtered_count), $filtered_count) ?>
            </small>

            <nav aria-label="Duplicate pairs pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($pagination_base, ['page' => $page - 1])) ?>">
                            <i class="ti ti-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $show = [];
                    for ($p = 1; $p <= $total_pages; $p++) {
                        if ($p === 1 || $p === $total_pages || abs($p - $page) <= 1) {
                            $show[] = $p;
                        }
                    }
                    $prev = null;
                    foreach ($show as $p):
                        if ($prev !== null && $p - $prev > 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                        <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($pagination_base, ['page' => $p])) ?>"><?= $p ?></a>
                        </li>
                    <?php $prev = $p; endforeach; ?>
                    <li class="page-item<?= $page >= $total_pages ? ' disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($pagination_base, ['page' => $page + 1])) ?>">
                            <i class="ti ti-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>

            <form class="d-flex align-items-center gap-1" method="get">
                <?php if ($filter_search !== ''): ?>
                    <input type="hidden" name="filter_search" value="<?= htmlspecialchars($filter_search, ENT_QUOTES) ?>">
                <?php endif; ?>
                <?php if ($filter_type !== ''): ?>
                    <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type, ENT_QUOTES) ?>">
                <?php endif; ?>
                <?php if ($filter_reason !== ''): ?>
                    <input type="hidden" name="filter_reason" value="<?= htmlspecialchars($filter_reason, ENT_QUOTES) ?>">
                <?php endif; ?>
                <input type="hidden" name="page" value="1">
                <label class="text-muted small mb-0" for="dup-per-page"><?= __('Per page:', 'duplicate') ?></label>
                <select id="dup-per-page" name="per_page" class="form-select form-select-sm" style="width:auto"
                        onchange="this.form.submit()">
                    <?php foreach ($allowed_per_page as $n): ?>
                        <option value="<?= $n ?>"<?= $n === $per_page ? ' selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php
$js_i18n = [
    'failedToIgnore' => __('Failed to ignore pair', 'duplicate'),
    'networkError'   => __('Network error', 'duplicate'),
    'ignore'         => __('Ignore', 'duplicate'),
];
?>
<script>
const dup_i18n = <?= json_encode($js_i18n, JSON_HEX_TAG) ?>;

document.querySelectorAll('.dup-row-link').forEach(function(row) {
    row.addEventListener('click', function(e) {
        if (e.target.closest('a, button')) return;
        window.location.href = row.dataset.href;
    });
});

document.querySelectorAll('.dup-ignore-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var form = btn.closest('form');
        var row  = btn.closest('tr');

        var data = new FormData();
        data.append('action',             'ignore');
        data.append('itemtype',           form.dataset.itemtype);
        data.append('id_a',              form.dataset.idA);
        data.append('id_b',              form.dataset.idB);
        data.append('reason',            form.dataset.reason);
        data.append('_glpi_csrf_token',  form.dataset.csrf);

        btn.disabled = true;
        btn.innerHTML = '<i class="ti ti-loader-2"></i>';

        fetch(form.dataset.ajax, {method: 'POST', body: data})
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); }, 310);
                } else {
                    alert(resp.error || dup_i18n.failedToIgnore);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ti ti-eye-off"></i> ' + dup_i18n.ignore;
                }
            })
            .catch(function() {
                alert(dup_i18n.networkError);
                btn.disabled = false;
                btn.innerHTML = '<i class="ti ti-eye-off"></i> ' + dup_i18n.ignore;
            });
    });
});
</script>

<?php
Html::footer();
