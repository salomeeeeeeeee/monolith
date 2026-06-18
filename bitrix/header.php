<?require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog.php");?>

<?php
GLOBAL $USER;
$userID = $USER->GetID();
$userGroups = $USER->GetUserGroupArray();
$URL = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$URLexploded=explode("/",$URL);


if (in_array(28, $userGroups)) {
   
    function getCIBlockElementsByFilterHeader($arFilter = array()) {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return [];
        }

        $arElements = [];
        $arSelect = [
            "ID","IBLOCK_ID","NAME","DATE_ACTIVE_FROM","PROPERTY_*",
            "PREVIEW_PICTURE","DETAIL_PICTURE", "IBLOCK_SECTION_ID"
        ];

        $res = CIBlockElement::GetList([], $arFilter, false, ["nPageSize"=>50], $arSelect);

        while($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps  = $ob->GetProperties();

            $arPushs = [];
            foreach($arFields as $key => $arField) {
                $arPushs[$key] = $arField;
            }
            foreach($arProps as $key => $arProp) {
                $arPushs[$key] = $arProp["VALUE"];
            }

            $arElements[] = $arPushs;
        }

        return $arElements;
    }

    $employeeStatusFilter = ["IBLOCK_ID" => 39,"PROPERTY_EMPLOYEE" =>$userID];
    $employeeStatus = getCIBlockElementsByFilterHeader($employeeStatusFilter);
}



if($reservationDate){
    $reservDateExploded = explode("/",$reservationDate);
    $todaydate = $reservDateExploded[2].$reservDateExploded[1].$reservDateExploded[0];
} else {
    $todaydate=date("Ymd");
}

$currentDate = new DateTime();
$currentDate1 = new DateTime();
$currentDate->modify('+3 days');
$futureDate = $currentDate->format('Ymd');
?>


<script>

var userID = <? echo json_encode($userID, JSON_UNESCAPED_UNICODE); ?>;
var currentDate1 = <? echo json_encode($currentDate1, JSON_UNESCAPED_UNICODE); ?>;
var futureDate = <? echo json_encode($futureDate, JSON_UNESCAPED_UNICODE); ?>;
var userGroups = <? echo json_encode($userGroups, JSON_UNESCAPED_UNICODE); ?>;
url = <? echo json_encode($URLexploded); ?>;

setTimeout(()=>{
    const settingsScript = document.createElement('script');
    settingsScript.textContent = `
        window.gtranslateSettings = {
            "default_language":"en","languages":["ka","en"],"wrapper_selector":".gtranslate_wrapper","flag_size":24};
    `;
    document.body.appendChild(settingsScript);

    const gtranslateScript = document.createElement('script');
    gtranslateScript.src = "https://cdn.gtranslate.net/widgets/latest/flags.js";
    gtranslateScript.defer = true;
    document.body.appendChild(gtranslateScript);

    var logo = document.getElementsByClassName('menu-items-header');
    var translatehtml = `<div class="gtranslate_wrapper"></div>`;
    logo[0].insertAdjacentHTML('beforebegin', translatehtml);
}, 1000);


pathname = window.location.pathname.split("/");

if(pathname[2] == "deal" || pathname[2] == "lead"){

    if(userGroups.includes(28)){

        let employeeStatus = <? echo json_encode($employeeStatus[0], JSON_UNESCAPED_UNICODE); ?>;

        console.log(employeeStatus);
        let toolbarContainer = document.getElementById("uiToolbarContainer");

        let statusDiv = document.createElement("div");
        statusDiv.style.marginLeft = "10px";

        let statusSelect = document.createElement("select");
        statusSelect.style.padding = "5px 10px";
        statusSelect.style.borderRadius = "6px";
        statusSelect.style.fontWeight = "bold";
        statusSelect.style.cursor = "pointer";
        statusSelect.style.color = "white";

        let activeOption = document.createElement("option");
        activeOption.value = "Active";
        activeOption.text = "Active";

        let inactiveOption = document.createElement("option");
        inactiveOption.value = "Inactive";
        inactiveOption.text = "Inactive";

        statusSelect.appendChild(activeOption);
        statusSelect.appendChild(inactiveOption);

        function updateBackground() {
            if (statusSelect.value === "Active") {
                statusSelect.style.backgroundColor = "green";
            } else {
                statusSelect.style.backgroundColor = "red";
            }
        }

        if (employeeStatus && employeeStatus.STATUS === "Active") {
            statusSelect.value = "Active";
        } else {
            statusSelect.value = "Inactive";
        }
        updateBackground();

        function handleStatusChange() {
            updateBackground();
            employeeStatusChange(statusSelect.value);
        }

        handleStatusChange();

        statusSelect.addEventListener("change", handleStatusChange);

        statusDiv.appendChild(statusSelect);
        toolbarContainer.appendChild(statusDiv);

        function employeeStatusChange(status) {
            reqParams = `userId=${userID}&status=${status}`;
            fetch(`${location.origin}/rest/local/changeEmployeeStatus.php?${reqParams}`)
            .then(data => data.json())
            .then(data => {
                console.log(data);
                if(data.status == "timeout"){
                    statusSelect.value = "Inactive";
                    updateBackground();
                }
            })
            .catch(error => {
                console.log(error);
            });
        }
    }
}

var datenow = <? echo json_encode($todaydate, JSON_UNESCAPED_UNICODE); ?>;

if (pathname[2] == "deal") {
    setInterval(() => {
        let datediv = document.querySelector('[name="UF_CRM_1717762131806"]');

        if (datediv) {
            dealIdLink = datediv.parentElement.parentElement.parentElement.parentElement.parentElement.parentElement.children[0].children[1].children[1].getAttribute('href');
            dealIdLinkArr = dealIdLink.split('/');
            dealIdValue = dealIdLinkArr[4];

            fetch(`${location.origin}/rest/local/getReservationDate.php?dealID=${dealIdValue}`)
            .then(data => data.json())
            .then(data => {
                if (datediv.value) {
                    let todaydatearr = datediv.value.split("/");
                    let todaydatestr = todaydatearr[2] + todaydatearr[1] + todaydatearr[0];

                    if (Number(datenow) == Number(todaydatestr)) {
                        datediv.value = "";
                        html = `<div style="margin: 10px;color: red;" id="wrongdatewarning">შეყვანილი თარიღი არასწორია</div>`;
                        let warningdiv = document.getElementById("wrongdatewarning");
                        if (!warningdiv) {
                            datediv.parentElement.insertAdjacentHTML("afterbegin", html);
                        }
                    } else {
                        let warningdiv = document.getElementById("wrongdatewarning");
                        if (warningdiv) {
                            warningdiv.remove();
                        }
                    }
                }
            })
            .catch(error => {
                console.log(error);
            });
        }
    }, 500);
}

if(pathname[1] == "crm"){

    if(userGroups.includes(17)){
        setInterval(()=>{
            let allbutton = document.querySelectorAll(".menu-item-block");
            if(allbutton){
                for(let btn of allbutton){
                    let allowList = ["bx_left_menu_786740379"];
                    if(!allowList.includes(btn.getAttribute("id"))){
                        btn.style.display = "none";
                    } else {
                        btn.style.display = "";
                    }
                }
            }
        }, 250);
    }

}
</script>