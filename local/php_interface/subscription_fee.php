<?php
/**
 * აბონირების გადასახადი: Dighomi 2500 ₾, Green City 2300 ₾, მხოლოდ ბინებზე.
 * ბინის ფასში და გრაფიკში (ლისტი 22) არ შედის, კლიენტი ცალკე იხდის.
 *
 * ორი სია (ქმნის /crm/deal/bank_integration/setup.php):
 *  - SUBSCRIPTION_CHARGES "აბონირების დარიცხვა": თითო დილზე ერთი ელემენტი - თანხა, გადახდილი, დარჩენილი.
 *  - SUBSCRIPTION_PAYMENTS "აბონირების გადახდები": ბანკის / სალაროს ამონაწერიდან (bog_merge.php) ან ხელით.
 *    დილის ბარათზე ჩანს ტაბად "აბონირების გადახდები" (DEAL ველი, VISIBLE = Y).
 *
 * დარიცხვა იქმნება, როცა ამ პროექტების ბინის დილი ხელშეკრულების ან შემდეგ სტადიაზე გადადის. თუ დილი ამ
 * სტადიებიდან გავიდა და გადახდა არ აქვს, დარიცხვა იშლება. ძველ (უკვე გაყიდულ) დილს დარიცხვა მაშინ ექმნება,
 * როცა ბანკის გვერდზე თანხა აბონირებაზე გატარდება.
 * გადახდების სიის ყოველი ცვლილება (კოდით თუ ხელით) დარიცხვის "გადახდილი / დარჩენილი" ველებს თავიდან ითვლის.
 */

const SUBSCRIPTION_FEE_CHARGE_CODE  = 'SUBSCRIPTION_CHARGES';
const SUBSCRIPTION_FEE_PAYMENT_CODE = 'SUBSCRIPTION_PAYMENTS';
const SUBSCRIPTION_FEE_D_PROJECT    = 'UF_CRM_1779277729207';  // პროექტი
const SUBSCRIPTION_FEE_D_TYPE       = 'UF_CRM_1779277898205';  // ფართის ტიპი
const SUBSCRIPTION_FEE_D_BLOCK      = 'UF_CRM_1779277644355';  // ბლოკი
const SUBSCRIPTION_FEE_D_UNIT       = 'UF_CRM_1779277613798';  // ბინის ნომერი
const SUBSCRIPTION_FEE_D_CONTRACT   = 'UF_CRM_1791205458297';  // ხელშეკრულების ნომერი
const SUBSCRIPTION_FEE_UNIT_TYPE    = 'ბინა';
// ხელშეკრულება, დადასტურებული ხელშეკრულება, მოთხოვნილი გაყიდვა, გაყიდული - იგივე, რაზეც ბანკის მიბმა მუშაობს
const SUBSCRIPTION_FEE_STAGES       = ['EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];

/** პროექტი (დილის ველი, პატარა ასოებით) => თანხა ₾ */
function subscriptionFeeAmounts()
{
    return [
        'dighomi'    => 2500,
        'green city' => 2300,
    ];
}

function subscriptionFeeIblockId($code)
{
    static $ids = [];
    if (!isset($ids[$code])) {
        $row = CModule::IncludeModule('iblock')
            ? CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch()
            : false;
        $ids[$code] = $row ? (int)$row['ID'] : 0;
    }
    return $ids[$code];
}

function subscriptionFeeChargeIblockId()
{
    return subscriptionFeeIblockId(SUBSCRIPTION_FEE_CHARGE_CODE);
}

function subscriptionFeePaymentIblockId()
{
    return subscriptionFeeIblockId(SUBSCRIPTION_FEE_PAYMENT_CODE);
}

function subscriptionFeeMoney($value)
{
    return round((float)$value, 2);
}

function subscriptionFeeUfValue($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return trim((string)$value);
}

/** დილის აბონირების თანხა პროექტით: 0, თუ პროექტს აბონირება არ აქვს ან ფართი ბინა არ არის */
function subscriptionFeeAmountForDeal(array $deal)
{
    if (subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_TYPE] ?? '') !== SUBSCRIPTION_FEE_UNIT_TYPE) {
        return 0.0;
    }
    $project = mb_strtolower(subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_PROJECT] ?? ''));
    return (float)(subscriptionFeeAmounts()[$project] ?? 0);
}

function subscriptionFeeDealSelect()
{
    return [
        'ID', 'STAGE_ID', 'CONTACT_FULL_NAME', 'COMPANY_TITLE',
        SUBSCRIPTION_FEE_D_PROJECT, SUBSCRIPTION_FEE_D_TYPE, SUBSCRIPTION_FEE_D_BLOCK,
        SUBSCRIPTION_FEE_D_UNIT, SUBSCRIPTION_FEE_D_CONTRACT,
    ];
}

/** დილები ID => row (ძველი GetList ზოგ ველს აგდებს, ამიტომ GetListEx) */
function subscriptionFeeLoadDeals(array $dealIds)
{
    $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    $out = [];
    if (empty($dealIds) || !CModule::IncludeModule('crm')) {
        return $out;
    }
    $res = CCrmDeal::GetListEx([], ['@ID' => $dealIds, 'CHECK_PERMISSIONS' => 'N'], false, false, subscriptionFeeDealSelect());
    while ($row = $res->Fetch()) {
        $out[(int)$row['ID']] = $row;
    }
    return $out;
}

function subscriptionFeeDealIdFromValue($value)
{
    return preg_match('/(\d+)/', subscriptionFeeUfValue($value), $m) ? (int)$m[1] : 0;
}

/** დილის ID => ['ID' => დარიცხვის ელემენტი, 'AMOUNT' => ₾] */
function subscriptionFeeChargesByDeal(array $dealIds)
{
    $iblockId = subscriptionFeeChargeIblockId();
    $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    $out = [];
    if ($iblockId <= 0 || empty($dealIds)) {
        return $out;
    }
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'PROPERTY_DEAL' => $dealIds, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'PROPERTY_DEAL', 'PROPERTY_TANXA']
    );
    while ($row = $res->Fetch()) {
        $dealId = subscriptionFeeDealIdFromValue($row['PROPERTY_DEAL_VALUE'] ?? '');
        if ($dealId > 0 && !isset($out[$dealId])) {
            $out[$dealId] = ['ID' => (int)$row['ID'], 'AMOUNT' => subscriptionFeeMoney($row['PROPERTY_TANXA_VALUE'] ?? 0)];
        }
    }
    return $out;
}

/** დილის ID => გადახდილი ₾. შეცდომით გატარებული გადახდა სიიდან იშლება, უკუგატარება არ არის. */
function subscriptionFeePaidByDeal(array $dealIds)
{
    $iblockId = subscriptionFeePaymentIblockId();
    $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    $out = [];
    if ($iblockId <= 0 || empty($dealIds)) {
        return $out;
    }
    $res = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'PROPERTY_DEAL' => $dealIds, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'PROPERTY_DEAL', 'PROPERTY_TANXA']
    );
    while ($row = $res->Fetch()) {
        $dealId = subscriptionFeeDealIdFromValue($row['PROPERTY_DEAL_VALUE'] ?? '');
        if ($dealId > 0) {
            $out[$dealId] = subscriptionFeeMoney(($out[$dealId] ?? 0) + (float)($row['PROPERTY_TANXA_VALUE'] ?? 0));
        }
    }
    return $out;
}

/**
 * დილის ID => ['AMOUNT', 'PAID', 'LEFT', 'CHARGE_ID'] - მხოლოდ იმ დილებზე, რომლებსაც აბონირება აქვთ:
 * დარიცხვით, ან (ძველი დილი, დარიცხვის გარეშე) პროექტის თანხით.
 * $deals - დილის ID => row (პროექტისა და ტიპის ველებით); სადაც row არ არის, ჩაიტვირთება.
 */
function subscriptionFeeStatusMap(array $deals)
{
    $out = [];
    if (subscriptionFeeChargeIblockId() <= 0) {
        return $out;
    }
    $dealIds = array_values(array_filter(array_map('intval', array_keys($deals))));
    if (empty($dealIds)) {
        return $out;
    }
    $missing = [];
    foreach ($dealIds as $dealId) {
        if (!is_array($deals[$dealId] ?? null)) {
            $missing[] = $dealId;
        }
    }
    if ($missing) {
        $deals = subscriptionFeeLoadDeals($missing) + $deals;
    }

    $charges = subscriptionFeeChargesByDeal($dealIds);
    $paid = subscriptionFeePaidByDeal($dealIds);
    foreach ($dealIds as $dealId) {
        $chargeId = $charges[$dealId]['ID'] ?? 0;
        $amount = $chargeId ? $charges[$dealId]['AMOUNT'] : subscriptionFeeAmountForDeal(is_array($deals[$dealId] ?? null) ? $deals[$dealId] : []);
        if ($amount <= 0 && !$chargeId) {
            continue;
        }
        $dealPaid = $paid[$dealId] ?? 0.0;
        $out[$dealId] = [
            'AMOUNT' => $amount,
            'PAID' => $dealPaid,
            'LEFT' => subscriptionFeeMoney($amount - $dealPaid),
            'CHARGE_ID' => $chargeId,
        ];
    }
    return $out;
}

function subscriptionFeeStatusForDeal($dealId)
{
    $dealId = (int)$dealId;
    return $dealId > 0 ? (subscriptionFeeStatusMap([$dealId => null])[$dealId] ?? null) : null;
}

/** გადახდის ტიპის (pay_type) enum ID XML_ID-ით: bank, cash, manual */
function subscriptionFeePayTypeId($xmlId)
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        $iblockId = subscriptionFeePaymentIblockId();
        if ($iblockId > 0) {
            $res = CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'pay_type']);
            while ($row = $res->Fetch()) {
                $ids[(string)$row['XML_ID']] = (int)$row['ID'];
            }
        }
    }
    return $ids[$xmlId] ?? 0;
}

/** სიის ელემენტების საერთო ველები დილიდან */
function subscriptionFeeDealProps(array $deal)
{
    $name = trim((string)($deal['CONTACT_FULL_NAME'] ?? ''));
    return [
        'DEAL' => (int)$deal['ID'],
        'DEAL_ID' => (int)$deal['ID'],
        'PROJECT' => subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_PROJECT] ?? ''),
        'KORPUSI' => subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_BLOCK] ?? ''),
        'BINIS_NOMERI' => subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_UNIT] ?? ''),
        'FULL_NAME' => $name !== '' ? $name : trim((string)($deal['COMPANY_TITLE'] ?? '')),
        'xelshNum' => subscriptionFeeUfValue($deal[SUBSCRIPTION_FEE_D_CONTRACT] ?? ''),
    ];
}

/** დილს დარიცხვას უქმნის, თუ აბონირება ეკუთვნის და ჯერ არ აქვს. აბრუნებს დარიცხვის ID-ს (0 - არ ეკუთვნის / ვერ შეიქმნა). */
function subscriptionFeeEnsureCharge($dealId, ?array $deal = null)
{
    $dealId = (int)$dealId;
    $iblockId = subscriptionFeeChargeIblockId();
    if ($dealId <= 0 || $iblockId <= 0) {
        return 0;
    }
    $existing = subscriptionFeeChargesByDeal([$dealId]);
    if (isset($existing[$dealId])) {
        return $existing[$dealId]['ID'];
    }
    if ($deal === null || !array_key_exists(SUBSCRIPTION_FEE_D_PROJECT, $deal)) {
        $deal = subscriptionFeeLoadDeals([$dealId])[$dealId] ?? null;
    }
    $amount = $deal ? subscriptionFeeAmountForDeal($deal) : 0.0;
    if ($amount <= 0) {
        return 0;
    }

    $props = subscriptionFeeDealProps($deal + ['ID' => $dealId]);
    $paid = subscriptionFeePaidByDeal([$dealId])[$dealId] ?? 0.0;
    $props += [
        'TANXA' => $amount,
        'PAID' => $paid,
        'LEFT' => subscriptionFeeMoney($amount - $paid),
    ];
    $el = new CIBlockElement();
    $id = $el->Add([
        'IBLOCK_ID' => $iblockId,
        'NAME' => trim('აბონირება #' . $dealId . ' ' . $props['FULL_NAME']),
        'ACTIVE' => 'Y',
        'PROPERTY_VALUES' => $props,
    ]);
    return $id ? (int)$id : 0;
}

/** დარიცხვის "გადახდილი / დარჩენილი" ველები გადახდების სიიდან */
function subscriptionFeeRefreshCharge($dealId)
{
    $dealId = (int)$dealId;
    $charge = subscriptionFeeChargesByDeal([$dealId])[$dealId] ?? null;
    if (!$charge) {
        return;
    }
    $paid = subscriptionFeePaidByDeal([$dealId])[$dealId] ?? 0.0;
    CIBlockElement::SetPropertyValuesEx($charge['ID'], subscriptionFeeChargeIblockId(), [
        'PAID' => $paid,
        'LEFT' => subscriptionFeeMoney($charge['AMOUNT'] - $paid),
    ]);
}

// ── დილის სტადია ──────────────────────────────────────────────────────────

/** დილის ID => სტადია ცვლილებამდე; OnAfter-ში მხოლოდ რეალური გადასვლა ითვლება (ბარათის შენახვა სტადიას ხშირად იმავეს აწვდის) */
$GLOBALS['subscriptionFeePrevStage'] = [];

function subscriptionFeeOnBeforeDealUpdate(&$fields)
{
    try {
        $dealId = (int)($fields['ID'] ?? 0);
        if ($dealId > 0 && isset($fields['STAGE_ID']) && CModule::IncludeModule('crm')) {
            $row = CCrmDeal::GetListEx([], ['=ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'STAGE_ID'])->Fetch();
            $GLOBALS['subscriptionFeePrevStage'][$dealId] = (string)($row['STAGE_ID'] ?? '');
        }
    } catch (\Throwable $e) {
    }
    return true;
}

function subscriptionFeeOnAfterDealUpdate($fields)
{
    try {
        $dealId = (int)($fields['ID'] ?? 0);
        $newStage = (string)($fields['STAGE_ID'] ?? '');
        $prevStage = $GLOBALS['subscriptionFeePrevStage'][$dealId] ?? null;
        unset($GLOBALS['subscriptionFeePrevStage'][$dealId]);
        if ($dealId <= 0 || $newStage === '' || $prevStage === null || $prevStage === $newStage
            || subscriptionFeeChargeIblockId() <= 0) {
            return;
        }

        if (in_array($newStage, SUBSCRIPTION_FEE_STAGES, true)) {
            subscriptionFeeEnsureCharge($dealId);
            return;
        }

        // ხელშეკრულება / გაყიდვა აღარ არის: გადახდის გარეშე დარიცხვა აღარ სჭირდება
        $charge = subscriptionFeeChargesByDeal([$dealId])[$dealId] ?? null;
        if ($charge && empty(subscriptionFeePaidByDeal([$dealId])[$dealId])) {
            CIBlockElement::Delete($charge['ID']);
        }
    } catch (\Throwable $e) {
    }
}

AddEventHandler('crm', 'OnBeforeCrmDealUpdate', 'subscriptionFeeOnBeforeDealUpdate');
AddEventHandler('crm', 'OnAfterCrmDealUpdate', 'subscriptionFeeOnAfterDealUpdate');

// ── გადახდების სია: დარიცხვის ჯამების განახლება ──────────────────────────

/** ცვლილებამდე დილი: გადახდა სხვა დილზე გადაიტანეს ან წაშალეს - ძველ დილსაც უნდა გადაეთვალოს */
$GLOBALS['subscriptionFeeTouchedDeals'] = [];

function subscriptionFeePaymentDeal($elementId)
{
    $iblockId = subscriptionFeePaymentIblockId();
    if ((int)$elementId <= 0 || $iblockId <= 0) {
        return 0;
    }
    $row = CIBlockElement::GetList(
        [],
        ['ID' => (int)$elementId, 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'PROPERTY_DEAL']
    )->Fetch();
    return $row ? subscriptionFeeDealIdFromValue($row['PROPERTY_DEAL_VALUE'] ?? '') : 0;
}

/** OnBeforeIBlockElementUpdate (ველები) / OnBeforeIBlockElementDelete (ID). false ცვლილებას გააუქმებდა - ყოველთვის true. */
function subscriptionFeeOnBeforePaymentChange($arg)
{
    try {
        $id = is_array($arg) ? (int)($arg['ID'] ?? 0) : (int)$arg;
        if (is_array($arg) && isset($arg['IBLOCK_ID']) && (int)$arg['IBLOCK_ID'] !== subscriptionFeePaymentIblockId()) {
            return true;
        }
        $dealId = subscriptionFeePaymentDeal($id);
        if ($dealId > 0) {
            $GLOBALS['subscriptionFeeTouchedDeals'][$dealId] = true;
        }
    } catch (\Throwable $e) {
    }
    return true;
}

/** OnAfterIBlockElementAdd / Update / Delete */
function subscriptionFeeOnAfterPaymentChange($fields)
{
    try {
        if (!is_array($fields) || (int)($fields['IBLOCK_ID'] ?? 0) <= 0
            || (int)$fields['IBLOCK_ID'] !== subscriptionFeePaymentIblockId()) {
            return;
        }
        if (array_key_exists('RESULT', $fields) && !$fields['RESULT']) {
            return;
        }
        $dealIds = array_keys($GLOBALS['subscriptionFeeTouchedDeals']);
        $GLOBALS['subscriptionFeeTouchedDeals'] = [];
        $current = subscriptionFeePaymentDeal($fields['ID'] ?? 0);
        if ($current > 0) {
            $dealIds[] = $current;
        }
        foreach (array_unique($dealIds) as $dealId) {
            subscriptionFeeRefreshCharge($dealId);
        }
    } catch (\Throwable $e) {
    }
}

AddEventHandler('iblock', 'OnBeforeIBlockElementUpdate', 'subscriptionFeeOnBeforePaymentChange');
AddEventHandler('iblock', 'OnBeforeIBlockElementDelete', 'subscriptionFeeOnBeforePaymentChange');
AddEventHandler('iblock', 'OnAfterIBlockElementAdd', 'subscriptionFeeOnAfterPaymentChange');
AddEventHandler('iblock', 'OnAfterIBlockElementUpdate', 'subscriptionFeeOnAfterPaymentChange');
AddEventHandler('iblock', 'OnAfterIBlockElementDelete', 'subscriptionFeeOnAfterPaymentChange');
