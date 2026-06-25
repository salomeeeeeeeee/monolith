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

// ── Parameters ──
$prod_ID = $_GET["prod_ID"] ?? null;
$dealId  = $_GET["dealId"]  ?? null;
$dataID  = isset($_GET['dataID']) && $_GET['dataID'] !== 'undefined' ? $_GET['dataID'] : null;

if (!$prod_ID) exit('prod_ID is not specified');

$arFilter = array("ID" => $prod_ID);
$product = getCIBlockElementsByFilter($arFilter);

$korpusi           = $product[0]['_L24CUB'];
$sadarbazo         = $product[0]['_D599QA'];
$sartuli           = $product[0]['_FTRIDL'];
$flatNum           = $product[0]['__6KWOWZ'];
$totalspace        = $product[0]['__173JA5'];
$sacxovrebelifarti = $product[0]['__US58ND'];
$aivani            = $product[0]['__BL1XXK'];
$kvmdollar         = $product[0]['__6ZWTER'];
$sartulinew        = $product[0]["sartulinew"];
$mtavari_foto      = $product[0]["mtavari_foto"];
$floorplan         = $product[0]['floorplan'];
$threeD            = $product[0]["threedrender"];
$xedi_1            = $product[0]['xedi_1'];
$xedi_2            = $product[0]["xedi_2"];
$xedi_3            = $product[0]["xedi_3"];
$projectName       = $product[0]['__VO9RG4'];
$fartisType1       = $product[0]['__X1GCRZ'];

if ($fartisType1 == "ბინა") {
    $fartisType = "APARTMENT";
} elseif ($fartisType1 == "ავტოსადგომი") {
    $fartisType = "PARKING";
} else {
    $fartisType = $fartisType1;
}

// ── Calculator data (iBlock 26 or graphJSON param) ──
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

// ── Logo ──
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
        position: absolute; background-repeat: no-repeat;
        top: 220px; left: 30px; width: 100%; height: 100vh; background-size: 300px;
    }
    .foto2 {
        background-image: url("<?php echo $zionfoto ?>");
        background-repeat: no-repeat; width: 100%; height: 100%;
        background-size: 250px; margin-left: 50px; margin-top: 30px;
        position: absolute; display: flex;
    }
    .rounded-cell {
        height: 0px; background-color: #0b3860; border-radius: 50px;
        display: flex; align-items: center;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        padding: 20px; margin-left: 450px; margin-top: 20px; margin-right: 30px;
    }
    .footer {
        max-width: 100%; height: 50px; background-color: #0b3860; border-radius: 50px;
        display: flex; align-items: center; padding: 20px; margin-top: 13px; margin-right: 37px;
    }
    .footertext {
        color: white; font-size: 12px; font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase; margin: 5px; font-weight: normal;
    }
    .rounded-cell2 { max-width: 800px; height: 0px; background-color: #b2c2e1; border-radius: 50px; display: flex; align-items: center; padding: 20px; margin-top: 13px; }
    .rounded-cell3 { max-width: 800px; height: 0px; background-color: #b2c2e1; border-radius: 50px; display: flex; align-items: center; padding: 20px; margin-top: 5px; }
    .rounded-cell4 { max-width: 800px; height: 0px; background-color: #b2c2e1; border-radius: 50px; display: flex; align-items: center; padding: 20px; margin-top: 5px; margin-right: 30px; }
    .rounded-cell5 { max-width: 800px; height: 0px; background-color: #b2c2e1; border-radius: 50px; display: flex; align-items: center; padding: 20px; margin-top: 13px; margin-right: 30px; }
    .cell-text { color: white; font-size: 24px; font-family: "BPG WEB 001 Caps", sans-serif; text-transform: uppercase; margin: 5px; font-weight: bold; }
    .cell-text2 { color: #0b3860; font-size: 15px; font-family: "BPG Nino Elite Exp Caps", sans-serif; text-transform: uppercase; margin: 5px; font-weight: bold; }
    .column-container { display: flex; flex-direction: row; align-items: stretch; margin-left: 450px; justify-content: space-between; }
    .column { flex: 1; margin-right: 10px; width: 48%; }
    .column:last-child { margin-right: 0; }

    /* ── Schedule table ── */
    .schedule-section { margin-left: 0; margin-right: 0; margin-top: 24px; padding: 0 30px; }
    
    .schedule-table {
        width: 100%; border-collapse: separate; border-spacing: 0;
        border-radius: 12px; overflow: hidden; border: 1px solid #b2c2e1;
        font-family: "BPG Nino Elite Exp Caps", sans-serif; font-size: 13px;
    }
    .schedule-table thead tr { background-color: #0b3860; }
    .schedule-table th { color: white; padding: 10px 14px; text-align: center; font-size: 12px; text-transform: uppercase; }
    .schedule-table td { padding: 8px 14px; text-align: center; border-top: 1px solid #dde5f0; background: white; color: #0b3860; }
    .schedule-table tbody tr:nth-child(even) td { background: #f0f4ff; }
    .schedule-table tfoot td { background: #b2c2e1; color: #0b3860; font-weight: bold; padding: 10px 14px; text-align: center; text-transform: uppercase; }
    .schedule-summary { display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
    .summary-pill { background: #0b3860; color: white; border-radius: 50px; padding: 8px 20px; font-family: "BPG WEB 001 Caps", sans-serif; font-size: 12px; text-transform: uppercase; }
    .summary-pill span { color: #95c084; font-weight: bold; margin-left: 6px; }

    .floorplan img, .threeDRender img, .sartulinew img,
    .mtavari_foto img, .xedi_1 img, .xedi_2 img, .xedi_3 img { width: 100%; height: auto; }

    /* ── PDF Button ── */
    .pdf-export-btn {
        position: fixed; top: 20px; right: 20px; z-index: 9999;
        background-color: #0b3860; color: white; border: none;
        padding: 12px 24px; border-radius: 50px;
        font-family: "BPG WEB 001 Caps", sans-serif; font-size: 13px; text-transform: uppercase;
        cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transition: background-color 0.2s;
    }
    .pdf-export-btn:hover { background-color: #95c084; }
    .pdf-export-btn:disabled { background-color: #888; cursor: not-allowed; }
    @media print { .pdf-export-btn { display: none !important; } }
</style>

<button class="pdf-export-btn" id="pdfBtn" onclick="downloadPDF()">&#8595; Download PDF</button>

<div class="foto"></div>
<div class="foto2"></div>

<div class="page-content">
<div>
    <!-- Apartment Details -->
    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?> DETAILS</p>
    </div>
    <div class="column-container">
        <div class="column">
            <div class="rounded-cell2"><p class="cell-text2">PROJECT</p></div>
            <div class="rounded-cell3" id="korpusiDiv"><p class="cell-text2">BLOCK</p></div>
            <div class="rounded-cell3" id="sadarbazoDiv"><p class="cell-text2">ENTRANCE</p></div>
            <div class="rounded-cell3" id="sartuliDiv"><p class="cell-text2">FLOOR</p></div>
            <div class="rounded-cell3" id="binisNomeriDiv"><p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?> NUMBER</p></div>
            <div class="rounded-cell3" id="binisJamuriFasiDiv"><p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?> TOTAL SPACE</p></div>
            <div class="rounded-cell3" id="sacxovrebeliFartiDiv"><p class="cell-text2">INDOOR SPACE</p></div>
            <div class="rounded-cell3" id="sazafxuloDiv"><p class="cell-text2">BALCONY SPACE</p></div>
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

    <!-- Price -->
    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?> PRICE</p>
    </div>
    <div class="column-container">
        <div class="column">
            <div class="rounded-cell2"><p class="cell-text2">PRICE PER 1 SQ.M</p></div>
            <div class="rounded-cell2"><p class="cell-text2">TOTAL PRICE</p></div>
            <?php if (!empty($calcPlanType)): ?>
            <div class="rounded-cell2"><p class="cell-text2">PAYMENT PLAN</p></div>
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

       <!-- Footer -->
       <div class="column-container" style="margin-top: 20px;">
        <div class="column">
            <div class="footer">
                <p class="footertext">
                    <?php echo htmlspecialchars($salesmenegername); ?><br>
                    Tel: <?php echo htmlspecialchars($salesmenegerphone); ?>
                </p>
            </div>
        </div>
    </div>
</div>

    <!-- Payment Schedule -->
    <?php if (!empty($scheduleRows)): ?>
    <div class="schedule-section">
        <div class="rounded-cell" style="margin-left:0; margin-right:0; margin-bottom:16px;">
            <p class="cell-text">PAYMENT SCHEDULE</p>
        </div>

        <div class="schedule-summary">
            <div class="summary-pill">Down Payment: <span>$ <?php echo number_format($calcAdvance, 2, '.', ','); ?></span></div>
            <?php if ($calcMonthly > 0): ?>
            <div class="summary-pill">Monthly: <span>$ <?php echo number_format($calcMonthly, 2, '.', ','); ?></span></div>
            <?php endif; ?>
            <div class="summary-pill">Total: <span>$ <?php echo number_format($calcTotal, 2, '.', ','); ?></span></div>
        </div>

        <table class="schedule-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Payment Date</th>
                    <th>Amount ($)</th>
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
                    <td colspan="2">TOTAL</td>
                    <td>$ <?php echo number_format($calcTotal, 2, '.', ','); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

 
</div><!-- /page-content -->

<div style="page-break-before: always;"></div>
<div class="mtavari_foto" id="mtavari_foto"></div>
<div class="sartulinew" id="sartulinew"></div>
<div class="floorplan" id="floorplan"></div>
<div class="threeDRender" id="threeDRender"></div>
<div class="xedi_1" id="xedi_1"></div>
<div class="xedi_2" id="xedi_2"></div>
<div class="xedi_3" id="xedi_3"></div>

<script>
function formatNumber(num) {
    const options = { useGrouping: true };
    if (num % 1 !== 0) { options.minimumFractionDigits = 2; options.maximumFractionDigits = 2; }
    return num.toLocaleString('en', options);
}

const projectName       = <?php echo json_encode($projectName); ?>;
const korpusi           = <?php echo json_encode($korpusi); ?>;
const sadarbazo         = <?php echo json_encode($sadarbazo); ?>;
const sartuli           = <?php echo json_encode($sartuli); ?>;
const flatNum           = <?php echo json_encode($flatNum); ?>;
const totalspace        = <?php echo json_encode($totalspace); ?>;
const sacxovrebelifarti = <?php echo json_encode($sacxovrebelifarti); ?>;
const aivani            = <?php echo json_encode($aivani); ?>;
const threeD            = <?php echo json_encode($threeD); ?>;
const floorplan         = <?php echo json_encode($floorplan); ?>;
const sartulinew        = <?php echo json_encode($sartulinew); ?>;
const mtavari_foto      = <?php echo json_encode($mtavari_foto); ?>;
const xedi_1            = <?php echo json_encode($xedi_1); ?>;
const xedi_2            = <?php echo json_encode($xedi_2); ?>;
const xedi_3            = <?php echo json_encode($xedi_3); ?>;
const calcKvmPrice      = <?php echo json_encode($calcKvmPrice); ?>;
const calcPrice         = <?php echo json_encode($calcPrice); ?>;

document.getElementById("projectName").innerText = ` ${projectName} `;
document.getElementById("kvmPriceDisplay").innerText = `$ ${formatNumber(Math.floor(calcKvmPrice))}`;
document.getElementById("totalPriceDisplay").innerText = `$ ${formatNumber(Math.floor(calcPrice))}`;

document.getElementById("threeDRender").innerHTML = threeD ? `<img src='${threeD}' alt='3D render'>` : '';
document.getElementById("floorplan").innerHTML    = floorplan ? `<img src='${floorplan}' alt='floor plan'>` : '';
document.getElementById("sartulinew").innerHTML   = sartulinew ? `<img src='${sartulinew}' alt='floor'>` : '';
document.getElementById("mtavari_foto").innerHTML = mtavari_foto ? `<img src='${mtavari_foto}' alt='photo'>` : '';
if (xedi_1) document.getElementById("xedi_1").innerHTML = `<img src='${xedi_1}' alt='view'>`;
if (xedi_2) document.getElementById("xedi_2").innerHTML = `<img src='${xedi_2}' alt='view'>`;
if (xedi_3) document.getElementById("xedi_3").innerHTML = `<img src='${xedi_3}' alt='view'>`;

if (!korpusi) { document.getElementById("korpusiDiv").style.display = "none"; document.getElementById("korpusiValueDiv").style.display = "none"; }
else { document.getElementById("korpusi").innerText = ` ${korpusi} `; }

if (!sadarbazo) { document.getElementById("sadarbazoDiv").style.display = "none"; document.getElementById("sadarbazoValueDiv").style.display = "none"; }
else { document.getElementById("sadarbazo").innerText = ` ${sadarbazo} `; }

if (!sartuli) { document.getElementById("sartuliDiv").style.display = "none"; document.getElementById("sartuliValueDiv").style.display = "none"; }
else { document.getElementById("sartuli").innerText = ` ${sartuli} `; }

if (!flatNum) { document.getElementById("binisNomeriDiv").style.display = "none"; document.getElementById("binisNomeriValueDiv").style.display = "none"; }
else { document.getElementById("flatNum").innerText = ` ${flatNum} `; }

if (!totalspace) { document.getElementById("binisJamuriFasiDiv").style.display = "none"; document.getElementById("totalspaceValueDiv").style.display = "none"; }
else { document.getElementById("totalspace").innerText = ` ${totalspace} SQ.M`; }

let sacxovrebeliFartiCalculated = sacxovrebelifarti;
if (!sacxovrebelifarti && totalspace) {
    sacxovrebeliFartiCalculated = (parseFloat(totalspace) - parseFloat(aivani || 0)).toFixed(2);
}
document.getElementById("sacxovrebelifarti").innerText = `${sacxovrebeliFartiCalculated} SQ.M`;

if (!aivani) { document.getElementById("sazafxuloDiv").style.display = "none"; document.getElementById("sazafxuloValueDiv").style.display = "none"; }
else { document.getElementById("aivani").innerText = ` ${aivani} SQ.M`; }
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
async function downloadPDF() {
    const btn = document.getElementById('pdfBtn');
    btn.textContent = 'Loading...';
    btn.disabled = true;

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const pdfW = 297, pdfH = 210;

    const pages = document.querySelectorAll('.page-content');
    for (let i = 0; i < pages.length; i++) {
        const canvas = await html2canvas(pages[i], {
            scale: 2, useCORS: true, allowTaint: true,
            backgroundColor: '#f9faf8', logging: false
        });
        const imgData = canvas.toDataURL('image/jpeg', 0.95);
        const ratio = Math.min(pdfW / canvas.width, pdfH / canvas.height);
        const imgW = canvas.width * ratio, imgH = canvas.height * ratio;
        if (i > 0) pdf.addPage();
        pdf.addImage(imgData, 'JPEG', (pdfW - imgW) / 2, (pdfH - imgH) / 2, imgW, imgH);
    }
    pdf.save('offer.pdf');
    btn.textContent = '✓ Downloaded';
    btn.disabled = false;
    setTimeout(() => { btn.textContent = '↓ Download PDF'; }, 3000);
}
</script>