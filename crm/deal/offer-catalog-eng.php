<?
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle(" ");
CJSCore::Init(array("jquery"));





function getCIBlockElementsByFilter($arFilter = array()) {
    $arElements = array();
    $arSelect = Array("ID","IBLOCK_ID","NAME","DATE_ACTIVE_FROM","PROPERTY_*","PREVIEW_PICTURE","DETAIL_PICTURE", "IBLOCK_SECTION_ID");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize"=>50), $arSelect);
    while($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = array();
        foreach($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
        foreach($arProps as $key => $arProp) $arPushs[$key] = $arProp["VALUE"];
        $arPushs["floorplan"]    = CFile::GetPath($arPushs["floorplan"]);
        $arPushs["threedrender"]    = CFile::GetPath($arPushs["threedrender"]);
        $price      = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"] = $price["PRICE"];

        array_push($arElements, $arPushs);
    }
    return $arElements;
}



function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}



function getDealsByFilter($arFilter, $arSelect = array(), $arSort = array("ID"=>"DESC")) {
    $arDeals = array();
    $res = CCrmDeal::GetList($arSort, $arFilter, $arSelect);
    while($arDeal = $res->Fetch()) array_push($arDeals, $arDeal);
    return (count($arDeals) > 0) ? $arDeals : false;
}

function getUserName ($ASSIGNED_BY_ID) {
    $res = CUser::GetByID($ASSIGNED_BY_ID)->Fetch();

    return $res["ASSIGNED_BY_NAME"]." ".$res["ASSIGNED_BY_LAST_NAME"];
}

function getLatinName($str) {
    $GEO_LAT = array("ქ" => "q", "წ" => "ts", "ჭ" => "ch", "ე" => "e", "რ" => "r", "ღ" => "gh", "ტ" => "t", "თ" => "t", "ყ" => "y", "უ" => "u", "ი" => "i", "ო" =>"o", "პ" => "p", "ა" => "a", "ს" => "s", "შ" => "sh", "დ" => "d", "ფ" => "p", "გ" => "g", "ჰ" => "h", "ჯ" => "j", "ჟ" => "zh", "კ" => "k", "ლ" => "l", "ზ" => "z", "ხ" => "x", "ძ" => "dz", "ც" => "c", "ჩ" => "ch", "ვ" => "v", "ბ" => "b", "ნ" => "n", "მ" => "m");
    $newStr = "";
    $str = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    $str = str_replace(",", ".", $str);

    for ($i = 0; $i < sizeof($str); $i++) {
        if (array_key_exists($str[$i], $GEO_LAT)) {
            $newStr .= $GEO_LAT[$str[$i]];
        }
        else {
            $newStr .= $str[$i];
        }
    }
    return $newStr;
}

function capitalizeEachWord($string) {
    $words = explode(' ', $string);
    $capitalizedWords = array_map(function($word) {
        return ucfirst(strtolower($word));
    }, $words);
    return implode(' ', $capitalizedWords);
}

function grafikisGeneracia($danarti_content){
    // printArr($danarti_content);

    $grafiki = "
      <table class='grafik-table-danart'> 
          <tHead class='grafik-table-head'>
              <th class='grafik-coll-n'><b>№</b></th>
              <th class='grafik-coll'><b>Date</b></th>
              <th class='grafik-coll'><b>Amount $</b></th>
          </tHead>";


    foreach ($danarti_content["data"] as $story){
        $n=$story["payment"];
        $TARIGI=$story["date"];
        $TANXA_NUMBR=$story["amount"];
        $grafiki .=  "<tr class='grafik-content'>
                                      <td class='grafik-coll-n'>$n</td>       
                                      <td class='grafik-coll'>$TARIGI</td>
                                      <td class='grafik-coll'>$TANXA_NUMBR</td>
                                  </tr>";



    }

    $grafiki .= "</table>
                  </div>";
    return $grafiki;

}

function getUsersdsByID ($id) {
    $arrUsers=array();
    $arSelect = array('SELECT' => array("ID","WORK_POSITION", "PERSONAL_ICQ", "UF_*"));

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
$salesmeneger=getUsersdsByID($userID);

$salesmenegerphone=$salesmeneger["PERSONAL_MOBILE"];
$salesmenegername=$salesmeneger["NAME"]." ". $salesmeneger["LAST_NAME"];
$salesmenegermail=$salesmeneger["EMAIL"];
$salesmenegerworkphone=$salesmeneger["WORK_PHONE"];
$misamarti=$salesmeneger["PERSONAL_STREET"];



// printArr($salesmeneger);
$date = date("Y-m-d");

$url="https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";

$seb = file_get_contents($url);

$seb = json_decode($seb);

$seb_currency=$seb[0]->currencies[0]->rate;

// printArr($seb_currency);

if(isset($_GET["dealID"]) && !empty($_GET["dealID"])) $dealID = $_GET["dealID"];




$productID = $_GET["prod_ID"];

$arFilter = array(
    "ID" => $dealID,
);



// $product = getCIBlockElementsByFilter($arFilter);
$Deal = getDealsByFilter($arFilter);

$prods = CCrmDeal::LoadProductRows($dealID);

$arFilter=array("ID"=>$productID);

$product=getCIBlockElementsByFilter($arFilter);


$project=$product[0]['__VO9RG4'];
$sartuli=$product[0]['_FTRIDL'];
$sacxovrebelifarti=$product[0]['__US58ND'];
$sadarbazo=$product[0]['_D599QA'];
$aivani=$product[0]['__BL1XXK'];
$flatNum=$product[0]['__6KWOWZ'];
$korpusi=$product[0]['_L24CUB'];
$totalspace=$product[0]['__173JA5'];
$chabarebisforma= $product[0]['SUBMISSION_TYPE'];
$sawyisifasilari=$Deal[0]['UF_CRM_1693385814530'];
$totalprice = $product[0]['PRICE'];
// $kvmprice=$Deal[0]["UF_CRM_1693385814530"];
$kvmprice = $product[0]['PRICE'];
$kvmdollar = $product[0]['__6ZWTER'];
// $kursi=$Deal[0]["UF_CRM_1701786033562"];
$responsible=$product[0]["DEAL_RESPONSIBLE"];
$responsibleinfo=getUsersdsByID($Deal[0]["ASSIGNED_BY_ID"]);
// printArr($product);
$responsibleemail=$responsibleinfo["EMAIL"];
$chabarebisforma = $product[0]['SUBMISSION_TYPE'];

$korpusi = $product[0]['KORPUSIS_NOMERI_XE3NX2'];

$mapping = [
    'ა' => 'A',
    'ბ' => 'B',
    'გ' => 'G',
    'დ' => 'D',
];

if (isset($mapping[$korpusi])) {
    $korpusi = $mapping[$korpusi];
}


// $fartistipi=$Deal[0]['UF_CRM_1693385992603'];









//printArr($Deal);
// printArr($product[0]);

$twoDrender=$product[0]['floorplan'];
$threeD=$product[0]["threedrender"];

$projectID=$product[0]['IBLOCK_SECTION_ID'];

if($projectID==21){
    $projectName="ლისი";
}elseif($projectID==22){
    $projectName="დიღომი";
}elseif($projectID==23){
    $projectName="ვერონა";
}


$arFilter = array(
    "ID" => $projectID,
);

$arFilter = array(
    "ID" => 37197,
);


$fotilogo = getCIBlockElementsByFilter($arFilter);
if (count($fotilogo)) {
    $fotilogofoto = CFile::GetPath($fotilogo[0]["PHOTO"]);
}



$arFilter = array(
    "ID" =>37195,
);

$beliashvili = getCIBlockElementsByFilter($arFilter);
if (count($beliashvili)) {
    $beliashvilifoto = CFile::GetPath($beliashvili[0]["PHOTO"]);
}



$arFilter = array(
    "ID" =>37194,
);

$reverance = getCIBlockElementsByFilter($arFilter);
if (count($reverance)) {
    $reverancefoto = CFile::GetPath($reverance[0]["PHOTO"]);
}

$arFilter=array("PROPERTY_DEAL"=>$dealID);


$grafikiJson=getCIBlockElementsByFilter($arFilter);

if ($grafikiJson){
    $grafikicount=count($grafikiJson)-1;
    $json = str_replace("&quot;", "\"",  $grafikiJson[$grafikicount]["JSON"]);
    $grafikiArr = json_decode($json, true);

    $grafikiTable = grafikisGeneracia($grafikiArr);

    $sesxisMoculoba = $grafikiArr["loan_amount"];
    $tanamonawileoba = $grafikiArr["tanamonawileoba"];
    $wliuriProcent = $grafikiArr["wliuriProcent"];
    $sesxisVada = $grafikiArr["sesxisVada"];
    $dasafariSul = $grafikiArr["dasafariSul"];
    $gadasaxadiTveshi = $grafikiArr["gadasaxadiTveshi"];

}


?>

<head>
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-nino-elite-exp-caps/css/bpg-nino-elite-exp-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/bpg-web-001-caps/css/bpg-web-001-caps.min.css">
    <link rel="stylesheet" href="//cdn.web-fonts.ge/fonts/arial-geo-bolditalic/css/arial-geo-bolditalic.min.css">
</head>

<style>




    /* font-family: MyCustomFont; */



    /* Define styles for the table */
    #myTable {
        border-collapse: separate;
        border-spacing: 10px;
        width: 60%;
        background-color: #013d58;
        color: white;
        float: right;
        margin-top: 310px;
        font-size: small;
        margin-top:
    }


    #myTable th,
    #myTable td {
        width: 1%;
        text-align: left;
        padding-top: 2px;
        padding-bottom: 2px;
        border-bottom: 0.2px solid white;
        font-family: "BPG Nino Elite Exp Caps", sans-serif;
        text-transform: uppercase;
    }

    #myTable th {
        background-color: #013d58;
        width: 2%;
    }

    #myTable tr {
        position: relative;
    }

    #myTable tr::after {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 1px;
        background-color: white;
    }

    #myTable td {
        font-weight: 600;
        font-size: 14px;
        color: #d2d2ce;
    }

    .binisprice {
        background-color: #a0c6a6;
        font-weight: bolder;
    }

    .totalKVM {
        color: black;
    }

    #projectName {
        width: 33%;
    }

    .div p {
        margin-top: 2px;
    }

    #price th {
        align-items: center;
    }

    #myTable td:nth-child(1) {
        /* Style for the first column */
        font-weight: 700; /* Normal text */
        color: #ffffff; /* White color */
    }

    #myTable td:nth-child(2) {
        /* Style for the second column */
        font-weight: bolder; /* Bold text */
    }



    #myTable tr:nth-child(10) th,
    #myTable tr:nth-child(11) th {
        border-bottom: none !important;
    }



    .workarea-content-paddings {
        padding: 0 !important;
    }

    #address{
        font-weight: bolder;
        text-transform: none;

    }
    #sales{
        font-weight: bolder;
        text-transform: none;


    }
    #workphone{
        font-weight: bolder;
        text-transform: none;

    }

    #phone{
        font-weight: bolder;
        text-transform: none;

    }
    #mail{
        font-weight: bolder;
        text-transform: none;

    }

    #link{
        font-weight: bolder;
        text-transform: none;

    }
    .info {
        bottom: 0;
        left: 0;
        width: 100%;
        display: flex;
        justify-content:center;
        padding: 20px;
        margin-top: 940px;
        font-family: "Arial GEO BoldItalic", sans-serif;

    }

    .column {
        flex: 1;
        padding: 10px;
    }

    .column p{
        margin: 0;
    }

    .row {
        display: flex;
        flex-direction: column;
    }

    #detalebi{

        color: gray;
        position: relative;
        font-weight: 80%;
    }

    #girebuleba{
        text-transform: uppercase;
        font-weight: bolder;
        position: relative;
        font-family: "BPG WEB 001 Caps", sans-serif;

    }

    #sakontaqto{
        text-transform: uppercase;
        font-weight: bolder;
        position: relative;
        font-family: "BPG WEB 001 Caps", sans-serif;
        margin-left: 100px;
        margin-top: 1px;
    }


    .foto {
        background-image: url("<?php echo $whitebackgroundfoto ?>");
        background-size:cover;
        background-position: center; /* Center the background */
        position: absolute;
        background-repeat: no-repeat;
        top: 17px;
        left: 0;
        width: 100%; /* Full width */
        height: 100vh; /* Viewport height, covers the entire screen vertically */
    }

    img{
        margin-top: 1550px;
        width: 800px;
        height: auto;
        align-items: center;
    }

    .bx-layout-inner-inner-top-row{
        display:none;
    }

    .workarea-content-paddings{
        padding:0px;
        margin:0 !important;
    }
    .bx-layout-inner-left{
        display:none;
    }
    .bx-layout-inner-inner-cont{
        padding:0 !important;
    }
    .workarea-content{
        margin:0 !important;
    }
    #header-inner{
        display:none;
    }
    #bx-im-bar{
        display:none;
    }
    #header{
        display:none;
    }

    :root {
        background-color: white;
    }


    table {
        font-family: Arial, sans-serif;

    }
    .table-container {

        width: 100%;
        max-width: 1330px;
        margin: 0 auto;
        margin-top: calc(120px + 30px);

        border-collapse: collapse;


    }

    @media print {
        .table-container {

            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            margin-top: calc(120px + 30px);

            border-collapse: collapse;


        }
    }
    .table-container table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    .table-container th, .table-container td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    .table-container th {
        background-color: #493b93;
        color: white;
        text-align: center;
    }
    .table-title {
        font-weight: bold;
        margin-top: 20px;
    }

    .grafik-table_cell{
        border:solid;
        border-width:1px;
        width:49%;
    }
    .grafik-table{
        border-collapse:collapse ;
        border:solid;
        border-width:1px;
    }
    .grafik-table_cell_title{
        border:solid;
        border-width:1px;
        text-align:center;
    }


    .grafik-coll {
        border:solid;
        border-width:1px;
        text-align:center;
        text-align: center;
        border: 1px solid black;
        width:23%;
    }

    .grafik-coll-n{
        border:solid;
        border-width:1px;
        text-align:center;
        width:15%;
    }

    .grafik-table-danart{
        font-family: arial, sans-serif;
        border-collapse: collapse;
        width: 70%;
        margin-top: 200px;
        margin: 0 auto;
    }
    .grafik-table-danart {
        margin-top: 200px;
    }

    .grafik-table-head{
        color:black;
        height:10px;
        border: 1px solid black;
    }

    .grafik-content {
        border:solid;
        border-width:1px;
        text-align:center;

    }


    .grafik-table-head-grafiki{
        width:100%;
        text-align:center;
        margin-top: 500px;
        position: relative;
    }


    .fotilogofoto {
        background-image: url("<?php echo $fotilogofoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 150px;
        margin-left: 100px;
        margin-top: 40px;
        position: absolute;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        background-size: 200px;


    }
    .beliashvilifoto{
        background-image: url("<?php echo $beliashvilifoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 150px;
        margin-left: 100px;
        margin-top: 40px;
        position: absolute;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        background-size: 200px;


    }

    .reverance{
        background-image: url("<?php echo $reverancefoto ?>");
        background-repeat: no-repeat;
        width: 100%;
        height: 100%;
        background-size: 150px;
        margin-left: 100px;
        margin-top: 40px;
        position: absolute;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        background-size: 200px;


    }



    @media print {
        .header1 {
            position: fixed;

        }

    }

    .foto2, .link {
        flex: 1; /* Allow both sections to take equal space */
        text-align: center; /* Center align text within each section */
    }

    .link{
        font-weight: bold;
        margin-top: 60px;
        margin-right: -180px;
    }

    @media print {
        .link{
            font-weight: bold;
            margin-top: 60px;
            margin-right: 70px;
        }
    }
    .table-container th:first-child {
        text-align: left;
        padding-left: 10px;
    }
    .fasi{
        background-color: green;
    }


    #twoDRenderContainer{
        margin-top: 20px;
    }


</style>



<div class="table-container">

    <table>

        <tr>
            <th colspan="2" id="girebuleba">Apartment details</th>
        </tr>
        <tr>
            <td id="detalebi">Project Name</td>
            <td id="detalebi"><span id="project"> </span></td>
        </tr>
        <tr id="detalebi">
            <td id="detalebi">Building</td>
            <td id="detalebi"><span id="korpusi"></span></td>
        </tr>
        <!-- <tr>
            <td id="detalebi">Block</td>
            <td id="detalebi"><span id="sadarbazo"></span></td>
        </tr> -->
        <tr>
            <td id="detalebi">Floor</td>
            <td id="detalebi"><span id="sartuli"></span></td>
        </tr>
        <tr>
            <td id="detalebi">Apartment number</td>
            <td id="detalebi"><span id="flatNum"></span></td>
        </tr>
        <tr>
            <td id="detalebi">Living Area m<sup>2</sup></td>
            <td id="detalebi"><span id="sacxovrebelifarti"></span> m<sup>2</sup></td>
        </tr>
        <tr>
            <td id="detalebi">Balcony Area/Terrace m<sup>2</sup></td>
            <td id="detalebi"><span id="aivani"></span> m<sup>2</sup></td>
        </tr>
        <tr>
            <td id="detalebi">Total Area of Apartment</td>
            <td id="detalebi"><span id="totalspace"></span> m<sup>2</sup></td>
        </tr>

        <tr>
            <td id="detalebi">Delivery Condition</td>
            <td id="detalebi"><span id="chabarebisforma"></span></td>
        </tr>


    </table>

    <table>

        <tr class="fasi">
            <th id="girebuleba">Apartment Price</th>
            <th id="girebuleba">Dollar</th>
            <th id="girebuleba">GEL</th>
        </tr>


        <tr>
            <td id="detalebi">Initial Price 1 m<sup>2</sup></td>
            <td id="detalebi">$<span id="kvmprice"></span></td>
            <td id="detalebi">₾<span id="kvmpriceGel"></span></td>
        </tr>
        <tr>
            <td id="detalebi">Initial total Price</td>
            <td id="detalebi">$<span id="totalprice"></span></td>
            <td id="detalebi">₾<span id="totalpriceGel"> </span></td>
        </tr>
        <tr>
            <td id="detalebi">Final price 1 m<sup>2</sup></td>
            <td id="detalebi"></td>
            <td id="detalebi"></td>
        </tr>
        <tr>
            <td id="detalebi">Final total price</td>
            <td id="detalebi"></td>
            <td id="detalebi"></td>
        </tr>
        <!-- <tr>
            <td id="detalebi">Total Discount</td>
            <td id="detalebi"></td>
            <td id="detalebi"></td>

        </tr> -->
        <tr>
            <td id="detalebi" style="border:none"></td>
            <td id="detalebi"colspan="3" style="background-color:#e8e4e4; border:none"><span id="kursi"></span></td>
        </tr>

    </table>



</div>
<!-- <p style="display: inline; margin-left: 580px;" id="kursi"> </p> -->
<br>
<p style="display: inline; margin-left: 100px; color: gray;
  font-weight: 80%;"> Date of offer: <span id="current-date"></span></p>
<br>
<p  style="display: inline; margin-left: 100px; color: gray;
  font-weight: 80%;" > Offer prepared by: <?php echo htmlspecialchars(getLatinName(capitalizeEachWord($salesmenegername))); ?> </p>
<br><br>
<p id="sakontaqto">Contact information</p>
<br>

<div style="display: flex; margin-left: 100px; align-items: stretch; margin-top:-15px;">
    <!-- Vertical line -->
    <div style="border-left: 1px solid gray; margin-right: 10px; width: 1px;"></div>
    <!-- Content -->
    <div style="color: gray; font-weight: 80%;">
        <p style="margin: 0; padding: 5px 0;">Sales Manager: <?php echo htmlspecialchars(getLatinName(capitalizeEachWord($salesmenegername))); ?></p>
       
                 <p style="margin: 0; padding: 5px 0;">Tel: 032 211 11 05</p>
        <p style="margin: 0; padding: 5px 0;">Mob:<?php echo htmlspecialchars($salesmenegerphone); ?></p>
    </div>
</div>



<!-- <div id="twoDRenderContainer" class="twoDrenderr"> </div>
<div style="page-break-before: always;"></div>
<div class='threeDRender' id="threeDRender" ></div>  -->
<div style="page-break-before: always;"></div>


<!-- <div id="grafikiTable"> </div> -->


<script>
function sanitizeValue(value) {
    return value === null || value === undefined || value === 'NaN' ? '' : value;
}

const currentDate = new Date();
const formattedDate = `${currentDate.getDate()}/${currentDate.getMonth() + 1}/${currentDate.getFullYear()}`;
document.getElementById('current-date').textContent = formattedDate;

function addCellNextToProject(content) {
    let table = document.getElementById("myTable");
    let row = table.rows[2];
    let cell = row.insertCell(-1);
    cell.innerHTML = content;
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(value);
}

function getLatinName(str) {
    const GEO_LAT = {
        "ქ": "q", "წ": "ts", "ჭ": "ch", "ე": "e", "რ": "r", "ღ": "gh", "ტ": "t", "თ": "t",
        "ყ": "y", "უ": "u", "ი": "i", "ო": "o", "პ": "p", "ა": "a", "ს": "s", "შ": "sh",
        "დ": "d", "ფ": "p", "გ": "g", "ჰ": "h", "ჯ": "j", "ჟ": "zh", "კ": "k", "ლ": "l",
        "ზ": "z", "ხ": "x", "ძ": "dz", "ც": "c", "ჩ": "ch", "ვ": "v", "ბ": "b", "ნ": "n",
        "მ": "m"
    };

    str = str.replace(/,/g, '.'); // Replace commas with dots
    let newStr = '';

    for (let char of str) {
        if (GEO_LAT[char]) {
            newStr += GEO_LAT[char];
        } else {
            newStr += char;
        }
    }

    return newStr;
}

function capitalizeEachWord(str) {
    return str.replace(/\b\w/g, char => char.toUpperCase());
}


// Data from PHP
let project = <?php echo json_encode($project); ?>;
let sartuli = <?php echo json_encode($sartuli); ?>;
let flatNum = <?php echo json_encode($flatNum); ?>;
let sacxovrebelifarti = <?php echo json_encode($sacxovrebelifarti); ?>;
let korpusi = <?php echo json_encode($korpusi); ?>;
//let sadarbazo = <?php echo json_encode($sadarbazo); ?>;
let aivani = <?php echo json_encode($aivani); ?>;
let totalspace = <?php echo json_encode($totalspace); ?>;
let kvmdollar = Number(<?php echo json_encode($kvmdollar); ?>);
let totalprice = <?php echo json_encode($totalprice); ?>;
let kursi = <?php echo json_encode($seb_currency); ?>;
let chabarebisforma = <?php echo json_encode($chabarebisforma); ?>;


// Format values
let kvmdollarFormated = formatNumber(kvmdollar);
let totalpriceFormated = formatNumber(totalprice);

// Calculate values in GEL
let kvmpriceGel = (kvmdollar * kursi);
let totalpriceGel = (totalprice * kursi);

// Format values with commas
let kvmpriceGelFormated = formatNumber(kvmpriceGel);
let totalpriceGelFormated = formatNumber(totalpriceGel);

// Assign formatted values to HTML elements
document.getElementById("project").innerText = sanitizeValue(getLatinName(capitalizeEachWord(project)));
document.getElementById("sartuli").innerText = sanitizeValue(sartuli);
document.getElementById("flatNum").innerText = sanitizeValue(flatNum);
document.getElementById("sacxovrebelifarti").innerText = sanitizeValue(sacxovrebelifarti);
document.getElementById("korpusi").innerText = sanitizeValue(korpusi);
//document.getElementById("sadarbazo").innerText = sanitizeValue(sadarbazo);
document.getElementById("aivani").innerText = sanitizeValue(aivani);
document.getElementById("totalspace").innerText = sanitizeValue(totalspace);
document.getElementById("kursi").innerText = `National Bank Exchange Rate: ${kursi}`;
document.getElementById("kvmprice").innerText = sanitizeValue(kvmdollarFormated);
document.getElementById("totalprice").innerText = sanitizeValue(totalpriceFormated);
document.getElementById("kvmpriceGel").innerText = sanitizeValue(kvmpriceGelFormated);
document.getElementById("totalpriceGel").innerText = sanitizeValue(totalpriceGelFormated);
document.getElementById("chabarebisforma").innerText = chabarebisforma;



</script>