<?php
/**
 * BOG ინტეგრაციის სიების მომზადება — იდემპოტენტური სკრიპტი.
 *
 * URL: /crm/deal/bank_integration/setup.php
 *
 * ქმნის ამონაწერების სიას (CODE = BOG_STATEMENTS) და ამატებს გადახდების სიას
 * (ლისტი 23) ორ ველს: BANK_PAYMENT_ID (დუბლიკატების კონტროლი) და comment.
 *
 * ველები იქმნება Lists მოდულის API-ით (CList::AddField) და არა პირდაპირ
 * CIBlockProperty::Add-ით: Lists-ს ველების საკუთარი რეგისტრი აქვს და "ნედლი"
 * თვისება ბაზაში დევს, ინტერფეისში კი არ ჩანს.
 *
 * iblock-ის პარამეტრები (ტიპი, საიტი, უფლებები, VERSION, BIZPROC...) გადახდების
 * სიიდან კოპირდება, რომ ახალი სია ზუსტად ისევე მოიქცეს.
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/config.php';

@set_time_limit(0);

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('BOG — სიების მომზადება');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

/** ამონაწერების სიის ველები — bankBogBuildStatementPropsFromRecord()-ის სარკე. */
function bankBogStatementFields()
{
    $strings = [
        'EntryDate' => 'ჩანაწერის თარიღი',
        'EntryDocumentNumber' => 'დოკუმენტის ნომერი',
        'EntryAccountNumber' => 'ანგარიშის ნომერი',
        'EntryAmountDebit' => 'დებეტი',
        'EntryAmountDebitBase' => 'დებეტი (ბაზისური)',
        'EntryAmountCredit' => 'კრედიტი',
        'EntryAmountCreditBase' => 'კრედიტი (ბაზისური)',
        'EntryAmountBase' => 'თანხა (ბაზისური)',
        'EntryAmount' => 'თანხა',
        'EntryDepartment' => 'დეპარტამენტი',
        'EntryAccountPoint' => 'ანგარიშის პუნქტი',
        'DocumentProductGroup' => 'პროდუქტის ჯგუფი',
        'DocumentValueDate' => 'ვალუტირების თარიღი',
        'SenderDetails_Name' => 'გამგზავნი — დასახელება',
        'SenderDetails_Inn' => 'გამგზავნი — INN',
        'SenderDetails_AccountNumber' => 'გამგზავნი — ანგარიში',
        'SenderDetails_BankCode' => 'გამგზავნი — ბანკის კოდი',
        'SenderDetails_BankName' => 'გამგზავნი — ბანკი',
        'BeneficiaryDetails_Name' => 'მიმღები — დასახელება',
        'BeneficiaryDetails_Inn' => 'მიმღები — INN',
        'BeneficiaryDetails_AccountNumber' => 'მიმღები — ანგარიში',
        'BeneficiaryDetails_BankCode' => 'მიმღები — ბანკის კოდი',
        'BeneficiaryDetails_BankName' => 'მიმღები — ბანკი',
        'DocumentTreasuryCode' => 'ხაზინის კოდი',
        'DocumentNomination' => 'დანიშნულება',
        'DocumentInformation' => 'ინფორმაცია',
        'DocumentSourceAmount' => 'საწყისი თანხა',
        'DocumentSourceCurrency' => 'საწყისი ვალუტა',
        'DocumentDestinationAmount' => 'დანიშნულების თანხა',
        'DocumentDestinationCurrency' => 'დანიშნულების ვალუტა',
        'DocumentReceiveDate' => 'მიღების თარიღი',
        'DocumentBranch' => 'ფილიალი',
        'DocumentDepartment' => 'განყოფილება',
        'DocumentActualDate' => 'ფაქტობრივი თარიღი',
        'DocumentExpiryDate' => 'ვადის თარიღი',
        'DocumentRateLimit' => 'კურსის ლიმიტი',
        'DocumentRate' => 'კურსი',
        'DocumentRegistrationRate' => 'რეგისტრაციის კურსი',
        'DocumentSenderInstitution' => 'გამგზავნი ინსტიტუტი',
        'DocumentIntermediaryInstitution' => 'შუამავალი ინსტიტუტი',
        'DocumentBeneficiaryInstitution' => 'მიმღები ინსტიტუტი',
        'DocumentPayee' => 'მიმღები (Payee)',
        'DocumentCorrespondentAccountNumber' => 'კორ. ანგარიში',
        'DocumentCorrespondentBankCode' => 'კორ. ბანკის კოდი',
        'DocumentCorrespondentBankName' => 'კორ. ბანკი',
        'DocumentKey' => 'DocumentKey (უნიკალური)',
        'EntryId' => 'EntryId',
        'DocumentPayerInn' => 'გადამხდელის INN',
        'DocumentPayerName' => 'გადამხდელის დასახელება',
        'istodayactivity' => 'დღევანდელი აქტივობა',
        'todayactivities_Id' => 'დღევანდელი აქტივობის ID',
        'ACCOUNT_CURRENCY' => 'ანგარიშის ვალუტა',
        'ACCOUNT_NUMBER' => 'ანგარიშის ნომერი (იმპორტი)',
        'SALE_TYPE' => 'ტიპი',
        'PROJECT' => 'პროექტი',
    ];

    $fields = [];
    foreach ($strings as $code => $name) {
        $fields[] = ['CODE' => $code, 'NAME' => $name, 'TYPE' => 'S'];
    }

    $fields[] = ['CODE' => 'EntryComment', 'NAME' => 'კომენტარი', 'TYPE' => 'S', 'ROWS' => 3];
    $fields[] = ['CODE' => 'DocComment', 'NAME' => 'დოკუმენტის კომენტარი', 'TYPE' => 'S', 'ROWS' => 3];
    $fields[] = ['CODE' => 'REASON', 'NAME' => 'გამოტოვების მიზეზი', 'TYPE' => 'S', 'ROWS' => 2];
    $fields[] = ['CODE' => 'AMOUNT_GEL', 'NAME' => 'თანხა ₾', 'TYPE' => 'N'];
    $fields[] = ['CODE' => 'AMOUNT_USD', 'NAME' => 'თანხა $', 'TYPE' => 'N'];
    $fields[] = ['CODE' => 'NBG_RATE', 'NAME' => 'NBG კურსი', 'TYPE' => 'N'];

    return $fields;
}

/** გადახდების სიას (23) დასამატებელი ველები. */
function bankBogPaymentFields()
{
    return [
        ['CODE' => 'BANK_PAYMENT_ID', 'NAME' => 'ბანკის ამონაწერის ID', 'TYPE' => 'S'],
        ['CODE' => 'comment', 'NAME' => 'კომენტარი', 'TYPE' => 'S', 'ROWS' => 3],
    ];
}

function bankBogSetupTemplate($templateIblockId)
{
    $row = CIBlock::GetList([], ['ID' => $templateIblockId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();

    if (!$row) {
        return [
            'IBLOCK_TYPE_ID' => 'lists',
            'LID' => 's1',
            'GROUP_ID' => [1 => 'X', 2 => 'R'],
            'VERSION' => 1,
            'BIZPROC' => 'N',
            'INDEX_ELEMENT' => 'N',
            'LIST_MODE' => '',
        ];
    }

    $groups = CIBlock::GetGroupPermissions($templateIblockId);

    return [
        'IBLOCK_TYPE_ID' => $row['IBLOCK_TYPE_ID'],
        'LID' => $row['LID'],
        'GROUP_ID' => !empty($groups) ? $groups : [1 => 'X', 2 => 'R'],
        'VERSION' => (int)$row['VERSION'],
        'BIZPROC' => $row['BIZPROC'],
        'INDEX_ELEMENT' => $row['INDEX_ELEMENT'],
        'LIST_MODE' => $row['LIST_MODE'],
    ];
}

function bankBogSetupCreateIblock($code, $name, array $template, &$error)
{
    $ib = new CIBlock();

    $id = $ib->Add([
        'ACTIVE' => 'Y',
        'NAME' => $name,
        'CODE' => $code,
        'XML_ID' => $code,
        'IBLOCK_TYPE_ID' => $template['IBLOCK_TYPE_ID'],
        'SITE_ID' => [$template['LID']],
        'SORT' => 500,
        'VERSION' => $template['VERSION'],
        'BIZPROC' => $template['BIZPROC'],
        'INDEX_ELEMENT' => $template['INDEX_ELEMENT'],
        'INDEX_SECTION' => 'N',
        'LIST_MODE' => $template['LIST_MODE'],
        'WORKFLOW' => 'N',
        'GROUP_ID' => $template['GROUP_ID'],
    ]);

    if (!$id) {
        $error = $ib->LAST_ERROR;
        return 0;
    }

    return (int)$id;
}

/** CODE => თვისების ID (რაც ბაზაშია, Lists-ში დარეგისტრირების მიუხედავად). */
function bankBogSetupPropertyMap($iblockId)
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
function bankBogSetupRegisteredCodes($iblockId, array $propertyMap)
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

/** ველის დამატება Lists-ის API-ით; თუ მოდული მიუწვდომელია — პირდაპირ თვისებად. */
function bankBogSetupAddField($obList, $iblockId, array $property, $sort, &$error)
{
    $fields = [
        'NAME' => $property['NAME'],
        'CODE' => $property['CODE'],
        'SORT' => $sort,
        'ACTIVE' => 'Y',
        'IS_REQUIRED' => 'N',
        'MULTIPLE' => 'N',
        'FILTRABLE' => 'Y',
        'SEARCHABLE' => 'N',
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

    $fields['IBLOCK_ID'] = $iblockId;
    $fields['PROPERTY_TYPE'] = $property['TYPE'];

    $prop = new CIBlockProperty();
    if (!$prop->Add($fields)) {
        $error = $prop->LAST_ERROR;
        return false;
    }

    return true;
}

/** არსებულ სიას აკლებული ველების დამატება. აბრუნებს ანგარიშს. */
function bankBogSetupEnsureFields($iblockId, array $definitions, $listsModule)
{
    $entry = ['FIELDS_ADDED' => [], 'FIELDS_KEPT' => [], 'ERRORS' => []];

    $obList = ($listsModule && class_exists('CList')) ? new CList($iblockId) : null;
    $propertyMap = bankBogSetupPropertyMap($iblockId);
    $registered = bankBogSetupRegisteredCodes($iblockId, $propertyMap);
    $sort = 100;

    foreach ($definitions as $property) {
        $code = $property['CODE'];

        if (in_array($code, $registered, true)) {
            $entry['FIELDS_KEPT'][] = $code;
            $sort += 100;
            continue;
        }

        // ბაზაში დევს, მაგრამ Lists-ს არ უნახავს — არ ვეხებით, რომ მონაცემები არ დაიკარგოს
        if (isset($propertyMap[$code])) {
            $entry['ERRORS'][] = $code . ' — ნედლი თვისება ბაზაშია, Lists-ში არ ჩანს (შეამოწმე ხელით)';
            $sort += 100;
            continue;
        }

        $error = '';
        if (bankBogSetupAddField($obList, $iblockId, $property, $sort, $error)) {
            $entry['FIELDS_ADDED'][] = $code;
        } else {
            $entry['ERRORS'][] = $code . ' — ' . $error;
        }

        $sort += 100;
    }

    if ($obList !== null && method_exists($obList, 'Save')) {
        $obList->Save();
    }

    if (class_exists('CList')) {
        $verify = new CList($iblockId);
        $entry['LIST_FIELD_COUNT'] = count($verify->GetFields());
    }

    return $entry;
}

// ── გაშვება ─────────────────────────────────────────────────────────────

$report = [];

// 1. ამონაწერების სია
$statementRow = CIBlock::GetList([], ['CODE' => BANK_BOG_STATEMENT_IBLOCK_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
$statementIblockId = $statementRow ? (int)$statementRow['ID'] : 0;
$statementCreated = false;

if ($statementIblockId <= 0) {
    $template = bankBogSetupTemplate(BANK_BOG_PAYMENT_IBLOCK);
    $error = '';
    $statementIblockId = bankBogSetupCreateIblock(
        BANK_BOG_STATEMENT_IBLOCK_CODE,
        'BOG — ბანკის ამონაწერები',
        $template,
        $error
    );
    $statementCreated = $statementIblockId > 0;
    if (!$statementCreated) {
        $report[] = [
            'NAME' => 'BOG — ბანკის ამონაწერები',
            'ID' => 0,
            'STATUS' => 'ვერ შეიქმნა: ' . $error,
            'FIELDS_ADDED' => [],
            'FIELDS_KEPT' => [],
            'ERRORS' => [],
        ];
    }
}

if ($statementIblockId > 0) {
    $entry = bankBogSetupEnsureFields($statementIblockId, bankBogStatementFields(), $listsModule);
    $report[] = array_merge($entry, [
        'NAME' => 'BOG — ბანკის ამონაწერები (' . BANK_BOG_STATEMENT_IBLOCK_CODE . ')',
        'ID' => $statementIblockId,
        'STATUS' => $statementCreated ? 'ახლად შეიქმნა' : 'უკვე არსებობდა',
    ]);
}

// 2. გადახდების სია
$paymentEntry = bankBogSetupEnsureFields(BANK_BOG_PAYMENT_IBLOCK, bankBogPaymentFields(), $listsModule);
$report[] = array_merge($paymentEntry, [
    'NAME' => 'გადახდები (ლისტი ' . BANK_BOG_PAYMENT_IBLOCK . ')',
    'ID' => BANK_BOG_PAYMENT_IBLOCK,
    'STATUS' => 'არსებული სია',
]);
?>
<style>
    .bog-setup { font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 900px; padding: 16px 0; }
    .bog-setup h2 { margin: 24px 0 8px; font-size: 18px; }
    .bog-setup table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
    .bog-setup th, .bog-setup td { border: 1px solid #dfe3e7; padding: 6px 10px; text-align: left; vertical-align: top; }
    .bog-setup th { background: #f4f6f8; width: 220px; }
    .bog-setup .ok { color: #1a8f3c; }
    .bog-setup .err { color: #c0392b; }
</style>

<div class="bog-setup">
    <p>
        <b>lists მოდული:</b>
        <?= $listsModule ? 'ჩატვირთულია' : '<span class="err">არ ჩაიტვირთა — ველები Lists-ში არ გამოჩნდება</span>' ?>
    </p>

<?php foreach ($report as $entry): ?>
    <h2><?= htmlspecialcharsbx($entry['NAME']) ?></h2>
    <table>
        <tr><th>IBLOCK_ID</th><td><?= (int)$entry['ID'] ?></td></tr>
        <tr><th>სტატუსი</th><td><?= htmlspecialcharsbx($entry['STATUS']) ?></td></tr>
        <tr>
            <th>დამატებული ველები</th>
            <td class="<?= !empty($entry['FIELDS_ADDED']) ? 'ok' : '' ?>">
                <?= !empty($entry['FIELDS_ADDED']) ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_ADDED'])) : '-' ?>
            </td>
        </tr>
        <tr>
            <th>უკვე არსებული</th>
            <td><?= !empty($entry['FIELDS_KEPT']) ? htmlspecialcharsbx(implode(', ', $entry['FIELDS_KEPT'])) : '-' ?></td>
        </tr>
        <?php if (isset($entry['LIST_FIELD_COUNT'])): ?>
            <tr><th>Lists ხედავს ველებს</th><td><?= (int)$entry['LIST_FIELD_COUNT'] ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($entry['ERRORS'])): ?>
            <tr><th>შეცდომები</th><td class="err"><?= htmlspecialcharsbx(implode(' | ', $entry['ERRORS'])) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($entry['ID'])): ?>
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/fields/">ველების კონფიგურაცია →</a> ·
        <a href="/services/lists/<?= (int)$entry['ID'] ?>/view/">ჩანაწერები →</a>
    <?php endif; ?>
<?php endforeach; ?>

    <h2>შემდეგი ნაბიჯი</h2>
    <p>
        ანგარიში: <b><?= htmlspecialcharsbx(BANK_BOG_ACCOUNT) ?></b> ·
        <?= htmlspecialcharsbx(BANK_BOG_COMPANY_LABEL) ?>
        <?php if (BANK_BOG_ACCOUNT === '7777777777'): ?>
            <br><span class="err">ანგარიშის ნომერი დროებითია — შეცვალე
            <code>/crm/deal/bank_integration/config.php</code>-ში (BANK_BOG_ACCOUNT).</span>
        <?php endif; ?>
    </p>
    <p>
        <a href="/crm/deal/bog_import.php">ამონაწერის იმპორტი →</a> ·
        <a href="/crm/deal/bog_merge.php">გადახდებთან მიბმა →</a>
    </p>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
