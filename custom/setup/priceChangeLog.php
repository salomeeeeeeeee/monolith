<?php
/**
 * ფასის ცვლილებების ლოგის სიის შექმნა - იდემპოტენტური სკრიპტი.
 *
 * URL: https://crm.monolith.ge/custom/setup/priceChangeLog.php
 *
 * ქმნის სიას PRICE_CHANGE_LOG: 1 ჩანაწერი = პროდუქტების მოდულიდან ფასის 1 ცვლილება
 * (ერთი დაჭერა, ფილტრის ყველა პროდუქტი). სიაში წერს /rest/local/api/product/price-change.php;
 * სანამ სია არ არსებობს, API ფასს არ ცვლის.
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField), როგორც siteWebhookLog.php-ში.
 * iblock-ის ტიპი, საიტი, უფლებები და VERSION კოპირდება "Dailo API log"-იდან (iblock 26).
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('ფასის ცვლილებების ლოგი');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('PRICE_LOG_TEMPLATE_IBLOCK_ID', 26); // Dailo API log - პარამეტრების წყარო

$LIST = [
    'CODE'  => 'PRICE_CHANGE_LOG',
    'NAME'  => 'პროდუქტების მოდული - ფასის ცვლილებები',
    'PROPS' => [
        ['CODE' => 'OPERATION',     'NAME' => 'ცვლილება',                       'TYPE' => 'S'],
        ['CODE' => 'CHANGE_TYPE',   'NAME' => 'ცვლილების ტიპი',                 'TYPE' => 'S'],
        ['CODE' => 'DIRECTION',     'NAME' => 'მიმართულება',                    'TYPE' => 'S'],
        ['CODE' => 'CHANGE_VALUE',  'NAME' => 'მნიშვნელობა',                    'TYPE' => 'N'],
        ['CODE' => 'PROJECTS',      'NAME' => 'პროექტი',                        'TYPE' => 'S'],
        ['CODE' => 'UPDATED_COUNT', 'NAME' => 'განახლდა (პროდუქტი)',            'TYPE' => 'N'],
        ['CODE' => 'FAILED_COUNT',  'NAME' => 'ვერ განახლდა (პროდუქტი)',        'TYPE' => 'N'],
        ['CODE' => 'SUM_BEFORE',    'NAME' => 'კატალოგის ფასების ჯამი ცვლილებამდე $', 'TYPE' => 'N'],
        ['CODE' => 'SUM_AFTER',     'NAME' => 'კატალოგის ფასების ჯამი ცვლილების შემდეგ $', 'TYPE' => 'N'],
        ['CODE' => 'PRODUCT_IDS',   'NAME' => 'პროდუქტების ID',                 'TYPE' => 'N', 'MULTIPLE' => 'Y'],
        ['CODE' => 'DETAILS',       'NAME' => 'ძველი -> ახალი ფასები პროდუქტების მიხედვით', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ['CODE' => 'FAILED',        'NAME' => 'ვერ განახლდა - მიზეზი',          'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ['CODE' => 'FILTER_INFO',   'NAME' => 'ფილტრი',                         'TYPE' => 'S', 'ROWS' => 2],
        ['CODE' => 'CHANGED_BY',    'NAME' => 'ვინ შეცვალა',                    'TYPE' => 'S:employee'],
        ['CODE' => 'CHANGE_DATE',   'NAME' => 'ცვლილების დრო',                  'TYPE' => 'S:DateTime'],
    ],
];

function priceLogTemplate()
{
    $row = CIBlock::GetList([], ['ID' => PRICE_LOG_TEMPLATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    $groups = $row ? CIBlock::GetGroupPermissions(PRICE_LOG_TEMPLATE_IBLOCK_ID) : [];

    return [
        'IBLOCK_TYPE_ID' => $row ? $row['IBLOCK_TYPE_ID'] : 'lists',
        'LID'            => $row ? $row['LID'] : 's1',
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION'        => $row ? (int)$row['VERSION'] : 1,
    ];
}

function priceLogCreateIblock(array $definition, array $template, &$error)
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
function priceLogPropertyMap($iblockId)
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
function priceLogRegisteredCodes($iblockId, array $propertyMap)
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
function priceLogAddField($obList, $iblockId, array $property, $sort, &$error)
{
    $fields = [
        'NAME'        => $property['NAME'],
        'CODE'        => $property['CODE'],
        'SORT'        => $sort,
        'ACTIVE'      => 'Y',
        'IS_REQUIRED' => 'N',
        'MULTIPLE'    => $property['MULTIPLE'] ?? 'N',
        'FILTRABLE'   => 'Y',
        'SEARCHABLE'  => 'N',
    ];
    if (!empty($property['ROWS'])) {
        $fields['ROW_COUNT'] = (int)$property['ROWS'];
        $fields['COL_COUNT'] = 60;
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
    $iblockId = priceLogCreateIblock($LIST, priceLogTemplate(), $error);
    if ($iblockId <= 0) {
        $entry['ERRORS'][] = 'სია ვერ შეიქმნა: ' . $error;
    } else {
        $entry['CREATED'] = true;
    }
}

if ($iblockId > 0) {
    $entry['ID'] = $iblockId;

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;
    $propertyMap = priceLogPropertyMap($iblockId);
    $registered = priceLogRegisteredCodes($iblockId, $propertyMap);
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
            if (priceLogAddField($obList, $iblockId, $property, $sort, $error)) {
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
    .price-log-setup { font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 900px; padding: 16px 0; }
    .price-log-setup table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
    .price-log-setup th, .price-log-setup td { border: 1px solid #dfe3e7; padding: 6px 10px; text-align: left; vertical-align: top; }
    .price-log-setup th { background: #f4f6f8; width: 220px; }
    .price-log-setup .ok { color: #1a8f3c; }
    .price-log-setup .err { color: #c0392b; }
</style>

<div class="price-log-setup">
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
