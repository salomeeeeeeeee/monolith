<?php
/**
 * Bank of Georgia (BOG) integration — Monolith / New Depot
 *
 * ამონაწერები იტვირთება ბანკის API-დან ცალკე სიაში (BOG_STATEMENTS), შემდეგ
 * ხელით/ნახევრად ავტომატურად ებმება დილებს და იქმნება გადახდა ლისტ 23-ში.
 *
 * სიის შესაქმნელად ერთხელ გაუშვი: /crm/deal/bank_integration/setup.php
 */

/** ამონაწერების სია — setup.php ამ CODE-ით ქმნის iblock-ს. */
if (!defined('BANK_BOG_STATEMENT_IBLOCK_CODE')) {
    define('BANK_BOG_STATEMENT_IBLOCK_CODE', 'BOG_STATEMENTS');
}
/** გადახდები */
if (!defined('BANK_BOG_PAYMENT_IBLOCK')) {
    define('BANK_BOG_PAYMENT_IBLOCK', 23);
}
/** გადახდის გრაფიკი (დარიცხვები) */
if (!defined('BANK_BOG_SCHEDULE_IBLOCK')) {
    define('BANK_BOG_SCHEDULE_IBLOCK', 22);
}

if (!defined('BANK_BOG_CLIENT_ID')) {
    define('BANK_BOG_CLIENT_ID', 'e7b04aa7-8de1-42d5-8e72-e050329f915e');
}
if (!defined('BANK_BOG_CLIENT_SECRET')) {
    define('BANK_BOG_CLIENT_SECRET', 'c1221746-9a12-4537-ac26-b2a2f04d87e7');
}
/** დროებითი ნომერი — ჩაანაცვლე რეალური ანგარიშის ნომრით (IBAN). */
if (!defined('BANK_BOG_ACCOUNT')) {
    define('BANK_BOG_ACCOUNT', '7777777777');
}
if (!defined('BANK_BOG_COMPANY_LABEL')) {
    define('BANK_BOG_COMPANY_LABEL', 'New Depot');
}

/**
 * ანგარიშები — აქ დაამატე ახალი ნომრები როცა დასჭირდება.
 * client_id / client_secret ცარიელი = ძირითადი BANK_BOG_CLIENT_* გამოიყენება.
 */
function bankBogAccounts()
{
    return [
        [
            'number' => BANK_BOG_ACCOUNT,
            'label' => BANK_BOG_COMPANY_LABEL,
            'client_id' => '',
            'client_secret' => '',
        ],
    ];
}

function bankBogResolveAccount($accountNumber)
{
    $accountNumber = trim((string)$accountNumber);
    foreach (bankBogAccounts() as $acc) {
        if (($acc['number'] ?? '') === $accountNumber) {
            return [
                'number' => $acc['number'],
                'label' => $acc['label'] ?? $acc['number'],
                'client_id' => trim((string)($acc['client_id'] ?? '')) ?: BANK_BOG_CLIENT_ID,
                'client_secret' => trim((string)($acc['client_secret'] ?? '')) ?: BANK_BOG_CLIENT_SECRET,
            ];
        }
    }
    // fallback — პირველი ანგარიში
    $first = bankBogAccounts()[0] ?? null;
    if ($first) {
        return [
            'number' => $first['number'],
            'label' => $first['label'] ?? $first['number'],
            'client_id' => trim((string)($first['client_id'] ?? '')) ?: BANK_BOG_CLIENT_ID,
            'client_secret' => trim((string)($first['client_secret'] ?? '')) ?: BANK_BOG_CLIENT_SECRET,
        ];
    }
    return [
        'number' => BANK_BOG_ACCOUNT,
        'label' => BANK_BOG_COMPANY_LABEL,
        'client_id' => BANK_BOG_CLIENT_ID,
        'client_secret' => BANK_BOG_CLIENT_SECRET,
    ];
}

/**
 * ამონაწერების სიის ID. setup.php-მდე 0-ს აბრუნებს.
 * BANK_BOG_STATEMENT_IBLOCK-ის ხელით განსაზღვრა ძებნას გამორთავს.
 */
function bankBogStatementIblockId()
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    if (defined('BANK_BOG_STATEMENT_IBLOCK')) {
        return $id = (int)BANK_BOG_STATEMENT_IBLOCK;
    }
    if (!class_exists('CIBlock')) {
        return $id = 0;
    }
    $row = CIBlock::GetList([], ['CODE' => BANK_BOG_STATEMENT_IBLOCK_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $id = $row ? (int)$row['ID'] : 0;
}

/** Contact: პირადი ნომერი */
if (!defined('BANK_C_PERSONAL_ID')) {
    define('BANK_C_PERSONAL_ID', 'UF_CRM_1781244744534');
}
/** Contact: პასპორტის ნომერი */
if (!defined('BANK_C_PASSPORT')) {
    define('BANK_C_PASSPORT', 'UF_CRM_1790776088');
}
/** Company: საიდენტიფიკაციო კოდი */
if (!defined('BANK_CO_TAX_ID')) {
    define('BANK_CO_TAX_ID', 'UF_CRM_1790776120');
}

/** Deal UF fields */
if (!defined('BANK_D_PROJECT')) {
    define('BANK_D_PROJECT', 'UF_CRM_1779277729207');
}
if (!defined('BANK_D_BLOCK')) {
    define('BANK_D_BLOCK', 'UF_CRM_1779277644355');
}
if (!defined('BANK_D_TYPE')) {
    define('BANK_D_TYPE', 'UF_CRM_1779277898205');
}
if (!defined('BANK_D_FLOOR')) {
    define('BANK_D_FLOOR', 'UF_CRM_1779277828822');
}
if (!defined('BANK_D_UNIT')) {
    define('BANK_D_UNIT', 'UF_CRM_1779277613798');
}
if (!defined('BANK_D_CONTRACT_DATE')) {
    define('BANK_D_CONTRACT_DATE', 'UF_CRM_1779278774084');
}
if (!defined('BANK_D_CURRENCY')) {
    define('BANK_D_CURRENCY', 'UF_CRM_1702019032102');
}
/** ხელშეკრულების ნომერი → გადახდის xelshNum */
if (!defined('BANK_D_CONTRACT_NUM')) {
    define('BANK_D_CONTRACT_NUM', 'UF_CRM_1769416547');
}

/** Merge მხოლოდ ამ სტეიჯებზე. */
function bankBogMergeStages()
{
    return ['EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];
}
