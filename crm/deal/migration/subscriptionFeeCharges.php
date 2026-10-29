<?php
/**
 * აბონირების დარიცხვა (SUBSCRIPTION_CHARGES) არსებულ დილებზე, რომლებიც ხელშეკრულების ან შემდეგ სტადიაზე უკვე დგას.
 *
 * ახალ დილს დარიცხვას subscription_fee.php სტადიის გადასვლისას თვითონ უქმნის; ეს გვერდი იმ დილებისთვისაა,
 * რომლებიც ამ სტადიაზე აბონირების (ან ახალი ფართის ტიპის, მაგ. კომერციულის) დამატებამდე იდგნენ.
 * დარიცხვას ქმნის subscriptionFeeEnsureCharge() - იგივე, რაც სტადიის გადასვლისას. დარიცხვიან დილს არ ეხება.
 *
 * გახსნისას მხოლოდ აჩვენებს რა შეიქმნება, ჩაწერა ღილაკით ხდება.
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/subscriptionFeeCharges.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

define('BATCH_LIMIT', 500);
define('DEFAULT_TYPE', 'all');

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['apply'] ?? '') === '1'
    && check_bitrix_sessid();
$sessidField = bitrix_sessid_post();

if (function_exists('session_write_close')) {
    session_write_close();
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');

if (!function_exists('subscriptionFeeEnsureCharge') || subscriptionFeeChargeIblockId() <= 0) {
    echo 'აბონირების სია ვერ მოიძებნა: local/php_interface/subscription_fee.php არ ჩაიტვირთა,'
        . ' ან /crm/deal/bank_integration/setup.php ჯერ არ გაშვებულა.';
    exit;
}

// ფილტრი ფართის ტიპით: ერთი ტიპი ან ყველა (all)
$filterType = trim((string)($_REQUEST['type'] ?? DEFAULT_TYPE));
if ($filterType !== 'all' && !in_array($filterType, SUBSCRIPTION_FEE_UNIT_TYPES, true)) {
    $filterType = DEFAULT_TYPE;
}

$stageNames = CCrmStatus::GetStatusList('DEAL_STAGE');
if (!is_array($stageNames)) {
    $stageNames = [];
}

// 1. ხელშეკრულების და შემდეგი სტადიების დილები, რომლებსაც აბონირება ეკუთვნის (პროექტი + ფართის ტიპი)
$deals = [];
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    ['@STAGE_ID' => SUBSCRIPTION_FEE_STAGES, 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    array_merge(subscriptionFeeDealSelect(), ['TITLE'])
);
while ($deal = $res->Fetch()) {
    if (subscriptionFeeAmountForDeal($deal) > 0) {
        $deals[(int)$deal['ID']] = $deal;
    }
}

// 2. არსებული დარიცხვები
$charges = [];
foreach (array_chunk(array_keys($deals), 500) as $chunk) {
    $charges += subscriptionFeeChargesByDeal($chunk);
}

// 3. დარიცხვის გარეშე დილები + ტიპების ჯამი
$summary = [];
foreach (SUBSCRIPTION_FEE_UNIT_TYPES as $type) {
    $summary[$type] = ['has' => 0, 'missing' => 0];
}
$matches = [];
foreach ($deals as $dealId => $deal) {
    $type = subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_TYPE] ?? '');
    if (isset($charges[$dealId])) {
        $summary[$type]['has']++;
        continue;
    }
    $summary[$type]['missing']++;
    if ($filterType !== 'all' && $type !== $filterType) {
        continue;
    }
    $stageId = (string)($deal['STAGE_ID'] ?? '');
    $matches[] = [
        'deal'       => $deal,
        'type'       => $type,
        'amount'     => subscriptionFeeAmountForDeal($deal),
        'stage_name' => (string)($stageNames[$stageId] ?? $stageId),
        'status'     => 'would_create',
    ];
}

// 4. ჩაწერა
$counts = ['created' => 0, 'failed' => 0, 'remaining' => 0];
if ($apply) {
    $processed = 0;
    foreach ($matches as $i => $row) {
        if ($processed >= BATCH_LIMIT) {
            $matches[$i]['status'] = 'next_batch';
            $counts['remaining']++;
            continue;
        }
        $processed++;

        $dealId = (int)$row['deal']['ID'];
        if (subscriptionFeeEnsureCharge($dealId, $row['deal']) > 0) {
            $matches[$i]['status'] = 'created';
            $counts['created']++;
        } else {
            $matches[$i]['status'] = 'create_failed';
            $counts['failed']++;
        }
    }
}

$statusLabels = [
    'would_create'  => 'შეიქმნება',
    'created'       => 'შეიქმნა',
    'create_failed' => 'შეცდომა',
    'next_batch'    => 'შემდეგ გაშვებაზე',
];
$selfUrl = strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>აბონირების დარიცხვა არსებულ დილებზე</title>
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
        select { height: 40px; border-radius: 8px; border: 1px solid #d5dde5; padding: 0 10px; font-size: 14px; color: var(--text); }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .chip { background: #eef3f7; border-radius: 999px; padding: 6px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        .st-would_create, .st-created { color: var(--ok); font-weight: 600; }
        .st-next_batch { color: var(--muted); }
        .st-create_failed { color: var(--danger); font-weight: 600; }
        .muted { color: var(--muted); font-size: 12px; }
        .warn { color: var(--danger); font-size: 13px; margin: 8px 0 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>აბონირების დარიცხვა არსებულ დილებზე</h1>
    <p class="sub">
        Dighomi / Green City-ის დილები (<?= h(implode(', ', SUBSCRIPTION_FEE_UNIT_TYPES)) ?>), რომლებიც ხელშეკრულების ან
        შემდეგ სტადიაზე დგას და დარიცხვა ჯერ არ აქვთ. დარიცხვა იქმნება ისევე, როგორც სტადიის გადასვლისას:
        თანხა პროექტით, გადახდილი / დარჩენილი უკვე არსებული გადახდებიდან. დარიცხვიან დილებს არ ეხება.
        ერთ გაშვებაზე მაქსიმუმ <?= (int)BATCH_LIMIT ?> დარიცხვა იქმნება.
    </p>

    <form class="card" method="get">
        <div class="counts">
            <?php foreach ($summary as $type => $c): ?>
                <span class="chip"><?= h($type) ?>: დარიცხვით <?= (int)$c['has'] ?>, დარიცხვის გარეშე <?= (int)$c['missing'] ?></span>
            <?php endforeach; ?>
        </div>
        <div class="row">
            <label for="type">ფართის ტიპი:</label>
            <select name="type" id="type" onchange="this.form.submit()">
                <?php foreach (SUBSCRIPTION_FEE_UNIT_TYPES as $type): ?>
                    <option value="<?= h($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>ყველა</option>
            </select>
        </div>
    </form>

    <form class="card" method="post" action="<?= h($selfUrl) ?>" id="apply-form">
        <?= $sessidField ?>
        <input type="hidden" name="apply" value="1">
        <input type="hidden" name="type" value="<?= h($filterType) ?>">
        <div class="counts">
            <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
            <span class="chip">შესაქმნელი: <?= count($matches) ?></span>
            <?php if ($apply): ?>
                <span class="chip">შეიქმნა: <?= (int)$counts['created'] ?></span>
                <span class="chip">შეცდომა: <?= (int)$counts['failed'] ?></span>
                <span class="chip">დარჩა: <?= (int)$counts['remaining'] ?></span>
            <?php endif; ?>
        </div>
        <div class="row">
            <?php if (!$apply): ?>
                <button type="submit" class="apply" id="apply-btn" <?= $matches ? '' : 'disabled' ?>>
                    დარიცხვის შექმნა (<?= min(count($matches), BATCH_LIMIT) ?> დილი)
                </button>
            <?php endif; ?>
            <a class="btn" href="<?= h($selfUrl . '?type=' . rawurlencode($filterType)) ?>">თავიდან შემოწმება</a>
        </div>
        <?php if ($apply && $counts['remaining'] > 0): ?>
            <p class="warn">დარჩა <?= (int)$counts['remaining'] ?> დილი. დააჭირე "თავიდან შემოწმება" და შექმენი ისევ.</p>
        <?php endif; ?>
    </form>

    <div class="card">
        <?php if (!$matches): ?>
            <p class="muted">შესაქმნელი არაფერია: ამ ტიპის ყველა დილს დარიცხვა უკვე აქვს.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>დილი</th>
                    <th>კლიენტი</th>
                    <th>პროექტი / ბლოკი / #</th>
                    <th>ტიპი</th>
                    <th>სტადია</th>
                    <th>თანხა ₾</th>
                    <th>სტატუსი</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $r):
                    $deal = $r['deal'];
                    $dealId = (int)$deal['ID'];
                    $props = subscriptionFeeDealProps($deal);
                    $st = (string)$r['status'];
                ?>
                    <tr>
                        <td>
                            <a href="/crm/deal/details/<?= $dealId ?>/" target="_blank">#<?= $dealId ?></a>
                            <div><?= h($deal['TITLE'] ?? '') ?></div>
                        </td>
                        <td><?= h($props['FULL_NAME']) ?></td>
                        <td><?= h(implode(' / ', [$props['PROJECT'], $props['KORPUSI'], $props['BINIS_NOMERI']])) ?></td>
                        <td><?= h($r['type']) ?></td>
                        <td><?= h($r['stage_name']) ?></td>
                        <td><?= h(number_format($r['amount'], 2, '.', ' ')) ?></td>
                        <td><span class="st-<?= h($st) ?>"><?= h($statusLabels[$st] ?? $st) ?></span></td>
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
                <?= json_encode('სიაში "აბონირების დარიცხვა" შეიქმნება ' . min(count($matches), BATCH_LIMIT) . ' ელემენტი.', JSON_UNESCAPED_UNICODE) ?>
            );
            if (!ok) {
                e.preventDefault();
                return;
            }
            btn.disabled = true;
            btn.textContent = 'მიმდინარეობს შექმნა...';
        });
    })();
</script>
</body>
</html>
