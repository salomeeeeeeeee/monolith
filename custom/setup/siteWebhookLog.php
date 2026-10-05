<?php
/**
 * საიტის webhook-ის ლოგის სიის შექმნა - იდემპოტენტური სკრიპტი.
 *
 * URL: https://crm.monolith.ge/custom/setup/siteWebhookLog.php
 *
 * ქმნის სიას SITE_WEBHOOK_LOG: 1 ჩანაწერი = პროდუქტის სტატუსის 1 ცვლილება, რომელიც
 * საიტს (api.monolith.ge) ეგზავნება. სიაში წერს /local/php_interface/site_webhook.php.
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField), და არა პირდაპირ
 * CIBlockProperty::Add-ით: Lists-ს ველების საკუთარი რეგისტრი აქვს და "ნედლი"
 * თვისება ბაზაში დევს, ინტერფეისში კი არ ჩანს.
 *
 * iblock-ის ტიპი, საიტი და VERSION არსებული სიიდან - "Dailo API log" (iblock 26) -
 * კოპირდება. BIZPROC გამორთულია: ლოგს ბიზნეს-პროცესები არ სჭირდება.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('საიტის webhook-ის ლოგი');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('WEBHOOK_LOG_TEMPLATE_IBLOCK_ID', 26); // Dailo API log - პარამეტრების წყარო

$crmDeal = ['DEAL' => 'Y', 'VISIBLE' => 'Y', 'LEAD' => 'N', 'CONTACT' => 'N', 'COMPANY' => 'N'];

$LIST = [
    'CODE'  => 'SITE_WEBHOOK_LOG',
    'NAME'  => 'საიტის განახლება - სტატუსის ცვლილებები',
    'PROPS' => [
        ['CODE' => 'PRODUCT_ID',  'NAME' => 'პროდუქტის ID',     'TYPE' => 'N'],
        ['CODE' => 'PRODUCT',     'NAME' => 'პროდუქტი',         'TYPE' => 'S'],
        ['CODE' => 'DEAL',        'NAME' => 'დილი',             'TYPE' => 'S:ECrm', 'SETTINGS' => $crmDeal],
        ['CODE' => 'OLD_STATUS',  'NAME' => 'ძველი სტატუსი',    'TYPE' => 'S'],
        ['CODE' => 'NEW_STATUS',  'NAME' => 'ახალი სტატუსი',    'TYPE' => 'S'],
        ['CODE' => 'CAUSE',       'NAME' => 'რამ გამოიწვია',    'TYPE' => 'S', 'ROWS' => 2],
        ['CODE' => 'SOURCE_URL',  'NAME' => 'სკრიპტი / URL',    'TYPE' => 'S'],
        ['CODE' => 'CHANGED_BY',  'NAME' => 'ვინ შეცვალა',      'TYPE' => 'S:employee'],
        ['CODE' => 'HTTP_CODE',   'NAME' => 'საიტის პასუხის კოდი', 'TYPE' => 'N'],
        ['CODE' => 'RESPONSE',    'NAME' => 'საიტის პასუხი',    'TYPE' => 'S', 'ROWS' => 2],
        ['CODE' => 'CHANGE_DATE', 'NAME' => 'ცვლილების დრო',    'TYPE' => 'S:DateTime'],
    ],
];

function webhookLogTemplate()
{
    $row = CIBlock::GetList([], ['ID' => WEBHOOK_LOG_TEMPLATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    $groups = $row ? CIBlock::GetGroupPermissions(WEBHOOK_LOG_TEMPLATE_IBLOCK_ID) : [];

    return [
        'IBLOCK_TYPE_ID' => $row ? $row['IBLOCK_TYPE_ID'] : 'lists',
        'LID'            => $row ? $row['LID'] : 's1',
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION'        => $row ? (int)$row['VERSION'] : 1,
    ];
}

function webhookLogCreateIblock(array $definition, array $template, &$error)
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
        'BIZPROC'        => 'N',
        'INDEX_ELEMENT'  => 'N',
        'INDEX_SECTION'  => 'N',
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
function webhookLogPropertyMap($iblockId)
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
function webhookLogRegisteredCodes($iblockId, array $propertyMap)
{
    if (!class_exists('CList')) {
        return [];
    }

    $idToCode = array_flip($propertyMap);
    $codes = [];

    $obList = new CList($iblockId);
    foreach (array_keys($obList->GetFields()) as $fieldId) {
        if (preg_match('/^PROPERTY_(\d+)$/', $fieldId, $m) && isset($idToCode[(int)$m[1]])) {
            $codes[] = $idToCode[(int)$m[1]];
        }
    }

    return $codes;
}

/** ველის დამატება Lists-ის API-ით; თუ მოდული მიუწვდომელია - პირდაპირ თვისებად. */
function webhookLogAddField($obList, $iblockId, array $property, $sort, &$error)
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
    if (!empty($property['SETTINGS'])) {
        $fields['USER_TYPE_SETTINGS'] = $property['SETTINGS'];
    }

    if ($obList !== null) {
        $fields['TYPE'] = $property['TYPE'];
        if (!$obList->AddField($fields)) {
            $error = 'CList::AddField ჩავარდა';
            return false;
        }
        return true;
    }

    list($fields['PROPERTY_TYPE'], $userType) = array_pad(explode(':', $property['TYPE'], 2), 2, '');
    $fields['USER_TYPE'] = $userType;
    $fields['IBLOCK_ID'] = $iblockId;

    $prop = new CIBlockProperty();
    if (!$prop->Add($fields)) {
        $error = $prop->LAST_ERROR;
        return false;
    }
    return true;
}

// ── გაშვება ─────────────────────────────────────────────────────────────

$entry = [
    'ID'           => 0,
    'CREATED'      => false,
    'FIELDS_ADDED' => [],
    'FIELDS_KEPT'  => [],
    'LIST_FIELDS'  => [],
    'ERRORS'       => [],
];

$row = CIBlock::GetList([], ['CODE' => $LIST['CODE'], 'CHECK_PERMISSIONS' => 'N'])->Fetch();
$iblockId = $row ? (int)$row['ID'] : 0;

if ($iblockId <= 0) {
    $error = '';
    $iblockId = webhookLogCreateIblock($LIST, webhookLogTemplate(), $error);
    if ($iblockId <= 0) {
        $entry['ERRORS'][] = 'სია ვერ შეიქმნა: ' . $error;
    } else {
        $entry['CREATED'] = true;
    }
}

if ($iblockId > 0) {
    $entry['ID'] = $iblockId;

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;
    $propertyMap = webhookLogPropertyMap($iblockId);
    $registered = webhookLogRegisteredCodes($iblockId, $propertyMap);
    $sort = 100;

    foreach ($LIST['PROPS'] as $property) {
        $code = $property['CODE'];

        if (in_array($code, $registered, true)) {
            $entry['FIELDS_KEPT'][] = $code;
        } elseif (isset($propertyMap[$code])) {
            // ბაზაში დევს, მაგრამ Lists-ს არ უნახავს - არ ვეხებით, რომ მონაცემები არ დაიკარგოს
            $entry['ERRORS'][] = $code . ' - ნედლი თვისება ბაზაშია, Lists-ში არ ჩანს (შეამოწმე ხელით)';
        } else {
            $error = '';
            if (webhookLogAddField($obList, $iblockId, $property, $sort, $error)) {
                $entry['FIELDS_ADDED'][] = $code;
            } else {
                $entry['ERRORS'][] = $code . ' - ' . $error;
            }
        }

        $sort += 100;
    }

    if ($obList !== null && method_exists($obList, 'Save')) {
        $obList->Save();
    }

    if (class_exists('CList')) {
        $verify = new CList($iblockId);
        $entry['LIST_FIELDS'] = array_keys($verify->GetFields());
    }
}
?>
<style>
    .webhook-setup { font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 900px; padding: 16px 0; }
    .webhook-setup table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
    .webhook-setup th, .webhook-setup td { border: 1px solid #dfe3e7; padding: 6px 10px; text-align: left; vertical-align: top; }
    .webhook-setup th { background: #f4f6f8; width: 220px; }
    .webhook-setup .ok { color: #1a8f3c; }
    .webhook-setup .err { color: #c0392b; }
</style>

<div class="webhook-setup">
    <p>
        <b>lists მოდული:</b> <?= $listsModule ? 'ჩატვირთულია' : '<span class="err">არ ჩაიტვირთა - ველები Lists-ში არ გამოჩნდება</span>' ?>
    </p>
    <table>
        <tr><th>CODE</th><td><?= htmlspecialcharsbx($LIST['CODE']) ?></td></tr>
        <tr><th>IBLOCK_ID</th><td><?= (int)$entry['ID'] ?></td></tr>
        <tr>
            <th>სტატუსი</th>
            <td class="<?= $entry['CREATED'] ? 'ok' : '' ?>"><?= $entry['CREATED'] ? 'ახლად შეიქმნა' : ($entry['ID'] ? 'უკვე არსებობდა' : '-') ?></td>
        </tr>
        <tr>
            <th>დამატებული ველები</th>
            <td><?= $entry['FIELDS_ADDED'] ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_ADDED'])) : '-' ?></td>
        </tr>
        <tr>
            <th>უკვე დარეგისტრირებული</th>
            <td><?= $entry['FIELDS_KEPT'] ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_KEPT'])) : '-' ?></td>
        </tr>
        <tr>
            <th>Lists ხედავს</th>
            <td class="<?= count($entry['LIST_FIELDS']) > 1 ? 'ok' : 'err' ?>">
                <?= $entry['LIST_FIELDS'] ? htmlspecialcharsbx(implode(', ', $entry['LIST_FIELDS'])) : '-' ?>
            </td>
        </tr>
        <?php if (!empty($entry['ERRORS'])): ?>
            <tr><th>შეცდომები</th><td class="err"><?= htmlspecialcharsbx(implode(' | ', $entry['ERRORS'])) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($entry['ID'])): ?>
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/fields/">ველების კონფიგურაცია →</a> ·
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/view/">ჩანაწერები →</a>
    <?php endif; ?>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
