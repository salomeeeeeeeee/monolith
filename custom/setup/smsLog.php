<?php
/**
 * SMS-ების ლოგის სია SMS_LOG "SMS ისტორია" - იდემპოტენტური სკრიპტი (არსებულ სიას აკლებულ ველებს ამატებს).
 *
 * URL: https://crm.monolith.ge/custom/setup/smsLog.php
 *
 * 1 ჩანაწერი = 1 SMS, დილზე მიბმული (დილის ბარათზე ტაბად ჩანს). წერს local/php_interface/sms_log.php.
 * უფლებები: კითხულობენ გაყიდვები, sales head, გენ.დირექტორი, ფინანსური; რედაქტირება მხოლოდ ადმინს შეუძლია.
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField) - "ნედლი" თვისება ინტერფეისში არ ჩანს.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

CModule::IncludeModule('iblock');
CModule::IncludeModule('crm');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('SMS ისტორია - მომზადება');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

define('SMS_LOG_TEMPLATE_IBLOCK_ID', 26); // Dailo API log - პარამეტრების წყარო

// გაყიდვების ჯგუფი, გენ.დირექტორი, Sales head, ფინანსური განყოფილება
const SMS_LOG_READ_GROUPS = ['G17', 'G19', 'G20', 'G22'];

$crmDeal = ['DEAL' => 'Y', 'VISIBLE' => 'Y', 'LEAD' => 'N', 'CONTACT' => 'N', 'COMPANY' => 'N'];

$LIST = [
    'CODE'  => 'SMS_LOG',
    'NAME'  => 'SMS ისტორია',
    'PROPS' => [
        ['CODE' => 'DEAL',              'NAME' => 'დილი',                     'TYPE' => 'S:ECrm', 'SETTINGS' => $crmDeal],
        ['CODE' => 'SMS_TYPE',          'NAME' => 'SMS ტიპი',                 'TYPE' => 'S'],
        ['CODE' => 'STATUS',            'NAME' => 'სტატუსი',                  'TYPE' => 'S'],
        ['CODE' => 'PHONE',             'NAME' => 'რა ნომერზე გაიგზავნა',     'TYPE' => 'S'],
        ['CODE' => 'CLIENT_PHONE',      'NAME' => 'კლიენტის ნომერი',          'TYPE' => 'S'],
        ['CODE' => 'SMS_TEXT',          'NAME' => 'ტექსტი',                   'TYPE' => 'S', 'ROWS' => 5],
        ['CODE' => 'SMS_LANG',          'NAME' => 'ენა',                      'TYPE' => 'S'],
        ['CODE' => 'SMS_MODE',          'NAME' => 'რეჟიმი',                   'TYPE' => 'S'],
        ['CODE' => 'SENT_AT',           'NAME' => 'გაგზავნის დრო',            'TYPE' => 'S'],
        ['CODE' => 'DELIVERED_AT',      'NAME' => 'მიწოდების დრო',            'TYPE' => 'S'],
        ['CODE' => 'NOTE',              'NAME' => 'შენიშვნა',                 'TYPE' => 'S'],
        ['CODE' => 'SEND_RESPONSE',     'NAME' => 'პასუხი 1 (გაგზავნა)',      'TYPE' => 'S', 'ROWS' => 5],
        ['CODE' => 'DELIVERY_RESPONSE', 'NAME' => 'პასუხი 2 (მიწოდება)',      'TYPE' => 'S', 'ROWS' => 5],
        ['CODE' => 'INEX_ID',           'NAME' => 'Inex ID',                  'TYPE' => 'S'],
        ['CODE' => 'EVENT_KEY',         'NAME' => 'მოვლენის გასაღები',        'TYPE' => 'S'],
    ],
];

function smsLogSetupTemplate()
{
    $row = CIBlock::GetList([], ['ID' => SMS_LOG_TEMPLATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    $groups = $row ? CIBlock::GetGroupPermissions(SMS_LOG_TEMPLATE_IBLOCK_ID) : [];

    return [
        'IBLOCK_TYPE_ID' => $row ? $row['IBLOCK_TYPE_ID'] : 'lists',
        'LID'            => $row ? $row['LID'] : 's1',
        'GROUP_ID'       => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION'        => $row ? (int)$row['VERSION'] : 1,
    ];
}

function smsLogSetupCreateIblock(array $definition, array $template, &$error)
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
function smsLogSetupPropertyMap($iblockId)
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
function smsLogSetupRegisteredCodes($iblockId, array $propertyMap)
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
function smsLogSetupAddField($obList, $iblockId, array $property, $sort, &$error)
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

/** გაფართოებული უფლებები: ჯგუფები კითხულობენ, ადმინს სრული წვდომა ისედაც აქვს */
function smsLogSetupRights($iblockId, array $groups, &$error)
{
    $task = CTask::GetList([], ['NAME' => 'iblock_read', 'MODULE_ID' => 'iblock'])->Fetch();
    if (!$task) {
        $error = 'iblock_read უფლება ვერ მოიძებნა';
        return false;
    }
    (new CIBlock())->Update($iblockId, ['RIGHTS_MODE' => 'E']);
    $rights = [];
    foreach ($groups as $n => $group) {
        $rights['n' . $n] = ['GROUP_CODE' => $group, 'TASK_ID' => $task['ID']];
    }
    if (!(new CIBlockRights($iblockId))->SetRights($rights)) {
        $error = 'უფლებები ვერ დაყენდა';
        return false;
    }
    return true;
}

// ── გაშვება ─────────────────────────────────────────────────────────────

$report = ['ID' => 0, 'CREATED' => false, 'ADDED' => [], 'KEPT' => [], 'ERRORS' => []];

$row = CIBlock::GetList([], ['CODE' => $LIST['CODE'], 'CHECK_PERMISSIONS' => 'N'])->Fetch();
$iblockId = $row ? (int)$row['ID'] : 0;

if ($iblockId <= 0) {
    $error = '';
    $iblockId = smsLogSetupCreateIblock($LIST, smsLogSetupTemplate(), $error);
    if ($iblockId <= 0) {
        $report['ERRORS'][] = 'სია ვერ შეიქმნა: ' . $error;
    } else {
        $report['CREATED'] = true;
        if (!smsLogSetupRights($iblockId, SMS_LOG_READ_GROUPS, $error)) {
            $report['ERRORS'][] = $error;
        }
    }
}

if ($iblockId > 0) {
    $report['ID'] = $iblockId;

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;
    $propertyMap = smsLogSetupPropertyMap($iblockId);
    $registered = smsLogSetupRegisteredCodes($iblockId, $propertyMap);
    $sort = 100;

    foreach ($LIST['PROPS'] as $property) {
        $code = $property['CODE'];
        $error = '';
        if (in_array($code, $registered, true)) {
            $report['KEPT'][] = $code;
        } elseif (isset($propertyMap[$code])) {
            $report['ERRORS'][] = $code . ' - ნედლი თვისება ბაზაშია, Lists-ში არ ჩანს (შეამოწმე ხელით)';
        } elseif (smsLogSetupAddField($obList, $iblockId, $property, $sort, $error)) {
            $report['ADDED'][] = $code;
        } else {
            $report['ERRORS'][] = $code . ' - ' . $error;
        }
        $sort += 100;
    }

    if ($obList !== null && method_exists($obList, 'Save')) {
        $obList->Save();
    }
    // დილის ბარათის ტაბები (CLists::getIblockAttachedCrm) ქეშიდან იკითხება
    BXClearCache(true, '/lists/crm/attached/');
}
?>
<div style="font:14px/1.6 'Helvetica Neue',Arial,sans-serif;max-width:900px;padding:16px 0">
    <div><b>სია:</b> <?= htmlspecialcharsbx($LIST['NAME']) ?> (ID <?= (int)$report['ID'] ?>) - <?= $report['CREATED'] ? 'ახლად შეიქმნა' : ($report['ID'] ? 'უკვე არსებობდა' : 'არ არის') ?></div>
    <div><b>დამატებული ველები:</b> <?= htmlspecialcharsbx(implode(', ', $report['ADDED']) ?: '-') ?></div>
    <div><b>უკვე იყო:</b> <?= htmlspecialcharsbx(implode(', ', $report['KEPT']) ?: '-') ?></div>
    <div style="color:#c0392b"><?= htmlspecialcharsbx(implode(' | ', $report['ERRORS'])) ?></div>
    <?php if ($report['ID']): ?>
        <a href="/services/lists/<?= (int)$report['ID'] ?>/view/0/">ჩანაწერები</a>
    <?php endif; ?>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
