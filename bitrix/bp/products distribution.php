<?

//85 bp products distribution

require_once $_SERVER["DOCUMENT_ROOT"] . "/bitrix/bp/deal-allocation-helpers.php";

$root   = $this->GetRootActivity();
$dealID = intval($root->GetVariable("DEAL_ID"));

// თუ ცვლადი არ არის შექმნილი, Deal ID თვითონ workflow-ის დოკუმენტიდან ავიღოთ.
if ($dealID <= 0) {
    $documentId = $this->GetDocumentId();
    $documentValue = is_array($documentId) ? end($documentId) : $documentId;
    $dealID = intval(str_replace("DEAL_", "", (string)$documentValue));
}

$logText    = "";
$allocation = 0;

if ($dealID > 0) {
    $arProducts = CCrmDeal::LoadProductRows($dealID);
    if (count($arProducts) > 1) {
        $logText    = allocation_distributeProducts($dealID, $arProducts);
        $allocation = 1;
    }
}

$this->SetVariable("log", $logText, JSON_UNESCAPED_UNICODE);
$this->SetVariable("allocation", $allocation);
