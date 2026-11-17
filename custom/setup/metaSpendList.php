<?php
/**
 * Meta-ს რეკლამის ხარჯების სიის შექმნა - იდემპოტენტური სკრიპტი.
 *
 * URL: https://crm.monolith.ge/custom/setup/metaSpendList.php
 *
 * ქმნის სიას META_AD_SPEND: 1 ჩანაწერი = 1 რეკლამა × 1 პერიოდი (date_start .. date_stop).
 * სიაში წერს /rest/public/addMetaSpend.php. თუ სია უკვე არსებობს, მხოლოდ დაკლებულ
 * ველებს ამატებს.
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField), და არა პირდაპირ
 * CIBlockProperty::Add-ით: Lists-ს ველების საკუთარი რეგისტრი აქვს და "ნედლი"
 * თვისება ბაზაში დევს, ინტერფეისში კი არ ჩანს.
 *
 * iblock-ის ტიპი, საიტი, უფლებები და VERSION არსებული სიიდან - "Dailo API log"
 * (iblock 26) - კოპირდება. BIZPROC გამორთულია: იმპორტს ბიზნეს-პროცესები არ სჭირდება.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('Meta - რეკლამის ხარჯების სია');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('META_SPEND_TEMPLATE_IBLOCK_ID', 26); // Dailo API log - პარამეტრების წყარო

$LIST = [
    'CODE'  => 'META_AD_SPEND',
    'NAME'  => 'Meta - რეკლამის ხარჯები',
    'PROPS' => [
        ['CODE' => 'DATE_START',    'NAME' => 'პერიოდი - დან (date_start)',          'TYPE' => 'S:Date'],
        ['CODE' => 'DATE_STOP',     'NAME' => 'პერიოდი - მდე (date_stop)',           'TYPE' => 'S:Date'],
        ['CODE' => 'CAMPAIGN_NAME', 'NAME' => 'კამპანია (campaign_name)',            'TYPE' => 'S'],
        ['CODE' => 'CAMPAIGN_ID',   'NAME' => 'კამპანიის ID (campaign_id)',          'TYPE' => 'S'],
        ['CODE' => 'ADSET_NAME',    'NAME' => 'რეკლამების ჯგუფი (adset_name)',       'TYPE' => 'S'],
        ['CODE' => 'ADSET_ID',      'NAME' => 'რეკლამების ჯგუფის ID (adset_id)',     'TYPE' => 'S'],
        ['CODE' => 'AD_NAME',       'NAME' => 'რეკლამა (ad_name)',                   'TYPE' => 'S'],
        ['CODE' => 'AD_ID',         'NAME' => 'რეკლამის ID (ad_id)',                 'TYPE' => 'S'],
        ['CODE' => 'SPEND',         'NAME' => 'ხარჯი (spend)',                       'TYPE' => 'N'],
        ['CODE' => 'CURRENCY',      'NAME' => 'ვალუტა (account_currency)',           'TYPE' => 'S'],
        ['CODE' => 'RECEIVED_AT',   'NAME' => 'მიღების დრო',                         'TYPE' => 'S:DateTime'],
        ['CODE' => 'RAW_JSON',      'NAME' => 'JSON (როგორც მოვიდა)',                'TYPE' => 'S', 'ROWS' => 3],
    ],
];

function metaSpendSetupTemplate()
{
    $row = CIBlock::GetList([], ['ID' => META_SPEND_TEMPLATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    $groups = $row ? CIBlock::GetGroupPermissions(META_SPEND_TEMPLATE_IBLOCK_ID) : [];

    return [
        'IBLOCK_TYPE_ID' => $row ? $row['IBLOCK_TYPE_ID'] : 'lists',
        'LID'            => $row ? $row['LID'] : 's1',
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION'        => $row ? (int)$row['VERSION'] : 1,
    ];
}

function metaSpendSetupCreateIblock(array $definition, array $template, &$error)
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
function metaSpendSetupPropertyMap($iblockId)
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
function metaSpendSetupRegisteredCodes($iblockId, array $propertyMap)
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
function metaSpendSetupAddField($obList, $iblockId, array $property, $sort, &$error)
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
    'ELEMENTS'     => 0,
    'ERRORS'       => [],
];

$row = CIBlock::GetList([], ['CODE' => $LIST['CODE'], 'CHECK_PERMISSIONS' => 'N'])->Fetch();
$iblockId = $row ? (int)$row['ID'] : 0;

if ($iblockId <= 0) {
    $error = '';
    $iblockId = metaSpendSetupCreateIblock($LIST, metaSpendSetupTemplate(), $error);
    if ($iblockId <= 0) {
        $entry['ERRORS'][] = 'სია ვერ შეიქმნა: ' . $error;
    } else {
        $entry['CREATED'] = true;
    }
}

if ($iblockId > 0) {
    $entry['ID'] = $iblockId;

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;
    $propertyMap = metaSpendSetupPropertyMap($iblockId);
    $registered = metaSpendSetupRegisteredCodes($iblockId, $propertyMap);
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
            if (metaSpendSetupAddField($obList, $iblockId, $property, $sort, $error)) {
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

    $entry['ELEMENTS'] = (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'], []);
}

$secretFile = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/meta_spend_secret.php';
$token = is_file($secretFile) ? include $secretFile : '';
$token = is_string($token) ? trim($token) : '';
?>
<style>
    .meta-spend-setup { font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 900px; padding: 16px 0; }
    .meta-spend-setup h2 { margin: 24px 0 8px; font-size: 18px; }
    .meta-spend-setup table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
    .meta-spend-setup th, .meta-spend-setup td { border: 1px solid #dfe3e7; padding: 6px 10px; text-align: left; vertical-align: top; }
    .meta-spend-setup th { background: #f4f6f8; width: 220px; }
    .meta-spend-setup .ok { color: #1a8f3c; }
    .meta-spend-setup .err { color: #c0392b; }
    .meta-spend-setup pre { background: #f4f6f8; border: 1px solid #dfe3e7; padding: 12px; overflow-x: auto; }
</style>

<div class="meta-spend-setup">
    <p>
        <b>lists მოდული:</b> <?= $listsModule ? 'ჩატვირთულია' : '<span class="err">არ ჩაიტვირთა - ველები Lists-ში არ გამოჩნდება</span>' ?>
        · <b>ტოკენი:</b> <?= $token !== '' ? '<span class="ok">დაყენებულია</span>' : '<span class="err">არ არის - შექმენი /local/php_interface/meta_spend_secret.php</span>' ?>
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
        <tr><th>ჩანაწერები</th><td><?= (int)$entry['ELEMENTS'] ?></td></tr>
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

    <h2>ენდპოინტის შემოწმება</h2>
    <pre>curl -X POST https://crm.monolith.ge/rest/public/addMetaSpend.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?= htmlspecialcharsbx($token !== '' ? $token : '<TOKEN>') ?>" \
  -d '{"data":[{"date_start":"<?= date('Y-m-d') ?>","date_stop":"<?= date('Y-m-d') ?>",
       "campaign_id":"120210000000000001","campaign_name":"Campaign",
       "adset_id":"120210000000000002","adset_name":"Ad set",
       "ad_id":"120210000000000003","ad_name":"Ad","spend":"12.34"}]}'</pre>
    <p>იგივე რეკლამის იგივე პერიოდის ხელახლა გაგზავნა ჩანაწერს <b>აახლებს</b>, ახალს არ ქმნის.</p>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
