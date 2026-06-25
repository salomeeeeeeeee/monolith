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



function printArr($arr)
{
    echo "<pre>";
    print_r($arr);
    echo "</pre>";
}



function getDealsByFilter($arFilter, $arSelect = array(), $arSort = array("ID" => "DESC"))
{
    $arDeals = array();
    $res = CCrmDeal::GetList($arSort, $arFilter, $arSelect);
    while ($arDeal = $res->Fetch())
        array_push($arDeals, $arDeal);
    return (count($arDeals) > 0) ? $arDeals : false;
}

function getUserName($ASSIGNED_BY_ID)
{
    $res = CUser::GetByID($ASSIGNED_BY_ID)->Fetch();

    return $res["NAME"] . " " . $res["LAST_NAME"];
}
function getUsersdsByID($id)
{
    $arrUsers = array();
    $arSelect = array('SELECT' => array("ID", "WORK_POSITION", "PERSONAL_ICQ", "UF_*"));

    $arFilter = array(
        "ID" => $id,
    );
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
$salesmenegermail = $salesmeneger["EMAIL"];
$salesmenegerworkphone = $salesmeneger["WORK_PHONE"];
$misamarti = $salesmeneger["PERSONAL_STREET"];


$date = date("Y-m-d");

$url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
// printArr($url);
$seb = file_get_contents($url);

$seb = json_decode($seb);

$seb_currency = $seb[0]->currencies[0]->rate;
// printArr($seb_currency);


if (isset($_GET["prod_ID"]) && !empty($_GET["prod_ID"]))
    $documentid = $_GET["prod_ID"];

$prod_ID = $documentid;

$arFilter = array(
    "ID" => $prod_ID
);

$product = getCIBlockElementsByFilter($arFilter);

$korpusi = $product[0]['_L24CUB'];
$sadarbazo = $product[0]['_D599QA'];
$sartuli = $product[0]['_FTRIDL'];
$flatNum = $product[0]['__6KWOWZ'];
$totalspace = $product[0]['__173JA5'];
$sacxovrebelifarti = $product[0]['__US58ND'];
$aivani = $product[0]['__BL1XXK'];

$kvmdollar = $product[0]['__6ZWTER'];
$kvmezo = $product[0]['yardKvmPrice'];
$kvmterasa = $product[0]['terraceprice_per'];


$totalprice = round($product[0]['PRICE']);

$sartulinew = $product[0]["sartulinew"];
$mtavari_foto = $product[0]["mtavari_foto"];


$floorplan = $product[0]['floorplan'];
$threeD = $product[0]["threedrender"];

$xedi_1 = $product[0]['xedi_1'];
$xedi_2 = $product[0]["xedi_2"];
$xedi_3 = $product[0]["xedi_3"];

$chabarebisforma = $product[0]['SUBMISSION_TYPE'];
$projectName = $deals[0]['UF_CRM_1779277729207'];
$kvmPrice = $product[0]['__6ZWTER'];
$projectID = $product[0]['IBLOCK_SECTION_ID'];
$projectName = $product[0]['__VO9RG4'];


$fartisType1 = $product[0]['__X1GCRZ'];

if($fartisType1=="ბინა"){
    $fartisType="APPATREMENT";
}else if($fartisType1=="ავტოსადგომი"){
    $fartisType="PARKING";
}


$arFilter = array(
    "ID" => 10953,
);

$zion = getCIBlockElementsByFilter($arFilter);
if (count($zion)) {
    $zionfoto = CFile::GetPath($zion[0]["PHOTO"]);
}

$arFilter = array(
    "ID" => 11131,
);

$z = getCIBlockElementsByFilter($arFilter);
if (count($z)) {
    $zfoto = CFile::GetPath($z[0]["PHOTO"]);
}

$arFilter = array(
    "ID" => 11133,
);

$logo2 = getCIBlockElementsByFilter($arFilter);
if (count($logo2)) {
    $logo2foto = CFile::GetPath($logo2[0]["PHOTO"]);
}

$arFilter = array(
    "ID" => 11134,
);

$bade = getCIBlockElementsByFilter($arFilter);
if (count($bade)) {
    $badefoto = CFile::GetPath($bade[0]["PHOTO"]);
}

?>

<head>
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-nino-elite-exp-caps/css/bpg-nino-elite-exp-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-web-001-caps/css/bpg-web-001-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/arial-geo-bolditalic/css/arial-geo-bolditalic.min.css">
</head>

<style>
    .workarea-content-paddings {
        padding: 0 !important;
    }

    #address {
        font-weight: bolder;
        text-transform: none;
    }

    #sales {
        font-weight: bolder;
        text-transform: none;
    }

    #workphone {
        font-weight: bolder;
        text-transform: none;
    }

    #phone {
        font-weight: bolder;
        text-transform: none;
    }

    #mail {
        font-weight: bolder;
        text-transform: none;
    }

    #link {
        font-weight: bolder;
        text-transform: none;
    }

    .info {
        bottom: 0;
        left: 0;
        width: 100%;
        display: flex;
        justify-content: center;
        padding: 20px;
        font-family: "Arial GEO BoldItalic", sans-serif;
    }

    .column {
        flex: 1;
        padding: 10px;
    }

    .column p {
        margin: 0;
    }

    .row {
        display: flex;
        flex-direction: column;
    }

    .detalebi {
        text-transform: uppercase;
        font-weight: bolder;
        position: relative;
        top: 356px;
        left: 100px;
        border-bottom: 2px solid #013d58 !important;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase;
    }

    .girebuleba {
        text-transform: uppercase;
        font-weight: bolder;
        position: relative;
        top: 663px;
        left: 100px;
        border-bottom: 2px solid #013d58 !important;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase;
    }

    .foto {
        background-image: url("<?php echo $zfoto ?>");
        position: absolute;
        background-repeat: no-repeat;
        top: 220px;
        left: 30px;
        width: 100%;
        height: 100vh;
        background-size: 300px;
    }

    .foto2 {
        background-image: url("<?php echo $zionfoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 250px;
        margin-left: 50px;
        margin-top: 30px;
        position: absolute;
        display: flex;
    }

    .foto3 {
        background-image: url("<?php echo $zfoto ?>");
        position: absolute;
        background-repeat: no-repeat;
        top: 1440px;
        left: 30px;
        width: 100%;
        height: 100%;
        background-size: 340px;
    }

    .foto4 {
        background-image: url("<?php echo $zionfoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 280px;
        margin-left: 50px;
        margin-top: 30px;
        position: absolute;
        display: flex;
    }

    .foto5 {
        background-image: url("<?php echo $logo2foto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 280px;
        margin-left: 50px;
        margin-top: 500px;
        position: absolute;
        display: flex;
    }

    .foto6 {
        background-image: url("<?php echo $badefoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 250px;
        margin-left: 50px;
        margin-top: 750px;
        position: absolute;
        display: flex;
    }

    .bx-layout-inner-inner-top-row {
        display: none;
    }

    .workarea-content-paddings {
        padding: 0px;
        margin: 0 !important;
        background-color: #f9faf8;
    }

    .bx-layout-inner-left {
        display: none;
        background-color: #f9faf8;
    }

    .bx-layout-inner-inner-cont {
        padding: 0 !important;
        background-color: #f9faf8;
    }

    .workarea-content {
        margin: 0 !important;
        background-color: #f9faf8;
    }

    #header-inner {
        display: none;
    }

    #bx-im-bar {
        display: none;
    }

    #header {
        display: none;
    }

    :root {
        background-color: #f9faf8;
    }

    .rounded-cell {
        width: auto;
        height: 0px;
        background-color: #0b3860;
        border-radius: 50px;
        display: flex;
        align-items: center;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
        margin-left: 450px;
        margin-top: 20px;
        margin-right: 30px;
    }

    .footer {
        width: auto;
        max-width: 100%;
        height: 50px;
        background-color: #0b3860;
        border-radius: 50px;
        display: flex;
        align-items: center;
        padding: 20px;
        margin-top: 13px;
        margin-right: 37px;
    }

    .footertext {
        color: white;
        font-size: 12px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase;
        margin: 5px;
        font-weight: normal;
    }

    .rounded-cell2 {
        width: auto;
        max-width: 800px;
        height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex;
        align-items: center;
        padding: 20px;
        margin-top: 13px;
    }

    .cell-text {
        color: white;
        font-size: 24px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase;
        margin: 5px;
        font-weight: bold;
    }

    .cell-text2 {
        color: #0b3860;
        font-size: 15px;
        font-family: "BPG Nino Elite Exp Caps", sans-serif;
        text-transform: uppercase;
        margin: 5px;
        font-weight: bold;
    }

    .binaN {
        font-size: 25px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        text-transform: uppercase;
        font-weight: bolder;
        font-size: 30px;
        color: #95c084;
        margin-top: 28px;
        margin-left: 20px;
    }

    .rounded-cell3 {
        width: auto;
        max-width: 800px;
        height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex;
        align-items: center;
        padding: 20px;
        margin-top: 5px;
    }

    .rounded-cell4 {
        width: auto;
        max-width: 800px;
        height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex;
        align-items: center;
        padding: 20px;
        margin-top: 5px;
        margin-right: 30px;
    }

    .rounded-cell5 {
        width: auto;
        max-width: 800px;
        height: 0px;
        background-color: #b2c2e1;
        border-radius: 50px;
        display: flex;
        align-items: center;
        padding: 20px;
        margin-top: 13px;
        margin-right: 30px;
    }

    .column-container {
        display: flex;
        flex-direction: row;
        align-items: stretch;
        margin-left: 450px;
        justify-content: space-between;
    }

    .column {
        flex: 1;
        margin-right: 10px;
        width: 48%;
    }

    .column:last-child {
        margin-right: 0;
    }

    .text {
        font-size: 14px;
        color: black;
        font-weight: light;
    }

    .info2 {
        margin-left: 70px;
        margin-top: 810px;
        z-index: 1;
    }

    .floorplan img {
        width: 100%;
        height: auto;
    }

    .threeDRender img {
        width: 100%;
        height: auto;
    }

    .sartulinew img {
        width: 100%;
        height: auto;
    }

    .mtavari_foto img {
        width: 100%;
        height: auto;
    }

    .xedi_1 img {
        width: 100%;
        height: auto;
    }

    .xedi_2 img {
        width: 100%;
        height: auto;
    }

    .xedi_3 img {
        width: 100%;
        height: auto;
    }

    /* ===== PDF EXPORT BUTTON ===== */
    .pdf-export-btn {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        background-color: #0b3860;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 50px;
        font-family: "BPG WEB 001 Caps", sans-serif;
        font-size: 13px;
        text-transform: uppercase;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        transition: background-color 0.2s;
    }

    .pdf-export-btn:hover {
        background-color: #95c084;
    }

    .pdf-export-btn:disabled {
        background-color: #888;
        cursor: not-allowed;
    }

    @media print {
        .pdf-export-btn {
            display: none !important;
        }
    }
</style>

<!-- PDF Export Button -->
<button class="pdf-export-btn" id="pdfBtn" onclick="downloadPDF()">&#8595; Download PDF</button>

<div class="foto"></div>
<div class="foto2"> </div>

<!-- Wrap all offer content in .page-content for html2canvas capture -->
<div class="page-content">

<div>
    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?> DETAILS</p>
    </div>
    <div class="column-container">
        <div class="column">

            <div class="rounded-cell2">
                <p class="cell-text2">PROJECT</p>
            </div>
            <div class="rounded-cell3" id="korpusiDiv">
                <p class="cell-text2">BLOCK</p>
            </div>
            <div class="rounded-cell3" id="sadarbazoDiv">
                <p class="cell-text2">ENTRANCE</p>
            </div>
            <div class="rounded-cell3" id="sartuliDiv">
                <p class="cell-text2">FLOOR</p>
            </div>
            <div class="rounded-cell3" id="binisNomeriDiv">
                <p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?> NUMBER</p>
            </div>
            <div class="rounded-cell3" id="binisJamuriFasiDiv">
                <p class="cell-text2"><?php echo htmlspecialchars($fartisType); ?> TOTAL SPACE</p>
            </div>
            <div class="rounded-cell3" id="sacxovrebeliFartiDiv">
                <p class="cell-text2">INDOOR SPACE</p>
            </div>
            <div class="rounded-cell3" id="sazafxuloDiv">
                <p class="cell-text2">BALCONY SPACE</p>
            </div>

        </div>

        <div class="column">
            <div class="rounded-cell5">
                <p class="cell-text2"><span id="projectName"> </span></p>
            </div>
            <div class="rounded-cell4" id="korpusiValueDiv">
                <p class="cell-text2"><span id="korpusi"> </span></p>
            </div>
            <div class="rounded-cell4" id="sadarbazoValueDiv">
                <p class="cell-text2"><span id="sadarbazo"> </span></p>
            </div>
            <div class="rounded-cell4" id="sartuliValueDiv">
                <p class="cell-text2"><span id="sartuli"> </span></p>
            </div>
            <div class="rounded-cell4" id="binisNomeriValueDiv">
                <p class="cell-text2"><span id="flatNum"> </span></p>
            </div>
            <div class="rounded-cell4" id="totalspaceValueDiv">
                <p class="cell-text2"><span id="totalspace"> </span></p>
            </div>
            <div class="rounded-cell4" id="sacxovrebeliFartiValueDiv">
                <p class="cell-text2"><span id="sacxovrebelifarti"> </span></p>
            </div>
            <div class="rounded-cell4" id="sazafxuloValueDiv">
                <p class="cell-text2"><span id="aivani"> </span></p>
            </div>
        </div>
    </div>

    <div class="rounded-cell">
        <p class="cell-text"><?php echo htmlspecialchars($fartisType); ?> PRICE</p>
    </div>
    <div class="column-container">
        <div class="column">
            <div class="rounded-cell2" id="kvmPriceDiv">
                <p class="cell-text2">PRICE 1 SQ.M</p>
            </div>

            <?php if (($projectName == "PETRA SEA RESORT K" && $sartuli == "1") || ($projectName == "PETRA SEA RESORT D" && $sartuli == "1")) : ?>
                <div class="rounded-cell2" id="kvmPriceDiv">
                    <p class="cell-text2">ეზოს ფართის ფასი 1 კვ.მ</p>
                </div>
                <div class="rounded-cell2" id="kvmPriceDiv">
                    <p class="cell-text2">ტერასის ფართის ფასი 1 კვ.მ</p>
                </div>
            <?php endif; ?>

            <div class="rounded-cell2" id="totalpriceDiv">
                <p class="cell-text2">TOTAL PRICE</p>
            </div>
        </div>

        <div class="column">
            <div class="rounded-cell5" id="kvmPriceValueDiv">
                <p class="cell-text2"><span id="kvmPrice"> </span></p>
            </div>

            <?php if (($projectName == "PETRA SEA RESORT K" && $sartuli == "1") || ($projectName == "PETRA SEA RESORT D" && $sartuli == "1")) : ?>
                <div class="rounded-cell5" id="kvmPriceValueDiv">
                    <p class="cell-text2"><span id="kvmEzo"> </span></p>
                </div>
                <div class="rounded-cell5" id="kvmPriceValueDiv">
                    <p class="cell-text2"><span id="kvmterasa"> </span></p>
                </div>
            <?php endif; ?>

            <div class="rounded-cell5" id="totalPriceValueDiv">
                <p class="cell-text2"><span id="totalprice"> </span></p>
            </div>
        </div>
    </div>

    <div class="column-container">
        <div class="column">
            <div class="footer">
                <p class="footertext">
                    <?php echo htmlspecialchars($salesmenegername); ?><br>
                    Tel: <?php echo htmlspecialchars($salesmenegerphone); ?><br>
                </p>
            </div>
        </div>
    </div>

</div>

</div><!-- /page-content -->

<div style="page-break-before: always;"></div>

<div class="mtavari_foto" id="mtavari_foto"> </div>

<div class="floorplan" id="floorplan"></div>

<div class="sartulinew" id="sartulinew"> </div>
<div class="threeDRender" id="threeDRender"></div>

<div class="xedi_1" id="xedi_1"> </div>
<div class="xedi_2" id="xedi_2"></div>
<div class="xedi_3" id="xedi_3"></div>


<script>

    function formatNumber(num) {
        const options = {
            useGrouping: true,
        };

        if (num % 1 !== 0) {
            options.minimumFractionDigits = 2;
            options.maximumFractionDigits = 2;
        }

        return num.toLocaleString('en', options);
    }

    let projectName = <?php echo json_encode($projectName); ?>;
    let korpusi = <?php echo json_encode($korpusi); ?>;
    let sadarbazo = <?php echo json_encode($sadarbazo); ?>;
    let sartuli = <?php echo json_encode($sartuli); ?>;
    let flatNum = <?php echo json_encode($flatNum); ?>;
    let totalspace = <?php echo json_encode($totalspace); ?>;
    let sacxovrebelifarti = <?php echo json_encode($sacxovrebelifarti); ?>;
    let aivani = <?php echo json_encode($aivani); ?>;

    let kvmdollar = Number(<?php echo json_encode($kvmdollar); ?>);
    kvmdollar = Math.floor(kvmdollar);
    let kvmdollarFormated = formatNumber(kvmdollar);

    let kvmezo = Number(<?php echo json_encode($kvmezo); ?>);
    kvmezo = Math.floor(kvmezo);
    let kvmezoFormated = formatNumber(kvmezo);

    let kvmterasa = Number(<?php echo json_encode($kvmterasa); ?>);
    kvmterasa = Math.floor(kvmterasa);
    let kvmterasaFormated = formatNumber(kvmterasa);

    let totalprice = <?php echo json_encode($totalprice); ?>;
    totalprice = Math.floor(totalprice);
    let totalpriceFormated = formatNumber(totalprice);

    let threeD = <?php echo json_encode($threeD); ?>;
    let floorplan = <?php echo json_encode($floorplan); ?>;
    let sartulinew = <?php echo json_encode($sartulinew); ?>;
    let mtavari_foto = <?php echo json_encode($mtavari_foto); ?>;

    let xedi_1 = <?php echo json_encode($xedi_1); ?>;
    let xedi_2 = <?php echo json_encode($xedi_2); ?>;
    let xedi_3 = <?php echo json_encode($xedi_3); ?>;

    document.getElementById("projectName").innerText = ` ${projectName} `;
    document.getElementById("kvmPrice").innerText = ` $ ${kvmdollarFormated} `;

    if (document.getElementById("kvmEzo")) {
        document.getElementById("kvmEzo").innerText = ` $ ${kvmezoFormated} `;
    }

    if (document.getElementById("kvmterasa")) {
        document.getElementById("kvmterasa").innerText = ` $ ${kvmterasaFormated} `;
    }

    document.getElementById("totalprice").innerText = ` $ ${totalpriceFormated} `;

    document.getElementById("threeDRender").innerHTML = `<img src='${threeD}' alt='project picture'>`;
    document.getElementById("floorplan").innerHTML = `<img src='${floorplan}' alt='2D render'>`;
    document.getElementById("sartulinew").innerHTML = `<img src='${sartulinew}' alt='2D render'>`;
    document.getElementById("mtavari_foto").innerHTML = `<img src='${mtavari_foto}' alt='2D render'>`;

    if (xedi_1) {
        document.getElementById("xedi_1").innerHTML = `<img src='${xedi_1}' alt='2D render'>`;
    }

    if (xedi_2) {
        document.getElementById("xedi_2").innerHTML = `<img src='${xedi_2}' alt='2D render'>`;
    }

    if (xedi_3) {
        document.getElementById("xedi_3").innerHTML = `<img src='${xedi_3}' alt='2D render'>`;
    }

    if (!korpusi) {
        document.getElementById("korpusiDiv").style.display = "none";
        document.getElementById("korpusiValueDiv").style.display = "none";
    } else {
        document.getElementById("korpusi").innerText = ` ${korpusi} `;
    }

    if (!sadarbazo) {
        document.getElementById("sadarbazoDiv").style.display = "none";
        document.getElementById("sadarbazoValueDiv").style.display = "none";
    } else {
        document.getElementById("sadarbazo").innerText = ` ${sadarbazo} `;
    }

    if (!sartuli) {
        document.getElementById("sartuliDiv").style.display = "none";
        document.getElementById("sartuliValueDiv").style.display = "none";
    } else {
        document.getElementById("sartuli").innerText = ` ${sartuli} `;
    }

    if (!flatNum) {
        document.getElementById("binisNomeriDiv").style.display = "none";
        document.getElementById("binisNomeriValueDiv").style.display = "none";
    } else {
        document.getElementById("flatNum").innerText = ` ${flatNum} `;
    }

    if (!totalspace) {
        document.getElementById("binisJamuriFasiDiv").style.display = "none";
        document.getElementById("totalspaceValueDiv").style.display = "none";
    } else {
        document.getElementById("totalspace").innerText = ` ${totalspace} SQ.M`;
    }

    let sacxovrebeliFartiCalculated = sacxovrebelifarti;
    if (!sacxovrebelifarti && totalspace) {
        sacxovrebeliFartiCalculated = (parseFloat(totalspace) - parseFloat(aivani || 0)).toFixed(2);
    }
    document.getElementById("sacxovrebelifarti").innerText = `${sacxovrebeliFartiCalculated} SQ.M`;

    if (!aivani) {
        document.getElementById("sazafxuloDiv").style.display = "none";
        document.getElementById("sazafxuloValueDiv").style.display = "none";
    } else {
        document.getElementById("aivani").innerText = ` ${aivani} SQ.M`;
    }

</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
async function downloadPDF() {
    const btn = document.getElementById('pdfBtn');
    btn.textContent = 'Loading...';
    btn.disabled = true;

    const { jsPDF } = window.jspdf;
    // A4 landscape: 297 x 210 mm
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    const pdfW = 297;
    const pdfH = 210;

    const pages = document.querySelectorAll('.page-content');

    for (let i = 0; i < pages.length; i++) {
        const page = pages[i];

        const canvas = await html2canvas(page, {
            scale: 2,
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#f9faf8',
            logging: false
        });

        const imgData = canvas.toDataURL('image/jpeg', 0.95);

        const canvasW = canvas.width;
        const canvasH = canvas.height;
        const ratio = Math.min(pdfW / canvasW, pdfH / canvasH);
        const imgW = canvasW * ratio;
        const imgH = canvasH * ratio;
        const offsetX = (pdfW - imgW) / 2;
        const offsetY = (pdfH - imgH) / 2;

        if (i > 0) pdf.addPage();
        pdf.addImage(imgData, 'JPEG', offsetX, offsetY, imgW, imgH);
    }

    pdf.save('offer.pdf');

    btn.textContent = '✓ Downloaded';
    btn.disabled = false;
    setTimeout(() => { btn.textContent = '↓ Download PDF'; }, 3000);
}
</script>