<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
CJSCore::Init(array("jquery"));

$APPLICATION->SetTitle("MainPageLinks");

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getCIBlockElementsByFilter($arFilter = array(), $sort = array()) {
    $arElements = array();
    $arSelect = array(
        "ID", "IBLOCK_ID", "NAME",
        "PROPERTY_GROUP", "PROPERTY_LINK", "PROPERTY_POSITION", "PROPERTY_SEEGROUP"
    );
    $res = CIBlockElement::GetList($sort, $arFilter, false, array("nPageSize" => 500), $arSelect);

    while ($ob = $res->GetNextElement()) {
        $fields = $ob->GetFields();
        $properties = $ob->GetProperties();

        $seeGroups = $properties["SEEGROUP"]["VALUE"];

        if (!is_array($seeGroups)) {
            $seeGroups = [$seeGroups];
        }
        $seeGroups = array_unique($seeGroups);

        $element = array_merge($fields, array(
            "GROUP" => $properties["GROUP"]["VALUE"] ?: "Unsorted",
            "LINK" => $properties["LINK"]["VALUE"],
            "POSITION" => intval($properties["POSITION"]["VALUE"]),
            "SEEGROUP" => $seeGroups,
        ));

        $arElements[] = $element;
    }

    return $arElements;
}

// ==== მომხმარებლის ჯგუფების სახელების მიღება ====
global $USER;
$userid = $USER->GetID();

$userGroups = $USER->GetUserGroupArray();

$groupNames = [];
$rsGroups = CGroup::GetList(($by = "c_sort"), ($order = "asc"), ["ID" => implode("|", $userGroups)]);
while ($arGroup = $rsGroups->Fetch()) {
    $groupNames[] = $arGroup["NAME"];
}



// ==== ელემენტების წამოღება ====
$arFilter = array("IBLOCK_ID" => 17);
$elements = getCIBlockElementsByFilter($arFilter);

// printArr($elements);

$uniqueElements = [];
foreach ($elements as $el) {
    $uniqueElements[$el["ID"]] = $el;
}
$elements = array_values($uniqueElements);

$filteredElements = array_filter($elements, function ($el) use ($groupNames) {

    // თუ ველი საერთოდ არ არის შევსებული → ყველას ეჩვენოს
    $seeGroups = $el["SEEGROUP"];
    
    // გავფილტროთ ცარიელი მნიშვნელობები
    if (is_array($seeGroups)) {
        $seeGroups = array_filter($seeGroups, function($v) {
            return !empty($v) && $v !== "(not set)";
        });
    }
    
    if (empty($seeGroups)) {
        return true;
    }

    // მომხმარებლის ჯგუფები პატარა ასოებით
    $lowerGroupNames = array_map('strtolower', $groupNames);

    // თითოეული დატოვებული მნიშვნელობა lowercase-ში
    $seeGroups = array_map('strtolower', $seeGroups);

    // გადაკვეთების შემოწმება
    return count(array_intersect($seeGroups, $lowerGroupNames)) > 0;
});

$groupedElements = [];
foreach ($filteredElements as $element) {
    $groupedElements[$element["GROUP"]][] = $element;
}

uksort($groupedElements, function ($a, $b) {
    return $a === "Unsorted" ? 1 : ($b === "Unsorted" ? -1 : strcmp($a, $b));
});

foreach ($groupedElements as $group => &$items) {
    usort($items, function ($a, $b) {
        return $a["POSITION"] <=> $b["POSITION"];
    });
}
unset($items);

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Grouped Elements</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@600&family=Montserrat:wght@500&family=Roboto:wght@400&display=swap"
        rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
        }

        main {
            background-color: #ffffff;
        }

        body {
            background-color: #ffffff;
            padding: 20px;
        }

        /* Logo Container */
        .logo-container {
            text-align: center;
        }

        .logo-container img {
            max-width: 300px;
            height: auto;
        }

        /* Header */
        h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 36px;
            font-weight: 600;
            text-align: center;
            color: #3389cf;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 20px;
            margin-bottom: 30px;
        }

        /* Main Container */
        .row {
            background-color: #ffffff;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border-radius: 10px;
        }

        /* Group Titles */
        .group {
            margin-bottom: 15px;
            border-radius: 8px;
            overflow: hidden;
        }

        .group-title {
            font-family: 'Montserrat', sans-serif;
            background-color: #3389cf;   /* 🔵 always blue */
            color: #ffffff;              /* white text */
            padding: 14px 20px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 25px;
            border: 2px solid #3389cf;
            transition: all 0.3s ease-in-out;
            text-align: center;
        }


        /* Elements List */
        .group-elements {
            display: none;
            padding: 10px;
            background: #ffffff;
            border-radius: 5px;
        }

        /* Buttons */
        .button1 {
            display: block;
            width: 100%;
            margin: 8px 0;
            padding: 14px 20px;
            border-radius: 25px;
            background: #ffffff;
            border: 2px solid #3389cf;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease-in-out;
            text-decoration: none;
            color: #3389cf;
        }

        .button1:hover {
            background: #3389cf;
            transform: translateY(-2px);
            color: #ffffff;
        }

        .background {
            background-color: #ffffff;
        }

        main#air-workarea-content {
            background: #ffffff !important;
        }
            
    </style>
</head>

<body class="background">

    <div class="logo-container">
    <img src="/crm/main/newLogo.jpg" alt="Company Logo">
    </div>

    <div class="row">
        <div id="groups-container"></div>
    </div>

    <script>
        let groupedElements = <?php echo json_encode($groupedElements); ?>;
        let container = document.getElementById("groups-container");

        for (let group in groupedElements) {
            let groupDiv = document.createElement("div");
            groupDiv.classList.add("group");

            let groupTitle = document.createElement("div");
            groupTitle.classList.add("group-title");
            groupTitle.innerText = group;
            groupTitle.onclick = function () {
                let elementsDiv = this.nextElementSibling;
                elementsDiv.style.display = elementsDiv.style.display === "block" ? "none" : "block";
            };

            let elementsDiv = document.createElement("div");
            elementsDiv.classList.add("group-elements");

            groupedElements[group].forEach(element => {
                let button = document.createElement("a");
                button.href = element.LINK;
                button.target = "_blank";
                button.innerHTML = `<button class="button1">${element.NAME}</button>`;
                elementsDiv.appendChild(button);
            });

            groupDiv.appendChild(groupTitle);
            groupDiv.appendChild(elementsDiv);
            container.appendChild(groupDiv);
        }
    </script>

</body>

</html>
<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>