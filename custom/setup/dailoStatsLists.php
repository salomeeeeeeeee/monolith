<?php
/**
 * Dailo სტატისტიკის სიების შექმნა - იდემპოტენტური სკრიპტი.
 *
 * URL:       https://crm.monolith.ge/custom/setup/dailoStatsLists.php
 * Rebuild:   ?rebuild=1   - ცარიელ სიებს წაშლის და თავიდან შექმნის
 *
 * ქმნის ორ სიას:
 *   DAILO_STATS_DAILY   - 1 ჩანაწერი = 1 დღე
 *   DAILO_STATS_CHANNEL - 1 ჩანაწერი = დღე + არხი
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField), და არა პირდაპირ
 * CIBlockProperty::Add-ით: Lists-ს ველების საკუთარი რეგისტრი აქვს და "ნედლი"
 * თვისება ბაზაში დევს, ინტერფეისში კი არ ჩანს.
 *
 * iblock-ის პარამეტრები (ტიპი, საიტი, უფლებები, VERSION, BIZPROC...) არსებული
 * მომუშავე სიიდან - "Dailo API log" (iblock 26) - კოპირდება, რომ ახალი სიები
 * ზუსტად ისევე მოიქცნენ.
 *
 * თარიღი განზრახ სტრიქონია YYYY-MM-DD ფორმატში - ასე სორტირება და პერიოდის
 * ფილტრი ლექსიკოგრაფიულადვე მუშაობს, თარიღის ფორმატის გარდაქმნების გარეშე.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

@set_time_limit(0);

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('Dailo სტატისტიკის სიები');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('STATS_TEMPLATE_IBLOCK_ID', 26); // Dailo API log - პარამეტრების წყარო

$rebuild = isset($_GET['rebuild']) && $_GET['rebuild'] === '1';

$LISTS = [
    [
        'CODE'  => 'DAILO_STATS_DAILY',
        'NAME'  => 'Dailo - დღიური სტატისტიკა',
        'PROPS' => [
            ['CODE' => 'STAT_DATE',         'NAME' => 'თარიღი (YYYY-MM-DD)',         'TYPE' => 'S'],
            ['CODE' => 'PERIOD_FROM',       'NAME' => 'პერიოდი დან',                 'TYPE' => 'S'],
            ['CODE' => 'PERIOD_TO',         'NAME' => 'პერიოდი მდე',                 'TYPE' => 'S'],
            ['CODE' => 'CONVERSATIONS',     'NAME' => 'საუბრები',                    'TYPE' => 'N'],
            ['CODE' => 'LEADS',             'NAME' => 'ლიდები',                      'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_TOTAL',    'NAME' => 'კომენტარები - სულ',           'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_ANSWERED', 'NAME' => 'კომენტარები - პასუხგაცემული', 'TYPE' => 'N'],
            ['CODE' => 'COMMENTS_HIDDEN',   'NAME' => 'კომენტარები - დამალული',      'TYPE' => 'N'],
            ['CODE' => 'RECEIVED_AT',       'NAME' => 'მიღების დრო',                 'TYPE' => 'S'],
            ['CODE' => 'RAW_JSON',          'NAME' => 'JSON (როგორც მოვიდა)',        'TYPE' => 'S', 'ROWS' => 5],
        ],
    ],
    [
        'CODE'  => 'DAILO_STATS_CHANNEL',
        'NAME'  => 'Dailo - სტატისტიკა არხების მიხედვით',
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
        return [
            'IBLOCK_TYPE_ID' => 'lists',
            'LID'            => 's1',
            'GROUP_ID'       => [1 => 'X', 2 => 'R'],
            'VERSION'        => 1,
            'BIZPROC'        => 'N',
            'INDEX_ELEMENT'  => 'N',
            'LIST_MODE'      => '',
        ];
    }

    $groups = CIBlock::GetGroupPermissions(STATS_TEMPLATE_IBLOCK_ID);

    return [
        'IBLOCK_TYPE_ID' => $row['IBLOCK_TYPE_ID'],
        'LID'            => $row['LID'],
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION'        => (int)$row['VERSION'],
        'BIZPROC'        => $row['BIZPROC'],
        'INDEX_ELEMENT'  => $row['INDEX_ELEMENT'],
        'LIST_MODE'      => $row['LIST_MODE'],
    ];
}

function statsSetupFindIblock($code)
{
    $row = CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $row ? (int)$row['ID'] : 0;
}

function statsSetupElementCount($iblockId)
{
    return (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'], []);
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
        'VERSION'        => $template['VERSION'],
        'BIZPROC'        => $template['BIZPROC'],
        'INDEX_ELEMENT'  => $template['INDEX_ELEMENT'],
        'INDEX_SECTION'  => 'N',
        'LIST_MODE'      => $template['LIST_MODE'],
        'WORKFLOW'       => 'N',
        'GROUP_ID'       => $template['GROUP_ID'],
    ]);

    if (!$id) {
        $error = $ib->LAST_ERROR;
        return 0;
    }

    return (int)$id;
}

/** CODE => თვისების ID (რაც ბაზაშია, Lists-ში დარეგისტრირების მიუხედავად). */
function statsSetupPropertyMap($iblockId)
{
    $map = [];

    $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
    while ($row = $res->Fetch()) {
        if (!empty($row['CODE'])) {
            $map[$row['CODE']] = (int)$row['ID'];
        }
    }

    return $map;
}

/** ის CODE-ები, რომლებსაც Lists მოდული ხედავს. */
function statsSetupRegisteredCodes($iblockId, array $propertyMap)
{
    if (!class_exists('CList')) {
        return [];
    }

    $idToCode = array_flip($propertyMap);
    $codes = [];

    $obList = new CList($iblockId);
    foreach (array_keys($obList->GetFields()) as $fieldId) {
        if (preg_match('/^PROPERTY_(\d+)$/', $fieldId, $m)) {
            $propertyId = (int)$m[1];
            if (isset($idToCode[$propertyId])) {
                $codes[] = $idToCode[$propertyId];
            }
        }
    }

    return $codes;
}

function statsSetupFieldValues(array $property, $sort)
{
    $fields = [
        'NAME'        => $property['NAME'],
        'CODE'        => $property['CODE'],
        'SORT'        => $sort,
        'ACTIVE'      => 'Y',
        'IS_REQUIRED' => 'N',
        'MULTIPLE'    => 'N',
        'FILTRABLE'   => 'Y',
        'SEARCHABLE'  => 'N',
    ];

    if (!empty($property['ROWS'])) {
        $fields['ROW_COUNT'] = (int)$property['ROWS'];
        $fields['COL_COUNT'] = 60;
    }

    return $fields;
}

/** ველის დამატება Lists-ის API-ით; თუ მოდული მიუწვდომელია - პირდაპირ თვისებად. */
function statsSetupAddField($obList, $iblockId, array $property, $sort, &$error)
{
    $fields = statsSetupFieldValues($property, $sort);

    if ($obList !== null) {
        $fields['TYPE'] = $property['TYPE'];
        $fieldId = $obList->AddField($fields);

        if (!$fieldId) {
            $error = 'CList::AddField ჩავარდა';
            return false;
        }

        return true;
    }

    $fields['IBLOCK_ID']     = $iblockId;
    $fields['PROPERTY_TYPE'] = $property['TYPE'];

    $prop = new CIBlockProperty();
    if (!$prop->Add($fields)) {
        $error = $prop->LAST_ERROR;
        return false;
    }

    return true;
}

// ── გაშვება ─────────────────────────────────────────────────────────────

$template = statsSetupTemplate();
$report = [];

foreach ($LISTS as $definition) {
    $entry = [
        'CODE'          => $definition['CODE'],
        'NAME'          => $definition['NAME'],
        'ID'            => 0,
        'CREATED'       => false,
        'REBUILT'       => false,
        'FIELDS_ADDED'  => [],
        'FIELDS_KEPT'   => [],
        'ELEMENTS'      => 0,
        'LIST_FIELDS'   => [],
        'ERRORS'        => [],
    ];

    $iblockId = statsSetupFindIblock($definition['CODE']);

    // rebuild - მხოლოდ ცარიელ სიას ვშლით, მონაცემიანს არასდროს
    if ($rebuild && $iblockId > 0) {
        if (statsSetupElementCount($iblockId) > 0) {
            $entry['ERRORS'][] = 'rebuild გამოტოვებულია: სიაში ჩანაწერებია';
        } elseif (CIBlock::Delete($iblockId)) {
            $entry['REBUILT'] = true;
            $iblockId = 0;
        } else {
            $entry['ERRORS'][] = 'ძველი სია ვერ წაიშალა';
        }
    }

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
    $elementCount = statsSetupElementCount($iblockId);
    $entry['ELEMENTS'] = $elementCount;

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;

    $propertyMap = statsSetupPropertyMap($iblockId);
    $registered  = statsSetupRegisteredCodes($iblockId, $propertyMap);
    $sort = 100;

    foreach ($definition['PROPS'] as $property) {
        $code = $property['CODE'];

        if (in_array($code, $registered, true)) {
            $entry['FIELDS_KEPT'][] = $code;
            $sort += 100;
            continue;
        }

        // ბაზაში დევს, მაგრამ Lists-ს არ უნახავს - ცარიელ სიაში ვშლით და თავიდან ვამატებთ
        if (isset($propertyMap[$code])) {
            if ($elementCount > 0) {
                $entry['ERRORS'][] = $code . ' - ნედლი თვისება რჩება (სიაში ჩანაწერებია)';
                $sort += 100;
                continue;
            }
            CIBlockProperty::Delete($propertyMap[$code]);
        }

        $error = '';
        if (statsSetupAddField($obList, $iblockId, $property, $sort, $error)) {
            $entry['FIELDS_ADDED'][] = $code;
        } else {
            $entry['ERRORS'][] = $code . ' - ' . $error;
        }

        $sort += 100;
    }

    if ($obList !== null && method_exists($obList, 'Save')) {
        $obList->Save();
    }

    // შემოწმება: რას ხედავს Lists მოდული ახლა
    if (class_exists('CList')) {
        $verify = new CList($iblockId);
        $entry['LIST_FIELDS'] = array_keys($verify->GetFields());
    }

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
    <p>
        <b>lists მოდული:</b> <?= $listsModule ? 'ჩატვირთულია' : '<span class="err">არ ჩაიტვირთა - ველები Lists-ში არ გამოჩნდება</span>' ?>
        · <a href="?rebuild=1" onclick="return confirm('ცარიელი სიები წაიშლება და თავიდან შეიქმნება. გავაგრძელო?')">თავიდან აგება</a>
    </p>

<?php foreach ($report as $entry): ?>
    <h2><?= htmlspecialcharsbx($entry['NAME']) ?></h2>
    <table>
        <tr><th>CODE</th><td><?= htmlspecialcharsbx($entry['CODE']) ?></td></tr>
        <tr><th>IBLOCK_ID</th><td><?= (int)$entry['ID'] ?></td></tr>
        <tr>
            <th>სტატუსი</th>
            <td class="<?= $entry['CREATED'] ? 'ok' : '' ?>">
                <?php if ($entry['REBUILT']): ?>
                    თავიდან აიგო
                <?php elseif ($entry['CREATED']): ?>
                    ახლად შეიქმნა
                <?php else: ?>
                    უკვე არსებობდა
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>დამატებული ველები</th>
            <td><?= $entry['FIELDS_ADDED'] ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_ADDED'])) : '-' ?></td>
        </tr>
        <tr>
            <th>უკვე დარეგისტრირებული</th>
            <td><?= $entry['FIELDS_KEPT'] ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_KEPT'])) : '-' ?></td>
        </tr>
        <tr><th>ჩანაწერები</th><td><?= (int)$entry['ELEMENTS'] ?></td></tr>
        <tr>
            <th>Lists ხედავს</th>
            <td class="<?= count($entry['LIST_FIELDS']) > 1 ? 'ok' : 'err' ?>">
                <?= $entry['LIST_FIELDS'] ? htmlspecialcharsbx(implode(', ', $entry['LIST_FIELDS'])) : '-' ?>
            </td>
        </tr>
        <?php if (!empty($entry['ERRORS'])): ?>
            <tr>
                <th>შეცდომები</th>
                <td class="err"><?= htmlspecialcharsbx(implode(' | ', $entry['ERRORS'])) ?></td>
            </tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($entry['ID'])): ?>
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/fields/">ველების კონფიგურაცია →</a> ·
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/view/">ჩანაწერები →</a>
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
