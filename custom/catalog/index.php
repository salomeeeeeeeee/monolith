<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Catalog");
use Bitrix\Main\Loader;

Loader::includeModule('crm');
if (!Loader::includeModule('iblock')) {
    http_response_code(500);
    die(json_encode(['error' => 'iblock module not installed/loaded']));
}

// ── Field code aliases (must match get.php defines) ──
define('F_BLOCK',      '_L24CUB');
define('F_FLOOR',      '_FTRIDL');
define('F_NUMBER',     '__6KWOWZ');
define('F_TOTAL_AREA', '__173JA5');
define('F_TYPE',       '__X1GCRZ');
define('F_PRICE_USD',  'PRICE');
define('F_KVM_USD',    '__6ZWTER');
define('F_SALE',       '_UQIM2I');
define('F_CADASTRAL',  '__51MODL');
define('F_SECTOR',     '_3BU0JH');


// ------------------------------ FUNCTIONS ------------------------------
function getNBG($date) {
    $url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    $seb = json_decode(file_get_contents($url));
    return $seb[0]->currencies[0]->rate;
}

function getDealByFilter($arFilter) {
    $res = CCrmDeal::GetList(["ID" => "ASC"], $arFilter, []);
    $result = [];
    if ($row = $res->Fetch()) $result[] = $row;
    return $result;
}

function getDealProds($dealID) {
    $nbg     = getNBG(date("Y-m-d"));
    $baseUrl = "https://" . preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);
    $noImage = $baseUrl . "/catalog/projects/resources/noimage.jpg";
    $galleryFields = ['erteulis_gegma','erteuli_render','sartulis_gegma','sartulis_render','project_pics','company_logo','binis_gegmareba','render_3D','sartulis2D','binisNaxazi2D'];

    $prods    = CCrmDeal::LoadProductRows($dealID);
    $products = [];

    foreach ($prods as $prod) {
        $res = CIBlockElement::GetList([], ["ID" => $prod["PRODUCT_ID"], "IBLOCK_ID" => 14], false, ["nPageSize" => 1], ["ID","NAME","IBLOCK_ID","IBLOCK_SECTION_ID","PROPERTY_*"]);
        if (!($ob = $res->GetNextElement())) continue;

        $arFields = $ob->GetFields();
        $arProps  = $ob->GetProperties();
        $item     = [];
        foreach ($arFields as $k => $v) $item[$k] = $v;
        foreach ($arProps  as $code => $prop) $item[$code] = $prop["VALUE"];

        foreach ($galleryFields as $fc) {
            $path = CFile::GetPath($item[$fc] ?? null);
            $item[$fc] = $path ? $baseUrl . $path : "";
        }
        $legacyMap = ['image'=>'erteulis_gegma','image2'=>'binis_gegmareba','image3'=>'render_3D','image4'=>'sartulis2D','image5'=>'binisNaxazi2D'];
        foreach ($legacyMap as $lk => $sf) $item[$lk] = $item[$sf] ?: $noImage;

        $priceUsd = 0;
        if (!empty($item[F_PRICE_USD]) && is_numeric($item[F_PRICE_USD])) {
            $priceUsd = (float)$item[F_PRICE_USD];
        } else {
            $cp = CPrice::GetBasePrice($item["ID"]);
            if (isset($cp["PRICE"]) && $cp["PRICE"] > 0) $priceUsd = (float)$cp["PRICE"];
        }
        $item["PRICE"]      = round($priceUsd, 2);
        $item["PRICE_GEL"]  = round($priceUsd * $nbg, 2);
        $item["_P64GYD"]    = $item["_P64GYD"]    ?? "";
        $item["Number"]     = $item[F_NUMBER]      ?? "";
        $item["FLOOR"]      = $item[F_FLOOR]       ?? "";
        $item["TOTAL_AREA"] = $item[F_TOTAL_AREA]  ?? "";
        $item["__X1GCRZ"]   = $item[F_TYPE]        ?? "";
        $item["_L24CUB"]    = $item[F_BLOCK]       ?? "";
        $item["_3BU0JH"]    = $item[F_SECTOR]      ?? "";

        $products[] = $item;
    }
    return $products;
}

function getProjects() {
    $res = CIBlockSection::GetList(
        ["SORT" => "ASC"],
        ["IBLOCK_ID" => 14, "IBLOCK_SECTION_ID" => 10, "DEPTH_LEVEL" => 2, "ACTIVE" => "Y"],
        false,
        ["ID","NAME","DEPTH_LEVEL","IBLOCK_SECTION_ID"]
    );
    $projects = [];
    while ($s = $res->GetNext()) $projects[] = ["ID" => $s["ID"], "NAME" => $s["NAME"]];
    return $projects;
}

// ------------------------------ MAIN ------------------------------
global $USER;
$dealID      = $_GET["dealid"] ?? null;
$deal        = $dealID ? getDealByFilter(["ID" => $dealID]) : [];
$products    = $dealID ? getDealProds($dealID) : [];
$productsIds = array_column($products, 'ID');

$projects = getProjects();
usort($projects, fn($a,$b) => strnatcasecmp($a['NAME'], $b['NAME']));
$nbg = getNBG(date("Y-m-d"));

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:        #f0f2f7;
            --bg2:       #ffffff;
            --bg3:       #f5f6fa;
            --bg4:       #eaecf4;
            --border:    #dde1ee;
            --border2:   #c8cde0;
            --text:      #1a1d2e;
            --text2:     #5a6080;
            --text3:     #9399b2;
            --accent:    #3b5bdb;
            --accent2:   #4c6ef5;
            --accent-dim:rgba(59,91,219,.08);
            --accent-glow:rgba(59,91,219,.18);
            --green:     #0ca678;
            --green-dim: rgba(12,166,120,.10);
            --amber:     #e67700;
            --amber-dim: rgba(230,119,0,.10);
            --red:       #d92b3a;
            --red-dim:   rgba(217,43,58,.10);
            --blue:      #3b5bdb;
            --purple:    #7048e8;
            --radius:    7px;
            --radius2:   12px;
            --mono:      'JetBrains Mono', monospace;
            --display:   'Syne', 'Noto Sans Georgian', sans-serif;
            --body:      'Noto Sans Georgian', sans-serif;
            --shadow:    0 1px 3px rgba(26,29,46,.07), 0 4px 16px rgba(26,29,46,.06);
            --shadow2:   0 2px 8px rgba(26,29,46,.10), 0 8px 32px rgba(26,29,46,.08);
        }

        *, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

        body {
            font-family: var(--body);
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* ═══ LAYOUT ═══ */
        .containerCatalog {
            display: flex; padding: 18px; gap: 16px;
            align-items: flex-start;
        }

        /* ═══ FILTER SIDEBAR ═══ */
        #filterContainer {
            width: 215px; flex-shrink: 0;
            height: fit-content; padding: 16px 14px;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius2);
            box-shadow: var(--shadow);
            position: sticky; top: 18px;
        }
        #filterContainer h2 {
            font-family: var(--display);
            font-size: 9px; font-weight: 700;
            letter-spacing: 2px; text-transform: uppercase;
            color: var(--accent);
            margin: 16px 0 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        #filterContainer h2:first-child { margin-top: 0; }

        #filterContainer select {
            width: 100%; margin-bottom: 8px;
            padding: 7px 28px 7px 10px;
            border-radius: var(--radius);
            border: 1px solid var(--border2);
            background: var(--bg3);
            color: var(--text); font-size: 11px; font-family: var(--body);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239399b2' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 9px center;
            cursor: pointer; transition: border-color .2s, box-shadow .2s;
        }
        #filterContainer select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-dim); }

        .dropdown-checkbox { width:100%; margin-bottom:8px; position:relative; }
        .dropdown-header {
            padding: 7px 10px; background: var(--bg3);
            border: 1px solid var(--border2); border-radius: var(--radius);
            cursor: pointer; color: var(--text2); font-size: 11px; font-family: var(--body);
            transition: border-color .2s, box-shadow .2s;
            display: flex; justify-content: space-between; align-items: center;
        }
        .dropdown-header::after { content:"›"; font-size:14px; transform:rotate(90deg); display:inline-block; color:var(--text3); }
        .dropdown-header:hover { border-color:var(--accent); color:var(--text); }

        .dropdown-content {
            display: none; flex-direction: column;
            position: absolute; top: calc(100% + 4px); left:0; right:0;
            background: var(--bg2); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 6px; z-index: 200;
            box-shadow: var(--shadow2); max-height: 220px; overflow-y: auto;
        }
        .dropdown-content::-webkit-scrollbar { width:4px; }
        .dropdown-content::-webkit-scrollbar-thumb { background:var(--border2); border-radius:2px; }
        .dropdown-content label {
            display:flex; align-items:center; gap:8px; padding:5px 7px;
            border-radius:5px; font-size:11px; color:var(--text2);
            cursor:pointer; transition:background .15s, color .15s; font-family:var(--body);
        }
        .dropdown-content label:hover { background:var(--accent-dim); color:var(--accent); }
        .dropdown-content input[type=checkbox] { accent-color:var(--accent); width:13px; height:13px; flex-shrink:0; }

        /* Sector group header inside block dropdown */
        .block-dropdown-sector-header {
            font-size: 9px; font-weight: 700; letter-spacing: 1.5px;
            text-transform: uppercase; color: var(--accent);
            padding: 6px 7px 3px; margin-top: 4px;
            border-top: 1px solid var(--border);
        }
        .block-dropdown-sector-header:first-child { margin-top: 0; border-top: none; }

        .range-filter label { font-size:10px; letter-spacing:.5px; color:var(--text3); text-transform:uppercase; }
        .range-row { display:flex; align-items:center; gap:6px; margin-top:5px; }
        .range-row span { color:var(--text3); font-size:11px; }
        .range-row input {
            width:100%; padding:6px 8px; border:1px solid var(--border2);
            border-radius:var(--radius); background:var(--bg3); color:var(--text);
            font-size:11px; font-family:var(--mono); transition:border-color .2s, box-shadow .2s;
        }
        .range-row input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-dim); }
        .range-row input::placeholder { color:var(--text3); }

        #addFiltersBtn, #clean, #search {
            padding:7px 10px; width:100%; border-radius:var(--radius);
            font-size:11px; font-weight:600; font-family:var(--body);
            cursor:pointer; margin-bottom:6px; transition:all .2s; letter-spacing:.2px;
        }
        #addFiltersBtn { background:var(--bg3); border:1px solid var(--border2); color:var(--text2); }
        #addFiltersBtn:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-dim); }
        #search { background:var(--accent); border:1px solid var(--accent); color:#fff; font-weight:700; }
        #search:hover { background:var(--accent2); border-color:var(--accent2); box-shadow:0 4px 14px var(--accent-glow); transform:translateY(-1px); }
        #clean { background:transparent; border:1px solid var(--border2); color:var(--text3); }
        #clean:hover { border-color:var(--red); color:var(--red); background:var(--red-dim); }

        .filter-chip {
            display:flex; align-items:center; margin:3px 0; padding:4px 8px;
            background:var(--accent-dim); border:1px solid rgba(59,91,219,.2);
            border-radius:5px; font-size:11px; color:var(--accent);
        }
        .remove-chip { margin-left:auto; background:transparent; border:none; cursor:pointer; color:var(--text3); font-size:14px; line-height:1; transition:color .15s; }
        .remove-chip:hover { color:var(--red); }
        .extraFilters {
            background:none; border:none; color:var(--text2); font-size:11px;
            cursor:pointer; padding:5px 7px; width:100%; text-align:left;
            border-radius:5px; transition:all .15s; font-family:var(--body);
        }
        .extraFilters:hover { background:var(--accent-dim); color:var(--accent); }
        .disabled-button { opacity:.4; cursor:not-allowed !important; pointer-events:none; }

        /* ═══ LEGEND BAR ═══ */
        #gareAvtosadgomebi { margin-top:16px; }
        #legendBar {
            display:flex; align-items:center; flex-wrap:wrap; gap:6px;
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--radius2); padding:10px 14px; margin-bottom:12px;
            box-shadow: var(--shadow);
        }
        .legend-item {
            display:flex; align-items:center; gap:7px; font-size:11px; font-weight:500;
            color:var(--text2); padding:5px 11px; border-radius:6px;
            border:1px solid transparent; cursor:pointer; transition:all .2s; font-family:var(--body);
        }
        .legend-item:hover { background:var(--bg3); color:var(--text); border-color:var(--border); }
        .legend-item.legend-active.status-active-frame   { background:var(--green-dim); border-color:var(--green); color:var(--green); }
        .legend-item.legend-active.status-reserved-frame { background:var(--amber-dim); border-color:var(--amber); color:var(--amber); }
        .legend-item.legend-active.status-sold-frame     { background:var(--red-dim); border-color:var(--red); color:var(--red); }
        .legend-item.legend-active.status-nfs-frame      { background:rgba(112,72,232,.08); border-color:var(--purple); color:var(--purple); }
        .legend-item.legend-active.status-queue-frame    { background:rgba(219,39,119,.08); border-color:#db2777; color:#db2777; }
        .legend-count {
            font-family:var(--mono); font-size:11px; font-weight:700;
            padding:1px 7px; border-radius:4px; background:var(--bg3);
            color:var(--text); min-width:22px; text-align:center;
            border: 1px solid var(--border);
        }
        .legend-color { width:9px; height:9px; border-radius:50%; flex-shrink:0; }

        /* ═══ APT GRID ═══ */
        #apsDisplayWrapper {
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--radius2); padding:14px;
            overflow:hidden; max-width: 100%;
            box-shadow: var(--shadow);
        }
        #apsDisplay {
            display:flex; flex-direction:column; gap:5px;
            overflow-x:auto; overflow-y:auto; max-width:94%;
            padding:4px 12px 4px 4px; transition:max-width .3s;
        }
        #apsDisplay::-webkit-scrollbar { height:15px; }
        #apsDisplay::-webkit-scrollbar-track { background:var(--bg3); border-radius:3px; }
        #apsDisplay::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }
        #apsDisplay::-webkit-scrollbar-thumb:hover { background:var(--accent); }

        #floors {
            padding-top:50px; display:flex; flex-direction:column;
            justify-content:flex-start; gap:5px; flex-shrink:0; background:var(--bg2);
        }
        .floor-row { display:flex; flex-direction:row; align-items:center; gap:50px; padding:2px 10px; flex-wrap:nowrap; min-width:fit-content; }
        .floor-label {
            font-family:var(--mono); font-size:11px; font-weight:700; color:var(--text2);
            border-right:2px solid var(--accent); width:30px; height:30px;
            display:flex; justify-content:center; align-items:center;
        }
        .blockOnFloor { display:flex; gap:4px; height:30px; flex-shrink:0; }

        /* ═══ SECTOR DISPLAY ═══ */
        .sector-grid-wrapper {
            display: flex;
            flex-direction: row;
            gap: 16px;
            align-items: flex-start;
            overflow-x: auto;
            padding-bottom: 6px;
        }
        .sector-grid-wrapper::-webkit-scrollbar { height:15px; }
        .sector-grid-wrapper::-webkit-scrollbar-track { background:var(--bg3); border-radius:3px; }
        .sector-grid-wrapper::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }

        .sector-container {
            border-radius: 12px;
            padding: 8px 10px 10px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .sector-label {
            font-family: var(--display);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 8px;
            padding: 4px 6px 6px;
            border-bottom-width: 1px;
            border-bottom-style: solid;
        }
        .sector-blocks-row {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: flex-start;
        }
        .sector-block-col {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex-shrink: 0;
        }
        .sector-block-label {
            font-family: var(--mono);
            font-size: 11px;
            font-weight: 700;
            color: var(--text2);
            text-align: center;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid var(--border);
            margin-bottom: 4px;
        }
        .sector-floor-row {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 4px;
            min-height: 30px;
            align-items: flex-start;
            align-content: flex-start;
        }
        .sector-floor-labels {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex-shrink: 0;
            padding-top: 0;
        }
        .sector-floor-labels-spacer {
            height: 40px;
        }

        .sector-inline-floor-label {
            font-family: var(--mono);
            font-size: 10px;
            font-weight: 700;
            color: var(--text2);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 8px;
            min-height: 30px;
            border-right: 2px solid var(--accent);
            flex-shrink: 0;
            gap: 5px;
            margin-bottom: 5px;
        }

        /* ═══ APT TILES ═══ */
        .apt {
            width:30px; height:30px; border-radius:6px;
            display:flex; flex-direction:column; justify-content:center; align-items:center;
            font-family:var(--mono); font-size:8px; font-weight:700;
            cursor:pointer; transition:all .18s cubic-bezier(.34,1.56,.64,1);
            gap:1px; position:relative; border:1px solid transparent;
        }
        .apt:hover { transform:translateY(-3px) scale(1.14); z-index:10; box-shadow:var(--shadow2); }
        .dimmed { opacity:.22; filter:grayscale(70%); pointer-events:none; }

        .status-active     { background:#7cdf9c; border-color:#0ca678; color:#087a58; }
        .status-reserved   { background:#ffcf37; border-color:#e67700; color:#b35900; }
        .status-queue      { background:#b69bff; border-color:#7048e8; color:#5433c1; }
        .status-sold       { background:#f67581; border-color:#d92b3a; color:#a8202c; }
        .status-notforsale { background:#c0c0c0; border-color:#4e4046; color:#4e3f45; }
        .status-unknown    { background:var(--bg3); border-color:var(--border2); color:var(--text2); }

        /* ═══ PRODUCTS BOX ═══ */
        #productsBox {
            display:flex; min-width:160px; width:fit-content; height:38px;
            padding:4px 12px; background:var(--bg3); border:1px solid var(--border);
            border-radius:var(--radius); gap:6px; align-items:center;
            box-shadow:var(--shadow);
        }
        .border-text {
            font-size:9px; letter-spacing:.5px; text-transform:uppercase;
            color:var(--text3); position:absolute; top:-9px; left:10px;
            background:var(--bg2); padding:0 4px;
        }
        #saveBtn {
            height:32px; padding:0 16px; border:none; border-radius:var(--radius);
            background:var(--accent); color:#fff; font-size:11px; font-weight:700;
            font-family:var(--body); cursor:pointer; transition:all .2s; margin-left:8px;
        }
        #saveBtn:hover { background:var(--accent2); box-shadow:0 4px 14px var(--accent-glow); transform:translateY(-1px); }

        #apartmentPopup {
            width: 0; min-width: 0; flex-shrink: 0;
            height: calc(100vh - 36px);
            background: var(--bg2); border-left: 0px solid var(--border);
            box-shadow: none;
            overflow-y: auto; overflow-x: hidden;
            transition: width .35s cubic-bezier(.4,0,.2,1),
                        min-width .35s cubic-bezier(.4,0,.2,1),
                        border-left-width .35s,
                        box-shadow .35s;
            display: flex; flex-direction: column;
            position: sticky; top: 18px;
        }
        #apartmentPopup.active {
            width: 300px; min-width: 300px;
            border-left-width: 1px;
            box-shadow: -4px 0 40px rgba(26,29,46,.12);
        }
        #apartmentPopup::-webkit-scrollbar { width:8px; }
        #apartmentPopup::-webkit-scrollbar-track { background:var(--bg3); }
        #apartmentPopup::-webkit-scrollbar-thumb { background:var(--border2); border-radius:4px; }
        #apartmentPopup::-webkit-scrollbar-thumb:hover { background:var(--accent); }

        .popup-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:16px 16px 12px; border-bottom:1px solid var(--border);
            position:sticky; top:0; background:var(--bg2); z-index:10;
        }
        .popup-header h3 {
            font-family:var(--display); font-size:15px; font-weight:700;
            color:var(--text); max-width:200px; line-height:1.2;
        }
        #popupClose {
            width:28px; height:28px; background:var(--bg3); border:1px solid var(--border);
            border-radius:5px; color:var(--text2); font-size:18px; cursor:pointer;
            display:flex; align-items:center; justify-content:center; transition:all .2s; line-height:1;
        }
        #popupClose:hover { background:var(--red-dim); border-color:var(--red); color:var(--red); }

        .popup-body { padding:0 14px 20px; }
        .popup-buttons { padding:10px 0 6px; display:flex; justify-content:center; }
        #popupSelectBtn {
            display:none; justify-content:center; width:100%;
            padding:9px 14px; border:none; border-radius:var(--radius);
            font-weight:700; font-size:11px; font-family:var(--body); letter-spacing:.3px;
            cursor:pointer; background:var(--accent); color:#fff; transition:all .2s;
        }
        #popupSelectBtn:hover { background:var(--accent2); box-shadow:0 4px 14px var(--accent-glow); transform:translateY(-1px); }

        .sandrosBtns { display:none; }
        #popupCalc  { display:none !important; }
        #popupOffer { display:none !important; }

        .block-section { margin-bottom:2px; animation:sectionIn .3s ease both; }
        @keyframes sectionIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

        .block-header {
            display:flex; align-items:center; gap:8px;
            padding:8px 14px 7px; margin:10px -14px 0;
            background:var(--bg3); border-top:1px solid var(--border); border-bottom:1px solid var(--border);
        }
        .block-header-icon {
            width:18px; height:18px; border-radius:4px;
            background:var(--accent-dim); border:1px solid rgba(59,91,219,.2);
            display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .block-header-icon svg { width:10px; height:10px; }
        .block-header-title {
            font-family:var(--display); font-size:9px; font-weight:700;
            color:var(--accent); letter-spacing:1.5px; text-transform:uppercase;
        }

        .block-fields { padding:6px 0 0; display:flex; flex-direction:column; gap:2px; }
        .field-row {
            display:flex; flex-direction:row; align-items:center;
            justify-content:space-between; gap:8px; padding:5px 8px;
            border-radius:5px; background:transparent; border:1px solid transparent;
            transition:background .15s, border-color .15s; cursor:default;
        }
        .field-row.row-stacked { flex-direction:column; align-items:flex-start; gap:3px; }
        .field-row:hover { background:var(--bg3); border-color:var(--border); }
        .field-row.is-main { background:var(--bg3); border-color:var(--border); padding:7px 8px; }
        .field-row.is-main.apt-active     { background:#edfdf5; border-color:#b2f0d8; }
        .field-row.is-main.apt-reserved   { background:#fff8e6; border-color:#ffd580; }
        .field-row.is-main.apt-sold       { background:#fff0f1; border-color:#ffa8ae; }
        .field-row.is-main.apt-queue      { background:#fdf2f8; border-color:#f9a8d4; }
        .field-row.is-main.apt-notforsale { background:#f3f0ff; border-color:#c4b5fd; }

        .field-label {
            font-size:10px; font-weight:600; color:var(--text2);
            display:flex; align-items:center; gap:5px;
            flex-shrink:1; min-width:0; word-break:break-word;
        }
        .field-label::before { content:""; width:3px; height:3px; border-radius:50%; background:var(--accent); flex-shrink:0; opacity:.5; }
        .field-value {
            font-size:11px; font-family:var(--mono); padding:2px 8px; border-radius:4px;
            white-space:nowrap; flex-shrink:0;
            background:var(--bg4); color:var(--text);
            border:1px solid var(--border);
        }
        .field-value-long { width:100%; font-size:11px; color:var(--text2); line-height:1.55; word-break:break-word; font-family:var(--body); }
        .field-value-text { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .field-value-text.expanded { display:block; overflow:visible; -webkit-line-clamp:unset; }
        .show-more-btn { background:none; border:none; color:var(--accent); font-size:10px; font-weight:700; cursor:pointer; padding:3px 0 0; font-family:var(--body); }

        .badge-active   { background:var(--green-dim); color:var(--green); border-color:rgba(12,166,120,.3); }
        .badge-reserved { background:var(--amber-dim); color:var(--amber); border-color:rgba(230,119,0,.3); }
        .badge-sold     { background:var(--red-dim); color:var(--red); border-color:rgba(217,43,58,.3); }
        .badge-queue    { background:rgba(219,39,119,.10); color:#db2777; border-color:rgba(219,39,119,.3); }
        .badge-nfs      { background:rgba(112,72,232,.08); color:var(--purple); border-color:rgba(112,72,232,.3); }
        .badge-no-info  { background:var(--bg3); color:var(--text3); border-color:var(--border); font-style:italic; }

        .image-gallery { display:grid; grid-template-columns:repeat(2,1fr); gap:6px; padding:8px 0 4px; }
        .gallery-item { position:relative; border-radius:6px; overflow:hidden; background:var(--bg3); border:1px solid var(--border); aspect-ratio:4/3; cursor:pointer; transition:all .2s; }
        .gallery-item:hover { border-color:var(--accent); box-shadow:0 4px 16px var(--accent-glow); transform:scale(1.02); }
        .gallery-item img { width:100%; height:100%; object-fit:cover; display:block; }
        .gallery-item img.loading { opacity:.3; }
        .gallery-label { position:absolute; bottom:0; left:0; right:0; background:linear-gradient(to top,rgba(26,29,46,.8),transparent); color:#fff; font-size:9px; padding:12px 6px 5px; opacity:0; transition:opacity .2s; }
        .gallery-item:hover .gallery-label { opacity:1; }
        .gallery-zoom { position:absolute; top:5px; right:5px; width:18px; height:18px; background:var(--accent); border-radius:4px; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .2s; font-size:10px; color:#fff; }
        .gallery-item:hover .gallery-zoom { opacity:1; }

        #toggleDetailsBtn {
            display:none; margin-top:8px; background:none; border:1px solid var(--border);
            color:var(--text3); font-size:10px; font-weight:600; letter-spacing:.5px;
            cursor:pointer; padding:6px 0; width:100%; text-align:center;
            border-radius:5px; font-family:var(--body); transition:border-color .2s, color .2s, background .2s;
        }
        #toggleDetailsBtn:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-dim); }
        #popupDetailsWrapper { overflow:hidden; max-height:0; transition:max-height .4s ease,opacity .3s ease; opacity:0; }
        #popupDetailsWrapper.open { opacity:1; max-height:600px; }

        #exportExcelBtn {
            margin-left:auto !important; padding:5px 14px !important;
            border:1px solid var(--border) !important; border-radius:var(--radius) !important;
            background:var(--bg3) !important; color:var(--text2) !important;
            font-size:11px !important; font-weight:600 !important;
            cursor:pointer !important; transition:all .2s !important; font-family:var(--body) !important;
        }
        #exportExcelBtn:hover { border-color:var(--accent) !important; color:var(--accent) !important; background:var(--accent-dim) !important; }

        /* ═══ CAROUSEL ═══ */
        #imageCarouselPopup { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,12,20,.94); backdrop-filter:blur(14px); z-index:3000; justify-content:center; align-items:center; opacity:0; transition:opacity .3s; }
        #imageCarouselPopup.active { display:flex; opacity:1; }
        .carousel-container { position:relative; width:90vw; max-width:90vw; height:85vh; display:flex; align-items:center; justify-content:center; }
        .carousel-image-wrapper { display:flex; justify-content:center; align-items:center; width:80vw; height:80vh; }
        .carousel-image { max-width:100%; max-height:100%; object-fit:contain; border-radius:10px; box-shadow:0 16px 64px rgba(0,0,0,.7); }
        .carousel-arrow { position:absolute; top:50%; transform:translateY(-50%); background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.25); color:#fff; font-size:28px; width:48px; height:48px; border-radius:8px; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; z-index:10; }
        .carousel-arrow:hover { background:rgba(255,255,255,.2); border-color:rgba(255,255,255,.5); transform:translateY(-50%) scale(1.06); }
        .carousel-arrow.prev { left:40px; }
        .carousel-arrow.next { right:40px; }
        #carouselClose { position:absolute; top:20px; right:30px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.25); color:#fff; font-size:22px; width:40px; height:40px; border-radius:8px; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; }
        #carouselClose:hover { background:rgba(217,43,58,.5); border-color:rgba(217,43,58,.8); transform:rotate(90deg); }
        .carousel-counter { position:absolute; bottom:24px; left:50%; transform:translateX(-50%); background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); color:#fff; padding:5px 16px; border-radius:20px; font-size:12px; font-family:var(--mono); backdrop-filter:blur(8px); }
        .carousel-dots { position:absolute; bottom:-32px; left:50%; transform:translateX(-50%); display:flex; gap:6px; }
        .carousel-dot { width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,.3); cursor:pointer; transition:all .3s; }
        .carousel-dot.active { background:#fff; width:20px; border-radius:3px; }
    </style>
</head>
<body>
<div class="containerCatalog">

    <!-- FILTER SIDEBAR -->
    <div id="filterContainer">
        <h2>ძირითადი ფილტრი</h2>
        <select id="projects"><option value="" disabled selected>პროექტი *</option></select>

        <div class="dropdown-checkbox" id="blockFilter">
            <div class="dropdown-header">ბლოკი</div>
            <div class="dropdown-content"></div>
        </div>
        <div class="dropdown-checkbox" id="statusFilter">
            <div class="dropdown-header">სტატუსი</div>
            <div class="dropdown-content"></div>
        </div>
        <div class="dropdown-checkbox" id="apartmentTypeFilter">
            <div class="dropdown-header">ფართის ტიპი</div>
            <div class="dropdown-content"></div>
        </div>

        <div class="range-filter" style="margin-bottom:10px;">
            <label>სრული ფართი</label>
            <div class="range-row">
                <input type="number" id="aptMin" placeholder="Min">
                <span>-</span>
                <input type="number" id="aptMax" placeholder="Max">
            </div>
        </div>

        <h2>დამატებითი ფილტრი</h2>
        <div class="filter-chips" id="extraFilterChips"></div>
        <div class="dropdown-checkbox" id="addFiltersDropdown">
            <button id="addFiltersBtn" type="button">+</button>
            <div class="dropdown-content" id="extraFiltersDropdown" style="overflow:auto;max-height:200px;">
                <input type="text" id="filterSearch" placeholder="ძებნა..." style="width:94%;margin-bottom:6px;padding:5px;border:1px solid #ccc;border-radius:6px;font-size:12px;">
                <div id="filterButtonsContainer"></div>
            </div>
        </div>
        <button id="clean"  type="button">გასუფთავება</button>
        <button id="search" type="button">ძიება</button>
    </div>

    <!-- MAIN AREA -->
    <div style="flex-grow:1;min-width:0;max-width:94%;display:flex;flex-direction:column;gap:10px;">
        <div id="legendBar">
            <div class="legend-item status-active-frame"><span class="legend-color status-active"></span> თავისუფალი <span class="legend-count" id="count-active">0</span></div>
            <div class="legend-item status-reserved-frame"><span class="legend-color status-reserved"></span> დაჯავშნილი <span class="legend-count" id="count-reserved">0</span></div>
            <div class="legend-item status-sold-frame"><span class="legend-color status-sold"></span> გაყიდული <span class="legend-count" id="count-sold">0</span></div>
            <div class="legend-item status-nfs-frame"><span class="legend-color status-notforsale"></span> NFS <span class="legend-count" id="count-nfs">0</span></div>
            <div class="legend-item status-queue-frame"><span class="legend-color status-queue"></span> ჯავშნის რიგში <span class="legend-count" id="count-queue">0</span></div>
            <button id="exportExcelBtn" onclick="exportToExcel()">⬇ Excel</button>
        </div>

        <div id="productsBoxWrapper" style="display:none;position:relative;align-items:center;gap:0;">
            <div id="productsBox"></div>
            <button id="saveBtn">შენახვა</button>
        </div>

        <div id="apsDisplayWrapper">
            <div style="display:flex;">
                <div id="floors"></div>
                <div id="apsDisplay">იტვირთება...</div>
            </div>
        </div>
        <div id="gareAvtosadgomebi"></div>
    </div>

    <!-- POPUP -->
    <div id="apartmentPopup">
        <div class="popup-header">
            <h3 id="popupTitle">ბინის დეტალები</h3>
            <div style="display:flex;align-items:center;gap:4px;">
                <a id="popupOffer" target="_blank"><button class="sandrosBtns">შეთავაზება</button></a>
                <a id="popupCalc"  target="_blank"><button class="sandrosBtns">კალკულატორი</button></a>
                <button id="popupClose">&times;</button>
            </div>
        </div>
        <div class="popup-body">
            <div class="popup-buttons"><button id="popupSelectBtn">დამატება</button></div>
            <div id="popupBlockSections"></div>
            <button id="toggleDetailsBtn">► დამატებითი დეტალები</button>
            <div id="popupDetailsWrapper"><ul id="popupDetailsMore"></ul></div>
        </div>
    </div>

    <!-- CAROUSEL -->
    <div id="imageCarouselPopup">
        <button id="carouselClose">&times;</button>
        <div class="carousel-container">
            <button class="carousel-arrow prev">‹</button>
            <div class="carousel-image-wrapper"><img id="carouselImage" class="carousel-image" src="" alt=""></div>
            <button class="carousel-arrow next">›</button>
        </div>
        <div class="carousel-counter"><span id="currentImageNum">1</span> / <span id="totalImages">1</span></div>
        <div class="carousel-dots"></div>
    </div>
</div>

<script>

const fmt = n => n.toLocaleString("en-US", { maximumFractionDigits: 2 });

// ── PHP → JS ──
let dealID      = <?php echo json_encode($dealID); ?>;
let deal        = <?php echo json_encode($deal ?? []); ?>;
let products    = <?php echo json_encode($products ?? []); ?>;
let productsIds = <?php echo json_encode($productsIds ?? []); ?>;
let nbg         = <?php echo json_encode($nbg); ?>;
let projects    = <?php echo json_encode($projects); ?>;

// ── Field code constants (mirror PHP defines) ──
const F_BLOCK      = '_L24CUB';
const F_FLOOR      = '_FTRIDL';
const F_NUMBER     = '__6KWOWZ';
const F_TOTAL_AREA = '__173JA5';
const F_TYPE       = '__X1GCRZ';
const F_PRICE_USD  = 'PRICE';
const F_KVM_USD    = '__6ZWTER';
const F_SALE       = '_UQIM2I';
const F_CADASTRAL  = '__51MODL';
const F_SECTOR     = '_3BU0JH';

// ── Runtime state ──
let openedOnDeal    = Array.isArray(deal) && deal.length > 0;
let stage_id        = openedOnDeal ? deal[0].STAGE_ID : "";
let allowedStages   = ["PREPARATION","PREPAYMENT_INVOICE","EXECUTING"];
let inAllowedStages = true;
let productsCache   = [];
let propertyMap     = {};
let isDeletingOnly  = false;

// Image fields resolved to URLs by PHP
const IMAGE_CODES = new Set(["erteulis_gegma","erteuli_render","sartulis_gegma","sartulis_render","project_pics","company_logo"]);

// Fields to always skip in popup detail list
const SKIP_CODES = new Set([
    "ID","NAME","~ID","~NAME","IBLOCK_ID","~IBLOCK_ID","IBLOCK_SECTION_ID","~IBLOCK_SECTION_ID",
    "IBLOCK_ELEMENT_ID","PREVIEW_PICTURE","DETAIL_PICTURE","~DETAIL_PICTURE","MORE_PHOTO",
    "image","image2","image3","image4","image5",
    "binis_gegmareba","render_3D","sartulis2D","binisNaxazi2D",
    "erteulis_gegma","erteuli_render","sartulis_gegma","sartulis_render","project_pics","company_logo",
    "OWNER_DEAL","OWNER_CONTACT","OWNER_CONTACT_NAME","DEAL_RESPONSIBLE","DEAL_RESPONSIBLE_NAME","QUEUE",
    "PRICE","PRICE_GEL",
    "_P64GYD","Number","FLOOR","__X1GCRZ","_L24CUB",
]);

const MAIN_CODES = ["_P64GYD","Number","__X1GCRZ","_L24CUB","_3BU0JH","FLOOR","TOTAL_AREA"];

// ── Status helpers ──
const STATUS_MAP = {
    "თავისუფალი":    { tile:"active",     apt:"apt-active",     badge:"badge-active" },
    "დაჯავშნილი":    { tile:"reserved",   apt:"apt-reserved",   badge:"badge-reserved" },
    "გაყიდული":      { tile:"sold",       apt:"apt-sold",       badge:"badge-sold" },
    "ჯავშნის რიგში": { tile:"queue",      apt:"apt-queue",      badge:"badge-queue" },
    "NFS":           { tile:"notforsale", apt:"apt-notforsale", badge:"badge-nfs" },
};
function tileCls(s)  { return "status-" + (STATUS_MAP[s]?.tile  || "unknown"); }
function aptCls(s)   { return STATUS_MAP[s]?.apt   || ""; }
function badgeCls(s) { return STATUS_MAP[s]?.badge || ""; }

// ── Sector color palette ──
const SECTOR_COLORS = [
    { bg: "rgba(59,91,219,.06)",   border: "rgba(59,91,219,.22)",   label: "#3b5bdb" },
    { bg: "rgba(12,166,120,.06)",  border: "rgba(12,166,120,.22)",  label: "#0ca678" },
    { bg: "rgba(112,72,232,.06)",  border: "rgba(112,72,232,.22)",  label: "#7048e8" },
    { bg: "rgba(230,119,0,.06)",   border: "rgba(230,119,0,.22)",   label: "#e67700" },
    { bg: "rgba(217,43,58,.06)",   border: "rgba(217,43,58,.22)",   label: "#d92b3a" },
    { bg: "rgba(0,152,199,.06)",   border: "rgba(0,152,199,.22)",   label: "#0098c7" },
    { bg: "rgba(148,96,13,.06)",   border: "rgba(148,96,13,.22)",   label: "#94600d" },
];

// ══════════════════════════════════════════
//  DEAL INIT
// ══════════════════════════════════════════
const productsBoxWrapper = document.getElementById("productsBoxWrapper");
if (openedOnDeal) {
    document.getElementById("apsDisplay").style.maxWidth = "93%";
    document.querySelector(".containerCatalog").style.paddingLeft = "0";
    productsBoxWrapper.style.display = "flex";
    productsBoxWrapper.innerHTML += `<span class="border-text">დილზე დამატებული ბინები</span>`;

    if (Array.isArray(products) && products.length > 0) {
        const pb = document.getElementById("productsBox");
        products.forEach(apt => pb.appendChild(makeDealTile(apt)));
    }
}

function makeDealTile(apt) {
    const tile = document.createElement("div");
    tile.className = `apt ${tileCls(apt["_P64GYD"])}`;
    tile.dataset.id     = apt["ID"];
    tile.dataset.status = apt["_P64GYD"] || "";
    tile.textContent    = apt["Number"] || apt[F_NUMBER] || apt["NAME"];
    if (inAllowedStages && apt["_P64GYD"] === "თავისუფალი") {
        const rm = document.createElement("button");
        rm.textContent = "×"; rm.className = "remove-chip";
        rm.style.cssText = "margin-left:3px;font-size:10px;background:transparent;border:none;cursor:pointer;";
        rm.onclick = () => {
            if (document.getElementById("saveBtn").style.display === "none" && allowedStages.includes(stage_id))
                document.getElementById("saveBtn").style.display = "";
            tile.remove();
            const el = document.querySelector(`#apsDisplay .apt[data-id="${apt["ID"]}"]`);
            if (el && !productsIds.includes(apt["ID"])) el.classList.remove("dimmed");
            document.getElementById("popupSelectBtn").style.display = "flex";
        };
        tile.appendChild(rm);
    }
    return tile;
}

// ══════════════════════════════════════════
//  PROJECT SELECT → FETCH
// ══════════════════════════════════════════
const projectSelect = document.getElementById("projects");
let _fetchController = null;

// Populate project options
if (Array.isArray(projects) && projects.length > 0) {
    projects.forEach(p => {
        const o = document.createElement("option");
        o.value = p["ID"]; o.textContent = p["NAME"];
        projectSelect.appendChild(o);
    });
}

// Determine which project to auto-load
let projectToLoad = null;

if (openedOnDeal && Array.isArray(products) && products.length > 0) {
    const pp = parseInt(products[0]["IBLOCK_SECTION_ID"]);
    if (pp) projectToLoad = pp;
}

if (!projectToLoad && Array.isArray(projects) && projects.length > 0) {
    projectToLoad = projects[0]["ID"];
}

if (projectToLoad) {
    projectSelect.value = projectToLoad;
    setTimeout(() => projectSelect.dispatchEvent(new Event("change", { bubbles: true })), 100);
}

projectSelect.addEventListener("change", function() {
    const projId = this.value;
    if (!projId) return;

    // Cancel any in-flight fetch for a previous project
    if (_fetchController) _fetchController.abort();
    _fetchController = new AbortController();

    document.getElementById("apsDisplay").innerHTML = "იტვირთება...";

    fetch(`/rest/local/api/projects/get.php?projId=${projId}`, { signal: _fetchController.signal })
        .then(r => r.json())
        .then(data => {
            productsCache = data.products || [];

            if (data.nbg) nbg = data.nbg;

            const FIELD_NAMES = {
                "__9YCWGZ":  "ჯამური ღირებულება $",
                "__6ZWTER":  "კვ/მ ღირებულება $",
                "__VO9RG4":  "პროექტის დასახელება",
                "_L24CUB":   "ბლოკი",
                "__X1GCRZ":  "უძრავი ქონების ტიპი",
                "_D599QA":   "სადარბაზო",
                "_FTRIDL":   "სართული",
                "__51MODL":  "საკადასტრო კოდი",
                "_3BU0JH":   "სექტორი",
                "__6KWOWZ":  "უძრავი ქონების №",
                "__173JA5":  "სრული ფართი",
                "__US58ND":  "შიდა ფართი",
                "__BL1XXK":  "საზაფხულო ფართი",
                "__WX6YWZ":  "ოთახების რაოდენობა",
                "__KYRP1L":  "საძინებლების რაოდენობა",
                "__9H8XS9":  "სველი წერტილის რაოდენობა",
                "_P64GYD":   "სტატუსი",
                "Number":    "უძრავი ქონების №",
                "FLOOR":     "სართული",
                "TOTAL_AREA":"სრული ფართი",
                "_MVA3NL":   "ბლოკი",
            };
            propertyMap = {};
            Object.entries(FIELD_NAMES).forEach(([code, name]) => {
                propertyMap[code] = { name, type: "S" };
            });
            (data.properties || []).forEach(p => {
                propertyMap[p.CODE] = { name: p.NAME, type: p.TYPE };
            });

            buildBlockCheckboxes(data.blocks || []);
            renderProductsByBlock(productsCache, data.blocks || []);
            updateDynamicFilters(productsCache);
            fillAdditionalFilters();

            productsIds.forEach(id => {
                const el = document.querySelector(`#apsDisplay .apt[data-id="${id}"]`);
                if (el) { el.classList.add("dimmed"); el.style.outline="2px solid #ff343a"; el.style.transform="scale(1.2)"; }
            });
        })
        .catch(err => { if (err.name !== "AbortError") console.error("Fetch error:", err); });
});

// ══════════════════════════════════════════
//  BLOCK CHECKBOXES (sector-aware)
// ══════════════════════════════════════════
function buildBlockCheckboxes(blocks) {
    const c = document.querySelector("#blockFilter .dropdown-content");
    c.innerHTML = "";

    const hasSectors = productsCache.some(p => p[F_SECTOR] && p[F_SECTOR] !== "");

    if (hasSectors) {
        const sectorBlockMap = {};
        productsCache.forEach(p => {
            const sec = p[F_SECTOR] || "–";
            const blk = p[F_BLOCK]  || "";
            if (!blk) return;
            if (!sectorBlockMap[sec]) sectorBlockMap[sec] = new Set();
            sectorBlockMap[sec].add(blk);
        });
        if (blocks.includes("P")) {
            if (!sectorBlockMap["P"]) sectorBlockMap["P"] = new Set();
            sectorBlockMap["P"].add("P");
        }

        Object.keys(sectorBlockMap).sort().forEach(sec => {
            const hdr = document.createElement("div");
            hdr.className = "block-dropdown-sector-header";
            hdr.textContent = sec === "P" ? "გარე ავტოსადგომები" : "სექტ. " + sec;
            c.appendChild(hdr);

            [...sectorBlockMap[sec]].sort((a, b) => {
                const na = parseInt(a.match(/\d+/)?.[0] ?? "9999");
                const nb = parseInt(b.match(/\d+/)?.[0] ?? "9999");
                return na !== nb ? na - nb : a.localeCompare(b);
            }).forEach(block => {
                const lbl = document.createElement("label");
                lbl.innerHTML = block === "P"
                    ? `<input type="checkbox" value="${block}"> გარე ავტოსადგომები`
                    : `<input type="checkbox" value="${block}"> ${block}`;
                c.appendChild(lbl);
            });
        });
    } else {
        blocks.sort((a, b) => {
            const na = parseInt(a.match(/\d+/)?.[0] ?? "9999");
            const nb = parseInt(b.match(/\d+/)?.[0] ?? "9999");
            return na !== nb ? na - nb : (a.match(/[A-Z]+/)?.[0]||a).localeCompare(b.match(/[A-Z]+/)?.[0]||b);
        }).forEach(block => {
            const lbl = document.createElement("label");
            lbl.innerHTML = block === "P"
                ? `<input type="checkbox" value="${block}"> გარე ავტოსადგომები`
                : `<input type="checkbox" value="${block}"> ${block}`;
            c.appendChild(lbl);
        });
    }
}

// ══════════════════════════════════════════
//  RENDER ROUTER
// ══════════════════════════════════════════
function renderProductsByBlock(allProducts, selectedBlocks) {
    const container = document.getElementById("apsDisplay");
    container.innerHTML = "";

    if (!allProducts || allProducts.length === 0) {
        container.innerHTML = "<p style='color:#999;padding:10px;'>ბინები ვერ მოიძებნა.</p>";
        updateLegendCounts([]);
        return;
    }

    const hasSectors = allProducts.some(p => p[F_SECTOR] && p[F_SECTOR] !== "");

    if (hasSectors) {
        renderBySectors(allProducts, selectedBlocks, container);
    } else {
        renderByBlocks(allProducts, selectedBlocks, container);
    }

    updateLegendCounts(allProducts);
}

// ══════════════════════════════════════════
//  RENDER: BLOCK MODE (original logic)
// ══════════════════════════════════════════
function renderByBlocks(allProducts, selectedBlocks, container) {
    const seenIds  = new Set();
    const products = allProducts.filter(p => {
        if (seenIds.has(p["ID"])) return false;
        seenIds.add(p["ID"]);
        return true;
    });

    const apartments = [];
    const parking    = {};
    const blockPList = [];

    products.forEach(apt => {
        const pt = apt["__X1GCRZ"] || apt[F_TYPE] || "";
        if (pt === "ავტოსადგომი" || pt === "დამხმარე") {
            const b = apt["_L24CUB"] || apt[F_BLOCK] || "";
            if (b === "P") { blockPList.push(apt); return; }
            if (!parking[b]) parking[b] = [];
            parking[b].push(apt);
        } else {
            apartments.push(apt);
        }
    });

    const blocksToShow = [...new Set(selectedBlocks.filter(b => b !== "P"))];

    const byFloor = {};
    apartments.forEach(apt => {
        const f = parseInt(apt["FLOOR"] || apt[F_FLOOR] || 0);
        if (!byFloor[f]) byFloor[f] = [];
        byFloor[f].push(apt);
    });

    const maxPerBlock = {};
    Object.values(byFloor).forEach(fa => {
        const cnt = {};
        fa.forEach(a => { const b = a["_L24CUB"]||""; cnt[b]=(cnt[b]||0)+1; });
        Object.entries(cnt).forEach(([b,c]) => { if(!maxPerBlock[b]||c>maxPerBlock[b]) maxPerBlock[b]=c; });
    });
    const blockWidths = {};
    Object.entries(maxPerBlock).forEach(([b,mx]) => { blockWidths[b] = mx*30+(mx-1)*5; });

    // Block label row
    const labelRow = document.createElement("div");
    labelRow.className = "floor-row"; labelRow.id = "block-labels";
    blocksToShow.forEach(b => {
        const w = blockWidths[b] || 200;
        const d = document.createElement("div");
        d.style.cssText = `width:${w}px;display:flex;align-items:center;justify-content:center;height:30px;flex-shrink:0;font-weight:600;font-size:13px;`;
        d.textContent = b;
        labelRow.appendChild(d);
    });
    container.appendChild(labelRow);

    // Floor labels
    const floorsContainer = document.getElementById("floors");
    floorsContainer.style.display = "";
    floorsContainer.innerHTML = "";
    const uniqueFloors = [...new Set(apartments.map(a => parseInt(a["FLOOR"]||a[F_FLOOR]||0)))].sort((a,b)=>b-a);
    uniqueFloors.forEach(f => {
        const d = document.createElement("div");
        d.className = "floor-label";
        d.innerHTML = `<div>${f}</div>`;
        floorsContainer.appendChild(d);
    });

    // Floor rows
    uniqueFloors.forEach(floorNum => {
        const floorApts = byFloor[floorNum] || [];
        const blockMap  = {};
        blocksToShow.forEach(b => { blockMap[b] = []; });
        floorApts.forEach(a => { const b=a["_L24CUB"]||""; if(blockMap[b]!==undefined) blockMap[b].push(a); });

        const row = document.createElement("div");
        row.className = "floor-row";
        blocksToShow.forEach(blockName => {
            const w   = blockWidths[blockName] || 200;
            const box = document.createElement("div");
            box.className = "blockOnFloor";
            box.style.cssText = `width:${w}px;flex-shrink:0;`;
            (blockMap[blockName]||[]).forEach(apt => box.appendChild(makeAptTile(apt)));
            row.appendChild(box);
        });
        container.appendChild(row);
    });

    // Internal parking row
    floorsContainer.innerHTML += `<div class="floor-label" style="margin-top:10px;border-right:3px solid #9b59b6;visibility:hidden;"><div style="font-size:11px;">P/S</div></div>`;
    const parkRow = document.createElement("div");
    parkRow.className = "floor-row"; parkRow.style.marginTop = "10px";
    blocksToShow.forEach(b => {
        const w     = blockWidths[b] || 200;
        const items = parking[b] || [];
        const box   = document.createElement("div");
        box.style.cssText = `width:${w}px;flex-shrink:0;background:rgba(240,240,245,.6);border-radius:8px;`;
        if (items.length > 0) {
            const lbl = document.createElement("div");
            lbl.style.cssText = "font-size:11px;font-weight:600;color:#666;text-align:center;padding:5px 0 3px;";
            lbl.textContent = "P/S";
            box.appendChild(lbl);
            const wrap = document.createElement("div");
            wrap.style.cssText = "display:flex;flex-wrap:wrap;gap:5px;padding:5px 10px;max-height:240px;overflow-y:auto;";
            items.forEach(item => wrap.appendChild(makeAptTile(item, item["__X1GCRZ"]==="ავტოსადგომი"?"P":"S")));
            box.appendChild(wrap);
        }
        parkRow.appendChild(box);
    });
    container.appendChild(parkRow);

    // Outdoor parking (Block P)
    const outdoorEl = document.getElementById("gareAvtosadgomebi");
    outdoorEl.innerHTML = "";
    if (selectedBlocks.includes("P") && blockPList.length > 0) {
        const wrap = document.createElement("div");
        wrap.style.cssText = "max-width:1000px;background:rgba(240,240,245,.6);border-radius:8px;padding:8px 14px;";
        const h = document.createElement("div");
        h.style.cssText = "font-size:13px;font-weight:600;color:#555;margin-bottom:6px;";
        h.textContent = "გარე ავტოსადგომები";
        wrap.appendChild(h);
        const grid = document.createElement("div");
        grid.style.cssText = "display:flex;flex-wrap:wrap;gap:5px;";
        blockPList.forEach(item => grid.appendChild(makeAptTile(item)));
        wrap.appendChild(grid);
        outdoorEl.appendChild(wrap);
    }
}

// ══════════════════════════════════════════
//  RENDER: SECTOR MODE
// ══════════════════════════════════════════
function renderBySectors(allProducts, selectedBlocks, container) {
    const seenIds  = new Set();
    const products = allProducts.filter(p => {
        if (seenIds.has(p["ID"])) return false;
        seenIds.add(p["ID"]); return true;
    });

    const apartments     = [];
    const nonAptBySector = {}; // [sector][block][floor] = [items]
    const blockPList     = [];

    products.forEach(apt => {
        const pt    = apt["__X1GCRZ"] || apt[F_TYPE]   || "";
        const b     = apt[F_BLOCK]    || apt["_L24CUB"] || "";
        const sec   = apt[F_SECTOR]   || apt["_3BU0JH"] || "–";
        const floor = String(apt["FLOOR"] || apt[F_FLOOR] || "0");

        if (pt === "ავტოსადგომი" || pt === "დამხმარე") {
            if (b === "P") { blockPList.push(apt); return; }
            if (!nonAptBySector[sec])           nonAptBySector[sec]           = {};
            if (!nonAptBySector[sec][b])         nonAptBySector[sec][b]        = {};
            if (!nonAptBySector[sec][b][floor])  nonAptBySector[sec][b][floor] = [];
            nonAptBySector[sec][b][floor].push(apt);
        } else {
            apartments.push(apt);
        }
    });

    // sectorMap[sector][block][floor] = [apt, ...]
    const sectorMap = {};
    apartments.forEach(apt => {
        const sec   = apt[F_SECTOR] || apt["_3BU0JH"] || "–";
        const block = apt[F_BLOCK]  || apt["_L24CUB"]  || "–";
        const floor = parseInt(apt["FLOOR"] || apt[F_FLOOR] || 0);
        if (!sectorMap[sec])              sectorMap[sec]               = {};
        if (!sectorMap[sec][block])        sectorMap[sec][block]        = {};
        if (!sectorMap[sec][block][floor]) sectorMap[sec][block][floor] = [];
        sectorMap[sec][block][floor].push(apt);
    });

    const allFloors = [...new Set(
        apartments.map(a => parseInt(a["FLOOR"] || a[F_FLOOR] || 0))
    )].sort((a, b) => b - a);

    // Fixed column width — tiles wrap inside, column never grows horizontally
    const MAX_PER_ROW = 8;
    const TILE_W      = 30;
    const TILE_GAP    = 4;
    const COL_W       = MAX_PER_ROW * TILE_W + (MAX_PER_ROW - 1) * TILE_GAP; // 268px

    const floorsContainer = document.getElementById("floors");
    floorsContainer.innerHTML = "";
    floorsContainer.style.display = "none";

    const sectorsWrapper = document.createElement("div");
    sectorsWrapper.className = "sector-grid-wrapper";

    const sectorNames = Object.keys(sectorMap).sort((a, b) =>
        a.localeCompare(b, undefined, { numeric: true, sensitivity: "base" })
    );

    // ── helpers ───────────────────────────────────────────────────────

    function makeHeaderRow(activeBlocks, col) {
        const row = document.createElement("div");
        row.style.cssText = "display:flex;flex-direction:row;align-items:stretch;margin-bottom:3px;";
        const sp = document.createElement("div");
        sp.style.cssText = "width:36px;min-width:36px;flex-shrink:0;";
        row.appendChild(sp);
        activeBlocks.forEach((blockName, bi) => {
            const cell = document.createElement("div");
            cell.className = "sector-block-label";
            cell.style.cssText = `width:${COL_W}px;flex-shrink:0;border-bottom-color:${col.border};${bi < activeBlocks.length - 1 ? "margin-right:8px;" : ""}`;
            cell.textContent = blockName;
            row.appendChild(cell);
        });
        return row;
    }

    function makeFloorBand(floorNum, activeBlocks, dataByBlock, accentColor, tilePrefix) {
        const band = document.createElement("div");
        band.style.cssText = "display:flex;flex-direction:row;align-items:stretch;margin-bottom:3px;";

        const numEl = document.createElement("div");
        numEl.style.cssText = `
            font-family:var(--mono);font-size:10px;font-weight:700;
            color:var(--text2);
            width:28px;min-width:28px;flex-shrink:0;
            display:flex;align-items:center;justify-content:flex-end;
            padding-right:6px;
            border-right:2px solid ${accentColor};
            margin-right:8px;
        `;
        numEl.textContent = floorNum;
        band.appendChild(numEl);

        activeBlocks.forEach((blockName, bi) => {
            const cell = document.createElement("div");
            cell.style.cssText = `
                display:flex;flex-direction:row;flex-wrap:wrap;
                gap:${TILE_GAP}px;align-items:flex-start;align-content:flex-start;
                width:${COL_W}px;min-height:${TILE_W}px;flex-shrink:0;
                ${bi < activeBlocks.length - 1 ? "margin-right:8px;" : ""}
            `;
            const items = (dataByBlock[blockName] || {})[floorNum] || [];
            if (items.length > 0) {
                items.forEach(apt => cell.appendChild(makeAptTile(apt, tilePrefix || "")));
            } else {
                const ph = document.createElement("div");
                ph.style.cssText = `width:${COL_W}px;height:${TILE_W}px;`;
                cell.appendChild(ph);
            }
            band.appendChild(cell);
        });

        return band;
    }

    // ─────────────────────────────────────────────────────────────────

    sectorNames.forEach((sectorName, si) => {
        const col = SECTOR_COLORS[si % SECTOR_COLORS.length];

        const blocksInSector = Object.keys(sectorMap[sectorName] || {}).sort((a, b) => {
            const na = parseInt(a.match(/\d+/)?.[0] ?? "9999");
            const nb = parseInt(b.match(/\d+/)?.[0] ?? "9999");
            return na !== nb ? na - nb : a.localeCompare(b);
        });

        const activeBlocks = selectedBlocks.length > 0
            ? blocksInSector.filter(b => selectedBlocks.includes(b))
            : blocksInSector;
        if (activeBlocks.length === 0) return;

        const sectorEl = document.createElement("div");
        sectorEl.className = "sector-container";
        sectorEl.style.cssText = `background:${col.bg};border:1px solid ${col.border};`;

        const sectorLbl = document.createElement("div");
        sectorLbl.className = "sector-label";
        sectorLbl.style.cssText = `color:${col.label};border-bottom-color:${col.border};`;
        sectorLbl.textContent = "სექტ. " + sectorName;
        sectorEl.appendChild(sectorLbl);

        // ── Apartments ────────────────────────────────────────────────
        sectorEl.appendChild(makeHeaderRow(activeBlocks, col));
        allFloors.forEach(floorNum => {
            sectorEl.appendChild(makeFloorBand(
                floorNum,
                activeBlocks,
                sectorMap[sectorName],
                "var(--accent)",
                ""
            ));
        });

        // ── Non-apartment types (parking, storage…) ───────────────────
        const typeMap = {}; // typeMap[typeName][block][floor] = [items]
        activeBlocks.forEach(blockName => {
            const blockFloors = (nonAptBySector[sectorName] || {})[blockName] || {};
            Object.entries(blockFloors).forEach(([floor, items]) => {
                items.forEach(item => {
                    const typeName = item["__X1GCRZ"] || item[F_TYPE] || "სხვა";
                    if (!typeMap[typeName])                    typeMap[typeName]                    = {};
                    if (!typeMap[typeName][blockName])          typeMap[typeName][blockName]         = {};
                    if (!typeMap[typeName][blockName][floor])   typeMap[typeName][blockName][floor]  = [];
                    typeMap[typeName][blockName][floor].push(item);
                });
            });
        });

        Object.entries(typeMap).forEach(([typeName, blockData]) => {
            const isPark  = typeName === "ავტოსადგომი";
            const isStore = typeName === "დამხმარე";
            const prefix  = isPark ? "P" : isStore ? "S" : "";
            const accent  = isPark ? "var(--purple)" : isStore ? "var(--amber)" : "var(--text3)";
            const label   = isPark ? "ავტოსადგომი / P/S" : isStore ? "დამხმარე" : typeName;

            const typeFloors = [...new Set(
                activeBlocks.flatMap(b => Object.keys(blockData[b] || {}))
            )].sort((a, b) => parseInt(b) - parseInt(a));

            if (typeFloors.length === 0) return;

            const sep = document.createElement("div");
            sep.style.cssText = `margin-top:8px;padding-top:6px;border-top:1px dashed ${col.border};`;

            const sepLbl = document.createElement("div");
            sepLbl.style.cssText = "font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text3);margin-bottom:4px;font-family:var(--mono);";
            sepLbl.textContent = label;
            sep.appendChild(sepLbl);

            sep.appendChild(makeHeaderRow(activeBlocks, col));

            typeFloors.forEach(floorNum => {
                sep.appendChild(makeFloorBand(
                    floorNum,
                    activeBlocks,
                    blockData,
                    accent,
                    prefix
                ));
            });

            sectorEl.appendChild(sep);
        });

        sectorsWrapper.appendChild(sectorEl);
    });

    container.appendChild(sectorsWrapper);

    // ── Outdoor parking (Block P) ─────────────────────────────────────
    const outdoorEl = document.getElementById("gareAvtosadgomebi");
    outdoorEl.innerHTML = "";
    if (selectedBlocks.includes("P") && blockPList.length > 0) {
        const wrap = document.createElement("div");
        wrap.style.cssText = "max-width:1000px;background:rgba(240,240,245,.6);border-radius:8px;padding:8px 14px;";
        const h = document.createElement("div");
        h.style.cssText = "font-size:13px;font-weight:600;color:#555;margin-bottom:6px;";
        h.textContent = "გარე ავტოსადგომები";
        wrap.appendChild(h);
        const grid = document.createElement("div");
        grid.style.cssText = "display:flex;flex-wrap:wrap;gap:5px;";
        blockPList.forEach(item => grid.appendChild(makeAptTile(item)));
        wrap.appendChild(grid);
        outdoorEl.appendChild(wrap);
    }
}
// ══════════════════════════════════════════
//  APT TILE FACTORY
// ══════════════════════════════════════════
function makeAptTile(apt, prefix="") {
    const tile = document.createElement("div");
    const s    = apt["_P64GYD"] || "";
    tile.className = `apt ${tileCls(s)}${productsIds.includes(apt["ID"])?" dimmed":""}`;
    tile.dataset.id     = apt["ID"];
    tile.dataset.status = s;

    if (apt["aqcia"]) {
        const b = document.createElement("span");
        b.style.cssText = "position:absolute;top:-5px;right:-5px;font-size:9px;line-height:1;z-index:5;";
        b.textContent = "🎁"; tile.appendChild(b);
    }
    if (apt[F_SALE] === "Yes") {
        const fire = document.createElement("span");
        fire.style.cssText = "position:absolute;top:-7px;left:-5px;font-size:11px;line-height:1;z-index:5;filter:drop-shadow(0 0 3px rgba(255,100,0,.6));";
        fire.textContent = "🔥";
        tile.appendChild(fire);
    }

    const num = document.createElement("div");
    num.style.fontSize = "8px";
    num.textContent = prefix + (apt["Number"] || apt[F_NUMBER] || "");
    tile.appendChild(num);

    if (apt["TOTAL_AREA"] && !prefix) {
        const ar = document.createElement("div");
        ar.textContent = apt["TOTAL_AREA"];
        tile.appendChild(ar);
    }
    return tile;
}

// ══════════════════════════════════════════
//  LEGEND
// ══════════════════════════════════════════
function updateLegendCounts(prods) {
    const c = {active:0, reserved:0, sold:0, nfs:0, queue:0};
    (prods||[]).forEach(p => {
        switch(p["_P64GYD"]) {
            case "თავისუფალი":    c.active++;   break;
            case "დაჯავშნილი":    c.reserved++; break;
            case "გაყიდული":      c.sold++;     break;
            case "NFS":           c.nfs++;      break;
            case "ჯავშნის რიგში": c.queue++;    break;
        }
    });
    document.getElementById("count-active").textContent   = c.active;
    document.getElementById("count-reserved").textContent = c.reserved;
    document.getElementById("count-sold").textContent     = c.sold;
    document.getElementById("count-nfs").textContent      = c.nfs;
    document.getElementById("count-queue").textContent    = c.queue;
}

// ══════════════════════════════════════════
//  FILTERS
// ══════════════════════════════════════════
function updateDynamicFilters(prods) {
    if (!prods||prods.length===0) return;
    const statusSet=new Set(), typeSet=new Set();
    prods.forEach(p => {
        if (p["_P64GYD"])  statusSet.add(p["_P64GYD"]);
        if (p["__X1GCRZ"]) typeSet.add(p["__X1GCRZ"]);
    });
    buildDropdown("#statusFilter",        statusSet, "სტატუსი");
    buildDropdown("#apartmentTypeFilter", typeSet,   "ფართის ტიპი");
    $(".dropdown-content input[type=checkbox]").off("change").on("change", function() {
        const pid = $(this).closest(".dropdown-checkbox").attr("id");
        const def = pid==="statusFilter"?"სტატუსი":pid==="apartmentTypeFilter"?"ფართის ტიპი":"";
        updateDropdownHeader(pid, def);
    });
}

function buildDropdown(sel, valSet, defaultText) {
    const c = document.querySelector(sel+" .dropdown-content");
    c.innerHTML = "";
    Array.from(valSet).forEach(v => {
        const l = document.createElement("label");
        l.innerHTML = `<input type="checkbox" value="${v}"> ${v}`;
        c.appendChild(l);
    });
    document.querySelector(sel+" .dropdown-header").textContent = defaultText;
}

function fillAdditionalFilters() {
    const container = document.getElementById("filterButtonsContainer");
    container.innerHTML = "";
    Object.entries(propertyMap).forEach(([code, prop]) => {
        if (SKIP_CODES.has(code)) return;
        const btn = document.createElement("button");
        btn.className    = "extraFilters";
        btn.dataset.code = code;
        btn.textContent  = prop.name;
        btn.addEventListener("click", () => addFilter(code, prop.name, btn));
        container.appendChild(btn);
    });
}

function getAllFilters() {
    return {
        blocks:   getCheckboxValues("blockFilter"),
        status:   getCheckboxValues("statusFilter"),
        aptType:  getCheckboxValues("apartmentTypeFilter"),
        aptRange: { min:$("#aptMin").val(), max:$("#aptMax").val() },
        extra:    getExtraFilterValues()
    };
}
function getCheckboxValues(id) {
    const v=[]; $(`#${id} .dropdown-content input:checked`).each(function(){v.push($(this).val());}); return v;
}
function getExtraFilterValues() {
    const r={};
    document.querySelectorAll("#extraFilterChips .filter-chip").forEach(chip => {
        const dd = chip.querySelector(".dropdown-checkbox");
        if (!dd) return;
        const code    = dd.id.replace("_filter","");
        const checked = dd.querySelectorAll("input[type='checkbox']:checked");
        if (checked.length>0) r[code] = Array.from(checked).map(cb=>cb.parentElement.textContent.trim());
    });
    return r;
}

function applyFilters() {
    const f = getAllFilters();

    const hasSectors = productsCache.some(p => p[F_SECTOR] && p[F_SECTOR] !== "");
    const container  = document.getElementById("apsDisplay");

    if (hasSectors) {
        container.innerHTML = "";
        renderBySectors(productsCache, f.blocks, container);
    } else {
        container.innerHTML = "";
        renderByBlocks(productsCache, f.blocks, container);
    }

    ["#apsDisplay .apt","#gareAvtosadgomebi .apt"].forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
            const apt = productsCache.find(p=>p["ID"]==el.dataset.id);
            if (!apt) return;
            el.classList.toggle("dimmed", !matchesFilters(apt, f));
        });
    });

    updateLegendCounts(productsCache);
}

function matchesFilters(apt, f) {
    if (f.status.length>0  && !f.status.includes(apt["_P64GYD"]))        return false;
    if (f.aptType.length>0 && !f.aptType.includes(apt["__X1GCRZ"]))      return false;
    if (f.blocks.length>0  && !f.blocks.includes(apt["_L24CUB"]))        return false;
    const area=parseFloat(apt["TOTAL_AREA"]);
    if (f.aptRange.min!==""&&area<parseFloat(f.aptRange.min)) return false;
    if (f.aptRange.max!==""&&area>parseFloat(f.aptRange.max)) return false;
    for (const [code,val] of Object.entries(f.extra)) {
        const prop=apt[code];
        if (Array.isArray(val)) {
            if (val.length===0) continue;
            if (!prop||!val.some(v=>String(v).trim()===String(prop).trim())) return false;
        } else if (typeof val==="object"&&val!==null) {
            const n=parseFloat(prop);
            if (isNaN(n)) return false;
            if (val.min!==null&&n<val.min) return false;
            if (val.max!==null&&n>val.max) return false;
        } else {
            if (!prop||!String(prop).toLowerCase().includes(val.toLowerCase())) return false;
        }
    }
    return true;
}

document.getElementById("search").addEventListener("click", applyFilters);

$("#clean").on("click", function() {
    $("#statusFilter input,#apartmentTypeFilter input,#blockFilter input").prop("checked",false);
    $("#aptMin,#aptMax").val("");
    $("#statusFilter .dropdown-header").text("სტატუსი");
    $("#apartmentTypeFilter .dropdown-header").text("ფართის ტიპი");
    $("#blockFilter .dropdown-header").text("ბლოკი");
    document.querySelectorAll("#extraFilterChips .filter-chip").forEach(chip => {
        const btn=chip._sourceButton;
        if(btn){btn.disabled=false;btn.classList.remove("disabled-button");btn.style.background="";}
        chip.remove();
    });
    document.querySelectorAll("#legendBar .legend-item").forEach(i=>i.classList.remove("legend-active"));
    renderProductsByBlock(productsCache, []);
    $(".dropdown-content").slideUp(150);
});

function addFilter(code, label, buttonEl) {
    const chips = document.getElementById("extraFilterChips");
    const chip  = document.createElement("div");
    chip._sourceButton = buttonEl;
    chip.className = "filter-chip";

    const vals = new Set();
    productsCache.forEach(p => { if(p[code]!==undefined&&p[code]!==null&&p[code]!=="") vals.add(p[code]); });

    const dd  = document.createElement("div");
    dd.className="dropdown-checkbox"; dd.id=`${code}_filter`; dd.style.marginBottom="0";
    const hdr = document.createElement("div");
    hdr.className="dropdown-header"; hdr.textContent=label; hdr.style.fontSize="11px";
    const cnt = document.createElement("div");
    cnt.className="dropdown-content";
    Array.from(vals).forEach(v => {
        const l=document.createElement("label");
        l.innerHTML=`<input type="checkbox" value="${v}"> ${v}`;
        cnt.appendChild(l);
    });
    hdr.addEventListener("click", e => {
        e.stopPropagation();
        document.querySelectorAll(".dropdown-content").forEach(d=>{if(d!==cnt)d.style.display="none";});
        cnt.style.display=cnt.style.display==="flex"?"none":"flex";
    });
    cnt.addEventListener("click",e=>e.stopPropagation());
    dd.appendChild(hdr); dd.appendChild(cnt); chip.appendChild(dd);

    buttonEl.disabled=true; buttonEl.classList.add("disabled-button");
    const rm=document.createElement("button");
    rm.textContent="×"; rm.className="remove-chip";
    rm.addEventListener("click",e=>{e.stopPropagation();chip.remove();buttonEl.disabled=false;buttonEl.classList.remove("disabled-button");buttonEl.style.background="";});
    chip.appendChild(rm);
    chips.appendChild(chip);
}

document.getElementById("filterSearch")?.addEventListener("input",function(){
    const q=this.value.toLowerCase();
    document.querySelectorAll("#filterButtonsContainer .extraFilters").forEach(btn=>{
        btn.style.display=btn.textContent.toLowerCase().includes(q)?"block":"none";
    });
});

$(".dropdown-header").on("click",function(e){e.stopPropagation();$(".dropdown-content").not($(this).next()).slideUp(150);$(this).next(".dropdown-content").slideToggle(150);});
$(".dropdown-content").on("click",e=>e.stopPropagation());
$(document).on("click",()=>$(".dropdown-content").slideUp(150));
$("#addFiltersBtn").on("click",function(e){e.stopPropagation();$(this).siblings(".dropdown-content").slideToggle(150);});
function updateDropdownHeader(id,def){const v=getCheckboxValues(id);document.querySelector(`#${id} .dropdown-header`).textContent=v.length>0?v.join(", "):def;}

// ══════════════════════════════════════════
//  POPUP
// ══════════════════════════════════════════
// ══════════════════════════════════════════
//  POPUP
// ══════════════════════════════════════════
let currentlyActiveApt = null;
document.addEventListener("click", e => {
    const apt = e.target.closest(".apt");
    if (!apt) return;
    if (currentlyActiveApt&&currentlyActiveApt!==apt){currentlyActiveApt.style.transform="";currentlyActiveApt.style.border="";}
    currentlyActiveApt=apt;
    apt.style.transform="scale(1.2)"; apt.style.border="2px solid black";
    openPopup(apt.dataset.id, !!apt.closest("#productsBox"));
});

function openPopup(aptId, fromBox=false) {
    document.getElementById("apartmentPopup").classList.add("active");

    const apt = fromBox
        ? products.find(p=>p["ID"]==aptId)
        : productsCache.find(p=>p["ID"]==aptId);
    if (!apt) return;

    const typeLabel = apt["__X1GCRZ"]==="ავტოსადგომი"?"P":(apt["__X1GCRZ"]||"ბინა");
    const num       = apt["Number"] || apt[F_NUMBER] || apt["NAME"] || "–";
    document.getElementById("popupTitle").innerText      = `${typeLabel} Nº${num}`;
    document.getElementById("popupTitle").dataset.id     = aptId;
    document.getElementById("popupTitle").dataset.status = apt["_P64GYD"]||"";
    document.getElementById("popupTitle").dataset.fromBox = fromBox ? "1" : "0";

    renderBlockSections(apt);

    const selectBtn = document.getElementById("popupSelectBtn");
    const alreadyAdded = productsIds.includes(aptId) || !!document.querySelector(`#productsBox .apt[data-id="${aptId}"]`);
    const isInBox = fromBox || !!document.querySelector(`#productsBox .apt[data-id="${aptId}"]`);

    if (isInBox && openedOnDeal) {
        selectBtn.style.display = "flex";
        selectBtn.textContent   = "წაშლა";
        selectBtn.style.background = "var(--red)";
        selectBtn.dataset.mode  = "delete";
    } else {
        const canAdd = openedOnDeal && inAllowedStages && !alreadyAdded
                       && apt["_P64GYD"] !== "გაყიდული" && apt["_P64GYD"] !== "NFS";
        selectBtn.style.display    = canAdd ? "flex" : "none";
        selectBtn.textContent      = "დამატება";
        selectBtn.style.background = "var(--accent)";
        selectBtn.dataset.mode     = "add";
    }

    document.getElementById("popupDetailsWrapper").classList.remove("open");
    document.getElementById("toggleDetailsBtn").textContent = "► დამატებითი დეტალები";
}

// ── Grouped field definitions ──
const POPUP_GROUPS = [
    {
        title: "ძირითადი ინფორმაცია",
        icon: `<svg viewBox="0 0 16 16" fill="none"><rect x="2" y="1.5" width="12" height="13" rx="1.5" stroke="#00d4aa" stroke-width="1.2"/><line x1="4.5" y1="5" x2="11.5" y2="5" stroke="#00d4aa" stroke-width="1" stroke-linecap="round"/><line x1="4.5" y1="7.5" x2="11.5" y2="7.5" stroke="#00d4aa" stroke-width="1" stroke-linecap="round"/><line x1="4.5" y1="10" x2="8.5" y2="10" stroke="#00d4aa" stroke-width="1" stroke-linecap="round"/></svg>`,
        codes: ["_P64GYD", "Number", "__X1GCRZ", "_L24CUB", "_3BU0JH", "_FTRIDL", "_D599QA", "__51MODL", "__VO9RG4", "__6ZWTER"],
        mainCodes: ["_P64GYD", "Number", "__X1GCRZ"],
    },
    {
        title: "ფართობები",
        icon: `<svg viewBox="0 0 16 16" fill="none"><rect x="1.5" y="1.5" width="13" height="13" rx="1.5" stroke="#00d4aa" stroke-width="1.2"/><path d="M1.5 6h13M6 1.5v13" stroke="#00d4aa" stroke-width="1" stroke-linecap="round"/></svg>`,
        codes: ["__173JA5", "__US58ND", "__BL1XXK", "_1_7CGWZ9", "_2_I5WZ38", "__J9TMOP"],
    },
    {
        title: "ოთახები",
        icon: `<svg viewBox="0 0 16 16" fill="none"><rect x="1.5" y="3.5" width="13" height="10" rx="1.5" stroke="#00d4aa" stroke-width="1.2"/><path d="M1.5 8h13M8 3.5v10" stroke="#00d4aa" stroke-width="1" stroke-linecap="round"/></svg>`,
        codes: ["__WX6YWZ", "__KYRP1L", "_1_UJ6WRQ", "_2_UH0KQR", "_3_4Y50I1", "__Z60OKH", "__J33KT8"],
    },
    {
        title: "სველი წერტილები",
        icon: `<svg viewBox="0 0 16 16" fill="none"><path d="M8 2C6 5 3 7 3 10a5 5 0 0 0 10 0c0-3-3-5-5-8z" stroke="#00d4aa" stroke-width="1.2" fill="none"/></svg>`,
        codes: ["__9H8XS9", "_1_8M61S3", "_2_LK1VJB"],
    },
    {
        title: "პროექტი / სხვა",
        icon: `<svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#00d4aa" stroke-width="1.2"/><path d="M8 5v3l2 2" stroke="#00d4aa" stroke-width="1.1" stroke-linecap="round"/></svg>`,
        codes: ["__THLWP9", "_UQIM2I"],
    },
];

function renderBlockSections(apt) {
    const container = document.getElementById("popupBlockSections");
    container.innerHTML = "";

    const shownCodes = new Set([
        "ID","NAME","~ID","~NAME","IBLOCK_ID","~IBLOCK_ID","IBLOCK_SECTION_ID","~IBLOCK_SECTION_ID",
        "IBLOCK_ELEMENT_ID","PREVIEW_PICTURE","DETAIL_PICTURE","~DETAIL_PICTURE","MORE_PHOTO",
        "image","image2","image3","image4","image5",
        "binis_gegmareba","render_3D","sartulis2D","binisNaxazi2D",
        "erteulis_gegma","erteuli_render","sartulis_gegma","sartulis_render","project_pics","company_logo",
        "PRICE","PRICE_GEL",
        F_BLOCK, F_FLOOR, F_NUMBER, F_TYPE, F_SALE, F_CADASTRAL, F_SECTOR,
        "Number","FLOOR","TOTAL_AREA","__X1GCRZ","_L24CUB","_3BU0JH","__6ZWTER","__9YCWGZ",
        "OWNER_DEAL","OWNER_CONTACT","OWNER_CONTACT_NAME",
        "DEAL_RESPONSIBLE","DEAL_RESPONSIBLE_NAME","OWNER_PERSONAL_CONTACT","QUEUE",
        ]);

    // ── 1. Price (top) ────────────────────────────────────────────────
    appendPriceSection(container, apt);
    shownCodes.add("PRICE"); shownCodes.add("PRICE_GEL");

    // ── 2. Grouped sections ───────────────────────────────────────────
    POPUP_GROUPS.forEach(group => {
        const fields = group.codes
            .filter(code =>
                !shownCodes.has(code) &&
                apt[code] !== undefined &&
                apt[code] !== null &&
                apt[code] !== ""
            )
            .map(code => ({
                code,
                name: propertyMap[code]?.name || code,
                main: (group.mainCodes || []).includes(code),
            }));

        if (fields.length === 0) return;

        const sc = aptCls(apt["_P64GYD"] || "");
        appendSectionWithIcon(container, group.title, group.icon, fields, apt, sc);
        fields.forEach(f => shownCodes.add(f.code));
    });

    // ── 3. Images ─────────────────────────────────────────────────────
    const imgFields = Object.keys(apt).filter(
        code => IMAGE_CODES.has(code) && apt[code] && !shownCodes.has(code)
    );
    if (imgFields.length > 0) {
        appendImageSection(container, imgFields, apt);
        imgFields.forEach(c => shownCodes.add(c));
    }

    // ── 4. Remainder + links merged into one section ───────────────────
    const restFields = Object.keys(apt)
        .filter(code =>
            !shownCodes.has(code) &&
            !code.startsWith("~") &&
            apt[code] !== undefined &&
            apt[code] !== null &&
            apt[code] !== ""
        )
        .map(code => ({ code, name: propertyMap[code]?.name || code, main: false }));

    // Build link rows as pseudo field-row HTML appended after restFields
    const linkRows = [];
    if (apt["OWNER_DEAL"])
        linkRows.push({ label: "მფლობელის დილი", html: `<a href="/crm/deal/details/${apt["OWNER_DEAL"]}/" target="_blank">${apt["OWNER_DEAL"]}</a>` });
    if (apt["QUEUE"] && apt["QUEUE"] !== "") {
        const ql = apt["QUEUE"].split("|").filter(q=>q).map(q=>`<a href="/crm/deal/details/${q}/" target="_blank">${q}</a>`).join(", ");
        linkRows.push({ label: "ჯავშნის რიგში", html: ql });
    }
    if (apt["OWNER_CONTACT"])
        linkRows.push({ label: "კონტაქტი", html: `<a href="/crm/contact/details/${apt["OWNER_CONTACT"]}/" target="_blank">${apt["OWNER_CONTACT_NAME"] || apt["OWNER_CONTACT"]}</a>` });
    if (apt["DEAL_RESPONSIBLE"])
        linkRows.push({ label: "პასუხისმგებელი", html: `<a href="/company/personal/user/${apt["DEAL_RESPONSIBLE"]}/" target="_blank">${apt["DEAL_RESPONSIBLE_NAME"] || apt["DEAL_RESPONSIBLE"]}</a>` });

    if (restFields.length > 0 || linkRows.length > 0) {
        const icon = `<svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#00d4aa" stroke-width="1.2"/><path d="M8 6v2M8 10v.5" stroke="#00d4aa" stroke-width="1.4" stroke-linecap="round"/></svg>`;
        const sec = document.createElement("div");
        sec.className = "block-section";
        sec.innerHTML = `
            <div class="block-header">
                <div class="block-header-icon">${icon}</div>
                <span class="block-header-title">დამატებითი ინფორმაცია</span>
            </div>
            <div class="block-fields"></div>`;
        container.appendChild(sec);
        const bf = sec.querySelector(".block-fields");

        // Regular unknown fields
        restFields.forEach(f => bf.appendChild(buildFieldRow(f, apt, "")));

        // Link rows styled like field rows
        linkRows.forEach(({ label, html }) => {
            const row = document.createElement("div");
            row.className = "field-row";
            row.innerHTML = `
                <span class="field-label">${label}</span>
                <span class="field-value" style="white-space:normal;text-align:right;">${html}</span>`;
            bf.appendChild(row);
        });
    }
}

function appendSectionWithIcon(container, title, iconSvg, fields, apt, statusClass) {
    const sec = document.createElement("div");
    sec.className = "block-section";
    sec.innerHTML = `
        <div class="block-header">
            <div class="block-header-icon">${iconSvg}</div>
            <span class="block-header-title">${title}</span>
        </div>
        <div class="block-fields"></div>`;
    container.appendChild(sec);
    const bf = sec.querySelector(".block-fields");
    fields.forEach(f => bf.appendChild(buildFieldRow(f, apt, statusClass)));
}

function appendSection(container, title, fields, apt, statusClass) {
    const icon = `<svg viewBox="0 0 16 16" fill="none"><rect x="2" y="1.5" width="12" height="13" rx="1.5" stroke="#00d4aa" stroke-width="1.2"/></svg>`;
    appendSectionWithIcon(container, title, icon, fields, apt, statusClass);
}

function buildFieldRow(field, apt, statusClass) {
    const value  = apt[field.code];
    const isLong = typeof value==="string"&&value.length>22;
    const row    = document.createElement("div");
    row.className = `field-row ${isLong?"row-stacked":"row-inline"}${field.main?" is-main":""}`;
    if (field.main&&field.code==="_P64GYD"&&statusClass) row.classList.add(statusClass);

    if (isLong) {
        row.innerHTML=`<span class="field-label">${field.name}</span><div class="field-value-long"><div class="field-value-text">${value}</div><button class="show-more-btn">მეტის ნახვა ▼</button></div>`;
        const btn=row.querySelector(".show-more-btn"),txt=row.querySelector(".field-value-text");
        btn.addEventListener("click",e=>{e.stopPropagation();const exp=txt.classList.toggle("expanded");btn.textContent=exp?"დამალვა ▲":"მეტის ნახვა ▼";});
    } else {
        const badge=(field.main&&field.code==="_P64GYD")?badgeCls(apt["_P64GYD"]||""):"";
        row.innerHTML=`<span class="field-label">${field.name}</span><span class="field-value ${badge}">${value??""}</span>`;
    }
    return row;
}

function appendImageSection(container, imgFields, apt) {
    const sec=document.createElement("div"); sec.className="block-section";
    sec.innerHTML=`<div class="block-header"><div class="block-header-icon"><svg viewBox="0 0 16 16" fill="none"><rect x="1.5" y="3.5" width="13" height="9" rx="1.5" stroke="#00d4aa" stroke-width="1.2"/><circle cx="5.5" cy="6.5" r="1" fill="#00d4aa"/><path d="M1.5 10.5l3-3 2.5 2.5 2-2 4 4" stroke="#00d4aa" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/></svg></div><span class="block-header-title">ნახაზები / სურათები</span></div><div class="block-fields"><div class="image-gallery"></div></div>`;
    container.appendChild(sec);
    const gallery=sec.querySelector(".image-gallery");
    imgFields.forEach(code=>{
        const src=apt[code], label=propertyMap[code]?.name||code;
        const item=document.createElement("div"); item.className="gallery-item";
        item.innerHTML=`<img src="${src}" alt="${label}" class="loading" onload="this.classList.remove('loading')" onerror="this.closest('.gallery-item').style.display='none'"><div class="gallery-label">${label}</div><div class="gallery-zoom">⤢</div>`;
        item.addEventListener("click",()=>{carouselImages=imgFields.map(c=>apt[c]).filter(Boolean);currentImageIndex=imgFields.indexOf(code);openCarousel();});
        gallery.appendChild(item);
    });
}

function appendPriceSection(container, apt) {
    const price    = parseFloat(String(apt["PRICE"] || apt[F_PRICE_USD] || apt["PRICE"] || "0").replace(/[^0-9.]/g, "")) || 0;
    const priceGel = price ? Math.round(price * nbg) : 0;
    const kvmUsd   = parseFloat(String(apt[F_KVM_USD] || "0").replace(/[^0-9.]/g, "")) || 0;
    const kvmGel   = kvmUsd ? Math.round(kvmUsd * nbg) : 0;
    if (!price && !priceGel && !kvmUsd) return;

    const sec = document.createElement("div"); sec.className = "block-section";
    sec.innerHTML = `
        <div class="block-header">
            <div class="block-header-icon"><svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#00d4aa" stroke-width="1.2"/><path d="M8 4.5v1M8 10.5v1M6 7c0-.83.9-1.5 2-1.5s2 .67 2 1.5S9.1 8.5 8 8.5 6 9.17 6 10s.9 1.5 2 1.5 2-.67 2-1.5" stroke="#00d4aa" stroke-width="1.1" stroke-linecap="round"/></svg></div>
            <span class="block-header-title">ფასი</span>
        </div>
        <div style="background:linear-gradient(135deg,#f0fdf9,#e6fff8);border:1px solid #a7f3d0;border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;margin-top:8px;">
            ${kvmUsd ? `<div style="display:flex;gap:6px;">
                <div style="flex:1;background:#fff;border:1px solid #d1fae5;border-radius:7px;padding:6px 8px;"><div style="font-size:9px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">მ² ფასი $</div><div style="font-size:13px;font-weight:700;color:#047857;font-family:'DM Mono',monospace;">$${fmt(kvmUsd)}</div></div>
                <div style="flex:1;background:#fff;border:1px solid #d1fae5;border-radius:7px;padding:6px 8px;"><div style="font-size:9px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">მ² ფასი ₾</div><div style="font-size:13px;font-weight:700;color:#047857;font-family:'DM Mono',monospace;">₾${fmt(kvmGel)}</div></div>
            </div>` : ""}
            <div style="display:flex;gap:6px;">
                ${price ? `<div style="flex:1;background:#fff;border:1px solid #d1fae5;border-radius:7px;padding:6px 8px;"><div style="font-size:9px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">სრული ფასი $</div><div style="font-size:13px;font-weight:700;color:#047857;font-family:'DM Mono',monospace;">$${fmt(price)}</div></div>` : ""}
                ${priceGel ? `<div style="flex:1;background:#fff;border:1px solid #d1fae5;border-radius:7px;padding:6px 8px;"><div style="font-size:9px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">სრული ფასი ₾</div><div style="font-size:13px;font-weight:700;color:#047857;font-family:'DM Mono',monospace;">₾${fmt(priceGel)}</div></div>` : ""}
            </div>
            <div style="text-align:right;font-size:9px;color:#6b7280;padding-top:2px;border-top:1px solid #d1fae5;">NBG კურსი: <span style="font-weight:700;color:#047857;">${nbg} ₾</span></div>
        </div>`;
    container.appendChild(sec);
}

function appendLinksSection(container, apt) {
    const links=[];
    if (apt["OWNER_DEAL"])     links.push(`<li><b>მფლობელის დილი: </b><a href="/crm/deal/details/${apt["OWNER_DEAL"]}/" target="_blank">${apt["OWNER_DEAL"]}</a></li>`);
    if (apt["QUEUE"]&&apt["QUEUE"]!=="") {
        const ql=apt["QUEUE"].split("|").filter(q=>q).map(q=>`<a href="/crm/deal/details/${q}/" target="_blank">${q}</a>`).join(", ");
        links.push(`<li><b>ჯავშნის რიგში: </b>${ql}</li>`);
    }
    if (apt["OWNER_CONTACT"])  links.push(`<li><b>კონტაქტი: </b><a href="/crm/contact/details/${apt["OWNER_CONTACT"]}/" target="_blank">${apt["OWNER_CONTACT_NAME"]||apt["OWNER_CONTACT"]}</a></li>`);
    if (apt["DEAL_RESPONSIBLE"]) links.push(`<li><b>პასუხისმგებელი: </b><a href="/company/personal/user/${apt["DEAL_RESPONSIBLE"]}/" target="_blank">${apt["DEAL_RESPONSIBLE_NAME"]||apt["DEAL_RESPONSIBLE"]}</a></li>`);
    if (links.length===0) return;
    const sec=document.createElement("div"); sec.className="block-section";
    sec.innerHTML=`<div class="block-header"><div class="block-header-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M6 8a2 2 0 1 0 4 0 2 2 0 0 0-4 0z" fill="#00d4aa" opacity=".3"/></svg></div><span class="block-header-title">მონათვლები</span></div><ul style="list-style:none;padding:0;margin:6px 0 0;display:flex;flex-direction:column;gap:4px;">${links.join("")}</ul>`;
    container.appendChild(sec);
}

// POPUP CLOSE
document.getElementById("popupClose").addEventListener("click", closePopup);
document.addEventListener("click", e => {
    const popup=document.getElementById("apartmentPopup");
    if (!popup.classList.contains("active")) return;
    if (popup.contains(e.target)||e.target.closest(".apt")||e.target.closest("#imageCarouselPopup")) return;
    closePopup();
});
function closePopup() {
    document.getElementById("apartmentPopup").classList.remove("active");
    const id=document.getElementById("popupTitle").dataset.id;
    const el=document.querySelector(`#apsDisplay .apt[data-id="${id}"]`);
    if (el){el.style.transform="";el.style.border="";}
}
document.getElementById("toggleDetailsBtn").addEventListener("click",function(){
    const w=document.getElementById("popupDetailsWrapper");
    w.classList.toggle("open");
    this.textContent=w.classList.contains("open")?"▼ დამალე":"► დამატებითი დეტალები";
});

// ══════════════════════════════════════════
//  DEAL: ADD / DELETE / SAVE
// ══════════════════════════════════════════
document.getElementById("popupSelectBtn")?.addEventListener("click", () => {
    const mode = document.getElementById("popupSelectBtn").dataset.mode;
    if (mode === "delete") {
        deleteSelectedApartment();
    } else {
        closePopup();
        addSelectedApartment();
    }
});

function deleteSelectedApartment() {
    isDeletingOnly = true;
    const aptId = document.getElementById("popupTitle").dataset.id;
    const sb    = document.getElementById("saveBtn");
    const tile  = document.querySelector(`#productsBox .apt[data-id="${aptId}"]`);
    if (tile) tile.remove();
    const el = document.querySelector(`#apsDisplay .apt[data-id="${aptId}"]`);
    if (el) { el.classList.remove("dimmed"); el.style.outline = ""; el.style.transform = ""; }
    if (allowedStages.includes(stage_id)) sb.style.display = "";
    closePopup();
}

function addSelectedApartment() {
    isDeletingOnly = false;
    const pb=document.getElementById("productsBox");
    const pt=document.getElementById("popupTitle");
    const aptId=pt.dataset.id, status=pt.dataset.status;
    if (status==="გაყიდული"){alert("ბინა უკვე გაყიდულია.");return;}
    if (pb.querySelector(`.apt[data-id="${aptId}"]`)){alert("ბინა უკვე დამატებულია.");return;}
    const apt=productsCache.find(p=>p["ID"]==aptId);
    if (!apt) return;
    if (document.getElementById("saveBtn").style.display==="none"&&allowedStages.includes(stage_id))
        document.getElementById("saveBtn").style.display="";
    pb.appendChild(makeDealTile(apt));
    const el=document.querySelector(`#apsDisplay .apt[data-id="${aptId}"]`);
    if (el) el.classList.add("dimmed");
    document.getElementById("popupSelectBtn").style.display="none";
}

document.getElementById("saveBtn")?.addEventListener("click", () => {
    const sb  = document.getElementById("saveBtn");
    const ids = [...document.getElementById("productsBox").children]
                    .map(el => Number(el.dataset.id))
                    .filter(Boolean);

    sb.disabled = true; sb.textContent = "..."; sb.style.opacity = ".6";

    fetch(`/rest/local/api/projects/saveApartment.php?deal_id=${dealID}&productIds=${ids.join(",")}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 200) {
                if (isDeletingOnly) {
                    location.reload();
                } else {
                    showReservationPopup();
                }
            } else {
                alert(data.error || "შეცდომა");
                sb.disabled = false; sb.textContent = "შენახვა"; sb.style.opacity = "1";
            }
        })
        .catch(err => {
            console.error(err);
            sb.disabled = false; sb.textContent = "შენახვა"; sb.style.opacity = "1";
        });
});

document.getElementById("sendButton")?.addEventListener("click", submitReservation);

// ══════════════════════════════════════════
//  LEGEND CLICK FILTER
// ══════════════════════════════════════════
const legendStatusMap = {
    "count-active":   "თავისუფალი",
    "count-reserved": "დაჯავშნილი",
    "count-sold":     "გაყიდული",
    "count-nfs":      "NFS",
    "count-queue":    "ჯავშნის რიგში",
};

document.querySelectorAll("#legendBar .legend-item").forEach(item=>{
    item.addEventListener("click",()=>{
        const ce=item.querySelector("[id^='count-']"); if(!ce) return;
        const status=legendStatusMap[ce.id]; if(!status) return;
        const cb=document.querySelector(`#statusFilter .dropdown-content input[value="${status}"]`); if(!cb) return;
        cb.checked=!cb.checked;
        updateDropdownHeader("statusFilter","სტატუსი");
        item.classList.toggle("legend-active",cb.checked);
        applyFilters();
    });
});

// ══════════════════════════════════════════
//  CAROUSEL
// ══════════════════════════════════════════
let carouselImages=[], currentImageIndex=0;
function openCarousel(){document.getElementById("imageCarouselPopup").classList.add("active");updateCarousel();}
function closeCarousel(){document.getElementById("imageCarouselPopup").classList.remove("active");}
function updateCarousel(){
    document.getElementById("carouselImage").src=carouselImages[currentImageIndex]||"";
    document.getElementById("currentImageNum").textContent=currentImageIndex+1;
    document.getElementById("totalImages").textContent=carouselImages.length;
    const dots=document.querySelector(".carousel-dots"); dots.innerHTML="";
    carouselImages.forEach((_,i)=>{const d=document.createElement("div");d.className=`carousel-dot${i===currentImageIndex?" active":""}`;d.addEventListener("click",()=>{currentImageIndex=i;updateCarousel();});dots.appendChild(d);});
}
document.getElementById("carouselClose").addEventListener("click",closeCarousel);
document.querySelector(".carousel-arrow.next").addEventListener("click",()=>{currentImageIndex=(currentImageIndex+1)%carouselImages.length;updateCarousel();});
document.querySelector(".carousel-arrow.prev").addEventListener("click",()=>{currentImageIndex=(currentImageIndex-1+carouselImages.length)%carouselImages.length;updateCarousel();});
document.addEventListener("keydown",e=>{
    if(!document.getElementById("imageCarouselPopup").classList.contains("active")) return;
    if(e.key==="ArrowRight"){currentImageIndex=(currentImageIndex+1)%carouselImages.length;updateCarousel();}
    if(e.key==="ArrowLeft"){currentImageIndex=(currentImageIndex-1+carouselImages.length)%carouselImages.length;updateCarousel();}
    if(e.key==="Escape") closeCarousel();
});
document.getElementById("imageCarouselPopup").addEventListener("click",e=>{if(e.target.id==="imageCarouselPopup")closeCarousel();});

// ══════════════════════════════════════════
//  EXCEL EXPORT
// ══════════════════════════════════════════
async function exportToExcel() {
    const btn=document.getElementById("exportExcelBtn");
    btn.textContent="⏳ ..."; btn.disabled=true;
    try {
        const visibleIds=new Set();
        document.querySelectorAll("#apsDisplay .apt:not(.dimmed),#gareAvtosadgomebi .apt:not(.dimmed)").forEach(el=>{if(el.dataset.id)visibleIds.add(el.dataset.id);});
        if(visibleIds.size===0){alert("ჩვენებადი ბინები ვერ მოიძებნა.");return;}

        const all=[...productsCache,...(products||[])];
        const seen=new Set(), apts=[];
        visibleIds.forEach(id=>{const a=all.find(p=>p["ID"]==id);if(a&&!seen.has(id)){seen.add(id);apts.push(a);}});

        const skipExport=new Set([...SKIP_CODES,"~ID","~NAME","~IBLOCK_ID","~IBLOCK_SECTION_ID","MORE_PHOTO","PREVIEW_PICTURE","DETAIL_PICTURE","~DETAIL_PICTURE","image","image2","image3","image4","image5","binis_gegmareba","render_3D","sartulis2D","binisNaxazi2D","erteulis_gegma","erteuli_render","sartulis_gegma","sartulis_render","project_pics","company_logo"]);
        const priorityKeys = ["ID","_P64GYD","Number","__X1GCRZ","_L24CUB","_3BU0JH","FLOOR","TOTAL_AREA","PRICE","PRICE_GEL", F_KVM_USD];

        const dataKeys = new Set();
        apts.forEach(a => Object.keys(a).forEach(k => dataKeys.add(k)));

        const seen_keys = new Set();
        const orderedKeys = [];
        [...priorityKeys, ...Object.keys(propertyMap)].forEach(k => {
            if (!seen_keys.has(k) && !skipExport.has(k) && !k.startsWith("~") && dataKeys.has(k)) {
                seen_keys.add(k);
                orderedKeys.push(k);
            }
        });
        dataKeys.forEach(k => {
            if (!seen_keys.has(k) && !skipExport.has(k) && !k.startsWith("~")) {
                seen_keys.add(k);
                orderedKeys.push(k);
            }
        });

        const getName=code=>propertyMap[code]?.name||code;

        const wb=new ExcelJS.Workbook(), ws=wb.addWorksheet("ბინები");
        ws.columns = orderedKeys.map((k) => ({
            header: getName(k) || k,
            key: k,
            width: Math.max((getName(k) || k).length + 4, 14)
        }));
        const hdr=ws.getRow(1);
        for (let i = 1; i <= orderedKeys.length; i++) {
            const cell = hdr.getCell(i);
            cell.fill = { type:"pattern", pattern:"solid", fgColor:{argb:"FF1A1A2E"} };
            cell.font = { bold:true, color:{argb:"FFFFFFFF"}, size:10 };
            cell.alignment = { vertical:"middle", horizontal:"center", wrapText:true };
            cell.border = { bottom:{style:"medium", color:{argb:"FF00D4AA"}} };
        }
        hdr.height=28;

        const statusColors={"თავისუფალი":"FF28C7A9","დაჯავშნილი":"FFF9C74F","გაყიდული":"FFE63946","ჯავშნის რიგში":"FFDB2777","NFS":"FF9B59B6"};
        apts.forEach((apt, i) => {
            const rd = {};
            orderedKeys.forEach(k => {
                let v = apt[k];
                if (v === undefined || v === null) v = "";
                if (Array.isArray(v)) v = v.join(", ");
                rd[k] = String(v);
            });
            const row = ws.addRow(rd);
            const bg = i % 2 === 0 ? "FFF7F8FC" : "FFFFFFFF";
            for (let ci = 1; ci <= orderedKeys.length; ci++) {
                const cell = row.getCell(ci);
                cell.fill = { type:"pattern", pattern:"solid", fgColor:{argb:bg} };
                cell.alignment = { vertical:"middle" };
                cell.font = { size:10 };
            }
            const statusCol = orderedKeys.indexOf("_P64GYD") + 1;
            const sc = statusColors[apt["_P64GYD"]];
            if (sc && statusCol > 0) {
                const c = row.getCell(statusCol);
                c.fill = { type:"pattern", pattern:"solid", fgColor:{argb:sc+"33"} };
                c.font = { bold:true, size:10 };
            }
            row.height = 18;
        });
        ws.views=[{state:"frozen",ySplit:1}];
        try {
            ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: orderedKeys.length } };
        } catch(e) {
            console.warn("AutoFilter skipped:", e.message);
        }
        const buf=await wb.xlsx.writeBuffer();
        const blob=new Blob([buf],{type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
        const url=URL.createObjectURL(blob);
        const a=document.createElement("a"); a.href=url; a.download=`export_${new Date().toISOString().slice(0,10)}.xlsx`; a.click();
        URL.revokeObjectURL(url);
    } catch(err){console.error(err);alert("შეცდომა: "+err.message);}
    finally{btn.textContent="⬇ Excel";btn.disabled=false;}
}
</script>

<!-- ═══ RESERVATION POPUP ═══ -->
<div id="reservationOverlay" style="display:none;position:fixed;inset:0;background:rgba(10,12,20,.55);backdrop-filter:blur(6px);z-index:4000;justify-content:center;align-items:center;">
<div id="reservationPopup" style="position:relative;width:520px;max-width:95vw;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.22);border:.5px solid #d0d0d0;font-family:'Noto Sans Georgian',sans-serif;">

  <!-- header -->
  <div style="background:#7db84a;padding:11px 18px;display:flex;align-items:center;justify-content:space-between;">
    <span style="font-size:14px;font-weight:600;color:#fff;letter-spacing:.01em;">რეზერვაცია</span>
    <span onclick="closeReservationPopup()" style="cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:.85;">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><line x1="2" y1="2" x2="12" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="2" x2="2" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
    </span>
  </div>

  <!-- body -->
  <div style="padding:18px;background:#f4f6f8;">
    <div style="background:#fff;border:.5px solid #e0e0e0;border-radius:10px;padding:18px;">

      <!-- type -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ტიპი</label>
        <select id="resUserSelect" onchange="resToggleFields()" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;background:#fff;color:#333;font-family:'Noto Sans Georgian',sans-serif;">
          <option value="">აირჩიეთ რეზერვაციის ტიპი</option>
          <option value="41">სტანდარტული</option>
          <option value="42">არასტანდარტული</option>
        </select>
      </div>

      <!-- deadline -->
      <div id="resDateWrap" style="margin-bottom:14px;display:none;">
  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ვადა</label>
  <input type="text" id="resDate" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;font-family:'Noto Sans Georgian',sans-serif;background:#f4f6f8;color:#555;" readonly />
  <input type="date" id="resDatePicker" style="display:none;width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;" onchange="resDatePickerChange()" />
</div>

      <!-- payment type -->
      <div id="resPayTypeWrap" style="margin-bottom:14px;display:none;">
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> სასურველი გადახდის ტიპი</label>
        <select id="resPayType" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;background:#fff;color:#333;font-family:'Noto Sans Georgian',sans-serif;">
          <option value="">აირჩიეთ გადახდის ტიპი</option>
          <option value="ნაღდი">ნაღდი</option>
          <option value="ჩარიცხვა">ჩარიცხვა</option>
          <option value="ტერმინალი">ტერმინალი</option>
        </select>
      </div>

      <!-- price -->
<div id="resAmountWrap" style="margin-bottom:14px;display:none;">
  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ფასი</label>
  <div style="display:flex;gap:6px;">
    <input type="text" id="resAmount" placeholder="0.00" style="flex:1;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;font-family:'Noto Sans Georgian',sans-serif;" />
    <select id="resCurrency" style="height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 8px;font-size:13px;background:#fff;color:#333;font-family:'Noto Sans Georgian',sans-serif;min-width:70px;">
      <option value="GEL">₾ GEL</option>
      <option value="USD">$ USD</option>
    </select>
  </div>
</div>

      <!-- passport upload -->
      <div style="margin-bottom:4px;">
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">ატვირთეთ პირადობა ან პასპორტი</label>
        <input id="resPassportInput" type="file" style="display:none;" 
       accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" 
       onchange="resHandleFile()" />
        <div id="resDropZone" onclick="document.getElementById('resPassportInput').click()"
          style="border:1.5px dashed #97c459;border-radius:7px;padding:14px 12px;text-align:center;cursor:pointer;background:#f5fced;transition:background .15s;">
          <svg width="22" height="22" viewBox="0 0 28 28" fill="none" style="margin:0 auto 6px;display:block;">
            <rect x="2" y="6" width="24" height="18" rx="3" stroke="#7db84a" stroke-width="1.5" fill="none"/>
            <path d="M9 6V5a5 5 0 0 1 10 0v1" stroke="#7db84a" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M14 12v6M11 15l3-3 3 3" stroke="#7db84a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <p style="margin:0;font-size:12px;color:#3b6d11;font-weight:500;">Click to upload</p>
          <p style="margin:3px 0 0;font-size:11px;color:#6b7280;">ID card or passport — JPG, PNG, PDF</p>
        </div>
        <div id="resFileChosen" style="display:none;margin-top:6px;background:#eaf3de;border:.5px solid #97c459;border-radius:7px;padding:7px 10px;align-items:center;gap:8px;">
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><rect x="2" y="1" width="10" height="13" rx="2" stroke="#3b6d11" stroke-width="1.2"/><path d="M5 5h5M5 8h5M5 11h3" stroke="#3b6d11" stroke-width="1.2" stroke-linecap="round"/></svg>
  <span id="resFileName" style="font-size:12px;color:#3b6d11;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
  <span onclick="removeResFile()" style="cursor:pointer;font-size:16px;color:#d85a30;line-height:1;flex-shrink:0;" title="წაშლა">&times;</span>
</div>
      </div>
    </div>

    <div id="resMsgSuccess" style="display:none;color:#3b6d11;background:#eaf3de;border:.5px solid #97c459;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">მოთხოვნა გაიგზავნა წარმატებით</div>
    <div id="resMsgError"   style="display:none;color:#993c1d;background:#faece7;border:.5px solid #f0997b;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">შეცდომა გაგზავნისას</div>
    <div id="resMsgWarn"    style="display:none;color:#854f0b;background:#faeeda;border:.5px solid #ef9f27;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">გთხოვთ შეავსოთ ყველა სავალდებულო ველი</div>
  </div>

  <!-- footer buttons -->
  <div id="resFooter" style="display:none;padding:10px 18px;background:#fff;border-top:.5px solid #e0e0e0;justify-content:flex-end;gap:8px;align-items:center;">
    <span onclick="closeReservationPopup()" style="cursor:pointer;font-size:12px;padding:5px 14px;border-radius:6px;border:1px solid #f0997b;color:#f1361b;line-height:22px;">გაუქმება</span>
    <span id="sendButton" style="cursor:pointer;font-size:12px;padding:5px 14px;border-radius:6px;background:#7db84a;color:#fff;font-weight:500;line-height:22px;border:none;">გაგზავნა</span>
  </div>
</div>
</div>

<canvas id="confettiCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10001;display:none;"></canvas>

<script>
// ── Reservation popup helpers ──
function showReservationPopup() {
    document.getElementById("resUserSelect").value = "";
    document.getElementById("resDateWrap").style.display    = "none";
    document.getElementById("resPayTypeWrap").style.display = "none";
    document.getElementById("resAmountWrap").style.display  = "none";
    document.getElementById("resFooter").style.display      = "none";
    document.getElementById("resMsgSuccess").style.display  = "none";
    document.getElementById("resMsgError").style.display    = "none";
    document.getElementById("resMsgWarn").style.display     = "none";
    document.getElementById("resDropZone").style.display    = "";
    document.getElementById("resFileChosen").style.display  = "none";
    document.getElementById("resFileName").textContent      = "";
    document.getElementById("resPassportInput").value       = "";
    if (document.getElementById("resAmount")) document.getElementById("resAmount").value = "";

    const overlay = document.getElementById("reservationOverlay");
    overlay.style.display = "flex";
    requestAnimationFrame(() => { overlay.style.opacity = "0"; requestAnimationFrame(() => { overlay.style.transition = "opacity .25s"; overlay.style.opacity = "1"; }); });
}

function closeReservationPopup() {
    const overlay = document.getElementById("reservationOverlay");
    overlay.style.opacity = "0";
    setTimeout(() => { overlay.style.display = "none"; location.reload(); }, 250);
}

function resAddWorkingDays(date, days) {
    var r = new Date(date), added = 0;
    while (added < days) { r.setDate(r.getDate()+1); var d=r.getDay(); if(d!==0&&d!==6) added++; }
    return r;
}
function resToDatetimeLocal(date) {
    var p = n => String(n).padStart(2,"0");
    return p(date.getDate())+"/"+p(date.getMonth()+1)+"/"+date.getFullYear();
}

function resToggleFields() {
    var choice = document.getElementById("resUserSelect").value;
    var now    = new Date();
    document.getElementById("resDateWrap").style.display    = "none";
    document.getElementById("resPayTypeWrap").style.display = "none";
    document.getElementById("resAmountWrap").style.display  = "none";
    document.getElementById("resFooter").style.display      = "none";

    if (choice === "41") {
        document.getElementById("resDate").value            = resToDatetimeLocal(new Date(now.getTime() + 24*60*60*1000));
        document.getElementById("resDate").style.display    = "";
        document.getElementById("resDatePicker").style.display = "none";
        document.getElementById("resDateWrap").style.display = "block";
        document.getElementById("resFooter").style.display   = "flex";
    }
    if (choice === "42") {
        var dl = resAddWorkingDays(now, 10);
        var y = dl.getFullYear(), m = String(dl.getMonth()+1).padStart(2,"0"), d = String(dl.getDate()).padStart(2,"0");
        document.getElementById("resDatePicker").value         = y+"-"+m+"-"+d;
        document.getElementById("resDate").style.display       = "none";
        document.getElementById("resDatePicker").style.display = "block";
        // sync the hidden text field so submitReservation still reads it
        document.getElementById("resDate").value = d+"/"+m+"/"+y;
        document.getElementById("resDateWrap").style.display    = "block";
        document.getElementById("resPayTypeWrap").style.display = "block";
        document.getElementById("resAmountWrap").style.display  = "block";
        document.getElementById("resFooter").style.display      = "flex";
    }
}

function resDatePickerChange() {
    var val = document.getElementById("resDatePicker").value; // YYYY-MM-DD
    if (!val) return;
    var parts = val.split("-");
    document.getElementById("resDate").value = parts[2]+"/"+parts[1]+"/"+parts[0];
}

function resHandleFile() {
    var inp  = document.getElementById("resPassportInput");
    var file = inp.files[0];
    if (!file) return;

    const allowed = ["image/jpeg", "image/png", "application/pdf"];
    if (!allowed.includes(file.type)) {
        alert("დაშვებულია მხოლოდ JPG, PNG ან PDF ფორმატი");
        inp.value = "";
        return;
    }

    const maxSize = 10 * 1024 * 1024;
    if (file.size > maxSize) {
        alert("ფაილის ზომა არ უნდა აღემატებოდეს 10MB-ს");
        inp.value = "";
        return;
    }

    document.getElementById("resFileName").textContent     = file.name;
    document.getElementById("resDropZone").style.display   = "none";
    document.getElementById("resFileChosen").style.display = "flex";
}

function removeResFile() {
    document.getElementById("resPassportInput").value       = "";
    document.getElementById("resFileName").textContent      = "";
    document.getElementById("resFileChosen").style.display  = "none";
    document.getElementById("resDropZone").style.display    = "";
}


(function() {
    var dz = document.getElementById("resDropZone");
    if (!dz) return;
    dz.addEventListener("dragover",  e => { e.preventDefault(); dz.style.background="#dff0c8"; });
    dz.addEventListener("dragleave", () => { dz.style.background="#f5fced"; });
    dz.addEventListener("drop", e => {
        e.preventDefault(); dz.style.background="#f5fced";
        var file = e.dataTransfer.files[0]; if (!file) return;
        var inp = document.getElementById("resPassportInput");
        var dt  = new DataTransfer(); dt.items.add(file); inp.files = dt.files;
        resHandleFile();
    });
})();

function submitReservation() {
    var type    = document.getElementById("resUserSelect").value;
    var date    = document.getElementById("resDate").value;
    var payType = document.getElementById("resPayType")?.value || "";
    var amount  = document.getElementById("resAmount")?.value  || "";
    var fileInp = document.getElementById("resPassportInput");

    if (!type || !date) { showResMsg("warn"); return; }
    if (type === "paid" && (!payType || !amount)) { showResMsg("warn"); return; }

    var formData = new FormData();
    formData.append("deal_id",          dealID);
    formData.append("userSelect",       type);
    formData.append("reserveDate",      date);
    var currency = document.getElementById("resCurrency")?.value || "GEL";
    formData.append("reservationPrice", amount);
    formData.append("currency",         currency);
    formData.append("paymentType",      payType);
    formData.append("passport",         fileInp.files[0]);

    console.log(fileInp.files[0]);

    var btn = document.getElementById("sendButton");
    btn.style.opacity = ".6"; btn.style.pointerEvents = "none";

    
    fetch("/rest/local/api/projects/saveReservation.php", { method: "POST", body: formData })
        .then(r => r.json())
        .then(data => {
            console.log(data);
            if (data.status === 200) {
                showResMsg("success");
                launchConfetti();
                setTimeout(closeReservationPopup, 2200);
            } else {
                showResMsg("error");
                btn.style.opacity = "1"; btn.style.pointerEvents = "";
                
            }
        })
        .catch(function(err) {
            console.error('fetch error:', err);
            showResMsg("error");
            btn.style.opacity = "1"; btn.style.pointerEvents = "";
        });
}

function showResMsg(type) {
    ["resMsgSuccess","resMsgError","resMsgWarn"].forEach(id => document.getElementById(id).style.display="none");
    var map = { success:"resMsgSuccess", error:"resMsgError", warn:"resMsgWarn" };
    if (map[type]) document.getElementById(map[type]).style.display="block";
}

function launchConfetti() {
    var canvas = document.getElementById("confettiCanvas");
    canvas.style.display = "block";
    var ctx    = canvas.getContext("2d");
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
    var pieces = Array.from({length:120}, () => ({
        x: Math.random()*canvas.width,
        y: Math.random()*canvas.height - canvas.height,
        r: Math.random()*6+3,
        d: Math.random()*120,
        color: ["#7db84a","#3b5bdb","#ffd43b","#f03e3e","#ae3ec9","#1c7ed6"][Math.floor(Math.random()*6)],
        tilt: Math.random()*10-10,
        tiltSpeed: Math.random()*.07+.05,
        angle: 0, speed: Math.random()*2+1
    }));
    var frame = 0;
    function draw() {
        ctx.clearRect(0,0,canvas.width,canvas.height);
        pieces.forEach(p => {
            p.angle += p.tiltSpeed; p.y += p.speed; p.x += Math.sin(p.angle)*2;
            p.tilt = Math.sin(p.angle-frame/3)*12;
            ctx.beginPath(); ctx.lineWidth=p.r; ctx.strokeStyle=p.color;
            ctx.moveTo(p.x+p.tilt+p.r/3, p.y);
            ctx.lineTo(p.x+p.tilt, p.y+p.tilt+p.r/5);
            ctx.stroke();
            if (p.y > canvas.height) { p.y=-10; p.x=Math.random()*canvas.width; }
        });
        frame++;
        if (frame < 140) requestAnimationFrame(draw);
        else { ctx.clearRect(0,0,canvas.width,canvas.height); canvas.style.display="none"; }
    }
    draw();
}

document.getElementById("reservationOverlay").addEventListener("click", function(e) {
    if (e.target === this) closeReservationPopup();
});
</script>
</body>
</html>