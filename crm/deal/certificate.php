<?php
/**
 * ცნობა / Certificate — სრულ ან ნაწილობრივ დაფარვაზე
 * URL: /crm/deal/certificate.php?dealID=123&lang=ka|en&type=full|partial
 * Debug (deal + contact fields): &debug=1
 */
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule("crm");
CModule::IncludeModule("iblock");

/* ────────────────────────────── CONFIG ──────────────────────────────
 * Put your real field codes here. Empty code => that part is skipped
 * (or the default text below is used).
 */
$CRT_CFG = array(
    "IBLOCK_PLAN"     => 22,                       // payment plan
    "IBLOCK_PAYMENTS" => 23,                       // payments
    "CURRENCY_FIELD"  => "UF_CRM_1702019032102",   // deal currency
    "GEL_ID"          => 322,                      // enum ID meaning GEL

    // Deal fields
    "ADDRESS_FIELD"       => "",   // property address (Georgian)
    "ADDRESS_EN_FIELD"    => "",   // property address (English)
    "CADASTRAL_FIELD"     => "",   // საკადასტრო კოდი
    "SECTOR_FIELD"        => "",   // სექტორი
    "BLOCK_FIELD"         => "",   // ბლოკი
    "FLOOR_FIELD"         => "",   // სართული
    "APARTMENT_FIELD"     => "",   // ბინის N
    "AREA_FIELD"          => "",   // ფართი კვ.მ
    "PRICE_FIELD"         => "",   // ღირებულება (empty => sum of payment plan)
    "CONTRACT_DATE_FIELD" => "",   // წინასწარი ნასყიდობის ხელშეკრულების თარიღი

    // Contact fields
    "PERSONAL_ID_FIELD"   => "",   // პირადი ნომერი
    "NAME_EN_FIELD"       => "",   // name in Latin letters (empty => NAME + LAST_NAME)

    // Fallbacks
    "ADDRESS_DEFAULT"    => "ქალაქი თბილისი, კრწანისის რაიონი, ნადიკვარის ქუჩა N34-ის მიმდებარედ",
    "ADDRESS_EN_DEFAULT" => "Tbilisi, Krtsanisi district, near 34 Nadikvari Street",

    "ASSETS"             => "/crm/deal/invoice_assets",   // same logo / footer as the invoices
);

// Seller (static)
$CRT_SELLER = array(
    "ka" => array(
        "NAME"    => 'შპს „მონოლით ჯგუფი პლუსი“',
        "ADDRESS" => "ზურაბ ანჯაფარიძის ქუჩა N30, თბილისი, საქართველო",
    ),
    "en" => array(
        "NAME"    => "Monolith Group Plus LLC",
        "ADDRESS" => "30 Zurab Anjaparidze Street, Tbilisi, Georgia",
    ),
    "CODE" => "204943357",
);

// Signatory
$CRT_SIGNER = array(
    "ka" => array("NAME" => "ლევან არქანია", "ROLE" => "დირექტორი"),
    "en" => array("NAME" => "Levan Arkania", "ROLE" => "Director"),
);

// Footer (same as invoice)
$CRT_FOOTER = array(
    "ka" => array("ADDRESS_1" => "სანდრო ეულის 15, საქართველო,", "ADDRESS_2" => "თბილისი 0186."),
    "en" => array("ADDRESS_1" => "15 Sandro Euli St.,",           "ADDRESS_2" => "Tbilisi 0186, Georgia"),
    "PHONE" => "+9950322309977",
    "EMAIL" => "info@monolith.ge",
    "WEB"   => "www.monolith.ge",
);

/* ────────────────────────────── HELPERS ────────────────────────────── */

function crt_printArr($arr) {
    echo "<pre style='font-size:12px'>"; print_r($arr); echo "</pre>";
}

function crt_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function crt_toNum($val) {
    if (is_array($val)) $val = reset($val);
    if ($val === null || $val === '' || $val === false) return 0.0;
    if (is_int($val) || is_float($val)) return (float)$val;

    $val = trim((string)$val);
    $val = preg_replace('/\|[A-Z]{3}$/i', '', $val);
    $val = str_replace(array(' ', "\xC2\xA0"), '', $val);

    if (strpos($val, ',') !== false && strpos($val, '.') !== false) {
        $val = str_replace(',', '', $val);
    } else {
        $val = str_replace(',', '.', $val);
    }
    return is_numeric($val) ? (float)$val : 0.0;
}

/** 131100 => "131 100", 1500.5 => "1 500.50" */
function crt_amount($n) {
    $n = round((float)$n, 2);
    $dec = (abs($n - round($n)) > 0.001) ? 2 : 0;
    return number_format($n, $dec, '.', ' ');
}

/** Parses Bitrix dates: 13.08.2026, 13/08/2026, 2026-08-13 (with or without time) */
function crt_parseDate($val) {
    if (is_array($val)) $val = reset($val);
    if (is_object($val) && method_exists($val, 'format')) $val = $val->format('d.m.Y');
    $val = trim((string)$val);
    if ($val === '') return null;
    $val = preg_split('/\s+/', $val)[0];
    foreach (array('d.m.Y', 'd/m/Y', 'Y-m-d', 'm/d/Y') as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $val);
        if ($d && $d->format($fmt) === $val) return $d;
    }
    return null;
}

function crt_fmtDate($d, $lang) {
    if (!$d) return "";
    if ($lang === "en") return $d->format('F j, Y');
    $months = array(1 => "იანვარი", "თებერვალი", "მარტი", "აპრილი", "მაისი", "ივნისი",
                    "ივლისი", "აგვისტო", "სექტემბერი", "ოქტომბერი", "ნოემბერი", "დეკემბერი");
    return $d->format('j') . " " . $months[(int)$d->format('n')] . " " . $d->format('Y') . " წ.";
}

/** Reads a UF field and resolves list / iblock-element / boolean values to text */
function crt_uf($entity, $row, $code) {
    if ($code === "" || !is_array($row) || !array_key_exists($code, $row)) return "";
    $val = $row[$code];

    static $ufCache = array();
    if (!isset($ufCache[$entity])) {
        global $USER_FIELD_MANAGER;
        $ufCache[$entity] = is_object($USER_FIELD_MANAGER)
            ? (array)$USER_FIELD_MANAGER->GetUserFields($entity, 0, LANGUAGE_ID)
            : array();
    }
    $type = isset($ufCache[$entity][$code]) ? $ufCache[$entity][$code]["USER_TYPE_ID"] : "";

    $vals = is_array($val) ? $val : array($val);
    $out  = array();
    foreach ($vals as $v) {
        if ($v === null || $v === "" || $v === false) continue;
        if ($type === "enumeration") {
            $e = CUserFieldEnum::GetList(array(), array("ID" => (int)$v))->Fetch();
            $v = $e ? $e["VALUE"] : $v;
        } elseif ($type === "iblock_element") {
            $e = CIBlockElement::GetList(array(), array("ID" => (int)$v), false, false, array("ID", "NAME"))->Fetch();
            $v = $e ? $e["NAME"] : $v;
        } elseif ($type === "boolean") {
            $v = $v ? "Y" : "N";
        } elseif ($type === "money") {
            $v = preg_replace('/\|[A-Z]{3}$/i', '', (string)$v);
        } elseif (is_object($v) && method_exists($v, 'toString')) {
            $v = $v->toString();
        }
        $out[] = trim((string)$v);
    }
    return implode(", ", $out);
}

function crt_getRows($iblockID, $dealID) {
    $rows = array();
    $res = CIBlockElement::GetList(array(), array("IBLOCK_ID" => $iblockID, "PROPERTY_DEAL" => $dealID),
        false, array("nPageSize" => 99999), array("ID", "IBLOCK_ID", "NAME", "PROPERTY_*"));
    while ($ob = $res->GetNextElement()) {
        $f = $ob->GetFields();
        $rows[] = array("ID" => (int)$f["ID"], "PROPS" => $ob->GetProperties());
    }
    return $rows;
}

/* ────────────────────────────── INPUT ────────────────────────────── */

$dealID = (int)($_GET["dealID"] ?? $_GET["dealid"] ?? 0);
$lang   = (($_GET["lang"] ?? "ka") === "en") ? "en" : "ka";
$type   = (($_GET["type"] ?? "full") === "partial") ? "partial" : "full";

if (!$dealID) { die("dealID is missing"); }

$deal = CCrmDeal::GetList(array(), array("ID" => $dealID, "CHECK_PERMISSIONS" => "N"), array("*", "UF_*"))->Fetch();
if (!$deal) { die("Deal not found"); }

$isGEL = ($deal[$CRT_CFG["CURRENCY_FIELD"]] == $CRT_CFG["GEL_ID"]);
$curSymbol = $isGEL ? "₾" : "$";
$curCode   = $isGEL ? "GEL" : "USD";
$curWordKa = $isGEL ? "ლარს" : "აშშ დოლარს";

/* ── Money: same logic as finance_card.php ── */
$totalPlan = 0.0;
foreach (crt_getRows($CRT_CFG["IBLOCK_PLAN"], $dealID) as $r) {
    $totalPlan += crt_toNum($isGEL ? $r["PROPS"]["amount_GEL"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]);
}
$totalPaid = 0.0;
foreach (crt_getRows($CRT_CFG["IBLOCK_PAYMENTS"], $dealID) as $r) {
    $amt = crt_toNum($isGEL ? $r["PROPS"]["tanxa_gel"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]);
    $totalPaid += ($r["PROPS"]["refund"]["VALUE"] == "YES") ? -$amt : $amt;
}

$priceRaw = crt_uf("CRM_DEAL", $deal, $CRT_CFG["PRICE_FIELD"]);
$price    = $priceRaw !== "" ? crt_toNum($priceRaw) : $totalPlan;
$totalPaid = round($totalPaid, 2);
$balance   = round($price - $totalPaid, 2);

/* ── Buyer: primary contact on the deal ── */
$buyerName = "";
$buyerPID  = "";
$contactID = 0;
if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
    foreach (\Bitrix\Crm\Binding\DealContactTable::getDealBindings($dealID) as $b) {
        if (!$contactID || $b["IS_PRIMARY"] === "Y") {
            $contactID = (int)$b["CONTACT_ID"];
            if ($b["IS_PRIMARY"] === "Y") break;
        }
    }
}
if (!$contactID && !empty($deal["CONTACT_ID"])) $contactID = (int)$deal["CONTACT_ID"];

$contact = null;
if ($contactID) {
    $contact = CCrmContact::GetList(array(), array("ID" => $contactID, "CHECK_PERMISSIONS" => "N"),
        array("ID", "NAME", "SECOND_NAME", "LAST_NAME", "FULL_NAME", "UF_*"))->Fetch();
}
if ($contact) {
    if ($lang === "en") $buyerName = crt_uf("CRM_CONTACT", $contact, $CRT_CFG["NAME_EN_FIELD"]);
    if ($buyerName === "") $buyerName = trim($contact["NAME"] . " " . $contact["LAST_NAME"]);
    if ($buyerName === "") $buyerName = trim((string)$contact["FULL_NAME"]);
    $buyerPID = crt_uf("CRM_CONTACT", $contact, $CRT_CFG["PERSONAL_ID_FIELD"]);
} elseif (!empty($deal["COMPANY_ID"])) {
    $company = CCrmCompany::GetList(array(), array("ID" => $deal["COMPANY_ID"], "CHECK_PERMISSIONS" => "N"), array("ID", "TITLE"))->Fetch();
    if ($company) $buyerName = $company["TITLE"];
}

/* ── Property ── */
$address = ($lang === "en")
    ? (crt_uf("CRM_DEAL", $deal, $CRT_CFG["ADDRESS_EN_FIELD"]) ?: $CRT_CFG["ADDRESS_EN_DEFAULT"])
    : (crt_uf("CRM_DEAL", $deal, $CRT_CFG["ADDRESS_FIELD"])    ?: $CRT_CFG["ADDRESS_DEFAULT"]);

$propParts = array();
$L = array(
    "ka" => array("CADASTRAL" => "საკადასტრო კოდი:", "SECTOR" => "სექტორი", "BLOCK" => "ბლოკი",
                  "FLOOR" => "სართული", "APARTMENT" => "ბინა N", "AREA" => "ფართი", "SQM" => "კვ.მ."),
    "en" => array("CADASTRAL" => "Cadastral code:", "SECTOR" => "Sector", "BLOCK" => "Block",
                  "FLOOR" => "Floor", "APARTMENT" => "Apartment No.", "AREA" => "Area", "SQM" => "sq.m."),
);
foreach (array("CADASTRAL", "SECTOR", "BLOCK", "FLOOR", "APARTMENT") as $k) {
    $v = crt_uf("CRM_DEAL", $deal, $CRT_CFG[$k . "_FIELD"]);
    if ($v !== "") $propParts[] = $L[$lang][$k] . " " . $v;
}
$area = crt_uf("CRM_DEAL", $deal, $CRT_CFG["AREA_FIELD"]);
if ($area !== "") $propParts[] = $L[$lang]["AREA"] . " " . $area . " " . $L[$lang]["SQM"];

$contractDate = crt_parseDate(crt_uf("CRM_DEAL", $deal, $CRT_CFG["CONTRACT_DATE_FIELD"]));
$today        = new DateTime();

if (isset($_GET["debug"])) {
    echo "<h3>DEAL</h3>"; crt_printArr($deal);
    if ($contact) { echo "<h3>CONTACT</h3>"; crt_printArr($contact); }
    echo "<h3>TOTALS</h3>"; crt_printArr(array("price" => $price, "paid" => $totalPaid, "balance" => $balance));
    die();
}

/* ── Texts ── */
$T = array(
    "ka" => array(
        "DOC_TITLE"  => "ცნობა",
        "TAX"        => "ს/კ:",
        "SELLER"     => "გამყიდველი:",
        "TAX_ID"     => "საიდენტიფიკაციო კოდი:",
        "ADDRESS"    => "მისამართი:",
        "CONCERN"    => "ვისაც ეხება",
        "PROPERTY"   => "უძრავი ქონება:",
        "PRICE"      => "ღირებულება:",
        "CONTRACT"   => "წინასწარი ნასყიდობის ხელშეკრულება გაფორმებულია:",
        "PAY_HEAD"   => "ზემოაღნიშნული ხელშეკრულების ფარგლებში განხორციელებული გადახდები:",
        "SINCERELY"  => "პატივისცემით,",
        "TEL"        => "ტელ:",
        "MAIL"       => "ელ.ფოსტა:",
        "PRINT"      => "ბეჭდვა / PDF",
    ),
    "en" => array(
        "DOC_TITLE"  => "Certificate",
        "TAX"        => "Tax ID:",
        "SELLER"     => "Seller:",
        "TAX_ID"     => "Tax ID:",
        "ADDRESS"    => "Address:",
        "CONCERN"    => "To whom it may concern",
        "PROPERTY"   => "Real Estate Property:",
        "PRICE"      => "Purchase Price:",
        "CONTRACT"   => "Pre-purchase agreement: signed on",
        "PAY_HEAD"   => "Payments made on behalf of the above-mentioned Agreement:",
        "SINCERELY"  => "Sincerely,",
        "TEL"        => "Tel:",
        "MAIL"       => "Email:",
        "PRINT"      => "Print / PDF",
    ),
);
$t = $T[$lang];

$who = crt_h($buyerName);
if ($buyerPID !== "") $who .= ($lang === "en" ? " (ID " : " (პ/ნ ") . crt_h($buyerPID) . ")";

$paidStr    = crt_amount($totalPaid);
$balanceStr = crt_amount(max(0, $balance));

if ($lang === "en") {
    $intro = "This is to confirm that <b>{$who}</b> has purchased the following real estate property from our company:";
    $paidLine = "The total amount paid under the above-mentioned pre-purchase agreement is <b>{$curCode} {$paidStr}</b>.";
    $statusLine = ($type === "full")
        ? "The purchase price of the property has been <b>paid in full</b>, and the buyer has no outstanding financial obligations to the company under this agreement."
        : "The remaining amount to be paid under the agreement is <b>{$curCode} {$balanceStr}</b>.";
    $closing = "This letter is issued upon request to confirm the above-mentioned real estate purchase and payments.";
} else {
    $intro = "ვადასტურებთ, რომ <b>{$who}</b> ჩვენი კომპანიისგან შეძენილი აქვს შემდეგი უძრავი ქონება:";
    $paidLine = "ზემოაღნიშნული წინასწარი ნასყიდობის ხელშეკრულების ფარგლებში გადახდილმა თანხამ ჯამურად შეადგინა <b>{$paidStr} {$curWordKa}</b>.";
    $statusLine = ($type === "full")
        ? "უძრავი ქონების ღირებულება <b>სრულად არის დაფარული</b> და მყიდველს აღნიშნული ხელშეკრულებით კომპანიის მიმართ ფინანსური ვალდებულება აღარ გააჩნია."
        : "ხელშეკრულებით გადასახდელი დარჩენილი თანხა შეადგენს <b>{$balanceStr} {$curWordKa}</b>.";
    $closing = "ცნობა გაცემულია მოთხოვნისამებრ, ზემოაღნიშნული უძრავი ქონების შეძენისა და გადახდების დასადასტურებლად.";
}

$seller = $CRT_SELLER[$lang];
$signer = $CRT_SIGNER[$lang];
$foot   = $CRT_FOOTER[$lang];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo crt_h($t["DOC_TITLE"] . " — " . $deal["TITLE"]); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef0f4;
            font-family: 'Noto Sans Georgian', sans-serif;
            color: #111;
            font-size: 13px;
            line-height: 1.5;
        }
        .toolbar {
            max-width: 210mm;
            margin: 20px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toolbar button {
            border: 0;
            border-radius: 999px;
            padding: 9px 18px;
            background: #1c6fb5;
            color: #fff;
            font: 600 13px 'Noto Sans Georgian', sans-serif;
            cursor: pointer;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto 30px;
            padding: 14mm 18mm 0;
            background: #fff;
            box-shadow: 0 6px 24px rgba(0,0,0,.08);
            display: flex;
            flex-direction: column;
        }

        /* Letterhead */
        .crt-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .crt-logo img { width: 32mm; display: block; }
        .crt-company { text-align: right; line-height: 1.7; }
        .crt-company__name { font-weight: 700; color: #1c4f8c; font-size: 14px; }
        .crt-company__code { color: #1c4f8c; }

        .crt-title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            margin: 9mm 0 7mm;
        }

        .crt-seller { line-height: 1.7; }
        .crt-concern { font-weight: 700; font-size: 15px; margin: 7mm 0 3mm; }

        .crt-block { margin-top: 4mm; }
        .crt-prop b:first-child { margin-right: 4px; }

        .crt-pay { margin-top: 6mm; }
        .crt-pay__head { font-weight: 700; margin-bottom: 2mm; }
        .crt-pay p { margin: 0 0 2mm; }

        .crt-closing { margin-top: 6mm; }

        .crt-sign { margin-top: 10mm; line-height: 1.8; }
        .crt-sign__role { font-style: italic; margin-top: 12mm; }

        /* Footer — same as invoice */
        .crt-footer {
            margin-top: auto;
            padding: 8mm 0 10mm;
            display: grid;
            grid-template-columns: 41fr 30fr 29fr;
            align-items: stretch;
        }
        .crt-footer__banner {
            width: 100%;
            height: 100%;
            min-height: 15mm;
            object-fit: cover;
            display: block;
        }
        .crt-footer__box {
            border: 1px solid #000;
            border-left: 0;
            padding: 2mm 3mm;
            font-size: 9pt;
            line-height: 1.45;
        }
        .crt-footer__box a { color: #0000ff; text-decoration: underline; }

        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()"><?php echo crt_h($t["PRINT"]); ?></button>
</div>

<div class="page">

    <div class="crt-head">
        <div class="crt-logo"><img src="<?php echo $CRT_CFG["ASSETS"]; ?>/logo.png" alt=""></div>
        <div class="crt-company">
            <div class="crt-company__name"><?php echo crt_h($seller["NAME"]); ?></div>
            <div class="crt-company__code"><?php echo crt_h($t["TAX"]); ?> <b><?php echo crt_h($CRT_SELLER["CODE"]); ?></b></div>
            <div><b><?php echo crt_h(crt_fmtDate($today, $lang)); ?></b></div>
        </div>
    </div>

    <div class="crt-title"><?php echo crt_h($t["DOC_TITLE"]); ?></div>

    <div class="crt-seller">
        <?php echo crt_h($t["SELLER"]); ?> <b><?php echo crt_h($seller["NAME"]); ?></b><br>
        <?php echo crt_h($t["TAX_ID"]); ?> <b><?php echo crt_h($CRT_SELLER["CODE"]); ?></b><br>
        <?php echo crt_h($t["ADDRESS"]); ?> <b><?php echo crt_h($seller["ADDRESS"]); ?></b>
    </div>

    <div class="crt-concern"><?php echo crt_h($t["CONCERN"]); ?></div>

    <div><?php echo $intro; ?></div>

    <div class="crt-block crt-prop">
        <b><?php echo crt_h($t["PROPERTY"]); ?></b>
        <?php echo crt_h($address); ?><?php echo $propParts ? ", " . crt_h(implode(", ", $propParts)) : ""; ?>.<br>
        <?php echo crt_h($t["PRICE"]); ?> <b><?php echo $curSymbol . " " . crt_amount($price); ?>.</b>
        <?php if ($contractDate): ?>
            <?php echo crt_h($t["CONTRACT"]); ?> <b><?php echo crt_h(crt_fmtDate($contractDate, $lang)); ?></b>
        <?php endif; ?>
    </div>

    <div class="crt-pay">
        <div class="crt-pay__head"><?php echo crt_h($t["PAY_HEAD"]); ?></div>
        <p><?php echo $paidLine; ?></p>
        <p><?php echo $statusLine; ?></p>
    </div>

    <div class="crt-closing"><?php echo crt_h($closing); ?></div>

    <div class="crt-sign">
        <?php echo crt_h($t["SINCERELY"]); ?><br>
        <b><?php echo crt_h($signer["NAME"]); ?></b>
        <div class="crt-sign__role"><?php echo crt_h($signer["ROLE"]); ?></div>
    </div>

    <div class="crt-footer">
        <img class="crt-footer__banner" src="<?php echo $CRT_CFG["ASSETS"]; ?>/footer.png" alt="">
        <div class="crt-footer__box">
            <?php echo crt_h($foot["ADDRESS_1"]); ?><br>
            <?php echo crt_h($foot["ADDRESS_2"]); ?>
        </div>
        <div class="crt-footer__box">
            <?php echo crt_h($t["TEL"]); ?> <?php echo crt_h($CRT_FOOTER["PHONE"]); ?><br>
            <?php echo crt_h($t["MAIL"]); ?> <a href="mailto:<?php echo crt_h($CRT_FOOTER["EMAIL"]); ?>"><?php echo crt_h($CRT_FOOTER["EMAIL"]); ?></a><br>
            <?php echo crt_h($CRT_FOOTER["WEB"]); ?>
        </div>
    </div>

</div>

</body>
</html>