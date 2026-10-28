<?php
/**
 * invoice.php — ანგარიშ ფაქტურა (Invoice) for one payment-plan / payment row
 * URL: /crm/deal/invoice.php?id=<ELEMENT_ID>&dealID=<DEAL_ID>
 * Debug (shows deal + contact fields to find UF codes): &debug=1
 *
 * Images expected in /crm/deal/invoice_assets/: logo.png, signature.png, footer.png
 */

// prolog_before = Bitrix core without the portal layout (clean printable page)
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule("crm");
CModule::IncludeModule("iblock");

/* ────────────────────────────── CONFIG ──────────────────────────────
 * Put your real field codes here. Empty code => the fallback text is shown.
 */
$INV_CFG = array(
    "IBLOCK_PLAN"     => 22,                       // payment plan
    "IBLOCK_PAYMENTS" => 23,                       // payments
    "CURRENCY_FIELD"  => "UF_CRM_1702019032102",   // deal currency
    "GEL_ID"          => 322,                      // enum ID meaning GEL

    // Deal fields
    "PROJECT_FIELD"   => "",   // project name field
    "ADDRESS_FIELD"   => "",   // property address
    "CADASTRAL_FIELD" => "",   // ს/კ (cadastral code)
    "APARTMENT_FIELD" => "",   // apartment data (block / floor / N)
    "AREA_FIELD"      => "",   // area m²
    "PRICE_FIELD"     => "",   // apartment price (empty => sum of payment plan)
    "DELIVERY_FIELD"  => "",   // ჩაბარების პირობა

    // Contact fields
    "PERSONAL_ID_FIELD" => "", // პირადი ნომერი

    // Fallbacks (from the template)
    "PROJECT_DEFAULT"   => "მონოლით ეთნო სითი",
    "ADDRESS_DEFAULT"   => "ქალაქი თბილისი, ქუჩა ნადიკვარი, N: 34-ის მიმდებარედ",
    "DELIVERY_DEFAULT"  => "პრემიუმ მწვანე კარკასი",

    "ASSETS"            => "/crm/deal/invoice_assets",
);

// Supplier (static)
$INV_SUPPLIER = array(
    "NAME"    => 'შპს "მონოლით ჯგუფი პლუსი"',
    "CODE"    => "204943357",
    "ADDRESS" => "ქ.თბილისი, კრწანისის რაიონი, ბეჟან კალანდაძის ქუჩა N3, სართული 9, ბინა 67",
    "ACCOUNT" => "GE19BG0000000553216688",
    "SWIFT"   => "BAGAGE22",
    "BANK"    => "თბილისი, „საქართველოს ბანკი“",
);

// Footer (next to the footer banner)
$INV_FOOTER = array(
    "ADDRESS_1" => "სანდრო ეულის 15, საქართველო,",
    "ADDRESS_2" => "თბილისი 0186.",
    "PHONE"     => "+9950322309977",
    "EMAIL"     => "info@monolith.ge",
    "WEB"       => "www.monolith.ge",
);

/* ────────────────────────────── HELPERS ────────────────────────────── */

function inv_printArr($arr) {
    echo "<pre style='font-size:12px'>"; print_r($arr); echo "</pre>";
}

function inv_toNum($val) {
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

function inv_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function inv_money($n, $cur) {
    $sign = $n < 0 ? "-" : "";
    return $sign . $cur . " " . number_format(abs($n), 2, '.', ',');
}

function inv_parseDate($d) {
    $d = trim((string)$d);
    foreach (array('d/m/Y', 'd.m.Y', 'd/m/Y H:i:s', 'd.m.Y H:i:s', 'Y-m-d') as $f) {
        $dt = DateTime::createFromFormat($f, $d);
        if ($dt) { $dt->setTime(0, 0, 0); return $dt; }
    }
    return null;
}

function inv_geoDate(DateTime $dt) {
    $months = array(1 => "იანვარი", "თებერვალი", "მარტი", "აპრილი", "მაისი", "ივნისი",
        "ივლისი", "აგვისტო", "სექტემბერი", "ოქტომბერი", "ნოემბერი", "დეკემბერი");
    return (int)$dt->format('j') . " " . $months[(int)$dt->format('n')] . " " . $dt->format('Y') . " წ";
}

/** Reads a UF value as text: resolves enumeration IDs, strips money suffix, joins multiple values */
function inv_uf($entityId, $row, $code) {
    if ($code === "" || !isset($row[$code])) return "";
    $val = $row[$code];
    $vals = is_array($val) ? $val : array($val);

    static $types = array();
    $key = $entityId . "|" . $code;
    if (!isset($types[$key])) {
        $uf = CUserTypeEntity::GetList(array(), array("ENTITY_ID" => $entityId, "FIELD_NAME" => $code))->Fetch();
        $types[$key] = $uf ? $uf["USER_TYPE_ID"] : "";
    }

    $out = array();
    foreach ($vals as $v) {
        if ($v === "" || $v === null || $v === false) continue;
        if ($types[$key] === "enumeration") {
            $e = CUserFieldEnum::GetList(array(), array("ID" => $v))->Fetch();
            $v = $e ? $e["VALUE"] : $v;
        } elseif ($types[$key] === "money") {
            $v = preg_replace('/\|[A-Z]{3}$/i', '', $v);
        }
        $out[] = $v;
    }
    return trim(implode(", ", $out));
}

function inv_getElement($id, $iblockId) {
    $res = CIBlockElement::GetList(array(), array("ID" => $id, "IBLOCK_ID" => $iblockId), false, false,
        array("ID", "IBLOCK_ID", "NAME"));
    if ($ob = $res->GetNextElement()) {
        return array("FIELDS" => $ob->GetFields(), "PROPS" => $ob->GetProperties());
    }
    return null;
}

function inv_getRows($iblockId, $dealId) {
    $rows = array();
    $res = CIBlockElement::GetList(array(), array("IBLOCK_ID" => $iblockId, "PROPERTY_DEAL" => $dealId), false,
        array("nPageSize" => 99999), array("ID", "IBLOCK_ID", "NAME"));
    while ($ob = $res->GetNextElement()) {
        $f = $ob->GetFields();
        $rows[] = array("ID" => $f["ID"], "PROPS" => $ob->GetProperties());
    }
    return $rows;
}

/* ────────────────────────────── DATA ────────────────────────────── */

$elementID = (int)$_GET["id"];
$dealID    = (int)$_GET["dealID"];

$deal = CCrmDeal::GetList(array(), array("ID" => $dealID, "CHECK_PERMISSIONS" => "N"),
    array("*", "UF_*"))->Fetch();

if (!$deal || !$elementID) {
    die("ინვოისი ვერ მოიძებნა");
}

$isGEL  = ($deal[$INV_CFG["CURRENCY_FIELD"]] == $INV_CFG["GEL_ID"]);
$valuta = $isGEL ? "₾" : "$";

// Element can be a plan row or a payment row
$rowType = "PLAN";
$el = inv_getElement($elementID, $INV_CFG["IBLOCK_PLAN"]);
if (!$el) {
    $el = inv_getElement($elementID, $INV_CFG["IBLOCK_PAYMENTS"]);
    $rowType = "PAYMENT";
}
if (!$el || (int)$el["PROPS"]["DEAL"]["VALUE"] !== $dealID) {
    die("ინვოისი ვერ მოიძებნა");
}

$rowDate = ($rowType === "PLAN") ? $el["PROPS"]["TARIGI"]["VALUE"] : $el["PROPS"]["date"]["VALUE"];
$invDate = inv_parseDate($rowDate);
if (!$invDate) $invDate = new DateTime();

// All plan + payment rows of the deal
$planRows = inv_getRows($INV_CFG["IBLOCK_PLAN"], $dealID);
$payRows  = inv_getRows($INV_CFG["IBLOCK_PAYMENTS"], $dealID);

$totalPlan = 0.0;
foreach ($planRows as $r) {
    $totalPlan += inv_toNum($isGEL ? $r["PROPS"]["amount_GEL"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]);
}

// Paid up to (and including) the invoice date
$paidToDate = 0.0;
foreach ($payRows as $r) {
    $d = inv_parseDate($r["PROPS"]["date"]["VALUE"]);
    if ($d && $d > $invDate) continue;
    $amt = inv_toNum($isGEL ? $r["PROPS"]["tanxa_gel"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]);
    $paidToDate += ($r["PROPS"]["refund"]["VALUE"] == "YES") ? -$amt : $amt;
}

// Invoice number: YYYYMMDD-NN (NN = position of this row among its type, sorted by date)
$sameType = ($rowType === "PLAN") ? $planRows : $payRows;
$dateKey  = ($rowType === "PLAN") ? "TARIGI" : "date";
usort($sameType, function ($a, $b) use ($dateKey) {
    $da = inv_parseDate($a["PROPS"][$dateKey]["VALUE"]);
    $db = inv_parseDate($b["PROPS"][$dateKey]["VALUE"]);
    if ($da == $db) return $a["ID"] <=> $b["ID"];
    return $da <=> $db;
});
$seq = 1;
foreach ($sameType as $k => $r) {
    if ((int)$r["ID"] === $elementID) { $seq = $k + 1; break; }
}
$invNumber = $invDate->format('Ymd') . "-" . str_pad($seq, 2, "0", STR_PAD_LEFT);

// Payer
$payerName = "";
$payerPID  = "";

// Deal's contact: primary contact from the deal-contact bindings, fallback to CONTACT_ID
$payerContactID = 0;
if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
    $bindings = \Bitrix\Crm\Binding\DealContactTable::getDealBindings($dealID);
    foreach ($bindings as $b) {
        if (!$payerContactID || $b["IS_PRIMARY"] === "Y") {
            $payerContactID = (int)$b["CONTACT_ID"];
            if ($b["IS_PRIMARY"] === "Y") break;
        }
    }
}
if (!$payerContactID && !empty($deal["CONTACT_ID"])) {
    $payerContactID = (int)$deal["CONTACT_ID"];
}

$contact = null;
if ($payerContactID) {
    $contact = CCrmContact::GetList(array(), array("ID" => $payerContactID, "CHECK_PERMISSIONS" => "N"),
        array("ID", "NAME", "SECOND_NAME", "LAST_NAME", "FULL_NAME", "UF_*"))->Fetch();
}

if ($contact) {
    $payerName = trim($contact["NAME"] . " " . $contact["LAST_NAME"]);
    if ($payerName === "") $payerName = trim((string)$contact["FULL_NAME"]);
    $payerPID  = inv_uf("CRM_CONTACT", $contact, $INV_CFG["PERSONAL_ID_FIELD"]);
} elseif (!empty($deal["COMPANY_ID"])) {
    $company = CCrmCompany::GetList(array(), array("ID" => $deal["COMPANY_ID"], "CHECK_PERMISSIONS" => "N"),
        array("TITLE"))->Fetch();
    $payerName = $company ? $company["TITLE"] : "";
}

// Property
$project   = inv_uf("CRM_DEAL", $deal, $INV_CFG["PROJECT_FIELD"])  ?: $INV_CFG["PROJECT_DEFAULT"];
$address   = inv_uf("CRM_DEAL", $deal, $INV_CFG["ADDRESS_FIELD"])  ?: $INV_CFG["ADDRESS_DEFAULT"];
$cadastral = inv_uf("CRM_DEAL", $deal, $INV_CFG["CADASTRAL_FIELD"]);
$apartment = inv_uf("CRM_DEAL", $deal, $INV_CFG["APARTMENT_FIELD"]);
$area      = inv_uf("CRM_DEAL", $deal, $INV_CFG["AREA_FIELD"]);
$delivery  = inv_uf("CRM_DEAL", $deal, $INV_CFG["DELIVERY_FIELD"]) ?: $INV_CFG["DELIVERY_DEFAULT"];

$priceRaw  = inv_uf("CRM_DEAL", $deal, $INV_CFG["PRICE_FIELD"]);
$price     = $priceRaw !== "" ? inv_toNum($priceRaw) : $totalPlan;

// ── ნაშთი for this row: same running balance as finance_card.php ──
$ledger = array();
foreach ($planRows as $r) {
    $ledger[] = array(
        "ID"    => (int)$r["ID"],
        "TYPE"  => "PLAN",
        "DATE"  => $r["PROPS"]["TARIGI"]["VALUE"],
        "DELTA" => inv_toNum($isGEL ? $r["PROPS"]["amount_GEL"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]),
    );
}
foreach ($payRows as $r) {
    $amt = inv_toNum($isGEL ? $r["PROPS"]["tanxa_gel"]["VALUE"] : $r["PROPS"]["TANXA"]["VALUE"]);
    $ledger[] = array(
        "ID"    => (int)$r["ID"],
        "TYPE"  => "PAYMENT",
        "DATE"  => $r["PROPS"]["date"]["VALUE"],
        "DELTA" => ($r["PROPS"]["refund"]["VALUE"] == "YES") ? $amt : -$amt,
    );
}
usort($ledger, function ($a, $b) {
    $da = DateTime::createFromFormat('d/m/Y', $a["DATE"]);
    $db = DateTime::createFromFormat('d/m/Y', $b["DATE"]);
    return $da <=> $db;
});

$leftToPay = 0.0;
$running   = 0.0;
foreach ($ledger as $l) {
    $running = round($running + $l["DELTA"], 2);
    if ($l["ID"] === $elementID && $l["TYPE"] === $rowType) {
        $leftToPay = $running;
        break;
    }
}

// Totals
$totalValue = $price;
$totalPaid  = round($paidToDate, 2);
$toPay      = $leftToPay;   // = ნაშთი of this row in the finance card

if (isset($_GET["debug"])) {
    echo "<h3>DEAL</h3>";   inv_printArr($deal);
    if (!empty($contact)) { echo "<h3>CONTACT</h3>"; inv_printArr($contact); }
    echo "<h3>ELEMENT ($rowType)</h3>"; inv_printArr($el["PROPS"]);
    die();
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ინვოისი <?php echo inv_h($invNumber); ?></title>
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
            padding: 14mm 14mm 0;
            background: #fff;
            box-shadow: 0 6px 24px rgba(0,0,0,.08);
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .inv-head {
            display: grid;
            grid-template-columns: 38mm 1fr 38mm;
            align-items: center;
        }
        .inv-logo img { width: 32mm; display: block; }
        .inv-title { text-align: center; font-weight: 700; line-height: 1.9; font-size: 14px; }

        /* Supplier */
        .inv-supplier { margin-top: 10mm; font-weight: 700; line-height: 1.75; }

        /* Payer */
        .inv-payer {
            margin-top: 8mm;
            display: grid;
            grid-template-columns: 38mm 1fr;
            font-weight: 700;
        }
        .inv-payer span:last-child { text-align: center; }

        /* Property table */
        .inv-table {
            width: 100%;
            margin-top: 8mm;
            border-collapse: collapse;
        }
        .inv-table th {
            font-weight: 700;
            text-align: center;
            padding: 6px 4px;
            border-bottom: 1.5px solid #111;
        }
        .inv-table th:first-child { text-align: left; }
        .inv-table td {
            padding: 8px 4px;
            text-align: center;
            vertical-align: top;
            font-size: 12px;
        }
        .inv-table td:first-child { text-align: left; }
        .inv-table .paid td {
            font-weight: 700;
            border-bottom: 1px solid #d0d4da;
        }
        .inv-table .paid td.lbl { text-align: left; }
        .money { white-space: nowrap; font-variant-numeric: tabular-nums; }

        /* Totals */
        .inv-totals {
            margin-top: 8mm;
            margin-left: auto;
            width: 110mm;
            border-collapse: collapse;
        }
        .inv-totals td { padding: 6px 8px; }
        .inv-totals td:last-child { text-align: right; }
        .inv-totals tr.due td { font-weight: 700; background: #ffff00; }

        .inv-delivery { margin-top: 8mm; }
        .inv-delivery b { margin-left: 8mm; }

        .inv-note { margin-top: 6mm; font-size: 10.5px; }

        .inv-sign { margin-top: 6mm; }
        .inv-sign img { width: 52mm; display: block; margin-left: 40mm; }
        .inv-sign p { margin: 2mm 0 0; font-size: 10.5px; }

        .inv-footer {
            margin-top: auto;
            padding: 8mm 0 10mm;
            display: grid;
            grid-template-columns: 41fr 30fr 29fr;
            align-items: stretch;
            gap: 0;
        }
        .inv-footer__banner {
            width: 100%;
            height: 100%;
            min-height: 15mm;
            object-fit: cover;
            display: block;
        }
        .inv-footer__box {
            border: 1px solid #000;
            border-left: 0;
            padding: 2mm 3mm;
            font-size: 9pt;
            line-height: 1.45;
        }
        .inv-footer__box a { color: #0000ff; text-decoration: underline; }

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
    <button type="button" onclick="window.print()">ბეჭდვა / PDF</button>
</div>

<div class="page">

    <div class="inv-head">
        <div class="inv-logo"><img src="<?php echo $INV_CFG["ASSETS"]; ?>/logo.png" alt=""></div>
        <div class="inv-title">
            ანგარიშ ფაქტურა N: <?php echo inv_h($invNumber); ?><br>
            <?php echo inv_h(inv_geoDate(new DateTime())); ?><br>
            პროექტი "<?php echo inv_h($project); ?>"
        </div>
        <div></div>
    </div>

    <div class="inv-supplier">
        მიმწოდებელი: <?php echo inv_h($INV_SUPPLIER["NAME"]); ?> (ს/კ <?php echo inv_h($INV_SUPPLIER["CODE"]); ?>)<br>
        მისამართი: <?php echo inv_h($INV_SUPPLIER["ADDRESS"]); ?><br>
        ანგ.N <?php echo inv_h($INV_SUPPLIER["ACCOUNT"]); ?><br>
        კოდი <?php echo inv_h($INV_SUPPLIER["SWIFT"]); ?><br>
        <?php echo inv_h($INV_SUPPLIER["BANK"]); ?>
    </div>

    <div class="inv-payer">
        <span>გადამხდელი:</span>
        <span><?php echo inv_h($payerName); ?><?php echo $payerPID !== "" ? " (" . inv_h($payerPID) . ")" : ""; ?></span>
    </div>

    <table class="inv-table">
        <thead>
            <tr>
                <th style="width:30%">უძრავი ქონების მის:</th>
                <th style="width:18%">ს/კ</th>
                <th style="width:24%">ბინის მონაცემები:</th>
                <th style="width:12%">ფართი:</th>
                <th style="width:16%">ღირებულება</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo inv_h($address); ?></td>
                <td><?php echo inv_h($cadastral); ?></td>
                <td><?php echo inv_h($apartment); ?></td>
                <td><?php echo $area !== "" ? inv_h($area) . " მ²" : ""; ?></td>
                <td class="money"><?php echo inv_money($price, $valuta); ?></td>
            </tr>
            <tr class="paid">
                <td></td>
                <td class="lbl" colspan="3">გადახდილი თანხა:</td>
                <td class="money"><?php echo inv_money($totalPaid, $valuta); ?></td>
            </tr>
        </tbody>
    </table>

    <table class="inv-totals">
        <tr>
            <td>ჯამური ღირებულება:</td>
            <td class="money"><?php echo inv_money($totalValue, $valuta); ?></td>
        </tr>
        <tr>
            <td>გადახდილი თანხა ჯამურად:</td>
            <td class="money"><?php echo inv_money($totalPaid, $valuta); ?></td>
        </tr>
        <tr class="due">
            <td>გადასახდელი თანხა:</td>
            <td class="money"><?php echo inv_money($toPay, $valuta); ?></td>
        </tr>
    </table>

    <div class="inv-delivery">
        ჩაბარების პირობა: <b><?php echo inv_h($delivery); ?></b>
    </div>

    <?php if (!$isGEL): ?>
    <div class="inv-note">
        ანგარიშწორება წარმოებს გადახდის დღეს არსებული ეროვნული ბანკის კურსით ეკვივალენტი ლარში.
    </div>
    <?php endif; ?>

    <div class="inv-sign">
        <img src="<?php echo $INV_CFG["ASSETS"]; ?>/signature.png" alt="">
        <p>ინვოისი მომზადებული და დადასტურებულია ბუღალტერიის მიერ</p>
    </div>

    <div class="inv-footer">
        <img class="inv-footer__banner" src="<?php echo $INV_CFG["ASSETS"]; ?>/footer.png" alt="">
        <div class="inv-footer__box">
            <?php echo inv_h($INV_FOOTER["ADDRESS_1"]); ?><br>
            <?php echo inv_h($INV_FOOTER["ADDRESS_2"]); ?>
        </div>
        <div class="inv-footer__box">
            ტელ: <?php echo inv_h($INV_FOOTER["PHONE"]); ?><br>
            ელ.ფოსტა: <a href="mailto:<?php echo inv_h($INV_FOOTER["EMAIL"]); ?>"><?php echo inv_h($INV_FOOTER["EMAIL"]); ?></a><br>
            <?php echo inv_h($INV_FOOTER["WEB"]); ?>
        </div>
    </div>

</div>

</body>
</html>