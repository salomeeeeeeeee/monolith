<?php
/**
 * დილის პროექტის ველში (UF_CRM_1779277729207) "მონოლით ნიუ დეპო" -> "New Depo".
 *
 * ყველა ვორონკის და სტადიის დილს ამოწმებს. რამდენიმე პროდუქტიან დილზე ველი
 * " /"-ით არის გაერთიანებული (saveApartment.php), ამიტომ იცვლება მხოლოდ ის
 * ნაწილი, რომელიც "მონოლით ნიუ დეპო"-ს ემთხვევა.
 *
 * გახსნისას მხოლოდ აჩვენებს რა შეიცვლება, ჩაწერა ღილაკით ხდება.
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/projectNewDepo.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');

define('D_PROJECT', 'UF_CRM_1779277729207');
define('FROM_PROJECT', 'მონოლით ნიუ დეპო');
define('TO_PROJECT', 'New Depo');
define('BATCH_LIMIT', 500);

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['apply'] ?? '') === '1'
    && check_bitrix_sessid();
$sessidField = bitrix_sessid_post();

if (function_exists('session_write_close')) {
    session_write_close();
}

function ndFieldText($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function ndNorm($value)
{
    $value = str_replace("\xc2\xa0", ' ', (string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return mb_strtolower(trim($value));
}

/** [ახალი მნიშვნელობა, შეიცვალა თუ არა] */
function ndReplaceProject($text)
{
    $from = ndNorm(FROM_PROJECT);
    $parts = preg_split('/\s*\/\s*/u', $text);
    $matched = false;
    foreach ($parts as $i => $part) {
        if (ndNorm($part) === $from) {
            $parts[$i] = TO_PROJECT;
            $matched = true;
        } else {
            $parts[$i] = trim($part);
        }
    }
    if (!$matched) {
        return [$text, false];
    }
    return [implode(' /', $parts), true];
}

function ndLoadStageNames()
{
    $categories = [0 => 'ძირითადი'];
    if (class_exists('\Bitrix\Crm\Category\DealCategory')) {
        try {
            foreach ((array)\Bitrix\Crm\Category\DealCategory::getAll(true) as $cat) {
                $id = (int)($cat['ID'] ?? 0);
                if ($id > 0) {
                    $categories[$id] = (string)($cat['NAME'] ?? ('ვორონკა ' . $id));
                }
            }
        } catch (Throwable $e) {
            // მხოლოდ ძირითადი ვორონკა დარჩება
        }
    }
    $stages = [];
    foreach (array_keys($categories) as $catId) {
        $list = CCrmStatus::GetStatusList($catId > 0 ? 'DEAL_STAGE_' . $catId : 'DEAL_STAGE');
        $stages[$catId] = is_array($list) ? $list : [];
    }
    return [$categories, $stages];
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

[$categories, $stageNames] = ndLoadStageNames();

// 1. დილების სკანირება
$matches = [];
$similar = [];
$scanned = 0;
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    ['CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY', D_PROJECT]
);
while ($deal = $res->Fetch()) {
    $scanned++;
    $project = ndFieldText($deal[D_PROJECT] ?? '');
    if ($project === '') {
        continue;
    }

    [$newProject, $matched] = ndReplaceProject($project);

    $norm = ndNorm($project);
    if (mb_strpos($norm, 'დეპო') !== false || mb_strpos($norm, 'depo') !== false) {
        if (!isset($similar[$project])) {
            $similar[$project] = ['count' => 0, 'changes' => $matched];
        }
        $similar[$project]['count']++;
    }

    if (!$matched) {
        continue;
    }

    $categoryId = (int)($deal['CATEGORY_ID'] ?? 0);
    $stageId = (string)($deal['STAGE_ID'] ?? '');
    $matches[] = [
        'deal'       => $deal,
        'before'     => $project,
        'after'      => $newProject,
        'stage_name' => (string)($stageNames[$categoryId][$stageId] ?? $stageId),
        'category'   => (string)($categories[$categoryId] ?? ('#' . $categoryId)),
        'status'     => 'would_update',
    ];
}
uasort($similar, function ($a, $b) {
    return $b['count'] <=> $a['count'];
});

// 2. ჩაწერა
$counts = ['updated' => 0, 'failed' => 0, 'remaining' => 0];
if ($apply) {
    $processed = 0;
    foreach ($matches as $i => $row) {
        if ($processed >= BATCH_LIMIT) {
            $matches[$i]['status'] = 'next_batch';
            $counts['remaining']++;
            continue;
        }
        $processed++;

        $deal = $row['deal'];
        // OPPORTUNITY / CURRENCY_ID / IS_MANUAL_OPPORTUNITY უცვლელად გადაეცემა,
        // რომ Update-მა დილის თანხა თავიდან არ გადათვალოს
        $fields = [
            D_PROJECT               => $row['after'],
            'OPPORTUNITY'           => round((float)($deal['OPPORTUNITY'] ?? 0), 2),
            'IS_MANUAL_OPPORTUNITY' => ($deal['IS_MANUAL_OPPORTUNITY'] ?? '') === 'Y' ? 'Y' : 'N',
        ];
        if ((string)($deal['CURRENCY_ID'] ?? '') !== '') {
            $fields['CURRENCY_ID'] = (string)$deal['CURRENCY_ID'];
        }

        $crmDeal = new CCrmDeal(false);
        if ($crmDeal->Update((int)$deal['ID'], $fields)) {
            $matches[$i]['status'] = 'updated';
            $counts['updated']++;
        } else {
            $matches[$i]['status'] = 'update_failed';
            $matches[$i]['error'] = (string)$crmDeal->LAST_ERROR;
            $counts['failed']++;
        }
    }
}

$statusLabels = [
    'would_update'  => 'შეიცვლება',
    'updated'       => 'განახლდა',
    'update_failed' => 'შეცდომა',
    'next_batch'    => 'შემდეგ გაშვებაზე',
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>პროექტი: New Depo</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --danger: #c0392b;
            --ok: #0e7c66;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap { max-width: 1280px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; line-height: 1.5; }
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            margin-bottom: 20px;
        }
        .row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        button, .btn {
            height: 40px;
            border: 0;
            border-radius: 8px;
            padding: 0 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: var(--text);
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        button.apply { background: var(--ok); }
        button:disabled { opacity: .45; cursor: not-allowed; }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .chip { background: #eef3f7; border-radius: 999px; padding: 6px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        .st-would_update, .st-updated { color: var(--ok); font-weight: 600; }
        .st-next_batch { color: var(--muted); }
        .st-update_failed { color: var(--danger); font-weight: 600; }
        .old { color: var(--danger); text-decoration: line-through; }
        .new { color: var(--ok); font-weight: 600; }
        .muted { color: var(--muted); font-size: 12px; }
        .warn { color: var(--danger); font-size: 13px; margin: 8px 0 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>პროექტი: "<?= h(FROM_PROJECT) ?>" → "<?= h(TO_PROJECT) ?>"</h1>
    <p class="sub">
        ყველა დილზე, სადაც პროექტის ველში (<?= h(D_PROJECT) ?>) წერია "<?= h(FROM_PROJECT) ?>",
        ჩაიწერება "<?= h(TO_PROJECT) ?>". სხვა ველებს და პროდუქტებს არ ეხება.
        ერთ გაშვებაზე მაქსიმუმ <?= (int)BATCH_LIMIT ?> დილი ახლდება.
    </p>

    <form class="card" method="post" id="apply-form">
        <?= $sessidField ?>
        <input type="hidden" name="apply" value="1">
        <div class="counts">
            <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
            <span class="chip">შემოწმდა დილი: <?= (int)$scanned ?></span>
            <span class="chip">"<?= h(FROM_PROJECT) ?>": <?= count($matches) ?></span>
            <?php if ($apply): ?>
                <span class="chip">განახლდა: <?= (int)$counts['updated'] ?></span>
                <span class="chip">შეცდომა: <?= (int)$counts['failed'] ?></span>
                <span class="chip">დარჩა: <?= (int)$counts['remaining'] ?></span>
            <?php endif; ?>
        </div>
        <div class="row">
            <?php if (!$apply): ?>
                <button type="submit" class="apply" id="apply-btn" <?= $matches ? '' : 'disabled' ?>>
                    ჩაწერა (<?= min(count($matches), BATCH_LIMIT) ?> დილი)
                </button>
            <?php endif; ?>
            <a class="btn" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">თავიდან შემოწმება</a>
        </div>
        <?php if ($apply && $counts['remaining'] > 0): ?>
            <p class="warn">დარჩა <?= (int)$counts['remaining'] ?> დილი. დააჭირე "თავიდან შემოწმება" და ჩაწერე ისევ.</p>
        <?php endif; ?>
    </form>

    <?php if ($similar): ?>
        <div class="card">
            <h2>პროექტის ველის მნიშვნელობები, რომლებიც შეიცავს "დეპო" / "depo"</h2>
            <table>
                <thead>
                <tr>
                    <th>მნიშვნელობა</th>
                    <th>დილები</th>
                    <th>შეიცვლება</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($similar as $value => $info): ?>
                    <tr>
                        <td><?= h($value) ?></td>
                        <td><?= (int)$info['count'] ?></td>
                        <td><?= $info['changes'] ? '<span class="new">კი</span>' : '<span class="muted">არა</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if (!$matches): ?>
            <p class="muted">"<?= h(FROM_PROJECT) ?>" პროექტით დილი ვერ მოიძებნა.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>დილი</th>
                    <th>სტადია</th>
                    <th>პროექტი</th>
                    <th>სტატუსი</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $r):
                    $dealId = (int)$r['deal']['ID'];
                    $st = (string)$r['status'];
                ?>
                    <tr>
                        <td>
                            <a href="/crm/deal/details/<?= $dealId ?>/" target="_blank">#<?= $dealId ?></a>
                            <div><?= h($r['deal']['TITLE'] ?? '') ?></div>
                        </td>
                        <td>
                            <?= h($r['stage_name']) ?>
                            <div class="muted"><?= h($r['category']) ?></div>
                        </td>
                        <td>
                            <span class="old"><?= h($r['before']) ?></span>
                            → <span class="new"><?= h($r['after']) ?></span>
                        </td>
                        <td>
                            <span class="st-<?= h($st) ?>"><?= h($statusLabels[$st] ?? $st) ?></span>
                            <?php if (!empty($r['error'])): ?>
                                <div class="warn"><?= h($r['error']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<script>
    (function () {
        const form = document.getElementById('apply-form');
        const btn = document.getElementById('apply-btn');
        if (!btn) return;
        form.addEventListener('submit', function (e) {
            const ok = confirm(
                'დარწმუნებული ხარ?\n\n' +
                <?= json_encode('პროექტის ველში "' . FROM_PROJECT . '" შეიცვლება "' . TO_PROJECT . '"-ით.', JSON_UNESCAPED_UNICODE) ?> + '\n' +
                <?= json_encode('განახლდება ' . min(count($matches), BATCH_LIMIT) . ' დილი.', JSON_UNESCAPED_UNICODE) ?>
            );
            if (!ok) {
                e.preventDefault();
                return;
            }
            btn.disabled = true;
            btn.textContent = 'მიმდინარეობს ჩაწერა...';
        });
    })();
</script>
</body>
</html>
