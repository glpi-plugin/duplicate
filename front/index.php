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

// Pagination
$flat_pairs = [];
foreach ($all_results as $itemtype => $pairs) {
    foreach ($pairs as $pair) {
        $flat_pairs[] = ['itemtype' => $itemtype, 'pair' => $pair];
    }
}

$allowed_per_page = [25, 50, 100];
$per_page_input = (int)($_GET['per_page'] ?? 50);
$per_page = in_array($per_page_input, $allowed_per_page) ? $per_page_input : 50;
$total_pages = $total_count > 0 ? (int)ceil($total_count / $per_page) : 1;
$page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $per_page;

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
                <strong><?= $total_count ?> <?= _n('duplicate pair', 'duplicate pairs', $total_count, 'duplicate') ?> <?= __('found', 'duplicate') ?></strong>
                <?= __('across', 'duplicate') ?> <?= count($all_results) ?> <?= _n('asset type', 'asset types', count($all_results), 'duplicate') ?>.
            </div>
        </div>

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
                            <tr>
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

        <?php if ($total_pages > 1 || $per_page !== 50): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <small class="text-muted">
                <?= sprintf(__('Showing %d–%d of %d pairs', 'duplicate'), $offset + 1, min($offset + $per_page, $total_count), $total_count) ?>
            </small>

            <nav aria-label="Duplicate pairs pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&per_page=<?= $per_page ?>">
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
                            <a class="page-link" href="?page=<?= $p ?>&per_page=<?= $per_page ?>"><?= $p ?></a>
                        </li>
                    <?php $prev = $p; endforeach; ?>
                    <li class="page-item<?= $page >= $total_pages ? ' disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&per_page=<?= $per_page ?>">
                            <i class="ti ti-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="d-flex align-items-center gap-1">
                <label class="text-muted small mb-0" for="dup-per-page"><?= __('Per page:', 'duplicate') ?></label>
                <select id="dup-per-page" class="form-select form-select-sm" style="width:auto"
                        onchange="location.href='?page=1&per_page='+this.value">
                    <?php foreach ($allowed_per_page as $n): ?>
                        <option value="<?= $n ?>"<?= $n === $per_page ? ' selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
