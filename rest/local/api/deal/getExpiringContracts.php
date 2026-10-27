<?php
// 24 სთ-ში ხელშეკრულების ვადა ეწურება: "ხელშეკრულების" ეტაპზე მყოფი დილები, რომელთა 5 სამუშაო დღე 24 სთ-ში იწურება.
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/contract_deadline.php");

$deals = [];

if ($USER->IsAuthorized()) {
    $currentUserId = (int)$USER->GetID();
    // 9 - ნუკი პაიჭაძე (სეილს ჰედი): ყველა დილს ხედავს, როგორც ადმინი
    $showAllDeals = in_array($currentUserId, [1, 9], true) || $USER->IsAdmin();

    $now = time();
    $expiring = [];
    foreach (contractDeadlineDeals($showAllDeals ? 0 : $currentUserId) as $deal) {
        if ($deal["DEADLINE"] > $now && $deal["DEADLINE"] <= $now + 86400) {
            $expiring[(int)$deal["ID"]] = $deal["DEADLINE"];
        }
    }

    if ($expiring) {
        $res = CCrmDeal::GetListEx(
            ["ID" => "ASC"],
            ["@ID" => array_keys($expiring), "CHECK_PERMISSIONS" => "N"],
            false,
            false,
            ["ID", "CONTACT_ID", "COMPANY_ID", "CONTACT_FULL_NAME", "COMPANY_TITLE", "UF_CRM_1779277729207", "UF_CRM_1779277613798"]
        );
        while ($deal = $res->Fetch()) {
            $clientName = "";
            $clientId = 0;
            $clientType = "";

            if (!empty($deal["CONTACT_ID"])) {
                $clientId = (int)$deal["CONTACT_ID"];
                $clientName = (string)$deal["CONTACT_FULL_NAME"];
                $clientType = "contact";
            } elseif (!empty($deal["COMPANY_ID"])) {
                $clientId = (int)$deal["COMPANY_ID"];
                $clientName = (string)$deal["COMPANY_TITLE"];
                $clientType = "company";
            }

            // პროექტი / ბინის № (saveApartment.php-ის ველები)
            $apartment = trim((string)$deal["UF_CRM_1779277729207"]);
            if (trim((string)$deal["UF_CRM_1779277613798"]) !== "") {
                $apartment .= ($apartment !== "" ? ", " : "") . "N" . trim((string)$deal["UF_CRM_1779277613798"]);
            }

            $deals[] = [
                "ID" => (int)$deal["ID"],
                "CLIENT_ID" => $clientId,
                "CLIENT_NAME" => $clientName,
                "CLIENT_TYPE" => $clientType,
                "APARTMENT" => $apartment,
                "DEADLINE" => date("d/m/Y H:i", $expiring[(int)$deal["ID"]]),
            ];
        }
    }
}

$response = [
    "deals" => $deals,
    "count" => count($deals),
];

ob_end_clean();
header("Content-Type: application/json; charset=utf-8");
echo json_encode($response, JSON_UNESCAPED_UNICODE);
