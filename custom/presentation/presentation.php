<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');

// output buffer — არ გამოჩნდეს Bitrix layout
ob_start();

// ======================= FUNCTIONS =======================

function getScheduleTotal($dealID, $iblockID) {
    $total = 0;
    $res = CIBlockElement::GetList(
            array(),
            array("IBLOCK_ID" => $iblockID, "PROPERTY_DEAL" => $dealID),
            false,
            array("nPageSize" => 99999),
            array("ID", "PROPERTY_TANXA")
    );
    while ($row = $res->Fetch()) {
        $total += floatval($row['PROPERTY_TANXA_VALUE']);
    }
    return $total;
}

function getDealById($dealID) {
    $res = CCrmDeal::GetListEx(array(), array('ID' => $dealID), false, false, array('*','UF_*'));
    if ($deal = $res->Fetch()) return $deal;
    return null;
}

function getDealProduct($dealID) {
    $products = CCrmDeal::LoadProductRows($dealID);
    if (empty($products)) return null;
    return getProdByID($products[0]['PRODUCT_ID']);
}

function getProdByID($ID) {
    if (!is_numeric($ID) || $ID <= 0) return null;

    $res = CIBlockElement::GetList(array(), array("IBLOCK_ID" => 14, "ID" => $ID), false, array("nPageSize" => 1), array());
    if ($ob = $res->GetNextElement()) {
        $arFields = $ob->GetFields();
        $arProps  = $ob->GetProperties();
        $r = array();
        foreach ($arFields as $k => $v) $r[$k] = $v;
        foreach ($arProps  as $k => $p) $r[$k] = $p["VALUE"];
        if (!empty($r['floorplan']))   $r['image_plan']    = CFile::GetPath($r['floorplan']);   // სართულის გეგმარება
        if (!empty($r['sartulinew']))  $r['image_plan_2d'] = CFile::GetPath($r['sartulinew']);  // ბინის 3D რენდერი
        if (!empty($r['drender']))     $r['image_render']  = CFile::GetPath($r['drender']);     // TODO: field code
        return $r;
    }
    return null;
}

function getCompanyById($id) {
    if (!$id) return null;
    $res = CCrmCompany::GetListEx(array(), array('ID' => $id), false, false, array('ID','TITLE'));
    return $res->Fetch() ?: null;
}

function getContactById($id) {
    if (!$id) return null;
    $res = CCrmContact::GetListEx(array(), array('ID' => $id), false, false, array('ID','NAME','LAST_NAME'));
    return $res->Fetch() ?: null;
}

function getUserById($id) {
    if (!$id) return null;
    $rs = CUser::GetList(($by="ID"), ($ord="ASC"), array("ID" => $id),
            array("SELECT" => array("NAME","LAST_NAME","EMAIL","PERSONAL_MOBILE","WORK_PHONE","PERSONAL_PHOTO","WORK_POSITION")));
    return $rs->Fetch() ?: null;
}

function getScheduleRows($dealID, $iblockID) {
    $rows = array();
    $res = CIBlockElement::GetList(
            array("PROPERTY_TARIGI" => "ASC"),
            array("IBLOCK_ID" => $iblockID, "PROPERTY_DEAL" => $dealID),
            false,
            array("nPageSize" => 99999),
            array("ID", "PROPERTY_TANXA", "PROPERTY_TARIGI")
    );
    while ($row = $res->Fetch()) {
        $rows[] = array(
                'date'   => $row['PROPERTY_TARIGI_VALUE'] ?? '',
                'amount' => floatval($row['PROPERTY_TANXA_VALUE'] ?? 0),
        );
    }
    return $rows;
}

// ======================= MAIN =======================

global $USER;
$currentUserID = $USER->GetID();

$dealID    = intval($_GET['dealID']    ?? 0);
$prodID    = intval($_GET['prodID']    ?? 0);
$managerID = intval($_GET['managerID'] ?? $currentUserID);

$clientName   = '';
$managerData  = null;
$unit         = array();
$source       = '';

// ── Calculator mode detection ──
$fromCalculator = ($_GET['fromCalculator'] ?? '0') === '1';
$calcConditionType = $_GET['conditionType'] ?? 'turnkey';
$calcTotalPrice = floatval($_GET['totalPrice'] ?? 0);
$calcWhiteFrame = floatval($_GET['whiteFrame'] ?? 0);
$calcRenovation = floatval($_GET['renovation'] ?? 0);
$calcFurniture  = floatval($_GET['furniture']  ?? 0);
$calcPricePerSqmWF = floatval($_GET['pricePerSqmWF'] ?? 0);
$calcScheduleJSON = $_GET['schedule'] ?? '';

if ($dealID > 0) {
    $source  = 'deal';
    $deal    = getDealById($dealID);
    $product = getDealProduct($dealID);
    $manager = getUserById($deal['ASSIGNED_BY_ID']);

    // client
    if (!empty($deal['COMPANY_ID'])) {
        $c = getCompanyById($deal['COMPANY_ID']);
        $clientName = $c['TITLE'] ?? '';
    } elseif (!empty($deal['CONTACT_ID'])) {
        $c = getContactById($deal['CONTACT_ID']);
        $clientName = trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? ''));
    }

    // გრაფიკების ჯამი
    if ($fromCalculator) {
        $totalBina = $calcWhiteFrame;
        $totalReno = $calcRenovation;
        $totalFurn = $calcFurniture;
    } else {
        $totalBina  = getScheduleTotal($dealID, 75);
        $totalReno  = getScheduleTotal($dealID, 67);
        $totalFurn  = getScheduleTotal($dealID, 68);
    }

    $dealScheduleRows = array();
    if (!$fromCalculator) {
        $binaRows = getScheduleRows($dealID, 75);
        $renoRows = getScheduleRows($dealID, 67);
        $furnRows = getScheduleRows($dealID, 68);

        // გავაერთიანოთ თარიღების მიხედვით
        $allDates = array();
        foreach ($binaRows as $r) $allDates[$r['date']]['whiteFrame'] = $r['amount'];
        foreach ($renoRows as $r) $allDates[$r['date']]['renovation'] = $r['amount'];
        foreach ($furnRows as $r) $allDates[$r['date']]['furniture'] = $r['amount'];
        ksort($allDates);

        foreach ($allDates as $date => $vals) {
            $wf = $vals['whiteFrame'] ?? 0;
            $rn = $vals['renovation'] ?? 0;
            $fn = $vals['furniture'] ?? 0;
            $dealScheduleRows[] = array(
                    'date' => $date,
                    'whiteFrame' => $wf,
                    'renovation' => $rn,
                    'furniture' => $fn,
                    'total' => $wf + $rn + $fn,
            );
        }
    }

    $unit = array(
            'number'          => $product['Number']                 ?? '',
            'floor'           => $product['FLOOR']                  ?? '',
            'total_area'      => $product['TOTAL_AREA']             ?? '',
            'sub_type'        => $product['_U3FC0U']                ?? '',
            'price'           => $totalBina,
            'kvm_price'       => $fromCalculator ? $calcPricePerSqmWF : ($deal['UF_CRM_1730904711123'] ?? ''),
            'reno_price'      => $totalReno,
            'furniture_price' => $totalFurn,
            'view'            => $product['_ZDR30Z']                ?? '',
            'block'           => $product['KORPUSIS_NOMERI_XE3NX2'] ?? '',
            'image_render'    => $product['image_render']  ?? '',
            'image_plan'      => $product['image_plan']    ?? '',
            'image_plan_2d'   => $product['image_plan_2d'] ?? '',
    );

} elseif ($prodID > 0) {
    $source  = 'catalog';
    $product = getProdByID($prodID);
    $manager = getUserById($managerID);
    // კატალოგიდან კლიენტი არ გვაქვს
    $clientName = '';

    $unit = array(
            'number'      => $product['Number']                  ?? '',
            'floor'       => $product['FLOOR']                   ?? '',
            'total_area'  => $product['TOTAL_AREA']              ?? '',
            'sub_type'    => $product['_U3FC0U'] ?? '',
            'price'       => $product['PRICE']                   ?? '',
            'kvm_price'   => $product['KVM_PRICE']               ?? '', // TODO: product property CODE
            'view'        => $product['_ZDR30Z']                 ?? '',
            'block'       => $product['KORPUSIS_NOMERI_XE3NX2']  ?? '',
            'image_render'  => $product['image_render']  ?? '',
            'image_plan'    => $product['image_plan']    ?? '',
            'image_plan_2d' => $product['image_plan_2d'] ?? '',
    );
}

// manager fields
$managerName  = $manager ? trim(($manager['NAME'] ?? '') . ' ' . ($manager['LAST_NAME'] ?? '')) : '';
$managerPhone = $manager['PERSONAL_MOBILE'] ?: ($manager['WORK_PHONE'] ?? '');
$managerEmail = $manager['EMAIL'] ?? '';
$managerTitle = !empty($manager['WORK_POSITION']) ? $manager['WORK_POSITION'] : 'Senior Property Consultant · Petra Group';
$managerPhoto = '';
if (!empty($manager['PERSONAL_PHOTO'])) {
    $managerPhoto = CFile::GetPath($manager['PERSONAL_PHOTO']);
}

// unit shorthand vars
$uNumber       = htmlspecialchars($unit['number']        ?? '');
$uFloor        = htmlspecialchars($unit['floor']         ?? '');
$uArea         = htmlspecialchars($unit['total_area']    ?? '');
$uSubType      = htmlspecialchars($unit['sub_type']      ?? '');
$uPrice        = htmlspecialchars($unit['price']         ?? '');
$uKvmPrice     = htmlspecialchars($unit['kvm_price']     ?? '');
$uRenoPrice    = htmlspecialchars($unit['reno_price']    ?? '');
$uFurnPrice    = htmlspecialchars($unit['furniture_price'] ?? '');
$uView         = htmlspecialchars($unit['view']          ?? 'Sea & Mountain View');
$uBlock        = htmlspecialchars($unit['block']         ?? 'Block A');
$uImgRender    = $unit['image_plan_2d'] ?? '';
$uFloorPlan    = $unit['image_plan']    ?? '';

$today = date('d/m/Y');
ob_clean(); // Bitrix prolog-ის output გავასუფთავოთ
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Petra Sea Resort — Exclusive Offer</title>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }

            :root {
                --navy: #0F2137;
                --navy-mid: #152B45;
                --navy-light: #1C3A55;
                --teal-cover: #1B5070;
                --gold: #C9963A;
                --gold-light: #E8C87A;
                --white: #FFFFFF;
                --off-white: #F8F8F8;
                --text-muted: #7A8FA6;
                --text-body: #2C3E50;
                --border: #E2E8EF;
                --accent: #1B5070;
                --chip-bg: rgba(255,255,255,0.18);
                --chip-border: rgba(255,255,255,0.35);
            }

            body {
                font-family: 'DM Sans', sans-serif;
                background: #D6D9DE;
                padding: 20px;
            }

            #download-bar {
                position: fixed;
                top: 16px;
                right: 20px;
                z-index: 9999;
            }

            .download-btn {
                background: #0F2137;
                color: #FFFFFF;
                border: none;
                padding: 12px 28px;
                border-radius: 8px;
                font-family: 'DM Sans', sans-serif;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                box-shadow: 0 4px 16px rgba(13,27,42,0.4);
                letter-spacing: 0.3px;
            }
            .download-btn:hover { background: #152B45; }
            .download-btn:disabled { opacity: 0.6; cursor: not-allowed; }

            /* ── OLD ADMIN (hidden) ── */
            #admin-panel {
                max-width: 900px;
                margin: 0 auto 40px;
                background: var(--white);
                border-radius: 16px;
                padding: 32px;
                box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            }

            #admin-panel h2 {
                font-family: 'Playfair Display', serif;
                font-size: 22px;
                color: var(--navy);
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid #E5E0D8;
            }

            .fields-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-bottom: 20px;
            }

            .field-group {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .field-group label {
                font-size: 12px;
                font-weight: 500;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .field-group input, .field-group select, .field-group textarea {
                padding: 10px 14px;
                border: 1px solid #D8D3CB;
                border-radius: 8px;
                font-family: 'DM Sans', sans-serif;
                font-size: 14px;
                color: var(--navy);
                background: #FAFAF8;
                outline: none;
                transition: border-color 0.2s;
            }

            .field-group input:focus, .field-group textarea:focus {
                border-color: var(--gold);
            }

            .unit-section {
                background: #F7F5F2;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 16px;
            }

            .unit-section h3 {
                font-size: 13px;
                font-weight: 500;
                color: var(--navy);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 14px;
                padding-bottom: 8px;
                border-bottom: 1px solid #E0DBD3;
            }

            .add-unit-btn {
                background: none;
                border: 1px dashed var(--gold);
                color: var(--gold);
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 13px;
                cursor: pointer;
                width: 100%;
                transition: all 0.2s;
                margin-bottom: 20px;
            }

            .add-unit-btn:hover { background: rgba(201,169,110,0.08); }

            .remove-unit-btn {
                background: none;
                border: none;
                color: #C0553A;
                font-size: 12px;
                cursor: pointer;
                float: right;
                padding: 2px 8px;
                border-radius: 4px;
            }

            .generate-btn {
                background: var(--navy);
                color: var(--white);
                border: none;
                padding: 14px 32px;
                border-radius: 10px;
                font-family: 'DM Sans', sans-serif;
                font-size: 15px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.2s;
                width: 100%;
            }

            .generate-btn:hover { background: var(--navy-mid); }

            .download-btn {
                background: var(--gold);
                color: var(--navy);
                border: none;
                padding: 14px 32px;
                border-radius: 10px;
                font-family: 'DM Sans', sans-serif;
                font-size: 15px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
                width: 100%;
                margin-top: 12px;
                display: none;
            }

            .download-btn:hover { background: var(--gold-light); }

            /* ── OFFER PAGES ── */
            #offer-output {
                max-width: 794px;
                margin: 0 auto;
            }

            .page {
                width: 794px;
                min-height: 1123px;
                background: var(--white);
                margin-bottom: 20px;
                position: relative;
                overflow: hidden;
                page-break-after: always;
            }

            /* PAGE 1 — COVER */
            #page-cover {
                background: var(--navy);
                display: flex;
                flex-direction: column;
            }

            .cover-hero {
                flex: 1;
                position: relative;
                min-height: 500px;
            }

            .cover-hero-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
                opacity: 0.65;
            }

            .cover-overlay {
                position: absolute;
                inset: 0;
                background: linear-gradient(to bottom, rgba(13,27,42,0.3) 0%, rgba(13,27,42,0.85) 100%);
            }

            .cover-hero-text {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 48px 56px 40px;
            }

            .cover-badge {
                display: inline-block;
                background: var(--gold);
                color: var(--navy);
                font-size: 10px;
                font-weight: 500;
                letter-spacing: 1.5px;
                text-transform: uppercase;
                padding: 5px 14px;
                border-radius: 3px;
                margin-bottom: 20px;
            }

            .cover-title {
                font-family: 'Playfair Display', serif;
                font-size: 58px;
                font-weight: 700;
                color: var(--white);
                line-height: 1;
                letter-spacing: -1px;
                margin-bottom: 6px;
            }

            .cover-subtitle {
                font-family: 'Playfair Display', serif;
                font-size: 58px;
                font-weight: 400;
                color: var(--gold);
                line-height: 1;
                letter-spacing: -1px;
            }

            .cover-info-bar {
                background: var(--navy);
                padding: 32px 56px;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                border-top: 1px solid rgba(201,169,110,0.2);
            }

            .cover-chips {
                display: flex;
                flex-direction: column;
                gap: 10px;
                flex: 1;
            }

            .cover-chip {
                display: inline-flex;
                align-items: center;
                background: rgba(255,255,255,0.06);
                border: 1px solid rgba(255,255,255,0.1);
                color: rgba(255,255,255,0.85);
                font-size: 13px;
                padding: 8px 16px;
                border-radius: 6px;
                width: fit-content;
                gap: 8px;
            }

            .cover-chip::before {
                content: '';
                width: 5px;
                height: 5px;
                border-radius: 50%;
                background: var(--gold);
                flex-shrink: 0;
            }

            .cover-manager-card {
                background: var(--white);
                border-radius: 12px;
                padding: 20px;
                width: 240px;
                flex-shrink: 0;
                margin-left: 40px;
            }

            .manager-photo {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                object-fit: cover;
                margin-bottom: 12px;
                background: #DDD;
            }

            .manager-photo-placeholder {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                background: var(--navy-light);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 12px;
                font-size: 24px;
                color: var(--gold);
                font-family: 'Playfair Display', serif;
            }

            .manager-name {
                font-weight: 500;
                font-size: 14px;
                color: var(--navy);
                margin-bottom: 3px;
            }

            .manager-title {
                font-size: 12px;
                color: var(--text-muted);
                margin-bottom: 14px;
                padding-bottom: 14px;
                border-bottom: 1px solid #EEE;
            }

            .manager-contact-item {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 12px;
                color: #555;
                margin-bottom: 6px;
            }

            .contact-icon {
                width: 18px;
                height: 18px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                flex-shrink: 0;
            }

            .contact-icon.phone { background: #E8F0F7; color: var(--accent); }
            .contact-icon.email { background: #FEF0E6; color: #D06B2A; }
            .contact-icon.wa { background: #E6F4E8; color: #2E7D32; }

            .cover-footer {
                background: var(--navy);
                padding: 16px 56px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-top: 1px solid rgba(255,255,255,0.06);
            }

            .cover-footer-brand {
                font-family: 'Playfair Display', serif;
                font-size: 13px;
                color: var(--gold);
                letter-spacing: 1px;
                text-transform: uppercase;
            }

            .cover-footer-meta {
                font-size: 11px;
                color: var(--text-muted);
            }

            /* PAGE 2 — OVERVIEW */
            #page-overview {
                background: #FFFFFF;
            }

            .page-header {
                background: #0F2137;
                padding: 16px 56px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .page-header-left {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .page-header-brand {
                font-family: 'DM Sans', sans-serif;
                font-size: 13px;
                font-weight: 600;
                color: #FFFFFF;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            }

            .page-header-badge {
                background: transparent;
                border: 1px solid rgba(255,255,255,0.45);
                color: rgba(255,255,255,0.9);
                font-size: 10px;
                letter-spacing: 1px;
                text-transform: uppercase;
                padding: 4px 14px;
                border-radius: 50px;
            }

            .page-header-section {
                font-size: 11px;
                color: rgba(255,255,255,0.55);
                letter-spacing: 0.3px;
                text-align: right;
            }

            .page-body {
                padding: 44px 56px;
            }

            .section-label {
                display: none;
            }

            .section-title {
                font-family: 'DM Sans', sans-serif;
                font-size: 15px;
                font-weight: 700;
                letter-spacing: 1px;
                text-transform: uppercase;
                color: #0F2137;
                margin-bottom: 18px;
            }

            .overview-body-text {
                font-size: 13.5px;
                color: #2C3E50;
                line-height: 1.75;
                margin-bottom: 24px;
                text-align: justify;
            }

            .overview-two-col {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                align-items: start;
            }

            .key-highlights-card {
                background: #FFFFFF;
                border-radius: 12px;
                border: 1px solid #E2E8EF;
                padding: 20px;
            }

            .key-highlights-title {
                font-size: 16px;
                font-weight: 700;
                letter-spacing: 0.3px;
                text-transform: uppercase;
                color: #0F2137;
                margin-bottom: 16px;
            }

            .highlight-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                background: transparent;
                border-radius: 0;
                overflow: visible;
                margin-bottom: 14px;
            }

            .highlight-item {
                background: #EEF1F6;
                padding: 14px 16px;
                border-radius: 10px;
            }

            .highlight-item-label {
                font-size: 10px;
                letter-spacing: 1px;
                text-transform: uppercase;
                color: #7A8FA6;
                margin-bottom: 7px;
            }

            .highlight-item-value {
                font-size: 15px;
                font-weight: 700;
                color: #0F2137;
            }

            .highlight-note {
                font-size: 12px;
                color: #7A8FA6;
                line-height: 1.6;
            }

            .overview-images {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .overview-img-main {
                width: 100%;
                height: 200px;
                object-fit: cover;
                border-radius: 8px;
                background: var(--navy-mid);
            }

            .overview-img-sub {
                width: 100%;
                height: 140px;
                object-fit: cover;
                border-radius: 10px;
                background: #3A5575;
            }

            /* PAGE 3 — COMMERCIAL */
            #page-commercial {
                background: #FFFFFF;
            }

            .units-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                margin-bottom: 28px;
                font-size: 12px;
                border: 1px solid #E2E8EF;
                border-radius: 10px;
                overflow: hidden;
            }

            .units-table thead tr {
                background: #0F2137;
            }

            .units-table thead th {
                color: rgba(255,255,255,0.85);
                font-weight: 600;
                font-size: 9.5px;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                padding: 12px 10px;
                text-align: left;
                vertical-align: bottom;
                line-height: 1.3;
            }

            .units-table thead th:first-child { border-radius: 10px 0 0 0; }
            .units-table thead th:last-child  { border-radius: 0 10px 0 0; }

            .units-table tbody tr {
                border-bottom: 1px solid #EDF0F5;
                background: #FFFFFF;
            }

            .units-table tbody tr:last-child td {
                border-bottom: none;
            }

            .units-table td {
                padding: 12px 10px;
                color: #0F2137;
                font-size: 12px;
                border-bottom: 1px solid #EDF0F5;
                vertical-align: top;
            }

            .units-table td strong {
                font-weight: 600;
            }

            .units-total-row td {
                background: #F5F3EE !important;
                font-weight: 700;
                border-top: 1.5px solid #D8D3CB;
                border-bottom: none !important;
                color: #0F2137;
                font-size: 13px;
            }

            .units-total-row td:first-child { border-radius: 0 0 0 10px; }
            .units-total-row td:last-child  { border-radius: 0 0 10px 0; }

            .payment-plans-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-bottom: 24px;
            }

            .payment-plan-card {
                background: #FFFFFF;
                border-radius: 12px;
                border: 1px solid #E2E8EF;
                overflow: hidden;
                min-width: 0;
            }

            .plan-header {
                background: transparent;
                padding: 16px 16px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .plan-name {
                font-family: 'DM Sans', sans-serif;
                font-size: 14px;
                font-weight: 700;
                color: #0F2137;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .plan-tag {
                background: #F0EDE8;
                border: 1px solid #D8D3CB;
                color: #5A5040;
                font-size: 9px;
                letter-spacing: 0.5px;
                padding: 4px 10px;
                border-radius: 50px;
                text-transform: uppercase;
                font-weight: 500;
                white-space: nowrap;
            }

            .plan-table {
                width: calc(100% - 28px);
                margin: 14px 14px 0;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 11.5px;
                table-layout: fixed;
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid #E2E8EF;
            }

            .plan-table thead tr {
                background: #0F2137;
            }

            .plan-table th {
                background: #0F2137;
                color: rgba(255,255,255,0.8);
                font-weight: 600;
                font-size: 8.5px;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                padding: 8px 8px;
                text-align: left;
            }

            .plan-table thead th:first-child { border-radius: 8px 0 0 0; }
            .plan-table thead th:last-child  { border-radius: 0 8px 0 0; }

            .plan-table th:nth-child(1) { width: 25%; }
            .plan-table th:nth-child(2) { width: 35%; }
            .plan-table th:nth-child(3) { width: 10%; white-space: nowrap; }
            .plan-table th:nth-child(4) { width: 30%; }

            .plan-table td {
                padding: 8px 7px;
                color: #0F2137;
                border-bottom: 1px solid #EDF0F5;
                font-size: 10.5px;
                vertical-align: top;
                word-break: break-word;
            }

            .plan-table td:nth-child(2) { word-break: normal; }
            .plan-table td:nth-child(3) { white-space: nowrap; }

            .plan-table tr:last-child td {
                border-bottom: none;
                font-weight: 700;
                background: #F5F3EE;
                color: #0F2137;
                font-size: 11.5px;
            }

            .plan-table tr:last-child td:first-child { border-radius: 0 0 0 8px; }
            .plan-table tr:last-child td:last-child  { border-radius: 0 0 8px 0; }

            .plan-note {
                font-size: 11px;
                color: #7A8FA6;
                line-height: 1.6;
                padding: 12px 14px;
                border-top: 1px solid #EDF0F5;
            }

            .offer-summary-box {
                background: #F4F6FA;
                border-radius: 10px;
                border: 1px solid #E2E8EF;
                padding: 20px 24px;
                margin-bottom: 16px;
            }

            .offer-summary-box p {
                color: #2C3E50;
                font-size: 13px;
                line-height: 1.7;
                margin-bottom: 6px;
            }

            .offer-summary-box p:last-child { margin-bottom: 0; }

            .disclaimer-text {
                font-size: 11px;
                color: #7A8FA6;
                line-height: 1.6;
                padding: 14px 16px;
                background: #FFFFFF;
                border-radius: 8px;
                border: 1px solid #E2E8EF;
            }

            .disclaimer-text strong {
                color: #0F2137;
            }

            /* PAGE FOOTER LINE */
            .page-footer {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 13px 56px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .footer-brand {
                font-size: 12px;
                color: #7A8FA6;
            }

            .footer-brand strong {
                color: #0F2137;
            }

            .footer-page-num {
                font-size: 11px;
                color: #7A8FA6;
                border: 1px solid #CBD5E0;
                padding: 3px 12px;
                border-radius: 50px;
                background: none;
            }

            @media print {
                body { padding: 0; background: none; }
                .page { margin-bottom: 0; box-shadow: none; }
                #admin-panel, #download-bar, #download-bottom { display: none; }
            }
        </style>
    </head>
    <body>

    <!-- Fixed download bar top-right -->
    <div id="download-bar">
        <button class="download-btn" onclick="downloadPDF(this)">⬇ Download PDF</button>
    </div>

    <!-- Download bar above pages -->
    <div style="max-width:794px; margin:0 auto 16px; display:flex; justify-content:flex-end;">
        <button onclick="downloadPDF(this)"
                style="background:#0F2137; color:#fff; border:none; padding:13px 36px; border-radius:8px; font-family:'DM Sans',sans-serif; font-size:14px; font-weight:500; cursor:pointer; box-shadow:0 4px 16px rgba(15,33,55,0.25); letter-spacing:0.3px;">
            ⬇ &nbsp;Download PDF
        </button>
    </div>

    <!-- Admin panel hidden but kept for getVal() references -->
    <div id="admin-panel" style="display:none">
        <input type="text"  id="f-client"        value="<?= htmlspecialchars($clientName) ?>">
        <input type="date"  id="f-date"          value="<?= date('Y-m-d') ?>">
        <input type="text"  id="f-manager"       value="<?= htmlspecialchars($managerName) ?>">
        <input type="text"  id="f-manager-title" value="<?= htmlspecialchars($managerTitle) ?>">
        <input type="text"  id="f-phone"         value="<?= htmlspecialchars($managerPhone) ?>">
        <input type="text"  id="f-email"         value="<?= htmlspecialchars($managerEmail) ?>">
        <input type="text"  id="f-block"         value="<?= $uBlock ? 'Block ' . $uBlock : 'Block A' ?>">
        <input type="text"  id="f-floor"         value="<?= $uFloor ? $uFloor . 'th Floor' : '10th Floor' ?>">
        <input type="text"  id="f-view"          value="<?= $uView ?: 'Sea & Mountain View' ?>">
        <input type="text"  id="f-type"          value="<?= $uSubType ?: 'Studio (STD)' ?>">
        <div id="units-container"></div>
    </div>

    <!-- ══════════════════════════════════════════
         OFFER OUTPUT
    ══════════════════════════════════════════ -->
    <div id="offer-output">

        <!-- PAGE 1: COVER -->
        <div class="page" id="page-cover" style="position:relative; overflow:hidden; background-color:#0D3A56;">
            <div id="cover-bg" style="position:absolute; inset:0; background-size:cover; background-position:center top;"></div>
            <!-- Subtle tint overlay -->
            <div style="position:absolute; inset:0; background:linear-gradient(180deg, rgba(10,45,75,0.15) 0%, rgba(8,35,60,0.35) 100%);"></div>

            <!-- Content layer -->
            <div style="position:relative; z-index:2; height:100%; display:flex; flex-direction:column; padding:52px 56px 60px;">

                <!-- TOP: Title left, Manager card right -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex:1;">

                    <!-- LEFT: Title + chips + prepared for -->
                    <div style="display:flex; flex-direction:column; flex:1; padding-right:40px; height:100%;">
                        <div>
                            <div style="font-family:'Playfair Display',serif; font-size:68px; font-weight:700; color:#FFFFFF; line-height:0.92; letter-spacing:0px; margin-bottom:4px;">PETRA</div>
                            <div style="font-family:'Playfair Display',serif; font-size:68px; font-weight:700; color:#FFFFFF; line-height:0.92; letter-spacing:0px; margin-bottom:4px;">SEA</div>
                            <div style="font-family:'Playfair Display',serif; font-size:68px; font-weight:700; color:#FFFFFF; line-height:0.92; letter-spacing:0px; margin-bottom:22px;">RESORT</div>
                            <div style="color:#FFFFFF; font-size:13px; font-weight:600; letter-spacing:0.5px; margin-bottom:28px;">Exclusive Offer</div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <div id="cv-block" style="background:#FFFFFF; border:none; color:#0F2137; font-size:13px; padding:9px 20px; border-radius:50px; width:260px;">Block A · 10th Floor</div>
                            <div id="cv-units" style="background:#FFFFFF; border:none; color:#0F2137; font-size:13px; padding:9px 20px; border-radius:50px; width:260px;">Units — · Studio (STD)</div>
                            <div id="cv-view"  style="background:#FFFFFF; border:none; color:#0F2137; font-size:13px; padding:9px 20px; border-radius:50px; width:260px;">Sea & Mountain View</div>
                        </div>

                        <div style="margin-top:28px; padding-bottom:48px;">
                            <div style="font-size:12.5px; color:rgba(255,255,255,0.9); margin-bottom:5px;"><strong>Prepared for:</strong> <span id="cv-client" style="color:rgba(255,255,255,0.7);">Client Name</span></div>
                            <div style="font-size:12.5px; color:rgba(255,255,255,0.9);"><strong>Date:</strong> <span id="cv-date" style="color:rgba(255,255,255,0.7);">{Auto Date}</span></div>
                        </div>
                    </div>

                    <!-- RIGHT: Manager card -->
                    <div class="cover-manager-card" style="flex-shrink:0; margin-top:0; border-radius:12px; overflow:hidden; padding:0; width:260px;">
                        <div id="cv-mgr-initial" style="width:100%; height:220px; overflow:hidden;">
                            <img src="/custom/presentation/managerplaceholder.png" id="cv-mgr-photo"
                                 style="width:100%; height:100%; object-fit:cover; object-position:center top; display:block;">
                        </div>
                        <div style="padding:18px 20px;">
                            <div class="manager-name" id="cv-mgr-name" style="font-size:15px; font-weight:600; color:#0F2137; margin-bottom:3px;">{Manager Name}</div>
                            <div class="manager-title" id="cv-mgr-title" style="font-size:12px; color:#6B8299; margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid #EEE;">Senior Property Consultant · Petra Group</div>
                            <div class="manager-contact-item" style="display:flex; align-items:center; gap:10px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #F0F0F0;">
                                <div class="contact-icon phone" style="width:20px; height:20px; font-size:12px; background:#F0E8F5; border-radius:4px; display:flex; align-items:center; justify-content:center;">📞</div>
                                <span id="cv-mgr-phone" style="font-size:12.5px; color:#333;">Phone</span>
                            </div>
                            <div class="manager-contact-item" style="display:flex; align-items:center; gap:10px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #F0F0F0;">
                                <div class="contact-icon wa" style="width:20px; height:20px; font-size:12px; background:#E8F0F8; border-radius:4px; display:flex; align-items:center; justify-content:center;">💬</div>
                                <span style="font-size:12.5px; color:#333;">WhatsApp</span>
                            </div>
                            <div class="manager-contact-item" style="display:flex; align-items:center; gap:10px;">
                                <div class="contact-icon email" style="width:20px; height:20px; font-size:12px; background:#EEF3F8; border-radius:4px; display:flex; align-items:center; justify-content:center;">✉</div>
                                <span id="cv-mgr-email" style="font-size:12.5px; color:#333;">{Email}</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div style="position:absolute; bottom:0; left:0; right:0; z-index:2; padding:16px 56px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-family:'DM Sans',sans-serif; font-size:12px; color:rgba(255,255,255,0.7);"><strong style="color:#FFFFFF;">Petra Group</strong> · Confidential Offer</span>
                <span style="font-size:11px; color:rgba(255,255,255,0.7); border:1px solid rgba(255,255,255,0.4); border-radius:50px; padding:3px 12px;">1 / 6</span>
            </div>
        </div>

        <!-- PAGE 2: OVERVIEW -->
        <div class="page" id="page-overview">
            <div class="page-header">
                <div class="page-header-left">
                    <span class="page-header-brand">Petra Sea Resort</span>
                    <span class="page-header-badge">Exclusive Offer</span>
                </div>
                <span class="page-header-section">Location & Project Overview</span>
            </div>

            <div class="page-body" style="padding-bottom:60px;">
                <div class="section-title" style="margin-bottom:16px;">Location &amp; Project Overview</div>

                <!-- Text full width -->
                <div style="margin-bottom:16px;">
                    <p class="overview-body-text" style="margin-bottom:0;">
                        Petra Sea Resort is a landmark, large-scale resort development on Georgia's Black Sea coast, designed to set a new standard for integrated, internationally branded destination living. The project is located on approximately 20 hectares of prime coastal land in Tsikhisdziri, just 15 minutes from Batumi, one of the most unique and picturesque locations along the Black Sea. It comprises 42 individual buildings, carefully master-planned to function as a fully self-sufficient resort ecosystem.
                    </p>
                </div>

                <!-- Images + highlights row -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;">

                    <!-- LEFT: tall image - height matched to right col via JS -->
                    <img id="ov-left-img" src="/custom/presentation/petra3.png" alt="Petra sea view"
                         style="width:100%; height:430px; object-fit:cover; border-radius:8px; display:block;">

                    <!-- RIGHT: image + key highlights -->
                    <div id="ov-right-col" style="display:flex; flex-direction:column; gap:16px;">
                        <img src="/custom/presentation/petra1.jpg" alt="Petra aerial view"
                             style="width:100%; height:215px; object-fit:cover; border-radius:8px; display:block;">
                        <div class="key-highlights-card">
                            <div class="key-highlights-title">Key Highlights</div>
                            <div class="highlight-grid">
                                <div class="highlight-item">
                                    <div class="highlight-item-label">View</div>
                                    <div class="highlight-item-value" id="ov-view">Sea & Mountain</div>
                                </div>
                                <div class="highlight-item">
                                    <div class="highlight-item-label">Floor</div>
                                    <div class="highlight-item-value" id="ov-floor">10th Floor</div>
                                </div>
                                <div class="highlight-item">
                                    <div class="highlight-item-label">Units</div>
                                    <div class="highlight-item-value" id="ov-units">—</div>
                                </div>
                                <div class="highlight-item">
                                    <div class="highlight-item-label">Type</div>
                                    <div class="highlight-item-value" id="ov-type">Studio (STD)</div>
                                </div>
                            </div>
                            <p class="highlight-note">Full availability and final pricing are subject to confirmation at the time of booking.</p>
                        </div>
                    </div>

                </div>
            </div>

            <div class="page-footer">
                <span class="footer-brand"><strong>Petra Group</strong> · Confidential Offer</span>
                <span class="footer-page-num">2 / 6</span>
            </div>
        </div>

        <!-- PAGE 3: PROJECT VISUALS -->
        <div class="page" id="page-visuals">
            <div class="page-header">
                <div class="page-header-left">
                    <span class="page-header-brand">Petra Sea Resort</span>
                    <span class="page-header-badge">Exclusive Offer</span>
                </div>
                <span class="page-header-section">Project Visuals</span>
            </div>

            <div class="page-body" style="padding-bottom:60px;">
                <div class="section-title" style="margin-bottom:20px;">Project Visuals</div>

                <!-- Big image top -->
                <img src="/custom/presentation/petra4.jpg" alt="Project Visual Main"
                     style="width:100%; height:260px; object-fit:cover; border-radius:8px; display:block; margin-bottom:14px;">

                <!-- Two images bottom -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                    <img src="/custom/presentation/petra5.jpg" alt="Project Visual Left"
                         style="width:100%; height:200px; object-fit:cover; border-radius:8px; display:block;">
                    <img src="/custom/presentation/petra7.jpg" alt="Project Visual Right"
                         style="width:100%; height:200px; object-fit:cover; border-radius:8px; display:block;">
                </div>

                <p style="font-size:11px; color:#7A8FA6; line-height:1.6;">
                    Visuals are illustrative and may differ from final delivered units. Exact specifications are confirmed in the SPA.
                </p>
            </div>

            <div class="page-footer">
                <span class="footer-brand"><strong>Petra Group</strong> · Confidential Offer</span>
                <span class="footer-page-num">3 / 6</span>
            </div>
        </div>

        <!-- PAGE 4: FLOOR PLAN -->
        <div class="page" id="page-floorplan">
            <div style="background:#FFFFFF; padding:14px 56px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #E2E8EF;">
                <span style="font-size:10px; color:#7A8FA6; letter-spacing:1px; text-transform:uppercase;">Petra Sea Resort · Exclusive Offer</span>
                <span style="font-size:10px; color:#7A8FA6; letter-spacing:0.5px;">Floor Plan</span>
            </div>

            <div class="page-body" style="padding-bottom:60px;">
                <div class="section-title" style="margin-bottom:6px;">Floor Plan — <span id="fp-floor">10th Floor</span></div>
                <p style="font-size:12px; color:#2C3E50; margin-bottom:32px;">
                    Highlighted units: <strong id="fp-units-label">1019</strong>
                </p>

                <!-- Floor plan image card -->
                <div style="border:1px solid #E2E8EF; border-radius:10px; overflow:hidden; padding:32px 24px; background:#FFFFFF; position:relative;">

                    <!-- Floor plan image -->
                    <div>
                        <img id="floorplan-img" src="/custom/presentation/floorplan.png" alt="Floor Plan"
                             style="width:100%; max-height:460px; object-fit:contain; display:block;">
                    </div>
                </div>
            </div>

            <div class="page-footer">
                <span class="footer-brand"><strong>Petra Group</strong> · Confidential Offer</span>
                <span class="footer-page-num">4 / 6</span>
            </div>
        </div>

        <!-- PAGE 5: STUDIO UNIT LAYOUT -->
        <div class="page" id="page-studio">
            <div class="page-header">
                <div class="page-header-left">
                    <span class="page-header-brand">Petra Sea Resort</span>
                    <span class="page-header-badge">Exclusive Offer</span>
                </div>
                <span class="page-header-section">Studio Layout</span>
            </div>

            <div class="page-body" style="padding-bottom:60px;">
                <div class="section-title" style="margin-bottom:6px;">Studio Unit Layout</div>
                <p style="font-size:12px; color:#7A8FA6; margin-bottom:40px;">Layout is indicative and presented for visualization purposes.</p>

                <!-- Render card -->
                <div style="border:1px solid #E2E8EF; border-radius:20px; overflow:hidden; max-width:700px; margin:0 auto; box-shadow:0 2px 20px rgba(0,0,0,0.06);">

                    <!-- Image area — white background like screenshot -->
                    <div style="background:#FFFFFF; padding:36px 32px 20px; position:relative; min-height:460px; display:flex; align-items:center; justify-content:center;">

                        <!-- Top-left logo text -->
                        <div style="position:absolute; top:28px; left:32px; font-family:'Playfair Display',serif; font-size:18px; font-weight:700; color:#0F2137; line-height:1.15;">
                            PETRA<br>SEA<br>RESORT
                        </div>

                        <!-- Render image — large and centered -->
                        <img id="studio-render" src="/custom/presentation/studio-render.png" alt="Studio Render"
                             style="width:85%; max-height:400px; object-fit:contain; display:block; margin:20px auto 0;">
                    </div>

                    <!-- Bottom label bar -->
                    <div style="background:#FFFFFF; padding:14px 24px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:13px; color:#0F2137;"><strong>Studio</strong> · Turn-key concept</span>
                        <span style="font-size:13px; color:#7A8FA6;">Petra Sea Resort</span>
                    </div>
                </div>
            </div>

            <div class="page-footer">
                <span class="footer-brand"><strong>Petra Group</strong> · Confidential Offer</span>
                <span class="footer-page-num">5 / 6</span>
            </div>
        </div>
<?php if (($fromCalculator && $calcTotalPrice > 0) || ($source === 'deal' && ($totalBina > 0 || $totalReno > 0 || $totalFurn > 0))): ?>
        <!-- PAGE 6: COMMERCIAL SUMMARY -->
        <div class="page" id="page-commercial">
            <div class="page-header">
                <div class="page-header-left">
                    <span class="page-header-brand">Petra Sea Resort</span>
                    <span class="page-header-badge">Exclusive Offer</span>
                </div>
                <span class="page-header-section">Commercial Summary</span>
            </div>

            <div class="page-body" style="padding-bottom: 70px;">
                <div class="section-title" style="margin-bottom:6px;">Unit Specification</div>
                <p style="font-size:12px; color:#7A8FA6; margin-bottom:16px;">Data below is taken directly from the provided offer table.</p>

                <table class="units-table">
                    <thead>
                    <tr>
                        <th>Floor</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>View</th>
                        <th>Area<br>(SQM)</th>
                        <th>Price/SQM<br>(White Frame)</th>
                        <th>Total<br>(White Frame)</th>
                        <th>Total +<br>Renovation</th>
                        <th>Total<br>(Turn-Key)</th>
                    </tr>
                    </thead>
                    <tbody id="units-tbody"></tbody>
                </table>

                <div class="section-title" style="margin-bottom:6px; margin-top:8px;">Payment Plan</div>
                <div style="font-size:12px; color:#7A8FA6; margin-bottom:14px;">Payment schedule is provided for convenience and is finalized at booking/SPA stage.</div>

                <div class="payment-plans-grid">
                    <div class="payment-plan-card">
                        <div class="plan-header">
                            <span class="plan-name">Option A</span>
                            <span class="plan-tag">+ Renovation</span>
                        </div>
                        <table class="plan-table">
                            <thead>
                            <tr><th>Stage</th><th>Timing</th><th>%</th><th>Amount (USD)</th></tr>
                            </thead>
                            <tbody id="plan-a-tbody"></tbody>
                        </table>
                        <div class="plan-note">Notes: exact dates and installment frequency are confirmed upon booking and depend on construction milestones.</div>
                    </div>
                    <div class="payment-plan-card">
                        <div class="plan-header">
                            <span class="plan-name">Option B</span>
                            <span class="plan-tag">Turn-Key</span>
                        </div>
                        <table class="plan-table">
                            <thead>
                            <tr><th>Stage</th><th>Timing</th><th>%</th><th>Amount (USD)</th></tr>
                            </thead>
                            <tbody id="plan-b-tbody"></tbody>
                        </table>
                        <div class="plan-note">Notes: turn-key package value is included in totals as per provided offer.</div>
                    </div>
                </div>

                <div class="offer-summary-box">
                    <p id="summary-text">This offer is prepared for the selected units with Sea & Mountain views.</p>
                    <p>Final availability and pricing are confirmed at the time of booking.</p>
                </div>

                <div class="disclaimer-text">
                    <strong>Disclaimer:</strong> This offer is for informational purposes only and does not constitute a legally binding agreement. Final pricing, availability, and payment milestones are subject to confirmation by the developer at the time of booking.
                </div>
            </div>

            <div class="page-footer">
                <span class="footer-brand"><strong>Petra Group</strong> · Confidential Offer</span>
                <span class="footer-page-num">6 / 6</span>
            </div>
        </div>

        <?php endif; ?>
    </div>


    <script>
        const FROM_CALCULATOR = <?= json_encode($fromCalculator) ?>;
        const CALC_CONDITION  = <?= json_encode($calcConditionType) ?>;
        const CALC_SCHEDULE   = <?= json_encode($calcScheduleJSON) ?>;
        const CALC_PRICES = {
            total:      <?= json_encode($calcTotalPrice) ?>,
            whiteFrame: <?= json_encode($calcWhiteFrame) ?>,
            renovation: <?= json_encode($calcRenovation) ?>,
            furniture:  <?= json_encode($calcFurniture) ?>,
            ppmWF:      <?= json_encode($calcPricePerSqmWF) ?>
        };
        const DEAL_SCHEDULE = <?= json_encode(!empty($dealScheduleRows) ? json_encode($dealScheduleRows) : '') ?>;

        let unitCount = 0;
        const units = [];

        function addUnit(data) {
            const idx = unitCount++;
            const d = data || {};
            units.push(idx);

            const section = document.createElement('div');
            section.className = 'unit-section';
            section.id = 'unit-' + idx;
            section.innerHTML = `
    <h3>Unit ${idx + 1} <button class="remove-unit-btn" onclick="removeUnit(${idx})">Remove</button></h3>
    <div class="fields-grid">
      <div class="field-group"><label>Unit Number</label><input type="text" id="u-num-${idx}" placeholder="e.g. 1019" value="${d.num||''}"></div>
      <div class="field-group"><label>Area (sqm)</label><input type="number" id="u-area-${idx}" placeholder="27.45" value="${d.area||''}"></div>
      <div class="field-group"><label>Price/sqm (USD)</label><input type="number" id="u-ppm-${idx}" placeholder="1851.18" value="${d.ppm||''}"></div>
      <div class="field-group"><label>Renovation Cost/sqm (USD)</label><input type="number" id="u-reno-${idx}" placeholder="e.g. 430" value="${d.reno||''}"></div>
      <div class="field-group"><label>Turn-Key Cost/sqm (USD)</label><input type="number" id="u-tk-${idx}" placeholder="e.g. 740" value="${d.tk||''}"></div>
      <input type="hidden" id="u-price-${idx}" value="${d.price||''}">
    </div>`;
            document.getElementById('units-container').appendChild(section);
        }

        function removeUnit(idx) {
            const el = document.getElementById('unit-' + idx);
            if (el) el.remove();
            const pos = units.indexOf(idx);
            if (pos > -1) units.splice(pos, 1);
        }

        function getVal(id) { return (document.getElementById(id)||{}).value || ''; }
        function fmtUSD(n) { return '$' + parseFloat(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }

        function generate() {
            const client  = getVal('f-client')   || 'Client Name';
            const dateRaw = getVal('f-date');
            const dateStr = dateRaw ? new Date(dateRaw).toLocaleDateString('en-GB') : new Date().toLocaleDateString('en-GB');
            const mgr     = getVal('f-manager')  || '{Manager Name}';
            const mgrT    = getVal('f-manager-title');
            const phone   = getVal('f-phone')    || 'Phone';
            const email   = getVal('f-email')    || '{Email}';
            const block   = getVal('f-block')    || 'Block A';
            const floor   = getVal('f-floor')    || '10th Floor';
            const view    = getVal('f-view')     || 'Sea & Mountain View';
            const type    = getVal('f-type')     || 'Studio (STD)';

            // collect active units
            const activeUnits = units
                .filter(idx => document.getElementById('unit-'+idx))
                .map(idx => ({
                    num:   getVal('u-num-'+idx),
                    area:  parseFloat(getVal('u-area-'+idx))  || 0,
                    ppm:   parseFloat(getVal('u-ppm-'+idx))   || 0,
                    price: parseFloat(getVal('u-price-'+idx)) || 0,
                    reno:  parseFloat(getVal('u-reno-'+idx))  || 0,
                    tk:    parseFloat(getVal('u-tk-'+idx))    || 0,
                }));

            // ── COVER PAGE ──
            document.getElementById('cv-block').textContent = block ? block + ' · ' + floor : floor;
            const unitNums = activeUnits.map(u => u.num).join(' & ');
            document.getElementById('cv-units').textContent = 'Unit ' + (unitNums || '—') + ' · ' + type;
            document.getElementById('cv-view').textContent  = view;
            document.getElementById('cv-client').textContent = client;
            document.getElementById('cv-date').textContent   = dateStr;
            document.getElementById('cv-mgr-name').textContent  = mgr;
            document.getElementById('cv-mgr-title').textContent = mgrT;
            document.getElementById('cv-mgr-phone').textContent = phone;
            document.getElementById('cv-mgr-email').textContent = email;
            // manager initial removed — photo placeholder used instead

            // ── OVERVIEW PAGE ──
            document.getElementById('ov-view').textContent  = view.replace(' View','');
            document.getElementById('ov-floor').textContent = floor;
            document.getElementById('ov-units').textContent = unitNums || '—';
            document.getElementById('ov-type').textContent  = type;

            // ── OVERVIEW: match left image height to right column ──
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    const rightCol = document.getElementById('ov-right-col');
                    const leftImg  = document.getElementById('ov-left-img');
                    if (rightCol && leftImg) {
                        leftImg.style.height = rightCol.offsetHeight + 'px';
                    }
                });
            });

            // ── FLOOR PLAN PAGE ──
            const fpFloor = document.getElementById('fp-floor');
            if (fpFloor) fpFloor.textContent = floor;
            const fpUnits = document.getElementById('fp-units-label');
            if (fpUnits) fpUnits.innerHTML = activeUnits.map(u => `<strong>${u.num}</strong>`).join(' and ') || '—';

            // ── COMMERCIAL PAGE ──
            const tbody = document.getElementById('units-tbody');
            if (!tbody) return; // commercial page არ არსებობს

            const condType = FROM_CALCULATOR ? CALC_CONDITION : 'turnkey';

            // ── Unit spec table: dynamic headers ──
            const theadEl = tbody.closest('table').querySelector('thead');
            if (theadEl) {
                let hdr = '<tr><th>Floor</th><th>Unit</th><th>Type</th><th>View</th><th>Area<br>(SQM)</th><th>Price/SQM<br>(White Frame)</th><th>Total<br>(White Frame)</th>';
                if (condType === 'whiteframe_renovation' || condType === 'turnkey') {
                    hdr += '<th>Total +<br>Renovation</th>';
                }
                if (condType === 'turnkey') {
                    hdr += '<th>Total<br>(Turn-Key)</th>';
                }
                hdr += '</tr>';
                theadEl.innerHTML = hdr;
            }

            // ── Compute prices ──
            const u = activeUnits[0];
            if (!u) return;

            let wfPrice, renoPrice, tkPrice, ppmVal;
            if (FROM_CALCULATOR) {
                wfPrice   = CALC_PRICES.whiteFrame;
                renoPrice = CALC_PRICES.whiteFrame + CALC_PRICES.renovation;
                tkPrice   = CALC_PRICES.total;
                ppmVal    = CALC_PRICES.ppmWF;
            } else {
                wfPrice   = u.price > 0 ? u.price : u.area * u.ppm;
                renoPrice = wfPrice + u.reno;
                tkPrice   = wfPrice + u.reno + u.tk;
                ppmVal    = u.ppm;
            }

            // ── Data row ──
            let rowHtml = `<tr>
                <td>${floor.replace('th Floor','').replace('th','').replace('Floor','').trim()}</td>
                <td><strong>${u.num}</strong></td>
                <td>${type.replace(' (STD)','')}</td>
                <td>${view.replace(' View','')}</td>
                <td>${u.area.toFixed(2)}</td>
                <td>${fmtUSD(ppmVal)}</td>
                <td>${fmtUSD(wfPrice)}</td>`;
            if (condType === 'whiteframe_renovation' || condType === 'turnkey') {
                rowHtml += `<td>${fmtUSD(renoPrice)}</td>`;
            }
            if (condType === 'turnkey') {
                rowHtml += `<td>${fmtUSD(tkPrice)}</td>`;
            }
            rowHtml += '</tr>';
            tbody.innerHTML = rowHtml;

            // ── Total row ──
            const colSpan = 6;
            let totalRowHtml = `<tr class="units-total-row"><td colspan="${colSpan}"><strong>Total Price</strong></td><td><strong>${fmtUSD(wfPrice)}</strong></td>`;
            if (condType === 'whiteframe_renovation' || condType === 'turnkey') {
                totalRowHtml += `<td><strong>${fmtUSD(renoPrice)}</strong></td>`;
            }
            if (condType === 'turnkey') {
                totalRowHtml += `<td><strong>${fmtUSD(tkPrice)}</strong></td>`;
            }
            totalRowHtml += '</tr>';
            tbody.innerHTML += totalRowHtml;

            // ── Payment Plans ──
            const planA = document.getElementById('plan-a-tbody');
            const planB = document.getElementById('plan-b-tbody');
            const plansGrid = document.querySelector('.payment-plans-grid');

            if (FROM_CALCULATOR && CALC_SCHEDULE) {
                // ── Calculator mode: show schedule as table ──
                // Hide the two payment plan cards
                if (plansGrid) plansGrid.style.display = 'none';

                // Create schedule table before the summary box
                let scheduleRows;
                try { scheduleRows = JSON.parse(CALC_SCHEDULE); } catch(e) { scheduleRows = []; }

                if (scheduleRows.length > 0) {
                    const summaryBox = document.querySelector('.offer-summary-box');
                    const schedDiv = document.createElement('div');
                    schedDiv.style.marginBottom = '24px';

                    let tbl = '<table class="units-table" style="font-size:11px;"><thead><tr>';
                    tbl += '<th style="width:30px">#</th><th>Date</th><th>Purchase Price</th><th>White Frame</th>';
                    if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += '<th>Renovation</th>';
                    if (condType === 'turnkey') tbl += '<th>Furniture</th>';
                    tbl += '</tr></thead><tbody>';

                    let sumP=0, sumW=0, sumR=0, sumF=0;
                    scheduleRows.forEach((r, i) => {
                        const p = parseFloat((r.total||'0').toString().replace(/[$,]/g,''))||0;
                        const w = parseFloat((r.whiteFrame||'0').toString().replace(/[$,]/g,''))||0;
                        const rn = parseFloat((r.renovation||'0').toString().replace(/[$,]/g,''))||0;
                        const f = parseFloat((r.furniture||'0').toString().replace(/[$,]/g,''))||0;
                        sumP+=p; sumW+=w; sumR+=rn; sumF+=f;
                        tbl += `<tr><td>${i+1}</td><td>${r.date}</td><td>${fmtUSD(p)}</td><td>${fmtUSD(w)}</td>`;
                        if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += `<td>${fmtUSD(rn)}</td>`;
                        if (condType === 'turnkey') tbl += `<td>${fmtUSD(f)}</td>`;
                        tbl += '</tr>';
                    });

                    // Total row
                    tbl += `<tr class="units-total-row"><td colspan="2"><strong>Total</strong></td><td><strong>${fmtUSD(sumP)}</strong></td><td><strong>${fmtUSD(sumW)}</strong></td>`;
                    if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += `<td><strong>${fmtUSD(sumR)}</strong></td>`;
                    if (condType === 'turnkey') tbl += `<td><strong>${fmtUSD(sumF)}</strong></td>`;
                    tbl += '</tr></tbody></table>';

                    schedDiv.innerHTML = tbl;
                    if (summaryBox) summaryBox.parentNode.insertBefore(schedDiv, summaryBox);
                }

            }  else {
            // ── Deal mode: check if IBlock schedule rows exist ──
            if (DEAL_SCHEDULE) {
                // Show schedule table like calculator mode
                if (plansGrid) plansGrid.style.display = 'none';

                let scheduleRows;
                try { scheduleRows = JSON.parse(DEAL_SCHEDULE); } catch(e) { scheduleRows = []; }

                if (scheduleRows.length > 0) {
                    const summaryBox = document.querySelector('.offer-summary-box');
                    const schedDiv = document.createElement('div');
                    schedDiv.style.marginBottom = '24px';

                    let tbl = '<table class="units-table" style="font-size:11px;"><thead><tr>';
                    tbl += '<th style="width:30px">#</th><th>Date</th><th>Purchase Price</th><th>White Frame</th>';
                    if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += '<th>Renovation</th>';
                    if (condType === 'turnkey') tbl += '<th>Furniture</th>';
                    tbl += '</tr></thead><tbody>';

                    let sumP=0, sumW=0, sumR=0, sumF=0;
                    scheduleRows.forEach((r, i) => {
                        const w = parseFloat(r.whiteFrame)||0;
                        const rn = parseFloat(r.renovation)||0;
                        const f = parseFloat(r.furniture)||0;
                        const p = parseFloat(r.total)||0;
                        sumP+=p; sumW+=w; sumR+=rn; sumF+=f;
                        tbl += `<tr><td>${i+1}</td><td>${r.date}</td><td>${fmtUSD(p)}</td><td>${fmtUSD(w)}</td>`;
                        if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += `<td>${fmtUSD(rn)}</td>`;
                        if (condType === 'turnkey') tbl += `<td>${fmtUSD(f)}</td>`;
                        tbl += '</tr>';
                    });

                    tbl += `<tr class="units-total-row"><td colspan="2"><strong>Total</strong></td><td><strong>${fmtUSD(sumP)}</strong></td><td><strong>${fmtUSD(sumW)}</strong></td>`;
                    if (condType === 'whiteframe_renovation' || condType === 'turnkey') tbl += `<td><strong>${fmtUSD(sumR)}</strong></td>`;
                    if (condType === 'turnkey') tbl += `<td><strong>${fmtUSD(sumF)}</strong></td>`;
                    tbl += '</tr></tbody></table>';

                    schedDiv.innerHTML = tbl;
                    if (summaryBox) summaryBox.parentNode.insertBefore(schedDiv, summaryBox);
                }
            } else {
                // ── No schedule rows — fallback to 20/60/20 payment plans ──
                if (plansGrid) plansGrid.style.display = '';

                if (condType === 'whiteframe') {
                    plansGrid.style.gridTemplateColumns = '1fr';
                    if (planA) planA.innerHTML = `
                            <tr><td>Booking / Signing</td><td>On booking</td><td>20%</td><td>${fmtUSD(wfPrice*0.20)}</td></tr>
                            <tr><td>Installments</td><td>During construction</td><td>60%</td><td>${fmtUSD(wfPrice*0.60)}</td></tr>
                            <tr><td>Handover</td><td>On handover</td><td>20%</td><td>${fmtUSD(wfPrice*0.20)}</td></tr>
                            <tr><td colspan="3">Total</td><td>${fmtUSD(wfPrice)}</td></tr>`;
                    if (planB) planB.closest('.payment-plan-card').style.display = 'none';
                    const tagA = planA?.closest('.payment-plan-card')?.querySelector('.plan-tag');
                    if (tagA) tagA.textContent = 'White Frame';
                } else if (condType === 'whiteframe_renovation') {
                    plansGrid.style.gridTemplateColumns = '1fr 1fr';
                    if (planA) planA.innerHTML = `
                            <tr><td>Booking / Signing</td><td>On booking</td><td>20%</td><td>${fmtUSD(wfPrice*0.20)}</td></tr>
                            <tr><td>Installments</td><td>During construction</td><td>60%</td><td>${fmtUSD(wfPrice*0.60)}</td></tr>
                            <tr><td>Handover</td><td>On handover</td><td>20%</td><td>${fmtUSD(wfPrice*0.20)}</td></tr>
                            <tr><td colspan="3">Total</td><td>${fmtUSD(wfPrice)}</td></tr>`;
                    const tagA2 = planA?.closest('.payment-plan-card')?.querySelector('.plan-tag');
                    if (tagA2) tagA2.textContent = 'White Frame';
                    if (planB) planB.innerHTML = `
                            <tr><td>Booking / Signing</td><td>On booking</td><td>20%</td><td>${fmtUSD(renoPrice*0.20)}</td></tr>
                            <tr><td>Installments</td><td>During construction</td><td>60%</td><td>${fmtUSD(renoPrice*0.60)}</td></tr>
                            <tr><td>Handover</td><td>On handover</td><td>20%</td><td>${fmtUSD(renoPrice*0.20)}</td></tr>
                            <tr><td colspan="3">Total</td><td>${fmtUSD(renoPrice)}</td></tr>`;
                    const tagB2 = planB?.closest('.payment-plan-card')?.querySelector('.plan-tag');
                    if (tagB2) tagB2.textContent = '+ Renovation';
                } else {
                    plansGrid.style.gridTemplateColumns = '1fr 1fr';
                    if (planA) planA.innerHTML = `
                            <tr><td>Booking / Signing</td><td>On booking</td><td>20%</td><td>${fmtUSD(renoPrice*0.20)}</td></tr>
                            <tr><td>Installments</td><td>During construction</td><td>60%</td><td>${fmtUSD(renoPrice*0.60)}</td></tr>
                            <tr><td>Handover</td><td>On handover</td><td>20%</td><td>${fmtUSD(renoPrice*0.20)}</td></tr>
                            <tr><td colspan="3">Total</td><td>${fmtUSD(renoPrice)}</td></tr>`;
                    if (planB) planB.innerHTML = `
                            <tr><td>Booking / Signing</td><td>On booking</td><td>20%</td><td>${fmtUSD(tkPrice*0.20)}</td></tr>
                            <tr><td>Installments</td><td>During construction</td><td>60%</td><td>${fmtUSD(tkPrice*0.60)}</td></tr>
                            <tr><td>Handover</td><td>On handover</td><td>20%</td><td>${fmtUSD(tkPrice*0.20)}</td></tr>
                            <tr><td colspan="3">Total</td><td>${fmtUSD(tkPrice)}</td></tr>`;
                }
            }
        }

            // ── Summary text ──
            const floorNum = floor.match(/\d+/)?.[0] || '';
            const summaryEl = document.getElementById('summary-text');
            if (summaryEl) summaryEl.textContent =
                `This offer is prepared for one studio unit on the ${floorNum ? floorNum + 'th floor' : floor} (unit ${u.num}) with ${view}.`;
        }

        async function downloadPDF(btn) {
            if (btn) { btn.textContent = 'Generating...'; btn.disabled = true; }
            try {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4', compress:true });
                const pages = document.querySelectorAll('#offer-output .page');
                for (let i = 0; i < pages.length; i++) {
                    const canvas = await html2canvas(pages[i], {
                        scale: 3,
                        useCORS: true,
                        allowTaint: true,
                        logging: false,
                        backgroundColor: '#ffffff',
                        imageTimeout: 0,
                        windowWidth: pages[i].offsetWidth,
                        windowHeight: pages[i].offsetHeight
                    });
                    const img = canvas.toDataURL('image/jpeg', 1.0);
                    if (i > 0) pdf.addPage();
                    pdf.addImage(img, 'JPEG', 0, 0, 210, 297, '', 'FAST');
                }
                const clientName = document.getElementById('f-client').value.replace(/\s+/g,'_') || 'offer';
                pdf.save('PetraSeaResort_' + clientName + '.pdf');
            } catch(e) {
                alert('PDF error: ' + e.message);
            }
            if (btn) { btn.textContent = '⬇ Download PDF'; btn.disabled = false; }
        }

        // ── LOAD COVER IMAGE AS BASE64 for crisp rendering ──
        function loadCoverImage() {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                const canvas = document.createElement('canvas');
                canvas.width  = img.naturalWidth;
                canvas.height = img.naturalHeight;
                canvas.getContext('2d').drawImage(img, 0, 0);
                const b64 = canvas.toDataURL('image/jpeg', 1.0);
                const bg = document.getElementById('cover-bg');
                if (bg) bg.style.backgroundImage = 'url(' + b64 + ')';
            };
            img.onerror = function() {
                const bg = document.getElementById('cover-bg');
                if (bg) bg.style.backgroundImage = "url('/custom/presentation/petra2.jpeg')";
            };
            img.src = '/custom/presentation/petra2.jpeg';
        }
        loadCoverImage();

        // ── INIT FROM PHP DATA ──
        <?php if ($uImgRender): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const sr = document.getElementById('studio-render');
            if (sr) sr.src = '<?= $uImgRender ?>';
        });
        <?php endif; ?>

        <?php if ($uFloorPlan): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const fp = document.getElementById('floorplan-img');
            if (fp) fp.src = '<?= $uFloorPlan ?>';
        });
        <?php endif; ?>

        <?php if ($managerPhoto): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const el = document.getElementById('cv-mgr-photo');
            if (el) el.src = '<?= $managerPhoto ?>';
        });
        <?php endif; ?>

        // init & auto-generate on load
        <?php if ($fromCalculator || $source === 'deal' || $source === 'catalog'): ?>
        // PHP data — single unit
        addUnit({
            num:   '<?= $uNumber ?>',
            area:  <?= floatval($uArea)      ?: 0 ?>,
            ppm:   <?= floatval($uKvmPrice)  ?: 0 ?>,
            price: <?= floatval($uPrice)     ?: 0 ?>,
            reno:  <?= floatval($uRenoPrice) ?: 0 ?>,
            tk:    <?= floatval($uFurnPrice) ?: 0 ?>,
        });
        <?php else: ?>
        // dev mode defaults
        addUnit({ num:'1019', area:27.45, ppm:1851.18, reno:429.80, tk:740.00 });
        addUnit({ num:'1021', area:27.75, ppm:1851.18, reno:428.88, tk:740.00 });
        <?php endif; ?>
        generate();
    </script>
    </body>
    </html>
<?php
$content = ob_get_clean();
// Bitrix-ის layout გარეშე, მხოლოდ ჩვენი HTML
die($content);
?>