<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
header('Content-Type: text/html; charset=UTF-8');
global $USER;

CModule::IncludeModule('crm');

$user_id_for_info=$USER->GetID();

//if($USER->GetID()){
//    $NotAuthorized=false;
//    $user_id=$USER->GetID();
//    $USER->Authorize(1);
//
//}
//else{
//    $NotAuthorized=true;
//    $USER->Authorize(1);
//}


function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
};

global $DB;


function getUserName ($id) {
    $res = CUser::GetByID($id)->Fetch();

    return $res["NAME"]." ".$res["LAST_NAME"];
}

// Получаем кастомные поля сделки
$customFields = [];
$rsUserFields = CUserTypeEntity::GetList(
    array(),
    array('ENTITY_ID' => 'CRM_DEAL')
);
while ($arUserField = $rsUserFields->Fetch()) {

    $customFields[$arUserField['FIELD_NAME']] = $arUserField['USER_TYPE_ID'];
}

//// Выводим типы кастомных полей
//foreach ($customFields as $fieldName => $fieldType) {
//    echo "Название кастомного поля: $fieldName, Тип поля: $fieldType\n";
//}


function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array());
    if($arContact = $res->Fetch()){
        $PHONE=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

function getCompanyInfo($contactId) {
    $arContact = array();
    $res = CCrmCompany::GetList(array("ID" => "ASC"), array("ID" => $contactId), array());
    if($arContact = $res->Fetch()){
        $PHONE=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'COMPANY','TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'COMPANY','TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

function getDealInfo($dealID) {
    $arDeal = array();
    $res = CCrmDeal::GetList(array("ID" => "ASC"), array("ID" => $dealID), array());
    if($arDeal = $res->Fetch()){
//        $CON="";
//
//        if($arDeal["COMPANY_ID"]!=="0"){
//            $CON=getCompanyInfo($arDeal["COMPANY_ID"]);
//        }else if (!empty($arDeal["CONTACT_ID"])){
//            $CON=getContactInfo($arDeal["CONTACT_ID"]);
//        }
//
//        $arDeal["CONTACT_ARR"] = $CON;
        return $arDeal;
    }
    return $arDeal;
}


function getCIBlockElementsByFilter($arFilter)
{
    $arElements = array();
    $res = CIBlockElement::GetList(array("ID"=>"ASC"), $arFilter, false, Array("nPageSize" => 99999), array());
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = array();
        foreach ($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
        foreach ($arProps as $key => $arProp) $arPushs[$key] = $arProp["VALUE"];
        array_push($arElements, $arPushs);
    }
    return $arElements;
}



function getDealInfoByContact1($contactID) {
    $resContract = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactID), array());

    $phone=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'WORK', "ELEMENT_ID" => $contactID))->Fetch();

    $resArray = array();
    if($arContact = $resContract->Fetch()){
        $arContact["PHONE_NUM"] = $phone["VALUE"];
        return $arContact;
    }
}

function dealstagestring($stageid){

    if($stageid=="NEW" || $stageid=="UC_71FU45" || $stageid=="UC_CJOUU6" || $stageid=="UC_VBOXJO" || $stageid=="UC_TDFF5K" || $stageid=="UC_41XZPE" || $stageid=="WON"){
        return "SALE";
    }elseif (strpos($stageid, "C3") !== false) {
        return "AFTER_SALE";
    }
    
    
    // elseif ($stageid=="C3:NEW" || $stageid=="C3:UC_DVLFYP"|| $stageid=="C3:UC_365OWU" || $stageid=="C3:1" || $stageid=="C3:UC_P3BGEK" || $stageid=="C3:UC_7AVWR7" || $stageid=="C3:WON" || $stageid=="C3:LOSE"  || $stageid=="C3:3" || $stageid=="C3:5"){
    //     return "AFTER_SALE";
    // }

}

function addCIBlockElement($arForAdd, $arProps = array()) {
    $el = new CIBlockElement;
    $arForAdd["PROPERTY_VALUES"] = $arProps;
    if ($PRODUCT_ID = $el->Add($arForAdd)) return $PRODUCT_ID;
    else return 'Error: ' . $el->LAST_ERROR;
}


function getDealsByFilterForGadaxdebi($arFilter, $arSelect = array(), $arSort = array("ID"=>"DESC")) {
    $arDeals = array();
    $arSelect = ["ID","OPPORTUNITY","UF_CRM_1684226981","UF_CRM_1684931758250","UF_CRM_1684931748592"];
    $res = CCrmDeal::GetList($arSort, $arFilter, $arSelect);
    while($arDeal = $res->Fetch()) array_push($arDeals, $arDeal);
    return (count($arDeals) > 0) ? $arDeals : false;
}


function updateDealGadaxdebi($dealid){

    $afterSaleDeal = getDealsByFilterForGadaxdebi(["CHECK_PERMISSIONS"=>"N","ID"=>$dealid]);
    $salesDealId = $afterSaleDeal[0]["UF_CRM_1684226981"];
    $saleDealIdNum = explode("_",$salesDealId)[1];

    $salesDealInfo = getDealsByFilterForGadaxdebi(["CHECK_PERMISSIONS"=>"N","ID"=>$saleDealIdNum]);

    $arFilter = array("PROPERTY_DEAL"=>$saleDealIdNum,
                    "IBLOCK_ID"=>19);
    $payments=getCIBlockElementsByFilter($arFilter);

    $moneyToPay=$salesDealInfo[0]['OPPORTUNITY']?:0;
    $payedMoney=0;


    foreach($payments as $singlePayment){
        $payedMoney+= $singlePayment['TANXA']?:0;
    }

    $moneyLeft=$moneyToPay-$payedMoney;

    if($salesDealInfo[0]["UF_CRM_1684931758250"] !=$moneyLeft ||  $salesDealInfo[0]["UF_CRM_1684931748592"] !=$payedMoney){
        $CCrmDeal = new CCrmDeal(false);
        $upd = array(
            "UF_CRM_1684931758250" => $moneyLeft,
            "UF_CRM_1684931748592" => $payedMoney,

        );
        $CCrmDeal->Update($saleDealIdNum, $upd);
    }

    if($afterSaleDeal[0]["UF_CRM_1684931758250"] !=$moneyLeft ||  $afterSaleDeal[0]["UF_CRM_1684931748592"] !=$payedMoney){
        $CCrmDeal = new CCrmDeal(false);
        $upd = array(
            "UF_CRM_1684931758250" => $moneyLeft,
            "UF_CRM_1684931748592" => $payedMoney,

        );
        $CCrmDeal->Update($dealid, $upd);
    }

 

}

$filesarr=array();


$dbRes = $DB->query('SELECT * FROM b_disk_object WHERE PARENT_ID = 38');
if ($dbRes) {
    while ($object = $dbRes->Fetch()) {
        $file_struct["NAME"] = $object["NAME"];
        $file_struct["ID"]   = $object["ID"];
        array_push($filesarr, $file_struct);
    }
}

$pop_up_files=array();

foreach ($filesarr as $value){
    $struct=array();
    $struct["NAME"]=$value["NAME"];
    $struct["ID"]=$value['ID'];
    $pop_up_files[]=$struct;
}



$empty_get=false;
$error_code="";

$popup_mode='nopop';

$dealid = !empty($_GET["dealid"]) ? $_GET["dealid"] : (!empty($_POST["deal_id"]) ? $_POST["deal_id"] : "");

if(!empty($dealid)){
    updateDealGadaxdebi($dealid);
}

if(isset($_GET["popup"])){

    $frompopup=$_GET["popup"];

    if($frompopup==true){
        $popup_mode='ispop';
    }

}

$deal = getDealInfo($dealid);

$filtered_files=array();

foreach ($filesarr as $key => $value){

    $explode_array=explode("$",$value["NAME"]);

    if($explode_array[0]=="ყველა"){
        array_push($filtered_files,$value);
    }

    if($explode_array[0]==$deal["UF_CRM_1779277729207"]){
        array_push($filtered_files,$value);
    }

}

$second_filter=array();

foreach ($filesarr as $value){
    $second_filter[] = array(
        "NAME" => $value["NAME"],
        "ID"   => $value["ID"]
    );
}

function formatNumber($value) {
    return number_format($value, 2, '.', ',');
}




function generateProductsTablegeo($dealId)
{
    $products = CCrmDeal::LoadProductRows($dealId);

    $table = "<table style='border-collapse: collapse; align-items: center; margin: 0; float: left;'>";
    $table .= "<tr>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>#</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>Product name</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>Quantity</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>Price</th>
    </tr>";

    $n = 0;
    $totalQty = 0;
    $totalPrice = 0;

    foreach ($products as $product) {
        $n++;
        $name = htmlspecialchars($product["PRODUCT_NAME"] ?? ''); 
        $qty = (float)($product["QUANTITY"] ?? 0);
        $price = (float)($product["PRICE"] ?? 0);

        $totalQty += $qty;
        $totalPrice += $qty * $price;

        $qtyFormatted = number_format($qty, 2, '.', '');
        $priceFormatted = number_format($price, 2, '.', '');

        $table .= "<tr>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$n</td>
            <td style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>$name</td>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$qtyFormatted</td>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$ $priceFormatted</td>
        </tr>";
    }

    $totalQtyFormatted = number_format($totalQty, 2, '.', '');
    $totalPriceFormatted = number_format($totalPrice, 2, '.', '');

    $table .= "<tr>
        <td colspan='2' style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: right;'>Total</td>
        <td style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: center;'>$totalQtyFormatted</td>
        <td style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: center;'>$ $totalPriceFormatted</td>
    </tr>";

    $table .= "</table>";
    return $table;
}


function generateProductsTable($dealId)
{
    $products = CCrmDeal::LoadProductRows($dealId);

    $table = "<table style='border-collapse: collapse; align-items: center; margin: 0; float: left;'>";
    $table .= "<tr>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>#</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>პროდუქტის სახელი</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>რაოდენობა</th>
        <th style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>ფასი</th>
    </tr>";

    $n = 0;
    $totalQty = 0;
    $totalPrice = 0;

    foreach ($products as $product) {
        $n++;
        $name = htmlspecialchars($product["PRODUCT_NAME"] ?? ''); 
        $name = mb_convert_encoding($name, 'UTF-8', 'auto');

        $qty = (float)($product["QUANTITY"] ?? 0);
        $price = (float)($product["PRICE"] ?? 0);

        $totalQty += $qty;
        $totalPrice += $qty * $price;

        $qtyFormatted = number_format($qty, 2, '.', '');
        $priceFormatted = number_format($price, 2, '.', '');

        $table .= "<tr>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$n</td>
            <td style='padding: 10px; border: 1px solid black; font-family: sylfaen;'>$name</td>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$qtyFormatted</td>
            <td style='padding: 10px; border: 1px solid black; text-align: center;'>$ $priceFormatted</td>
        </tr>";
    }

    $totalQtyFormatted = number_format($totalQty, 2, '.', '');
    $totalPriceFormatted = number_format($totalPrice, 2, '.', '');

    // Add totals row
    $table .= "<tr>
        <td colspan='2' style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: right; font-family: sylfaen;'>სულ</td>
        <td style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: center;'>$totalQtyFormatted</td>
        <td style='padding: 10px; border: 1px solid black; font-weight: bold; text-align: center;'>$ $totalPriceFormatted</td>
    </tr>";

    $table .= "</table>";
    return $table;
}



function grafikisGeneracia1($danarti_content, $fasdaklebuli) {
    $grafiki = "
    <table style='border-collapse: collapse; align-items: center; margin: 0; float: left;'> 
       ";

    $n = 0;
    $jamiTanxebis = 0;

    foreach ($danarti_content as $story) {
        $n++;

        $TARIGI = $story["TARIGI"];

        // თანხის მნიშვნელობის კონვერტაცია რიცხვში (და ათასეულის გამყოფის ამოღება)
        $TANXA_NUMBR = floatval(str_replace(",", "", $story["TANXA_NUMBR"]));

        $darchenilitanxa = $fasdaklebuli - $TANXA_NUMBR;
        $fasdaklebuli = $darchenilitanxa;

        if ($n === count($danarti_content)) {
            $darchenilitanxa = 0;
        }

        // თანხის ფორმატირება (html-ში გასაჩენად)
        $formattedTANXA_NUMBR = number_format($TANXA_NUMBR, 2);

        $jamiTanxebis += $TANXA_NUMBR;

        // დარჩენილ თანხაზე ფორმატირება
        $formattedDarchenilitanxa = number_format(round($darchenilitanxa, 2), 2);

        // HTML-ში ცალკე ფორმატირებული თანხები
        $grafiki .=  "<tr>
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$n</td>       
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$ $formattedTANXA_NUMBR</td>
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$TARIGI</td>
                      </tr>";
    }

    // ჯამი ფორმატირება HTML-ში
    $formattedJamiTanxebis = number_format($jamiTanxebis, 2);

    $grafiki .=
        "<tr>
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'></td>       
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>
                <strong>$ $formattedJamiTanxebis</strong>
            </td>
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'><strong>ჯამური ღირებულება</strong></td>
        </tr>";

    $grafiki .= "</table>";
    return $grafiki;
}

function grafikisGeneracia1_eng($danarti_content, $fasdaklebuli) {


    $grafiki = "
    <table style='border-collapse: collapse; align-items: center; margin: 0; float: left;'> 
       ";

    $n = 0;
    $jamiTanxebis = 0;

    foreach ($danarti_content as $story) {
        $n++;

        $TARIGI = $story["TARIGI"];

        // თანხის მნიშვნელობის კონვერტაცია რიცხვში (და ათასეულის გამყოფის ამოღება)
        $TANXA_NUMBR = floatval(str_replace(",", "", $story["TANXA_NUMBR"]));

        $darchenilitanxa = $fasdaklebuli - $TANXA_NUMBR;
        $fasdaklebuli = $darchenilitanxa;

        if ($n === count($danarti_content)) {
            $darchenilitanxa = 0;
        }

        // თანხის ფორმატირება (html-ში გასაჩენად)
        $formattedTANXA_NUMBR = number_format($TANXA_NUMBR, 2);

        $jamiTanxebis += $TANXA_NUMBR;

        // დარჩენილ თანხაზე ფორმატირება
        $formattedDarchenilitanxa = number_format(round($darchenilitanxa, 2), 2);

        // HTML-ში ცალკე ფორმატირებული თანხები
        $grafiki .=  "<tr>
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$n</td>       
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$ $formattedTANXA_NUMBR</td>
                          <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>$TARIGI</td>
                      </tr>";
    }

    // ჯამი ფორმატირება HTML-ში
    $formattedJamiTanxebis = number_format($jamiTanxebis, 2);

    $grafiki .=
        "<tr>
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'></td>       
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'>
                <strong>$ $formattedJamiTanxebis</strong>
            </td>
            <td style='padding: 30px; border: 1px solid black; font-size:13.5px; text-align:center; font-family: sylfaen;'><strong>Total amount</strong></td>
        </tr>";

    $grafiki .= "</table>";
    return $grafiki;
}




// function generateGrafikiForSale($data, $price, $lang)
// {
//     // ენის მიხედვით
//     if ($lang == "GEO") {
//         $dro        = "გადახდის დრო";
//         $tanxa      = "თანხა $";
//         $darchenili = "დარჩენილი თანხა $";
//     } else {
//         $dro        = "payment date";
//         $tanxa      = "amount $";
//         $darchenili = "remaining amount $";
//     }

//     // ცხრილის head
//     $grafiki = "
//     <table style='border-collapse: collapse; width:70%;'>
//         <thead>
//             <tr>
//                 <th style='border:1px solid black; padding:2px;'>#</th>
//                 <th style='border:1px solid black; padding:2px;'>$dro</th>
//                 <th style='border:1px solid black; padding:2px;'>$tanxa</th>
//                 <th style='border:1px solid black; padding:2px;'>$darchenili</th>
//             </tr>
//         </thead>
//         <tbody>
//     ";


//     foreach ($data as $row) {

//         // ამოღება
//         $payment   = $row["payment"];
//         $date      = $row["date"];
//         $amount    = floatval($row["amount"]);

//         // დარჩენილი ძირის გამოთვლა
//         $price = $price - $amount;

//         // ფორმატირება
//         $formattedAmount = number_format($amount, 2, '.', ',');
//         $formattedPrice  = number_format($price, 2, '.', ',');

//         // სტრიქონის დამატება
//         $grafiki .= "
//             <tr>
//                 <td style='border:1px solid black; padding:2px; text-align:center;'>$payment</td>
//                 <td style='border:1px solid black; padding:2px; text-align:center;'>$date</td>
//                 <td style='border:1px solid black; padding:2px; text-align:center;'>$formattedAmount</td>
//                 <td style='border:1px solid black; padding:2px; text-align:center;'>$formattedPrice</td>
//             </tr>
//         ";
//     }

//     $grafiki .= "</tbody></table>";

//     return $grafiki;
// }


function  generateGrafikiForRestruct($data, $lang){

     mb_internal_encoding('UTF-8');

    if ($lang == "GEO") {
        $date        = "გადახდის თარიღი";
        $total = "თანხა $";
    } else {
        $date        = "payment date";
        $total = "amount $";
    }

    $grafiki = "
    <table style='border-collapse: collapse; width:85%; font-family: Arial, sans-serif; margin: auto'>
        <thead>
            <tr style='background-color: #f2f2f2;'>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold; font-size:10px;font-family: sylfaen;'>#</th>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold;font-size:10px;font-family: sylfaen;'><b>" . htmlspecialchars($date) . "</b></th>

                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold;font-size:10px;font-family: sylfaen;'>$total</th>
            </tr>
        </thead>
        <tbody>
    ";

    $num = 0;

    foreach ($data as $row) {
      
        $date    = $row["date"];
        $scheduled    = $row["scheduled"];
        $fine    = $row["fine"];
        $total    = $row["total"];
        $numbering = $num;
        if($num == 0){
            
            if($lang == "GEO"){
                $numbering = "რესტრუქტურიზაცია";
            }else{
                $numbering = "Restructuring";
            }
        }


        $grafiki .= "
            <tr>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$numbering</td>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$date</td>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$total</td>
            </tr>
        ";

        $num ++;
    }

    $grafiki .= "</tbody></table>";

    return $grafiki;

}

function generateGrafikiForSale($data, $price, $lang)
{
    mb_internal_encoding('UTF-8');

    // ენის მიხედვით
    if ($lang == "GEO") {
        $dro        = "გადახდის დრო";
        $tanxa      = "თანხა $";
        $darchenili = "დარჩენილი თანხა $";
    } else {
        $dro        = "payment date";
        $tanxa      = "amount $";
        $darchenili = "remaining amount $";
    }

    // ცხრილის head
    $grafiki = "
    <table style='border-collapse: collapse; width:85%; font-family: Arial, sans-serif; margin: auto'>
        <thead>
            <tr style='background-color: #f2f2f2;'>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold; font-size:10px;font-family: sylfaen;'>#</th>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold;font-size:10px;font-family: sylfaen;'><b>" . htmlspecialchars($dro) . "</b></th>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold;font-size:10px;font-family: sylfaen;'>$tanxa</th>
                <th style='border:1px solid black; padding:2px; text-align:center; font-weight:bold;font-size:10px;font-family: sylfaen;'>$darchenili</th>
            </tr>
        </thead>
        <tbody>
    ";

    foreach ($data as $row) {
        $payment = $row["payment"];
        $date    = $row["date"];
        $amount  = floatval($row["amount"]);

        // აქ ხდება Geo -> Eng გადათარგმნა, თუ ენა GEO არ არის
        if ($lang !== "GEO") {
            if ($payment === "პირველადი შენატანი") {
                $payment = "Initial payment";
            } 
            elseif ($payment === "ბოლო გადახდა") {
                $payment = "Final payment";
            } elseif ($payment === "პირველი გადახდა") {
                $payment = "First payment";
            } elseif ($payment === "რესტრუქტურიზაცია") {
                $payment = "Restructured";
            }
        }

        // დარჩენილი თანხის გამოთვლა
        $price -= $amount;

        // ფორმატირება
        $formattedAmount = number_format($amount, 2, '.', ',');
        $formattedPrice  = number_format($price, 2, '.', ',');

        $grafiki .= "
            <tr>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$payment</td>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$date</td>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$formattedAmount</td>
                <td style='border:1px solid black; padding:2px; text-align:center; font-size:10px;font-family: sylfaen;'>$formattedPrice</td>
            </tr>
        ";
    }

    $grafiki .= "</tbody></table>";

    return $grafiki;
}

function wd_rpr_signature(DOMElement $run): string {
    foreach ($run->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === 'rPr') {
            return $c->ownerDocument->saveXML($c);
        }
    }
    return '';
}

function wd_generate_drawing_xml($rId, $wPx, $hPx) {
    // Convert pixels to EMUs (1px = 9525 EMUs)
    $wEmu = round($wPx * 9525);
    $hEmu = round($hPx * 9525);
    $id = rand(1000, 9999);

    return '
    <w:r xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
        <w:drawing>
            <wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">
                <wp:extent cx="'.$wEmu.'" cy="'.$hEmu.'"/>
                <wp:effectExtent l="0" t="0" r="0" b="0"/>
                <wp:docPr id="'.$id.'" name="Image '.$id.'"/>
                <wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>
                <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:nvPicPr>
                                <pic:cNvPr id="'.$id.'" name="Picture '.$id.'"/>
                                <pic:cNvPicPr/>
                            </pic:nvPicPr>
                            <pic:blipFill>
                                <a:blip r:embed="'.$rId.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>
                                <a:stretch><a:fillRect/></a:stretch>
                            </pic:blipFill>
                            <pic:spPr>
                                <a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$wEmu.'" cy="'.$hEmu.'"/></a:xfrm>
                                <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                            </pic:spPr>
                        </pic:pic>
                    </a:graphicData>
                </a:graphic>
            </wp:inline>
        </w:drawing>
    </w:r>';
}

function wd_replace_placeholder_with_xml($dom, $xp, $placeholder, $xmlFragment) {
    if (empty($xmlFragment)) return;
    
    $nodes = [];
    foreach ($xp->query('//w:t') as $tNode) {
        if (strpos($tNode->nodeValue, $placeholder) !== false) {
            $nodes[] = $tNode;
        }
    }

    foreach ($nodes as $tNode) {
        $parentRun = $tNode->parentNode;
        $parentPara = $parentRun->parentNode;

        $importDoc = new DOMDocument();
        @$importDoc->loadXML($xmlFragment);
        $importedNode = $dom->importNode($importDoc->documentElement, true);

        // If the fragment is a table, insert before the paragraph. 
        // If it is a drawing/run, replace the current run.
        if ($importedNode->localName === 'tbl') {
            $parentPara->parentNode->insertBefore($importedNode, $parentPara);
            $parentPara->parentNode->removeChild($parentPara);
        } else {
            $parentPara->insertBefore($importedNode, $parentRun);
            $parentPara->removeChild($parentRun);
        }
    }
}

function wd_merge_runs_in_paragraph(DOMElement $para, DOMXPath $xp): void {
    $changed = true;
    while ($changed) {
        $changed = false;
        $runs = [];
        foreach ($para->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') $runs[] = $child;
        }
        $i = 0;
        while ($i < count($runs) - 1) {
            $a = $runs[$i]; $b = $runs[$i + 1];
            $aTexts = $xp->query('w:t', $a);
            $bTexts = $xp->query('w:t', $b);

            $aHasOther = $bHasOther = false;
            foreach ($a->childNodes as $c) if ($c instanceof DOMElement && !in_array($c->localName, ['rPr','t'], true)) { $aHasOther = true; break; }
            foreach ($b->childNodes as $c) if ($c instanceof DOMElement && !in_array($c->localName, ['rPr','t'], true)) { $bHasOther = true; break; }
            if ($aHasOther || $bHasOther || $aTexts->length === 0 || $bTexts->length === 0) { $i++; continue; }

            $combinedPreview = '';
            foreach ($aTexts as $t) $combinedPreview .= $t->nodeValue;
            foreach ($bTexts as $t) $combinedPreview .= $t->nodeValue;

            $signaturesMatch = (wd_rpr_signature($a) === wd_rpr_signature($b));
            $looksLikePlaceholder = strpos($combinedPreview, '$') !== false;

            if (!$signaturesMatch && !$looksLikePlaceholder) { $i++; continue; }

            $combined = '';
            foreach ($aTexts as $t) $combined .= $t->nodeValue;
            foreach ($bTexts as $t) $combined .= $t->nodeValue;
            foreach (iterator_to_array($aTexts) as $t) $a->removeChild($t);

            $wns  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            $newT = $a->ownerDocument->createElementNS($wns, 'w:t');
            $newT->setAttribute('xml:space', 'preserve');
            $newT->appendChild($a->ownerDocument->createTextNode($combined));
            $a->appendChild($newT);

            $b->parentNode->removeChild($b);
            array_splice($runs, $i + 1, 1);
            $changed = true; // repeat the outer loop
        }
    }
}

function wd_replace_text_node_with_breaks(DOMElement $tNode, string $text): void {
    $run = $tNode->parentNode;
    if (!$run instanceof DOMElement) return;
    $dom = $tNode->ownerDocument;
    $wns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $parts    = explode("\n", $text);
    $fragment = $dom->createDocumentFragment();

    foreach ($parts as $idx => $part) {
        if ($idx > 0) $fragment->appendChild($dom->createElementNS($wns, 'w:br'));
        if ($part !== '') {
            $t = $dom->createElementNS($wns, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($part));
            $fragment->appendChild($t);
        }
    }

    $run->insertBefore($fragment, $tNode);
    $run->removeChild($tNode);
}

function wd_html_table_to_ooxml(string $html): string {
    $hdoc = new DOMDocument();
    libxml_use_internal_errors(true);
    $hdoc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $hxp = new DOMXPath($hdoc);

    $table = $hxp->query('//table')->item(0);
    if (!$table) return '';

    $colPcts = [];
    foreach ($hxp->query('.//colgroup/col', $table) as $col) {
        if (preg_match('/width\s*:\s*([\d.]+)%/', $col->getAttribute('style'), $m)) {
            $colPcts[] = floatval($m[1]);
        }
    }

    $totalDxa = 9000;
    $gridCols = [];
    foreach ($colPcts as $p) $gridCols[] = (int)round($totalDxa * $p / 100);

    $o  = '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
    $o .= '<w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
    foreach (['top','left','bottom','right','insideH','insideV'] as $s) {
        $o .= '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
    }
    $o .= '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';
    if ($gridCols) {
        $o .= '<w:tblGrid>';
        foreach ($gridCols as $w) $o .= '<w:gridCol w:w="' . $w . '"/>';
        $o .= '</w:tblGrid>';
    }

    // Helper: extract hex color from a style string (returns '' or '4472C4')
    $extractColor = function(string $style): string {
        // background-color: #4472C4  or  background-color:rgb(...)
        if (preg_match('/background-color\s*:\s*#([0-9a-fA-F]{6})/i', $style, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/background-color\s*:\s*#([0-9a-fA-F]{3})\b/i', $style, $m)) {
            // expand shorthand
            $s = $m[1];
            return strtoupper($s[0].$s[0].$s[1].$s[1].$s[2].$s[2]);
        }
        return '';
    };

    // Helper: extract font color
    $extractFontColor = function(string $style): string {
        if (preg_match('/(?:^|;)\s*color\s*:\s*#([0-9a-fA-F]{6})/i', $style, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/(?:^|;)\s*color\s*:\s*(white|#fff)\b/i', $style)) {
            return 'FFFFFF';
        }
        return '';
    };

    foreach ($hxp->query('.//tr', $table) as $tr) {
        $firstStyle = '';
        foreach ($tr->childNodes as $fc) if ($fc instanceof DOMElement) { $firstStyle = $fc->getAttribute('style'); break; }
        if (stripos($firstStyle, 'display:none') !== false) continue;

        // Row-level background from <tr style="background-color:..."> or <tr style="...color:white">
        $trStyle   = $tr->getAttribute('style');
        $trBgColor = $extractColor($trStyle);

        // Check if this row is inside <thead>
        $isHeaderRow = false;
        $parent = $tr->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->localName) === 'thead') {
            $isHeaderRow = true;
        }

        if ($isHeaderRow) {
            $o .= '<w:tr><w:trPr><w:trHeight w:val="400" w:hRule="atLeast"/><w:tblHeader/></w:trPr>';
        } else {
            $o .= '<w:tr><w:trPr><w:trHeight w:val="400" w:hRule="atLeast"/></w:trPr>';
        }
        $cells  = $hxp->query('./th | ./td', $tr);
        $colIdx = 0;

        foreach ($cells as $cell) {
            $cellStyle = $cell->getAttribute('style');
            $isTh      = strtolower($cell->localName) === 'th';

            // Cell bg: prefer cell-level, fall back to row-level
            $bgColor = $extractColor($cellStyle) ?: $trBgColor;

            // Font color
            $fontColor = $extractFontColor($cellStyle) ?: $extractFontColor($trStyle);

            $align = 'left';
            if (preg_match('/text-align\s*:\s*(\w+)/i', $cellStyle, $m)) $align = strtolower($m[1]);

            $colspanAttr = $cell->getAttribute('colspan');
            $colspan     = $colspanAttr ? max(1, (int)$colspanAttr) : 1;
            $cellW       = 0;
            for ($c = 0; $c < $colspan; $c++) {
                $cellW += $gridCols[$colIdx + $c] ?? 0;
            }
            if ($cellW === 0) $cellW = (int)round($totalDxa / max($cells->length, 1));

            $cellText = '';
            $hasBold  = false;
            foreach ($cell->childNodes as $cn) {
                if ($cn instanceof DOMElement && in_array(strtolower($cn->localName), ['b','strong'], true)) {
                    $hasBold = true;
                    $cellText .= $cn->textContent;
                } else {
                    $cellText .= $cn->textContent;
                }
            }
            $cellText = trim($cellText);

            $o .= '<w:tc><w:tcPr>';
            $o .= '<w:tcW w:w="' . $cellW . '" w:type="dxa"/>';
            if ($colspan > 1) $o .= '<w:gridSpan w:val="' . $colspan . '"/>';
            $o .= '<w:tcBorders>';
            foreach (['top','left','bottom','right'] as $s) {
                $o .= '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
            }
            $o .= '</w:tcBorders>';
            // *** Apply shading / fill color ***
            if ($bgColor !== '') {
                $o .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $bgColor . '"/>';
            }
            $o .= '<w:vAlign w:val="center"/></w:tcPr>';

            $jcMap = ['center'=>'center','right'=>'right','justify'=>'both','left'=>'left'];
            $jc    = $jcMap[$align] ?? 'left';
            $o .= '<w:p><w:pPr><w:jc w:val="' . $jc . '"/><w:spacing w:before="0" w:after="0"/></w:pPr>';
            $o .= '<w:r><w:rPr>';
            $o .= '<w:rFonts w:ascii="FreeSerif" w:hAnsi="FreeSerif" w:cs="FreeSerif"/>';
            $o .= '<w:sz w:val="22"/><w:szCs w:val="22"/>';
            if ($isTh || $hasBold) $o .= '<w:b/><w:bCs/>';
            // *** White font for colored rows ***
            if ($fontColor !== '') {
                $o .= '<w:color w:val="' . $fontColor . '"/>';
            }
            $o .= '</w:rPr>';
            $o .= '<w:t xml:space="preserve">' . htmlspecialchars($cellText, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
            $o .= '</w:r></w:p>';
            $o .= '</w:tc>';
            $colIdx += $colspan;
        }
        $o .= '</w:tr>';
    }
    $o .= '</w:tbl>';
    return $o;
}

function generateDocument($fileData, $variables, $convertToPdf = true) {
    $tempDocx = tempnam(sys_get_temp_dir(), 'fmg_') . '.docx';
    file_put_contents($tempDocx, $fileData);

    $zip = new ZipArchive();
    if ($zip->open($tempDocx) !== true) {
        @unlink($tempDocx);
        throw new Exception("Cannot open DOCX");
    }

    $documentXml = $zip->getFromName('word/document.xml');
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    
    if ($documentXml === false) {
        $zip->close(); @unlink($tempDocx);
        throw new Exception("document.xml not found");
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    @$dom->loadXML($documentXml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    // 1. Merge adjacent runs to fix split placeholders ($VARNAME$)
    foreach ($xp->query('//w:p') as $para) {
        wd_merge_runs_in_paragraph($para, $xp);
    }

    $scalars = [];
    $tableVars = [];
    $imageVars = [];

    // 2. Sort variables by type
    foreach ($variables as $var) {
        $type = $var['VarType'] ?? '';
        if ($type === 'T') {
            $tableVars[$var['VarName']] = $var['VarValue'];
        } elseif ($type === 'P' && !empty($var['VarValue'])) {
            $imageVars[$var['VarName']] = $var;
        } else {
            $scalars[$var['VarName']] = (string)($var['VarValue'] ?? '');
        }
    }

    // 3. Handle Text Replacements
    uksort($scalars, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($xp->query('//w:t') as $tNode) {
        $replaced = str_replace(array_keys($scalars), array_values($scalars), $tNode->nodeValue);
        if ($replaced !== $tNode->nodeValue) {
            if (strpos($replaced, "\n") !== false) {
                wd_replace_text_node_with_breaks($tNode, $replaced);
            } else {
                $tNode->nodeValue = htmlspecialchars($replaced, ENT_XML1);
            }
        }
    }

    // 4. Handle HTML Tables
    foreach ($tableVars as $placeholder => $html) {
        if (empty($html)) continue;
        $ooxmlTable = wd_html_table_to_ooxml($html);
        wd_replace_placeholder_with_xml($dom, $xp, $placeholder, $ooxmlTable);
    }

    // 5. Handle Images (The missing part)
    if (!empty($imageVars)) {
        // Load relationships to register images
        $relsDom = new DOMDocument();
        $relsDom->loadXML($relsXml);
        
        foreach ($imageVars as $placeholder => $imgData) {
            $rId = 'rIdImg' . uniqid();
            $imgBinary = base64_decode($imgData['VarValue']);
            $extension = 'jpg'; // Basic assumption for JPEG
            $imagePath = 'word/media/' . $rId . '.' . $extension;
            
            // Add image file to ZIP
            $zip->addFromString($imagePath, $imgBinary);
            
            // Register relationship
            $relNode = $relsDom->createElement('Relationship');
            $relNode->setAttribute('Id', $rId);
            $relNode->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
            $relNode->setAttribute('Target', 'media/' . $rId . '.' . $extension);
            $relsDom->documentElement->appendChild($relNode);
            
            // Generate <w:drawing> XML
            $drawingXml = wd_generate_drawing_xml($rId, $imgData['Width'] ?? 250, $imgData['Heigh'] ?? 350);
            wd_replace_placeholder_with_xml($dom, $xp, $placeholder, $drawingXml);
        }
        $zip->addFromString('word/_rels/document.xml.rels', $relsDom->saveXML());
    }

    $documentXmlStr = $dom->saveXML();
    $documentXmlStr = str_replace(
        ['w:ascii="Sylfaen"', 'w:hAnsi="Sylfaen"', 'w:cs="Sylfaen"', 'w:eastAsia="Sylfaen"'],
        ['w:ascii="FreeSerif"', 'w:hAnsi="FreeSerif"', 'w:cs="FreeSerif"', 'w:eastAsia="FreeSerif"'],
        $documentXmlStr
    );
    $zip->addFromString('word/document.xml', $documentXmlStr);

    // $zip->addFromString('word/document.xml', $dom->saveXML());
    $settingsXml = $zip->getFromName('word/settings.xml');
    if ($settingsXml !== false) {
        $settingsXml = str_replace(
            '</w:settings>',
            '<w:embedTrueTypeFonts/><w:embedSystemFonts/></w:settings>',
            $settingsXml
        );
        $zip->addFromString('word/settings.xml', $settingsXml);
    }
    $zip->close();

    // 6. PDF Conversion (Final output)
    if ($convertToPdf) {
        $pdfData = convertDocxToPdf($tempDocx);
        @unlink($tempDocx);
        return $pdfData;
    }

    $result = file_get_contents($tempDocx);
    @unlink($tempDocx);
    return $result;
}

function convertDocxToPdf($docxPath) {
    $outputDir = sys_get_temp_dir();
    putenv('HOME=/tmp');
    putenv('DCONF_PROFILE=/dev/null');

    $loConfigDir = sys_get_temp_dir() . '/lo_config_' . uniqid();
    mkdir($loConfigDir, 0777, true);

    $cmd = sprintf(
        'libreoffice --headless --norestore --nofirststartwizard -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
        escapeshellarg($loConfigDir),
        escapeshellarg($outputDir),
        escapeshellarg($docxPath)
    );

    exec($cmd, $output, $exitCode);

    // Clean up config dir
    array_map('unlink', glob($loConfigDir . '/*'));
    @rmdir($loConfigDir);

    $pdfPath = $outputDir . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';

    if ($exitCode === 0 && file_exists($pdfPath)) {
        $data = file_get_contents($pdfPath);
        @unlink($pdfPath);
        return $data;
    }
    throw new Exception('LibreOffice conversion failed: ' . implode(' ', $output));
}


$today=date("d/m/Y");

if (empty($dealid)){
    $empty_get=true;
    $error_code.="Empty Deal Id";
}

if(!empty($_POST)) {

        $popup_mode = $_POST['popup'];

        if ($popup_mode == 'ispop') {

            $doc_id = $_POST["docs"];

            $deal_id = $_POST["deal_id"];

            $deal = getDealInfo($deal_id);

            $old_owner_contact = array();

            $old_owner_company = array();

            if (!empty($deal["UF_CRM_1720001343"])) {

                foreach ($deal["UF_CRM_1720001343"] as $key => $value) {

                    $explode_arr = explode("_", $value);

                    if ($explode_arr[0] == "CO") {

                        array_push($old_owner_company, getCompanyInfo($explode_arr[1]));

                    } elseif ($explode_arr[0] == "C") {

                        array_push($old_owner_contact, getContactInfo($explode_arr[1]));

                    }

                }

            }


            $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($deal["ID"]);
            if($deal["UF_CRM_1755260753"]){
                array_push($contactIds,explode("_",$deal["UF_CRM_1755260753"])[1]);
            }
            
            $resContractArrIDInfo = array();

            foreach ($contactIds as $thisContactID) {
                $resContractArrIDInfo[] = getContactInfo($thisContactID);
            }

            $company = getCompanyInfo($deal["COMPANY_ID"]);

            $tech_contact = getContactInfo(1792);
            $tech_company = getCompanyInfo(2);

            foreach ($tech_contact as $key => $value) {

                $tech_contact[$key] = "";

            }

            foreach ($tech_company as $key => $value) {

                $tech_company[$key] = "";

            }

            if (count($resContractArrIDInfo) == 2) {

                $deal["DOUBLE_CONTACT_INFO"] = $resContractArrIDInfo;

            } elseif (count($resContractArrIDInfo) == 3) {

                $deal["TRIPLE_CONTACT_INFO"] = $resContractArrIDInfo;

            }

            $combinedArray = [];
            foreach ($resContractArrIDInfo as $infoArray) {
                foreach ($infoArray as $key => $value) {
                    if (!isset($combinedArray[$key])) {
                        $combinedArray[$key] = $value;
                    } else {
                        $combinedArray[$key] .= "," . $value;
                    }
                }
            }

            $combinedArray_old_company = [];
            foreach ($old_owner_company as $infoArray) {
                foreach ($infoArray as $key => $value) {
                    if (!isset($combinedArray_old_company[$key])) {
                        $combinedArray_old_company[$key] = $value;
                    } else {
                        $combinedArray_old_company[$key] .= "," . $value;
                    }
                }
            }

            $combinedArray_old_contact = [];
            foreach ($old_owner_contact as $infoArray) {
                foreach ($infoArray as $key => $value) {
                    if (!isset($combinedArray_old_contact[$key])) {
                        $combinedArray_old_contact[$key] = $value;
                    } else {
                        $combinedArray_old_contact[$key] .= "," . $value;
                    }
                }
            }

            $deal["COMPANY_ARR_OLD"] = $combinedArray_old_company;

            if (empty($deal["COMPANY_ARR_OLD"])) {

                $deal["COMPANY_ARR_OLD"] = $tech_company;

            }

            $deal["CONTACT_ARR_OLD"] = $combinedArray_old_contact;

            if (empty($deal["CONTACT_ARR_OLD"])) {

                $deal["CONTACT_ARR_OLD"] = $tech_contact;

            }

            $deal["CONTACT_ARR"] = $combinedArray;

            if (empty($deal["CONTACT_ARR"])) {

                $deal["CONTACT_ARR"] = $tech_contact;

            }

            $deal["COMPANY_ARR"] = $company;

            if (empty($deal["COMPANY_ARR"])) {

                $deal["COMPANY_ARR"] = $tech_company;

            }

            $file_type = $_POST["type"];

            if (!empty($deal["CONTACT_ARR"]) || !empty($deal["COMPANY_ARR"])) {

                if (!empty($deal["UF_CRM_1779277729207"])) {

                    $proj = $deal["UF_CRM_1779277729207"];

                    $codes = getCIBlockElementsByFilter(array("IBLOCK_ID" => 47, "PROPERTY_PROJECT_NAME" => $proj));

                    $outputArray_COMPANY = array();

                    foreach ($deal["COMPANY_ARR"] as $key => $value) {
                        if (is_array($value)) {

                        } else {
                            if (empty($value)) {

                                if ($value !== "0") {
                                    $value = "";
                                }

                            }

                            $subArray = array(
                                'VarName' => '$' . $key . '_COM$',
                                'VarValue' => $value
                            );

                            $outputArray_COMPANY[] = $subArray;
                        }
                    }

                    $outputArray_CONTACT = array();

                    foreach ($deal["CONTACT_ARR"] as $key => $value) {
                        if (is_array($value)) {

                        } else {
                            if (empty($value)) {
                                if ($value !== "0") {
                                    $value = "";
                                }
                            }

                            $subArray = array(
                                'VarName' => '$' . $key . '_USER$',
                                'VarValue' => $value
                            );

                            $outputArray_CONTACT[] = $subArray;
                        }
                    }

                    $outputArray_CONTACT_old = array();

                    foreach ($deal["CONTACT_ARR_OLD"] as $key => $value) {
                        if (is_array($value)) {

                        } else {
                            if (empty($value)) {
                                if ($value !== "0") {
                                    $value = "";
                                }
                            }

                            $subArray = array(
                                'VarName' => '$' . $key . '_OLD_CON$',
                                'VarValue' => $value
                            );

                            $outputArray_CONTACT_old[] = $subArray;
                        }
                    }

                    $outputArray_COMPANY_old = array();

                    foreach ($deal["COMPANY_ARR_OLD"] as $key => $value) {
                        if (is_array($value)) {

                        } else {
                            if (empty($value)) {
                                if ($value !== "0") {
                                    $value = "";
                                }
                            }

                            $subArray = array(
                                'VarName' => '$' . $key . '_OLD_COM$',
                                'VarValue' => $value
                            );

                            $outputArray_COMPANY_old[] = $subArray;
                        }
                    }

                    $outputArray_CODES = array();

                    foreach ($codes as $key => $value) {

                        if (empty($value["TEXT"])) {
                            if ($value !== "0") {
                                $value["TEXT"] = "";
                            }
                        }

                        $subArray = array(
                            'VarName' => '$' . $value["NAME"] . '$',
                            'VarValue' => htmlspecialchars_decode($value["TEXT"])
                        );

                        $outputArray_CODES[] = $subArray;
                    }

                    if (count($resContractArrIDInfo) == 2) {

                        $double_contact = array();

                        foreach ($deal["DOUBLE_CONTACT_INFO"][0] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER_1$',
                                    'VarValue' => $value
                                );

                                $double_contact[] = $subArray;
                            }
                        }

                        foreach ($deal["DOUBLE_CONTACT_INFO"][1] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER_2$',
                                    'VarValue' => $value
                                );

                                $double_contact[] = $subArray;
                            }
                        }

                    } elseif (count($resContractArrIDInfo) == 3) {

                        $triple_contact = array();

                        foreach ($deal["DOUBLE_CONTACT_INFO"][0] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER_1$',
                                    'VarValue' => $value
                                );

                                $triple_contact[] = $subArray;
                            }
                        }

                        foreach ($deal["DOUBLE_CONTACT_INFO"][1] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER_2$',
                                    'VarValue' => $value
                                );

                                $triple_contact[] = $subArray;
                            }
                        }

                        foreach ($deal["DOUBLE_CONTACT_INFO"][2] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER_3$',
                                    'VarValue' => $value
                                );

                                $triple_contact[] = $subArray;
                            }
                        }

                    }

                    $outputArray = array();

                    foreach ($deal as $key => $value) {

                        if (is_array($value)) {

                        } else {

                            if (empty($value)) {
                                if ($value !== "0") {
                                    $value = "";
                                }
                            }


                            // if ($key == "OPPORTUNITY"){
                            //     $subArray = array(
                            //         'VarName' => '$' . $key . '$',
                            //         'VarValue' => " "
                            //     ); 
                            // }else{
                                $subArray = array(
                                    'VarName' => '$' . $key . '$',
                                    'VarValue' => $value
                                );
                            // }

                        

                            $outputArray[] = $subArray;
                        }
                    }

                    $fullarr = array_merge($outputArray, $outputArray_CODES);

                    $fullarr = array_merge($fullarr, $outputArray_CONTACT);

                    $fullarr = array_merge($fullarr, $outputArray_COMPANY);

                    $fullarr = array_merge($fullarr, $outputArray_CONTACT_old);

                    $fullarr = array_merge($fullarr, $outputArray_COMPANY_old);

                    if (count($resContractArrIDInfo) == 2) {

                        $fullarr = array_merge($fullarr, $double_contact);

                    } elseif (count($resContractArrIDInfo) == 3) {

                        $fullarr = array_merge($fullarr, $triple_contact);


                    }

                    $date_arr = array(
                        "VarName" => '$TODAY_DATE$',
                        "VarValue" => $today
                    );

                    array_push($fullarr, $date_arr);

                    $dbRes = $DB->query('SELECT * FROM b_disk_object WHERE PARENT_ID = 38 AND ID = ' . $doc_id);

                    while ($object = $dbRes->Fetch()) {

                        $fileId = $object["FILE_ID"];

                        $id = $object["ID"];


                        $name_docs = $object["NAME"];

                        $name_docs = explode("$", $name_docs)[2];

                        $filePath = CFile::GetPath($fileId);

                        $filePath = $_SERVER["DOCUMENT_ROOT"] . $filePath;

                        $fileData = file_get_contents($filePath);

                        if ($id == $doc_id) {
                           
                            $convertToPdf = ($file_type == "pdf");

                            if (!$fileData || strlen($fileData) < 100) {
                                echo "File load failed. Path: " . $filePath . " | Size: " . strlen($fileData);
                                exit;
                            }

                            try {
                                $generatedFile = generateDocument($fileData, $fullarr, $convertToPdf);

                                $arForAdd = array('IBLOCK_ID' => 53, 'NAME' => "გენერაცია", 'ACTIVE' => 'Y');
                                $listdate = date("d/m/Y H:i:s");
                                $arPropsOld["DATE_CREATION"] = $listdate;
                                $arPropsOld["DOC_NAME"] = $name_docs;
                                $arPropsOld["USER"] = $user_id_for_info;
                                $arPropsOld["DEAL"] = $deal["ID"];
                                $arPropsOld["CLIENT"] = $deal["CONTACT_ARR"]["ID"];
                                addCIBlockElement($arForAdd, $arPropsOld);

                                ob_end_clean();
                                if ($file_type == "pdf") {
                                    header('Content-Type: application/pdf');
                                    header('Content-Disposition: attachment; filename="document.pdf"');
                                } else {
                                    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                                    header('Content-Disposition: attachment; filename="document.docx"');
                                }
                                header('Content-Length: ' . strlen($generatedFile));
                                echo $generatedFile;
                                exit;
                            } catch (Exception $e) {
                                echo 'Document generation error: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }

        } else {
                $doc_id = $_POST["docs"];

                $deal_id = $_POST["deal_id"];

                $deal = getDealInfo($deal_id);

                $old_owner_contact = array();

                $old_owner_company = array();

                if (!empty($deal["UF_CRM_1720001343"])) {

                    foreach ($deal["UF_CRM_1720001343"] as $key => $value) {

                        $explode_arr = explode("_", $value);

                        if ($explode_arr[0] == "CO") {

                            array_push($old_owner_company, getCompanyInfo($explode_arr[1]));

                        } elseif ($explode_arr[0] == "C") {

                            array_push($old_owner_contact, getContactInfo($explode_arr[1]));

                        }

                    }

                }


                $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($deal["ID"]);
                if($deal["UF_CRM_1755260753"]){
                    array_push($contactIds,explode("_",$deal["UF_CRM_1755260753"])[1]);
                }
                
                $resContractArrIDInfo = array();

                foreach ($contactIds as $thisContactID) {
                    $resContractArrIDInfo[] = getContactInfo($thisContactID);
                }

                $company = getCompanyInfo($deal["COMPANY_ID"]);

                $tech_contact = getContactInfo(1792);
                $tech_company = getCompanyInfo(2);

                foreach ($tech_contact as $key => $value) {

                    $tech_contact[$key] = "";

                }

                foreach ($tech_company as $key => $value) {

                    $tech_company[$key] = "";

                }

                if (count($resContractArrIDInfo) == 2) {

                    $deal["DOUBLE_CONTACT_INFO"] = $resContractArrIDInfo;

                } elseif (count($resContractArrIDInfo) == 3) {

                    $deal["TRIPLE_CONTACT_INFO"] = $resContractArrIDInfo;

                }

                $combinedArray = [];
                foreach ($resContractArrIDInfo as $infoArray) {
                    foreach ($infoArray as $key => $value) {
                        if (!isset($combinedArray[$key])) {
                            $combinedArray[$key] = $value;
                        } else {
                            $combinedArray[$key] .= "," . $value;
                        }
                    }
                }

                $combinedArray_old_company = [];
                foreach ($old_owner_company as $infoArray) {
                    foreach ($infoArray as $key => $value) {
                        if (!isset($combinedArray_old_company[$key])) {
                            $combinedArray_old_company[$key] = $value;
                        } else {
                            $combinedArray_old_company[$key] .= "," . $value;
                        }
                    }
                }

                $combinedArray_old_contact = [];
                foreach ($old_owner_contact as $infoArray) {
                    foreach ($infoArray as $key => $value) {
                        if (!isset($combinedArray_old_contact[$key])) {
                            $combinedArray_old_contact[$key] = $value;
                        } else {
                            $combinedArray_old_contact[$key] .= "," . $value;
                        }
                    }
                }

                $deal["COMPANY_ARR_OLD"] = $combinedArray_old_company;

                if (empty($deal["COMPANY_ARR_OLD"])) {

                    $deal["COMPANY_ARR_OLD"] = $tech_company;

                }

                $deal["CONTACT_ARR_OLD"] = $combinedArray_old_contact;

                if (empty($deal["CONTACT_ARR_OLD"])) {

                    $deal["CONTACT_ARR_OLD"] = $tech_contact;

                }

                $deal["CONTACT_ARR"] = $combinedArray;

                if (empty($deal["CONTACT_ARR"])) {

                    $deal["CONTACT_ARR"] = $tech_contact;

                }

                $deal["COMPANY_ARR"] = $company;

                if (empty($deal["COMPANY_ARR"])) {

                    $deal["COMPANY_ARR"] = $tech_company;

                }

                $file_type = $_POST["type"];

                if (!empty($deal["CONTACT_ARR"]) || !empty($deal["COMPANY_ARR"])) {

                    if (!empty($deal["UF_CRM_1779277729207"])) {

                        $proj = $deal["UF_CRM_1779277729207"];

                        $codes = getCIBlockElementsByFilter(array("IBLOCK_ID" => 47, "PROPERTY_PROJECT_NAME" => $proj));

                        $outputArray_COMPANY = array();

                        foreach ($deal["COMPANY_ARR"] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {

                                    if ($value !== "0") {
                                        $value = "";
                                    }

                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_COM$',
                                    'VarValue' => $value
                                );

                                $outputArray_COMPANY[] = $subArray;
                            }
                        }

                        $outputArray_CONTACT = array();

                        foreach ($deal["CONTACT_ARR"] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_USER$',
                                    'VarValue' => $value
                                );

                                $outputArray_CONTACT[] = $subArray;
                            }
                        }

                        $outputArray_CONTACT_old = array();

                        foreach ($deal["CONTACT_ARR_OLD"] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_OLD_CON$',
                                    'VarValue' => $value
                                );

                                $outputArray_CONTACT_old[] = $subArray;
                            }
                        }

                        $outputArray_COMPANY_old = array();

                        foreach ($deal["COMPANY_ARR_OLD"] as $key => $value) {
                            if (is_array($value)) {

                            } else {
                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }

                                $subArray = array(
                                    'VarName' => '$' . $key . '_OLD_COM$',
                                    'VarValue' => $value
                                );

                                $outputArray_COMPANY_old[] = $subArray;
                            }
                        }

                        $outputArray_CODES = array();

                        foreach ($codes as $key => $value) {

                            if (empty($value["TEXT"])) {
                                if ($value !== "0") {
                                    $value["TEXT"] = "";
                                }
                            }

                            $subArray = array(
                                'VarName' => '$' . $value["NAME"] . '$',
                                'VarValue' => htmlspecialchars_decode($value["TEXT"])
                            );

                            $outputArray_CODES[] = $subArray;
                        }

                        if (count($resContractArrIDInfo) == 2) {

                            $double_contact = array();

                            foreach ($deal["DOUBLE_CONTACT_INFO"][0] as $key => $value) {
                                if (is_array($value)) {

                                } else {
                                    if (empty($value)) {
                                        if ($value !== "0") {
                                            $value = "";
                                        }
                                    }

                                    $subArray = array(
                                        'VarName' => '$' . $key . '_USER_1$',
                                        'VarValue' => $value
                                    );

                                    $double_contact[] = $subArray;
                                }
                            }

                            foreach ($deal["DOUBLE_CONTACT_INFO"][1] as $key => $value) {
                                if (is_array($value)) {

                                } else {
                                    if (empty($value)) {
                                        if ($value !== "0") {
                                            $value = "";
                                        }
                                    }

                                    $subArray = array(
                                        'VarName' => '$' . $key . '_USER_2$',
                                        'VarValue' => $value
                                    );

                                    $double_contact[] = $subArray;
                                }
                            }

                        } elseif (count($resContractArrIDInfo) == 3) {

                            $triple_contact = array();

                            foreach ($deal["DOUBLE_CONTACT_INFO"][0] as $key => $value) {
                                if (is_array($value)) {

                                } else {
                                    if (empty($value)) {
                                        if ($value !== "0") {
                                            $value = "";
                                        }
                                    }

                                    $subArray = array(
                                        'VarName' => '$' . $key . '_USER_1$',
                                        'VarValue' => $value
                                    );

                                    $triple_contact[] = $subArray;
                                }
                            }

                            foreach ($deal["DOUBLE_CONTACT_INFO"][1] as $key => $value) {
                                if (is_array($value)) {

                                } else {
                                    if (empty($value)) {
                                        if ($value !== "0") {
                                            $value = "";
                                        }
                                    }

                                    $subArray = array(
                                        'VarName' => '$' . $key . '_USER_2$',
                                        'VarValue' => $value
                                    );

                                    $triple_contact[] = $subArray;
                                }
                            }

                            foreach ($deal["DOUBLE_CONTACT_INFO"][2] as $key => $value) {
                                if (is_array($value)) {

                                } else {
                                    if (empty($value)) {
                                        if ($value !== "0") {
                                            $value = "";
                                        }
                                    }

                                    $subArray = array(
                                        'VarName' => '$' . $key . '_USER_3$',
                                        'VarValue' => $value
                                    );

                                    $triple_contact[] = $subArray;
                                }
                            }

                        }

                        $outputArray = array();

                        foreach ($deal as $key => $value) {




                            if (is_array($value)) {

                            } else {

                                if (empty($value)) {
                                    if ($value !== "0") {
                                        $value = "";
                                    }
                                }



                                    $subArray = array(
                                        'VarName' => '$' . $key . '$',
                                        'VarValue' => $value
                                    );




                                $outputArray[] = $subArray;
                            }
                        }

                        $fullarr = array_merge($outputArray, $outputArray_CODES);

                        $fullarr = array_merge($fullarr, $outputArray_CONTACT);

                        $fullarr = array_merge($fullarr, $outputArray_COMPANY);

                        $fullarr = array_merge($fullarr, $outputArray_CONTACT_old);

                        $fullarr = array_merge($fullarr, $outputArray_COMPANY_old);

                        if (count($resContractArrIDInfo) == 2) {

                            $fullarr = array_merge($fullarr, $double_contact);

                        } elseif (count($resContractArrIDInfo) == 3) {

                            $fullarr = array_merge($fullarr, $triple_contact);


                        }

                        $date_arr = array(
                            "VarName" => '$TODAY_DATE$',
                            "VarValue" => $today
                        );

                        array_push($fullarr, $date_arr);

                        $dbRes = $DB->query('SELECT * FROM b_disk_object WHERE PARENT_ID = 38 AND ID = ' . $doc_id);

                        while ($object = $dbRes->Fetch()) {

                            $fasdaklebuli = $deal['OPPORTUNITY'];

                            $arFilter = array("PROPERTY_DEAL" => $deal_id);

                            $grafikiJson = getCIBlockElementsByFilter($arFilter);

                            $arFilter = array("IBLOCK_ID" => 64, "PROPERTY_DEAL" => $deal_id);
                            $dadasturebuligrafiki = getCIBlockElementsByFilter($arFilter);


                            $arFilterForSale = array(
                                "IBLOCK_ID" => 18, 
                                "PROPERTY_DEAL" => $deal_id,                                
                            );
                            $ganvadebebiSale = getCIBlockElementsByFilter($arFilterForSale);


                            $grafikiTable = '';
                            $grafikiTableGEO = '';


                            
                            $arFilter = array("IBLOCK_ID" => 68,"PROPERTY_DEAL"=>$deal_id);
                            $restructurizacia = getCIBlockElementsByFilter($arFilter);

                            if($restructurizacia){

                                $lastElement = end($restructurizacia);
                               
                                $grafikJson = $lastElement["JSON"];

                                $jsonRes = str_replace("&quot;", "\"", $grafikJson);
                                $jsonRes = json_decode($jsonRes, true);
                                $grafikRes = $jsonRes['data'];

                                $restructGrafik = generateGrafikiForRestruct($grafikRes,"GEO");
                                $restructGrafikEng = generateGrafikiForRestruct($grafikRes,"ENG");


                                $date_arr_table = array(
                                    "VarName" => 'grapik_geo_rest',
                                    "VarValue" => $restructGrafik,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);

                                $date_arr_table = array(
                                    "VarName" => 'grapik_eng_rest',
                                    "VarValue" => $restructGrafikEng,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);
                            }


                            if ($dadasturebuligrafiki) {

                                $danarti_content = array();
                                foreach ($dadasturebuligrafiki as $singleGrafik) {

                                    $tanxaExploded = explode("|", $singleGrafik["TANXA"]);

                                    $tanxaNum = $tanxaExploded[0];

                                    $darchTanxa = $darchTanxa - $tanxaNum;

                                    $darchTanxa = round($darchTanxa, 2);

                                    $arPush["N"] = $nomeri;
                                    $arPush["TARIGI"] = $singleGrafik["TARIGI"];
                                    $arPush["TANXA_NUMBR"] = $tanxaNum;

                                    $nomeri++;
                                    array_push($danarti_content, $arPush);
                                }
                                $grafikiTable = grafikisGeneracia1_eng($danarti_content, $fasdaklebuli);
                                $grafikiTableGEO = grafikisGeneracia1($danarti_content, $fasdaklebuli);

                                $date_arr_table = array(
                                    "VarName" => 'grapik_geo',
                                    "VarValue" => $grafikiTableGEO,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);

                                $date_arr_table = array(
                                    "VarName" => 'grapik_eng',
                                    "VarValue" => $grafikiTable,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);

                            }elseif($ganvadebebiSale){

                                $data = array();
                                foreach ($ganvadebebiSale as $ganvadebaSale) {

                                    $tanxaExploded = explode("|", $ganvadebaSale["TANXA"]);

                                    $tanxaNum = $tanxaExploded[0];

                                    $arPush["payment"] = $ganvadebaSale["PLAN_TYPE"];
                                    $arPush["date"] = $ganvadebaSale["TARIGI"];
                                    $arPush["amount"] = $tanxaNum;

                                    array_push($data, $arPush);
                                }

                                $salesGrafic=generateGrafikiForSale($data, $fasdaklebuli, "GEO");
                                $salesGraficEng=generateGrafikiForSale($data, $fasdaklebuli, "ENG");
                              

                                $date_arr_table = array(
                                    "VarName" => 'grapik_geo_sale',
                                    "VarValue" => $salesGrafic,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);


                                $date_arr_table = array(
                                    "VarName" => 'grapik_eng_sale',
                                    "VarValue" => $salesGraficEng,
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);


                            }else {
                                $date_arr_table = array(
                                    "VarName" => 'grapik_geo',
                                    "VarValue" => '',
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);

                                $date_arr_table = array(
                                    "VarName" => 'grapik_eng',
                                    "VarValue" => '',
                                    "VarType" => 'T'
                                );

                                array_push($fullarr, $date_arr_table);
                            }


                            $dealId = $deal['ID'];
                            $productTable = generateProductsTable($dealId);


                                $productTableArr = array(
                                    "VarName" => 'products_eng',
                                    "VarValue" => $productTable,
                                    "VarType" => 'T'
                                );
                                array_push($fullarr, $productTableArr);



                                $dealId = $deal['ID'];
                                $productTable = generateProductsTablegeo($dealId);
    
    
                                    $productTableArr = array(
                                        "VarName" => 'products_geo',
                                        "VarValue" => $productTable,
                                        "VarType" => 'T'
                                    );
                                    array_push($fullarr, $productTableArr);



                                    $fileId = $object["FILE_ID"];
                                    $id = $object["ID"];
                                    $name_docs = $object["NAME"];
                                    $name_docs_parts = explode("$", $name_docs);
                                    $name_docs = count($name_docs_parts) > 2 ? $name_docs_parts[2] : $name_docs;                                    
                                    // Get real file path from b_file
                                    $fileRow = $DB->query('SELECT SUBDIR, FILE_NAME FROM b_file WHERE ID = ' . (int)$fileId)->Fetch();
                                    if ($fileRow) {
                                        $filePath = $_SERVER["DOCUMENT_ROOT"] . '/upload/' . $fileRow["SUBDIR"] . '/' . $fileRow["FILE_NAME"];
                                    } else {
                                        $filePath = $_SERVER["DOCUMENT_ROOT"] . CFile::GetPath($fileId);
                                    }
                                    
                                    $fileData = file_get_contents($filePath);

                            if ($id == $doc_id) {
                         

                                $convertToPdf = ($file_type == "pdf");

                                if (!$fileData || strlen($fileData) < 100) {
                                    echo "File load failed. Path: " . $filePath . " | Size: " . strlen($fileData);
                                    exit;
                                }

                                try {
                                    $generatedFile = generateDocument($fileData, $fullarr, $convertToPdf);

                                    $arForAdd = array('IBLOCK_ID' => 53, 'NAME' => "გენერაცია", 'ACTIVE' => 'Y');
                                    $listdate = date("d/m/Y H:i:s");
                                    $arPropsOld["DATE_CREATION"] = $listdate;
                                    $arPropsOld["DOC_NAME"] = $name_docs;
                                    $arPropsOld["USER"] = $user_id_for_info;
                                    $arPropsOld["DEAL"] = $deal["ID"];
                                    $arPropsOld["CLIENT"] = $deal["CONTACT_ARR"]["ID"];
                                    addCIBlockElement($arForAdd, $arPropsOld);

                                    ob_end_clean();
                                    if ($file_type == "pdf") {
                                        header('Content-Type: application/pdf');
                                        header('Content-Disposition: attachment; filename="document.pdf"');
                                    } else {
                                        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                                        header('Content-Disposition: attachment; filename="document.docx"');
                                    }
                                    header('Content-Length: ' . strlen($generatedFile));
                                    echo $generatedFile;
                                    exit;
                                } catch (Exception $e) {
                                    echo 'Document generation error: ' . $e->getMessage();
                                }
                            }
                        }
                } else {
                    $empty_get = true;
                    $error_code .= " ,Empty Project Field";

                }
            } else {

                    $empty_get = true;
                    $error_code .= " ,Empty Contact or Company";

                }

            
        }

    
    }



//if($NotAuthorized) {
//    $USER->Logout();
//}
//else{
//    $USER->Authorize($user_id_for_info);
//}
?>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        background: #F0F4F8;
        font-family: 'Inter', sans-serif;
        color: #1a202c;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }

    .shell { width: 100%; max-width: 480px; }

    .page-head {
        text-align: center;
        margin-bottom: 28px;
    }
    .page-head h1 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 22px;
        font-weight: 700;
        color: #1a202c;
        margin-bottom: 4px;
    }
    .page-head p {
        font-size: 13px;
        color: #718096;
    }

    .card {
        background: #ffffff;
        border-radius: 16px;
        padding: 32px;
        box-shadow: 0 2px 8px rgba(0,0,0,.06), 0 8px 32px rgba(0,0,0,.06);
    }

    .field-group { margin-bottom: 20px; }

    .field-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .7px;
        color: #718096;
        margin-bottom: 7px;
    }

    select.styled {
        width: 100%;
        padding: 11px 36px 11px 14px;
        border: 1.5px solid #E2E8F0;
        border-radius: 10px;
        background: #F7FAFC;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        color: #2D3748;
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23718096' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 13px center;
        cursor: pointer;
        outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    select.styled:focus {
        border-color: #4299E1;
        box-shadow: 0 0 0 3px rgba(66,153,225,.15);
        background-color: #fff;
    }

    .format-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .format-option { position: relative; }
    .format-option input[type="radio"] {
        position: absolute; opacity: 0; width: 0; height: 0;
    }
    .format-option label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 11px 14px;
        border: 1.5px solid #E2E8F0;
        border-radius: 10px;
        background: #F7FAFC;
        font-size: 13px;
        font-weight: 500;
        color: #718096;
        cursor: pointer;
        transition: all .18s;
        user-select: none;
    }
    .format-option label svg {
        width: 15px; height: 15px;
        stroke: currentColor; fill: none;
        stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        flex-shrink: 0;
    }
    .format-option input[type="radio"]:checked + label {
        border-color: #4299E1;
        background: #EBF8FF;
        color: #2B6CB0;
    }
    .format-option label:hover {
        border-color: #CBD5E0;
        color: #2D3748;
        background: #EDF2F7;
    }

    .divider {
        height: 1px;
        background: #EDF2F7;
        margin: 24px 0;
    }

    .btn-submit {
        width: 100%;
        padding: 13px 20px;
        border: none;
        border-radius: 10px;
        background: #3182CE;
        color: #fff;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: background .2s, transform .15s, box-shadow .2s;
        box-shadow: 0 2px 8px rgba(49,130,206,.3);
    }
    .btn-submit svg {
        width: 16px; height: 16px;
        stroke: #fff; fill: none;
        stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }
    .btn-submit:hover {
        background: #2B6CB0;
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(49,130,206,.35);
    }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit:disabled { opacity: .65; cursor: not-allowed; transform: none; }

    .error-box {
        background: #FFF5F5;
        border: 1.5px solid #FEB2B2;
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 13px;
        color: #C53030;
        text-align: center;
    }
</style>
</head>
<body>

<div class="shell">
    <div class="page-head">
        <h1>დოკუმენტის გენერაცია</h1>
        <p>აირჩიეთ შაბლონი და ფაილის ფორმატი</p>
    </div>

    <div class="card">
        <form method="post" action="<?php echo $_SERVER['PHP_SELF'] . '?dealid=' . $dealid; ?>" id="docForm">
            <input name="deal_id" id="deal_id" type="hidden">
            <input name="popup"   id="popup"   type="hidden">

            <div class="field-group">
                <label class="field-label" for="docs">შაბლონი</label>
                <select name="docs" id="docs" class="styled"></select>
            </div>

            <div class="field-group">
                <label class="field-label">ფორმატი</label>
                <div class="format-row">
                    <div class="format-option">
                        <input type="radio" name="type" id="type_pdf" value="pdf" checked>
                        <label for="type_pdf">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            PDF
                        </label>
                    </div>
                    <div class="format-option">
                        <input type="radio" name="type" id="type_docx" value="docx">
                        <label for="type_docx">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            Word
                        </label>
                    </div>
                </div>
            </div>

            <div class="divider"></div>

            <button type="submit" class="btn-submit">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                გენერაცია და ჩამოტვირთვა
            </button>
        </form>
    </div>
</div>

<script>
    let files        = <?= json_encode($second_filter); ?>;
    let deal_id      = <?= json_encode($dealid); ?>;
    let get          = <?= json_encode($empty_get); ?>;
    let code         = <?= json_encode($error_code); ?>;
    let pop_up       = <?= json_encode($popup_mode); ?>;
    let pop_up_files = <?= json_encode($pop_up_files); ?>;

    if (get) {
        document.querySelector('.card').innerHTML =
            '<div class="error-box">⚠ ' + code + '</div>';
    } else {
        document.getElementById('popup').value   = pop_up;
        document.getElementById('deal_id').value = deal_id;

        var select = document.getElementById('docs');
        var list   = (pop_up === 'ispop') ? pop_up_files : files;

        for (var x = 0; x < list.length; x++) {
            select.innerHTML += '<option value="' + list[x]["ID"] + '">' + list[x]["NAME"] + '</option>';
        }

        document.getElementById('docForm').addEventListener('submit', function() {
            var btn = this.querySelector('.btn-submit');
            btn.textContent = 'მიმდინარეობს...';
            btn.disabled = true;
        });
    }
</script>

</body>
</html>



