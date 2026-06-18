<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Title");
CModule::IncludeModule('webservice');
CModule::IncludeModule("crm");
CModule::IncludeModule('bizproc');

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getLatinName($str) {
    $GEO_LAT = array("ქ" => "q", "წ" => "ts", "ჭ" => "ch", "ე" => "e", "რ" => "r", "ღ" => "gh", "ტ" => "t", "თ" => "t", "ყ" => "y", "უ" => "u", "ი" => "i", "ო" =>"o", "პ" => "p", "ა" => "a", "ს" => "s", "შ" => "sh", "დ" => "d", "ფ" => "p", "გ" => "g", "ჰ" => "h", "ჯ" => "j", "ჟ" => "zh", "კ" => "k", "ლ" => "l", "ზ" => "z", "ხ" => "x", "ძ" => "dz", "ც" => "c", "ჩ" => "ch", "ვ" => "v", "ბ" => "b", "ნ" => "n", "მ" => "m");
    $newStr = "";
    $str = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
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

$resArr = array();
$dealId = $_POST["dealId"];

if($_FILES["file"] && is_numeric($dealId)) {   
    $fl = $_FILES["file"];

    if(!empty($fl["name"]) && !empty($fl["tmp_name"])) {
        $folderPath = "/home/bitrix/www/crm/deal/images/".$dealId;

        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0777, true);
        }
        $filestring = $fl["tmp_name"];
        define('UPLOAD_DIR', "$folderPath/");    
        // $fl["name"] = getLatinName($fl['name']);       

        $file = UPLOAD_DIR . basename($fl['name']);
        move_uploaded_file($fl['tmp_name'], $file);

        $arIMAGE = CFile::MakeFileArray($file);

        $arIMAGE["MODULE_ID"] = "main";

        $fileID = CFile::SaveFile($arIMAGE, 'main/');

        $resArr["file"] = $fl;
        if($fileID) {
            $resArr["status"] = 200;
            $resArr["message"] = "Uploaded Success";
            $resArr["uploaded"] = $fileID;
            $resArr["fileName"] = $fl["name"];
        }
        else {
            $resArr["status"] = 404;
            $resArr["message"] = "Uploaded Error";
            $resArr["uploaded"] = $fileID;
        }
    }
    else {
        $resArr["status"] = 405;
        $resArr["message"] = "Method not Found";
    }
}
else {
    $resArr["status"] = 406;
    $resArr["message"] = "Not Acceptable";
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?></sizeof>