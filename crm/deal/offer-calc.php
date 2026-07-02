<?
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle(" ");
CJSCore::Init(array("jquery"));

function getCIBlockElementsByFilter($arFilter = array())
{
    $arElements = array();
    $arSelect = array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*", "PREVIEW_PICTURE", "DETAIL_PICTURE", "IBLOCK_SECTION_ID");
    $res = CIBlockElement::GetList(array(), $arFilter, false, array("nPageSize" => 50), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = array();
        foreach ($arFilds as $key => $arFild)
            $arPushs[$key] = $arFild;
        foreach ($arProps as $key => $arProp)
            $arPushs[$key] = $arProp["VALUE"];
        $arPushs["sartulinew"] = CFile::GetPath($arPushs["sartulinew"]);
        $arPushs["mtavari_foto"] = CFile::GetPath($arPushs["mtavari_foto"]);
        $arPushs["floorplan"] = CFile::GetPath($arPushs["floorplan"]);
        $arPushs["threedrender"] = CFile::GetPath($arPushs["threedrender"]);
        $arPushs["xedi_1"] = CFile::GetPath($arPushs["xedi_1"]);
        $arPushs["xedi_2"] = CFile::GetPath($arPushs["xedi_2"]);
        $arPushs["xedi_3"] = CFile::GetPath($arPushs["xedi_3"]);
        $price = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"] = $price["PRICE"];
        array_push($arElements, $arPushs);
    }
    return $arElements;
}

function getUsersdsByID($id)
{
    $arSelect = array('SELECT' => array("ID", "WORK_POSITION", "PERSONAL_ICQ", "UF_*"));
    $arFilter = array("ID" => $id);
    $rsUsers = CUser::GetList(($by = "NAME"), ($order = "desc"), $arFilter, $arSelect);
    while ($arUser = $rsUsers->Fetch()) {
        return $arUser;
    }
    return array();
}

global $USER;
$userID = $USER->GetID();
$salesmeneger = getUsersdsByID($userID);
$salesmenegerphone = $salesmeneger["PERSONAL_MOBILE"];
$salesmenegername = $salesmeneger["NAME"] . " " . $salesmeneger["LAST_NAME"];

$date = date("Y-m-d");
$url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
$seb = file_get_contents($url);
$seb = json_decode($seb);
$seb_currency = $seb[0]->currencies[0]->rate;

// ── პარამეტრები ──
$prod_ID   = $_GET["prod_ID"] ?? null;
$dealId    = $_GET["dealId"]  ?? null;
$dataID    = isset($_GET['dataID']) && $_GET['dataID'] !== 'undefined' ? $_GET['dataID'] : null;

if (!$prod_ID) exit('prod_ID არ არის მითითებული');

$arFilter = array("ID" => $prod_ID);
$product = getCIBlockElementsByFilter($arFilter);

$korpusi          = $product[0]['_L24CUB'];
$sadarbazo        = $product[0]['_D599QA'];
$sartuli          = $product[0]['_FTRIDL'];
$flatNum          = $product[0]['__6KWOWZ'];
$totalspace       = $product[0]['__173JA5'];
$sacxovrebelifarti = $product[0]['__US58ND'];
$aivani           = $product[0]['__BL1XXK'];
$kvmdollar        = $product[0]['__6ZWTER'];
$kvmezo           = $product[0]['yardKvmPrice'];
$kvmterasa        = $product[0]['terraceprice_per'];
$sartulinew       = $product[0]["sartulinew"];
$mtavari_foto     = $product[0]["mtavari_foto"];
$floorplan        = $product[0]['floorplan'];
$threeD           = $product[0]["threedrender"];
$xedi_1           = $product[0]['xedi_1'];
$xedi_2           = $product[0]["xedi_2"];
$xedi_3           = $product[0]["xedi_3"];
$projectName      = $product[0]['__VO9RG4'];
$fartisType1      = $product[0]['__X1GCRZ'];

if ($fartisType1 == "ბინა") {
    $fartisType = "ბინი";
} else {
    $fartisType = $fartisType1;
}

// ── კალკულატორის მონაცემები (iBlock 26 ან graphJSON პარამეტრი) ──
$scheduleRows = [];
$calcPrice    = round($product[0]['PRICE']);
$calcKvmPrice = floatval($kvmdollar);
$calcPlanType = '';
$calcAdvance  = 0;
$calcMonthly  = 0;
$calcTotal    = 0;

// Source 1: saved iBlock 26 element
if ($dataID) {
    $offerElement = getCIBlockElementsByFilter(['IBLOCK_ID' => 26, 'ID' => $dataID]);
    $rawRows    = $offerElement[0]['JSON'] ?? '[]';
    $storedData = json_decode(html_entity_decode($rawRows), true) ?: [];
    $calcPlanType = $storedData['planType'] ?? $storedData['graph'] ?? '';
    $calcPrice    = !empty($storedData['PRICE'])    ? floatval($storedData['PRICE'])    : $calcPrice;
    $calcKvmPrice = !empty($storedData['kvmPrice']) ? floatval($storedData['kvmPrice']) : $calcKvmPrice;
    $rows = $storedData['data'] ?? [];

// Source 2: graphJSON passed directly in URL from calculator
} elseif (!empty($_GET['graphJSON'])) {
    $storedData   = json_decode($_GET['graphJSON'], true) ?: [];
    $calcPlanType = $storedData['planType'] ?? '';
    $calcPrice    = !empty($storedData['PRICE'])    ? floatval($storedData['PRICE'])    : $calcPrice;
    $calcKvmPrice = !empty($storedData['kvmPrice']) ? floatval($storedData['kvmPrice']) : $calcKvmPrice;
    $rows = $storedData['data'] ?? [];
} else {
    $rows = [];
}

foreach ($rows as $i => $row) {
    $amt = floatval(str_replace(',', '', $row['amount'] ?? 0));
    $scheduleRows[] = [
        'payment' => $row['payment'] ?? ($i + 1),
        'date'    => $row['date']    ?? '',
        'amount'  => $amt,
    ];
    $calcTotal += $amt;
    if ($i === 0) $calcAdvance = $amt;
    if ($i === 1) $calcMonthly = $amt;
}

// ── ლოგო ──
$arFilter = array("ID" => 10953);
$zion = getCIBlockElementsByFilter($arFilter);
if (count($zion)) $zionfoto = CFile::GetPath($zion[0]["PHOTO"]);

$arFilter = array("ID" => 11131);
$z = getCIBlockElementsByFilter($arFilter);
if (count($z)) $zfoto = CFile::GetPath($z[0]["PHOTO"]);
?>

<head>
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-nino-elite-exp-caps/css/bpg-nino-elite-exp-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-web-001-caps/css/bpg-web-001-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/arial-geo-bolditalic/css/arial-geo-bolditalic.min.css">
</head>

<style>
    .workarea-content-paddings { padding: 0 !important; }
    .bx-layout-inner-inner-top-row { display: none; }
    .bx-layout-inner-left { display: none; background-color: #f9faf8; }
    .bx-layout-inner-inner-cont { padding: 0 !important; background-color: #f9faf8; }
    .workarea-content { margin: 0 !important; background-color: #f9faf8; }
    #header-inner, #bx-im-bar, #header { display: none; }
    :root { background-color: #f9faf8; }

    .foto {
        background-image: url("<?php echo $zfoto ?>");
        position: absolute;
        background-repeat: no-repeat;
        top: 220px; left: 30px;
        width: 100%; height: 100vh;
        background-size: 300px;
    }
    .foto2 {
        background-image: url("<?php echo $zionfoto ?>");
        background-repeat: no-repeat;
        width: 100%; height: 100%;
        background-size: 250px;
        margin-left: 50px; margin-top: 30px;
        position: absolute; display: flex;
    }

    .rounded-cell {
        height: 0px;
        background-color: #0b3860;
        border-radius: 50px;
        display: flex; align-items: center;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        padding: 20px;
        margin-left: 450px; margin-top: 20px; margin-right: 30px;
    }
    .footer {
        max-width: 100%; height: 50px;
        background-color: #0b3860;
        border-radius: 50px;
        display: flex; align-items: center;
        padding: 20px;
        margin-top: 13px; margin-right: 37px;
    }
    .footertext {
        color: white; font-size: 12px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase; margin: 5px; font-weight: normal;
    }
    .rounded-cell2 {
        max-width: 800px; height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex; align-items: center;
        padding: 20px; margin-top: 13px;
    }
    .rounded-cell3 {
        max-width: 800px; height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex; align-items: center;
        padding: 20px; margin-top: 5px;
    }
    .rounded-cell4 {
        max-width: 800px; height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex; align-items: center;
        padding: 20px; margin-top: 5px; margin-right: 30px;
    }
    .rounded-cell5 {
        max-width: 800px; height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex; align-items: center;
        padding: 20px; margin-top: 13px; margin-right: 30px;
    }
    .cell-text {
        color: white; font-size: 24px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase; margin: 5px; font-weight: bold;
    }
    .cell-text2 {
        color: #0b3860; font-size: 15px;
        font-family: "BPG Nino Elite Exp Caps", sans-serif;
        text-transform: uppercase; margin: 5px; font-weight: bold;
    }
    .column-container {
        display: flex; flex-direction: row;
        align-items: stretch;
        margin-left: 450px;
        justify-content: space-between;
    }
    .column { flex: 1; margin-right: 10px; width: 48%; }
    .column:last-child { margin-right: 0; }

    /* ── გრაფიკის ცხრილი ── */
    .schedule-section {
        margin-left: 0;
        margin-right: 0;
        margin-top: 24px;
        padding: 0 30px;
    }
    .schedule-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #b2c2e1;
        font-family: "BPG Nino Elite Exp Caps", sans-serif;
        font-size: 13px;
    }
    .schedule-table thead tr {
        background-color: #0b3860;
    }
    .schedule-table th {
        color: white;
        padding: 10px 14px;
        text-align: center;
        font-size: 12px;
        text-transform: uppercase;
    }
    .schedule-table td {
        padding: 8px 14px;
        text-align: center;
        border-top: 1px solid #dde5f0;
        background: white;
        color: #0b3860;
    }
    .schedule-table tbody tr:nth-child(even) td {
        background: #f0f4ff;
    }
    .schedule-table tfoot td {
        background: #b2c2e1;
        color: #0b3860;
        font-weight: bold;
        padding: 10px 14px;
        text-align: center;
        text-transform: uppercase;
    }
    .schedule-summary {
        display: flex;
        gap: 12px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .summary-pill {
        background: #0b3860;
        color: white;
        border-radius: 50px;
        padding: 8px 20px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        font-size: 12px;
        text-transform: uppercase;
    }
    .summary-pill span {
        color: #95c084;
        font-weight: bold;
        margin-left: 6px;
    }

    .floorplan img, .threeDRender img, .sartulinew img,
    .mtavari_foto img, .xedi_1 img, .xedi_2 img, .xedi_3 img {
        width: 100%; height: auto;
    }

    /* ── PDF ღილაკი ── */
    .pdf-export-btn {
        position: fixed;
        top: 20px; right: 20px;
        z-index: 9999;
        background-color: #0b3860;
        color: white; border: none;
        padding: 12px 24px;
        border-radius: 50px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        font-size: 13px; text-transform: uppercase;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        transition: background-color 0.2s;
    }
    .pdf-export-btn:hover { background-color: #95c084; }
    .pdf-export-btn:disabled { background-color: #888; cursor: not-allowed; }
    @media print { .pdf-export-btn { display: none !important; } }
</style>

<button class="pdf-export-btn" id="pdfBtn" onclick="downloadPDF()">&#8595; PDF გადმოწერა</button>

<div class="foto"></div>
<div class="foto2"></div>

<div class="page-content">
<div>
    <!-- ბინის დეტალები -->
    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?>ს დეტალები</p>
    </div>
    <div class="column-container">
        <div class="column">
            <div class="rounded-cell2"><p class="cell-text2">პროექტი</p></div>
            <div class="rounded-cell3" id="korpusiDiv"><p class="cell-text2">კორპუსი</p></div>
            <div class="rounded-cell3" id="sadarbazoDiv"><p class="cell-text2">სადარბაზო</p></div>
            <div class="rounded-cell3" id="sartuliDiv"><p class="cell-text2">სართული</p></div>
            <div class="rounded-cell3" id="binisNomeriDiv"><p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?>ს ნომერი</p></div>
            <div class="rounded-cell3" id="binisJamuriFasiDiv"><p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?>ს ჯამური ფართი</p></div>
            <div class="rounded-cell3" id="sacxovrebeliFartiDiv"><p class="cell-text2">შიდა ფართი</p></div>
            <div class="rounded-cell3" id="sazafxuloDiv"><p class="cell-text2">საზაფხულო ფართი</p></div>
        </div>
        <div class="column">
            <div class="rounded-cell5"><p class="cell-text2"><span id="projectName"> </span></p></div>
            <div class="rounded-cell4" id="korpusiValueDiv"><p class="cell-text2"><span id="korpusi"> </span></p></div>
            <div class="rounded-cell4" id="sadarbazoValueDiv"><p class="cell-text2"><span id="sadarbazo"> </span></p></div>
            <div class="rounded-cell4" id="sartuliValueDiv"><p class="cell-text2"><span id="sartuli"> </span></p></div>
            <div class="rounded-cell4" id="binisNomeriValueDiv"><p class="cell-text2"><span id="flatNum"> </span></p></div>
            <div class="rounded-cell4" id="totalspaceValueDiv"><p class="cell-text2"><span id="totalspace"> </span></p></div>
            <div class="rounded-cell4" id="sacxovrebeliFartiValueDiv"><p class="cell-text2"><span id="sacxovrebelifarti"> </span></p></div>
            <div class="rounded-cell4" id="sazafxuloValueDiv"><p class="cell-text2"><span id="aivani"> </span></p></div>
        </div>
    </div>

    <!-- ღირებულება -->
    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?>ს ღირებულება</p>
    </div>
    <div class="column-container">
        <div class="column">
            <div class="rounded-cell2"><p class="cell-text2">საცხოვრებელი ფართის ფასი 1 კვ.მ</p></div>
            <div class="rounded-cell2"><p class="cell-text2">ჯამური ფასი</p></div>
            <?php if (!empty($calcPlanType)): ?>
            <div class="rounded-cell2"><p class="cell-text2">გადახდის გრაფიკი</p></div>
            <?php endif; ?>
        </div>
        <div class="column">
            <div class="rounded-cell5"><p class="cell-text2"><span id="kvmPriceDisplay"> </span></p></div>
            <div class="rounded-cell5"><p class="cell-text2"><span id="totalPriceDisplay"> </span></p></div>
            <?php if (!empty($calcPlanType)): ?>
            <div class="rounded-cell5"><p class="cell-text2"><?php echo htmlspecialchars($calcPlanType); ?></p></div>
            <?php endif; ?>
        </div>
    </div>


        <!-- ფუთერი -->
        <div class="column-container" style="margin-top: 20px;">
        <div class="column">
            <div class="footer">
                <p class="footertext">
                    <?php echo htmlspecialchars($salesmenegername); ?><br>
                    ტელეფონი: <?php echo htmlspecialchars($salesmenegerphone); ?>
                </p>
            </div>
        </div>
    </div>
</div>

    <!-- გადახდის გრაფიკი -->
    <?php if (!empty($scheduleRows)): ?>
    <div class="schedule-section">
        <div class="rounded-cell" style="margin-left:0; margin-right:0; margin-bottom:16px;">
            <p class="cell-text">გადახდის გრაფიკი</p>
        </div>

        <div class="schedule-summary">
            <div class="summary-pill">პირველადი შენატანი: <span>$ <?php echo number_format($calcAdvance, 2, '.', ','); ?></span></div>
            <?php if ($calcMonthly > 0): ?>
            <div class="summary-pill">თვეში: <span>$ <?php echo number_format($calcMonthly, 2, '.', ','); ?></span></div>
            <?php endif; ?>
            <div class="summary-pill">სულ: <span>$ <?php echo number_format($calcTotal, 2, '.', ','); ?></span></div>
        </div>

        <table class="schedule-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>გადახდის თარიღი</th>
                    <th>თანხა ($)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scheduleRows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['payment']); ?></td>
                    <td><?php echo htmlspecialchars($row['date']); ?></td>
                    <td>$ <?php echo number_format($row['amount'], 2, '.', ','); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">სულ</td>
                    <td>$ <?php echo number_format($calcTotal, 2, '.', ','); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>


</div><!-- /page-content (page 1: details + pricing + schedule) -->

<!-- Pages 2+: one image per PDF page. Each wrapper is only emitted when that
     image actually has a value, and downloadPDF() turns every .page-content
     element into its own PDF page. -->

<?php if ($mtavari_foto) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="mtavari_foto" id="mtavari_foto"></div>
</div>
<?php endif; ?>

<?php if ($sartulinew) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="sartulinew" id="sartulinew"></div>
</div>
<?php endif; ?>

<?php if ($floorplan) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="floorplan" id="floorplan"></div>
</div>
<?php endif; ?>

<?php if ($threeD) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="threeDRender" id="threeDRender"></div>
</div>
<?php endif; ?>

<?php if ($xedi_1) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="xedi_1" id="xedi_1"></div>
</div>
<?php endif; ?>

<?php if ($xedi_2) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="xedi_2" id="xedi_2"></div>
</div>
<?php endif; ?>

<?php if ($xedi_3) : ?>
<div style="page-break-before: always;"></div>
<div class="page-content">
    <div class="xedi_3" id="xedi_3"></div>
</div>
<?php endif; ?>

<script>
function formatNumber(num) {
    const options = { useGrouping: true };
    if (num % 1 !== 0) { options.minimumFractionDigits = 2; options.maximumFractionDigits = 2; }
    return num.toLocaleString('en', options);
}

const projectName      = <?php echo json_encode($projectName); ?>;
const korpusi          = <?php echo json_encode($korpusi); ?>;
const sadarbazo        = <?php echo json_encode($sadarbazo); ?>;
const sartuli          = <?php echo json_encode($sartuli); ?>;
const flatNum          = <?php echo json_encode($flatNum); ?>;
const totalspace       = <?php echo json_encode($totalspace); ?>;
const sacxovrebelifarti = <?php echo json_encode($sacxovrebelifarti); ?>;
const aivani           = <?php echo json_encode($aivani); ?>;
const threeD           = <?php echo json_encode($threeD); ?>;
const floorplan        = <?php echo json_encode($floorplan); ?>;
const sartulinew       = <?php echo json_encode($sartulinew); ?>;
const mtavari_foto     = <?php echo json_encode($mtavari_foto); ?>;
const xedi_1           = <?php echo json_encode($xedi_1); ?>;
const xedi_2           = <?php echo json_encode($xedi_2); ?>;
const xedi_3           = <?php echo json_encode($xedi_3); ?>;
const calcKvmPrice     = <?php echo json_encode($calcKvmPrice); ?>;
const calcPrice        = <?php echo json_encode($calcPrice); ?>;

document.getElementById("projectName").innerText = ` ${projectName} `;
document.getElementById("kvmPriceDisplay").innerText = `$ ${formatNumber(Math.floor(calcKvmPrice))}`;
document.getElementById("totalPriceDisplay").innerText = `$ ${formatNumber(Math.floor(calcPrice))}`;

// Only insert an <img> when the URL actually exists and only into elements
// that exist on this page (a page/element for an empty image is never
// rendered at all now, so these checks also guard against null elements).
if (threeD && document.getElementById("threeDRender")) {
    document.getElementById("threeDRender").innerHTML = `<img src='${threeD}' alt='3D render' crossorigin='anonymous'>`;
}
if (floorplan && document.getElementById("floorplan")) {
    document.getElementById("floorplan").innerHTML = `<img src='${floorplan}' alt='floor plan' crossorigin='anonymous'>`;
}
if (sartulinew && document.getElementById("sartulinew")) {
    document.getElementById("sartulinew").innerHTML = `<img src='${sartulinew}' alt='floor' crossorigin='anonymous'>`;
}
if (mtavari_foto && document.getElementById("mtavari_foto")) {
    document.getElementById("mtavari_foto").innerHTML = `<img src='${mtavari_foto}' alt='photo' crossorigin='anonymous'>`;
}
if (xedi_1 && document.getElementById("xedi_1")) {
    document.getElementById("xedi_1").innerHTML = `<img src='${xedi_1}' alt='view' crossorigin='anonymous'>`;
}
if (xedi_2 && document.getElementById("xedi_2")) {
    document.getElementById("xedi_2").innerHTML = `<img src='${xedi_2}' alt='view' crossorigin='anonymous'>`;
}
if (xedi_3 && document.getElementById("xedi_3")) {
    document.getElementById("xedi_3").innerHTML = `<img src='${xedi_3}' alt='view' crossorigin='anonymous'>`;
}

if (!korpusi) {
    document.getElementById("korpusiDiv").style.display = "none";
    document.getElementById("korpusiValueDiv").style.display = "none";
} else { document.getElementById("korpusi").innerText = ` ${korpusi} `; }

if (!sadarbazo) {
    document.getElementById("sadarbazoDiv").style.display = "none";
    document.getElementById("sadarbazoValueDiv").style.display = "none";
} else { document.getElementById("sadarbazo").innerText = ` ${sadarbazo} `; }

if (!sartuli) {
    document.getElementById("sartuliDiv").style.display = "none";
    document.getElementById("sartuliValueDiv").style.display = "none";
} else { document.getElementById("sartuli").innerText = ` ${sartuli} `; }

if (!flatNum) {
    document.getElementById("binisNomeriDiv").style.display = "none";
    document.getElementById("binisNomeriValueDiv").style.display = "none";
} else { document.getElementById("flatNum").innerText = ` ${flatNum} `; }

if (!totalspace) {
    document.getElementById("binisJamuriFasiDiv").style.display = "none";
    document.getElementById("totalspaceValueDiv").style.display = "none";
} else { document.getElementById("totalspace").innerText = ` ${totalspace} კვ.მ`; }

let sacxovrebeliFartiCalculated = sacxovrebelifarti;
if (!sacxovrebelifarti && totalspace) {
    sacxovrebeliFartiCalculated = (parseFloat(totalspace) - parseFloat(aivani || 0)).toFixed(2);
}
document.getElementById("sacxovrebelifarti").innerText = `${sacxovrebeliFartiCalculated} კვ.მ`;

if (!aivani) {
    document.getElementById("sazafxuloDiv").style.display = "none";
    document.getElementById("sazafxuloValueDiv").style.display = "none";
} else { document.getElementById("aivani").innerText = ` ${aivani} კვ.მ`; }
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>

// Waits until every <img> inside a given container has either finished
// loading or errored out, so html2canvas never captures a container while
// its images are still mid-request. Each image gets a hard timeout so one
// stuck request can never hang the whole export forever.
function waitForImages(container, timeoutMs = 8000) {
    const imgs = Array.from(container.querySelectorAll('img'));
    return Promise.all(imgs.map(img => {
        if (img.complete && img.naturalWidth !== 0) return Promise.resolve();
        return new Promise(resolve => {
            const done = (reason) => {
                if (reason) console.warn('Image did not finish loading in time:', img.src, reason);
                resolve();
            };
            const timer = setTimeout(() => done('timeout'), timeoutMs);
            img.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
            img.addEventListener('error', () => { clearTimeout(timer); done('error event'); }, { once: true });
        });
    }));
}

// Rejects after ms milliseconds so a hung step can't freeze the export forever.
function withTimeout(promise, ms, label) {
    return Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error(`Timed out: ${label}`)), ms))
    ]);
}

async function downloadPDF() {
    const btn = document.getElementById('pdfBtn');
    btn.textContent = 'იტვირთება...';
    btn.disabled = true;

    try {
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const pdfW = 297, pdfH = 210;

        const pages = document.querySelectorAll('.page-content');

        if (pages.length === 0) {
            throw new Error('No .page-content elements found to export.');
        }

        for (let i = 0; i < pages.length; i++) {
            const page = pages[i];

            await withTimeout(waitForImages(page), 15000, `waiting for images on page ${i + 1}`);

            const canvas = await withTimeout(
                html2canvas(page, {
                    scale: 2, useCORS: true, allowTaint: true,
                    backgroundColor: '#f9faf8', logging: false
                }),
                20000,
                `rendering page ${i + 1} to canvas`
            );

            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const ratio = Math.min(pdfW / canvas.width, pdfH / canvas.height);
            const imgW = canvas.width * ratio, imgH = canvas.height * ratio;
            if (i > 0) pdf.addPage();
            pdf.addImage(imgData, 'JPEG', (pdfW - imgW) / 2, (pdfH - imgH) / 2, imgW, imgH);
        }

        pdf.save('offer.pdf');
        btn.textContent = '✓ გადმოწერილია';
    } catch (err) {
        console.error('PDF export failed:', err);
        btn.textContent = '⚠ შეცდომა, სცადეთ ხელახლა';
    } finally {
        btn.disabled = false;
        setTimeout(() => { btn.textContent = '↓ PDF გადმოწერა'; }, 3000);
    }
}
</script>