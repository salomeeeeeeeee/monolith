<?php
/**
 * Dailo სტატისტიკის სიების შექმნა — ერთჯერადი, იდემპოტენტური სკრიპტი.
 *
 * URL: https://crm.monolith.ge/custom/setup/dailoStatsLists.php
 *
 * ქმნის ორ სიას (თუ უკვე არსებობს — მხოლოდ დაკლებულ ველებს ამატებს):
 *   DAILO_STATS_DAILY   — 1 ჩანაწერი = 1 დღე
 *   DAILO_STATS_CHANNEL — 1 ჩანაწერი = დღე + არხი
 *
 * სიის ტიპს, საიტს და უფლებებს არსებული "Dailo API" სიიდან (iblock 26) იღებს,
 * რომ ახალი სიები იმავე ადგილას გამოჩნდეს, სადაც დანარჩენი.
 *
 * თარიღი განზრახ სტრიქონია YYYY-MM-DD ფორმატში — ასე სორტირება და პერიოდის
 * ფილტრი ლექსიკოგრაფიულადვე მუშაობს, თარიღის ფორმატის გარდაქმნების გარეშე.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

@set_time_limit(0);

CModule::IncludeModule('iblock');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('Dailo სტატისტიკის სიები');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b;font:14px Arial,sans-serif">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('STATS_TEMPLATE_IBLOCK_ID', 26); // Dailo API log — ტიპის/საიტის/უფლებების წყარო

$LISTS = [
    [
        'CODE'  => 'DAILO_STATS_DAILY',
        'NAME'  => 'Dailo — დღიური სტატისტიკა',
        'PROPS' => [
            ['CODE' => 'STAT_DATE',         'NAME' => 'თარიღი (YYYY-MM-DD)',         'TYPE' => 'S'],
            ['CODE' => 'PERIOD_FROM',       'NAME' => 'პერიოდი დან',                 'TYPE' => 'S'],
            ['CODE' => 'PERIOD_TO',         'NAME' => 'პერიოდი მდე',                 'TYPE' => 'S'],
            ['CODE' => 'CONVERSATIONS',     'NAME' => 'საუბრები',                    'TYPE' => 'N'],
            ['CODE' => 'LEADS',             'NAME' => 'ლიდები',                      'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_TOTAL',    'NAME' => 'კომენტარები — სულ',           'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_ANSWERED', 'NAME' => 'კომენტარები — პასუხგაცემული', 'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_HIDDEN',   'NAME' => 'კომენტარები — დამალული',      'TYPE' => 'N'],
            ['CODE' => 'RECEIVED_AT',       'NAME' => 'მიღების დრო',                 'TYPE' => 'S'],
            ['CODE' => 'RAW_JSON',          'NAME' => 'JSON (როგორც მოვიდა)',        'TYPE' => 'S', 'ROWS' => 5],
        ],
    ],
    [
        'CODE'  => 'DAILO_STATS_CHANNEL',
        'NAME'  => 'Dailo — სტატისტიკა არხების მიხედვით',
        'PROPS' => [
            ['CODE' => 'STAT_DATE',     'NAME' => 'თარიღი (YYYY-MM-DD)', 'TYPE' => 'S'],
            ['CODE' => 'CHANNEL',       'NAME' => 'არხი',                'TYPE' => 'S'],
            ['CODE' => 'CONVERSATIONS', 'NAME' => 'საუბრები',            'TYPE' => 'N'],
            ['CODE' => 'LEADS',         'NAME' => 'ლიდები',              'TYPE' => 'N'],
            ['CODE' => 'PARENT_ID',     'NAME' => 'დღიური ჩანაწერის ID', 'TYPE' => 'N'],
        ],
    ],
];

function statsSetupTemplate()
{
    $row = CIBlock::GetList([], ['ID' => STATS_TEMPLATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'])->Fetch();

    if (!$row) {
        return ['IBLOCK_TYPE_ID' => 'lists', 'LID' => 's1', 'GROUP_ID' => [1 => 'X', 2 => 'R']];
    }

    $groups = CIBlock::GetGroupPermissions(STATS_TEMPLATE_IBLOCK_ID);

    return [
        'IBLOCK_TYPE_ID' => $row['IBLOCK_TYPE_ID'],
        'LID'            => $row['LID'],
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
    ];
}

function statsSetupFindIblock($code)
{
    $row = CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $row ? (int)$row['ID'] : 0;
}

function statsSetupCreateIblock(array $definition, array $template, &$error)
{
    $ib = new CIBlock();

    $id = $ib->Add([
        'ACTIVE'         => 'Y',
        'NAME'           => $definition['NAME'],
        'CODE'           => $definition['CODE'],
        'XML_ID'         => $definition['CODE'],
        'IBLOCK_TYPE_ID' => $template['IBLOCK_TYPE_ID'],
        'SITE_ID'        => [$template['LID']],
        'SORT'           => 500,
        'VERSION'        => 2,
        'INDEX_ELEMENT'  => 'N',
        'INDEX_SECTION'  => 'N',
        'WORKFLOW'       => 'N',
        'BIZPROC'        => 'N',
        'LIST_MODE'      => 'S',
        'GROUP_ID'       => $template['GROUP_ID'],
    ]);

    if (!$id) {
        $error = $ib->LAST_ERROR;
        return 0;
    }

    return (int)$id;
}

function statsSetupExistingPropertyCodes($iblockId)
{
    $codes = [];

    $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
    while ($row = $res->Fetch()) {
        if (!empty($row['CODE'])) {
            $codes[] = $row['CODE'];
        }
    }

    return $codes;
}

function statsSetupAddProperty($iblockId, array $property, $sort, &$error)
{
    $fields = [
        'IBLOCK_ID'     => $iblockId,
        'NAME'          => $property['NAME'],
        'CODE'          => $property['CODE'],
        'PROPERTY_TYPE' => $property['TYPE'],
        'ACTIVE'        => 'Y',
        'SORT'          => $sort,
        'IS_REQUIRED'   => 'N',
        'MULTIPLE'      => 'N',
        'FILTRABLE'     => 'Y',
        'SEARCHABLE'    => 'N',
    ];

    if (!empty($property['ROWS'])) {
        $fields['ROW_COUNT'] = (int)$property['ROWS'];
        $fields['COL_COUNT'] = 60;
    }

    $prop = new CIBlockProperty();
    $id = $prop->Add($fields);

    if (!$id) {
        $error = $prop->LAST_ERROR;
        return 0;
    }

    return (int)$id;
}

// ── გაშვება ─────────────────────────────────────────────────────────────

$template = statsSetupTemplate();
$report = [];

foreach ($LISTS as $definition) {
    $entry = [
        'CODE'        => $definition['CODE'],
        'NAME'        => $definition['NAME'],
        'ID'          => 0,
        'CREATED'     => false,
        'PROPS_ADDED' => [],
        'PROPS_KEPT'  => [],
        'ELEMENTS'    => 0,
        'ERRORS'      => [],
    ];

    $iblockId = statsSetupFindIblock($definition['CODE']);

    if ($iblockId <= 0) {
        $error = '';
        $iblockId = statsSetupCreateIblock($definition, $template, $error);

        if ($iblockId <= 0) {
            $entry['ERRORS'][] = 'სია ვერ შეიქმნა: ' . $error;
            $report[] = $entry;
            continue;
        }

        $entry['CREATED'] = true;
    }

    $entry['ID'] = $iblockId;

    $existingCodes = statsSetupExistingPropertyCodes($iblockId);
    $sort = 100;

    foreach ($definition['PROPS'] as $property) {
        if (in_array($property['CODE'], $existingCodes, true)) {
            $entry['PROPS_KEPT'][] = $property['CODE'];
            $sort += 100;
            continue;
        }

        $error = '';
        if (statsSetupAddProperty($iblockId, $property, $sort, $error) > 0) {
            $entry['PROPS_ADDED'][] = $property['CODE'];
        } else {
            $entry['ERRORS'][] = $property['CODE'] . ' — ' . $error;
        }

        $sort += 100;
    }

    $entry['ELEMENTS'] = (int)CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
        []
    );

    $report[] = $entry;
}

$token = '';
$endpointFile = $_SERVER['DOCUMENT_ROOT'] . '/rest/public/addStats.php';
if (is_readable($endpointFile) && preg_match("/STATS_API_TOKEN\s*=\s*'([^']+)'/", file_get_contents($endpointFile), $m)) {
    $token = $m[1];
}
?>
<style>
    .stats-setup { font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 900px; padding: 16px 0; }
    .stats-setup h2 { margin: 24px 0 8px; font-size: 18px; }
    .stats-setup table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
    .stats-setup th, .stats-setup td { border: 1px solid #dfe3e7; padding: 6px 10px; text-align: left; vertical-align: top; }
    .stats-setup th { background: #f4f6f8; width: 220px; }
    .stats-setup .ok { color: #1a8f3c; }
    .stats-setup .err { color: #c0392b; }
    .stats-setup pre { background: #f4f6f8; border: 1px solid #dfe3e7; padding: 12px; overflow-x: auto; }
</style>

<div class="stats-setup">
<?php foreach ($report as $entry): ?>
    <h2><?= htmlspecialcharsbx($entry['NAME']) ?></h2>
    <table>
        <tr><th>CODE</th><td><?= htmlspecialcharsbx($entry['CODE']) ?></td></tr>
        <tr><th>IBLOCK_ID</th><td><?= (int)$entry['ID'] ?></td></tr>
        <tr>
            <th>სტატუსი</th>
            <td class="<?= $entry['CREATED'] ? 'ok' : '' ?>">
                <?= $entry['CREATED'] ? 'ახლად შეიქმნა' : 'უკვე არსებობდა' ?>
            </td>
        </tr>
        <tr>
            <th>დამატებული ველები</th>
            <td><?= $entry['PROPS_ADDED'] ? htmlspecialcharsbx(implode(', ', $entry['PROPS_ADDED'])) : '—' ?></td>
        </tr>
        <tr>
            <th>უკვე არსებული ველები</th>
            <td><?= $entry['PROPS_KEPT'] ? htmlspecialcharsbx(implode(', ', $entry['PROPS_KEPT'])) : '—' ?></td>
        </tr>
        <tr><th>ჩანაწერები</th><td><?= (int)$entry['ELEMENTS'] ?></td></tr>
        <?php if (!empty($entry['ERRORS'])): ?>
            <tr>
                <th>შეცდომები</th>
                <td class="err"><?= htmlspecialcharsbx(implode(' | ', $entry['ERRORS'])) ?></td>
            </tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($entry['ID'])): ?>
        <a href="/bitrix/admin/iblock_element_admin.php?IBLOCK_ID=<?= (int)$entry['ID'] ?>&amp;type=<?= htmlspecialcharsbx($template['IBLOCK_TYPE_ID']) ?>&amp;lang=<?= LANGUAGE_ID ?>">
            ჩანაწერების ნახვა →
        </a>
    <?php endif; ?>
<?php endforeach; ?>

    <h2>ენდპოინტის შემოწმება</h2>
    <pre>curl -X POST https://crm.monolith.ge/rest/public/addStats.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?= htmlspecialcharsbx($token !== '' ? $token : '<TOKEN>') ?>" \
  -d '{"date":"<?= date('Y-m-d') ?>","totals":{"conversations":340,"leads":47,"comments":96},
       "channels":[{"channel":"Messenger","conversations":210,"leads":28}],
       "comments":{"total":96,"answered":71,"hidden":5}}'</pre>
    <p>ერთი და იმავე თარიღის ხელახლა გაგზავნა ჩანაწერს <b>აახლებს</b>, ახალს არ ქმნის.</p>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
