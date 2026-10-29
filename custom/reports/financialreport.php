<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

/**
 * Safely converts Bitrix money/number values to float.
 * Handles: "", null, "1500|USD", "1500|GEL", "1 500.50", "1,500.50", "1500,50"
 */
function toNum($val) {
    if (is_array($val)) $val = reset($val);
    if ($val === null || $val === '' || $val === false) return 0.0;
    if (is_int($val) || is_float($val)) return (float)$val;

    $val = trim((string)$val);
    $val = preg_replace('/\|[A-Z]{3}$/i', '', $val); // strip currency suffix
    $val = str_replace([' ', "\xC2\xA0"], '', $val);  // spaces / nbsp

    if (strpos($val, ',') !== false && strpos($val, '.') !== false) {
        $val = str_replace(',', '', $val);            // 1,500.50 -> 1500.50
    } else {
        $val = str_replace(',', '.', $val);           // 1500,50 -> 1500.50
    }

    return is_numeric($val) ? (float)$val : 0.0;
}

function getDealInfoByID ($dealID, $arrSelect = array()) {
    // If no specific fields are requested, use "*" to get all available
    if(empty($arrSelect)) {
        $arrSelect = array("*", "UF_*");
    }

    $res = CCrmDeal::GetList(array("ID" => "ASC"), array("ID" => $dealID), $arrSelect);

    if($arDeal = $res->Fetch()){
        return $arDeal;
    }
    return null;
}


function getPaymentPlan($arFilter = array())
{
    $arElements = array();
    $arSelect = Array("ID", "IBLOCK_SECTION_ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize" => 99999), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();

        $arPushs = array();
        $arPushs["ID"] = $arFilds["ID"];
        $arPushs["DATE"] = $arProps["TARIGI"]["VALUE"];
        $arPushs["PAYMENT"] = "";
        $arPushs["PLAN"] = toNum($arProps["TANXA"]["VALUE"]);
        $arPushs["TYPE"] = "PLAN";
        $arPushs["refund"] = "";
        $arElements[] = $arPushs;
    }

    return $arElements;
}

function getPaymentPlanGEL($arFilter = array())
{
    $arElements = array();
    $arSelect = Array("ID", "IBLOCK_SECTION_ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize" => 99999), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();

        $arPushs = array();
        $arPushs["ID"] = $arFilds["ID"];
        $arPushs["DATE"] = $arProps["TARIGI"]["VALUE"];
        $arPushs["PAYMENT"] = "";
        $arPushs["PLAN"] = toNum($arProps["amount_GEL"]["VALUE"]);
        $arPushs["TYPE"] = "PLAN";
        $arPushs["refund"] = "";
        $arElements[] = $arPushs;
    }

    return $arElements;
}


function getPayments($arFilter = array())
{
    $arElements = array();
    $arSelect = Array("ID", "IBLOCK_SECTION_ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize" => 99999), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();

        $arPushs = array();
        $arPushs["ID"] = $arFilds["ID"];
        $arPushs["DATE"] = $arProps["date"]["VALUE"];
        $arPushs["PAYMENT"] = toNum($arProps["TANXA"]["VALUE"]);
        $arPushs["PLAN"] = "";
        $arPushs["TYPE"] = "PAYMENT";
        $arPushs["refund"] = $arProps["refund"]["VALUE"];
        $arElements[] = $arPushs;
    }

    return $arElements;
}


function getPaymentsGEL($arFilter = array())
{
    $arElements = array();
    $arSelect = Array("ID", "IBLOCK_SECTION_ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize" => 99999), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();

        $arPushs = array();
        $arPushs["ID"] = $arFilds["ID"];
        $arPushs["DATE"] = $arProps["date"]["VALUE"];
        $arPushs["PAYMENT"] = toNum($arProps["tanxa_gel"]["VALUE"]);
        $arPushs["PLAN"] = "";
        $arPushs["TYPE"] = "PAYMENT";
        $arPushs["refund"] = $arProps["refund"]["VALUE"];
        $arElements[] = $arPushs;
    }

    return $arElements;
}


function sortByDate($a, $b) {
    $dateA = DateTime::createFromFormat('d/m/Y', $a['DATE']);
    $dateB = DateTime::createFromFormat('d/m/Y', $b['DATE']);
    return $dateA <=> $dateB;
}


$deal_ID = (int)$_GET["dealid"];
$href="/crm/deal/details/$deal_ID/";

$dealData = getDealInfoByID($deal_ID, array("ID", "TITLE", "UF_CRM_1702019032102"));

$valuta = ($dealData["UF_CRM_1702019032102"] == 322) ? "₾" : "$";

// Info-block IDs
$IBLOCK_PLAN     = 22; // payment plan (was 24)
$IBLOCK_PAYMENTS = 23; // payments     (was 25)

if($dealData["UF_CRM_1702019032102"] == 322){
    $payments = getPaymentsGEL(array("IBLOCK_ID" => $IBLOCK_PAYMENTS,"PROPERTY_DEAL"=>$deal_ID));
    $paymentPlans = getPaymentPlanGEL(array("IBLOCK_ID" => $IBLOCK_PLAN,"PROPERTY_DEAL"=>$deal_ID));
}
else{
    $payments = getPayments(array("IBLOCK_ID" => $IBLOCK_PAYMENTS,"PROPERTY_DEAL"=>$deal_ID));
    $paymentPlans = getPaymentPlan(array("IBLOCK_ID" => $IBLOCK_PLAN,"PROPERTY_DEAL"=>$deal_ID));
}
$financeArr = array_merge($paymentPlans, $payments);

usort($financeArr, 'sortByDate');

$prevLeft = 0.0;
for ($i = 0; $i < count($financeArr); $i++) {
    if ($financeArr[$i]["TYPE"] == "PLAN") {
        $delta = toNum($financeArr[$i]["PLAN"]);
    } elseif ($financeArr[$i]["refund"] == "YES") {
        $delta = toNum($financeArr[$i]["PAYMENT"]);
    } else {
        $delta = -toNum($financeArr[$i]["PAYMENT"]);
    }

    $prevLeft = round($prevLeft + $delta, 2);
    $financeArr[$i]["leftToPay"] = $prevLeft;
}

// ── Totals for summary cards ──
$totalPlan = 0.0;
$totalPaid = 0.0;
foreach ($financeArr as $row) {
    if ($row["TYPE"] == "PLAN") {
        $totalPlan += toNum($row["PLAN"]);
    } elseif ($row["refund"] == "YES") {
        $totalPaid -= toNum($row["PAYMENT"]);
    } else {
        $totalPaid += toNum($row["PAYMENT"]);
    }
}
$balance  = round($totalPlan - $totalPaid, 2);
$progress = $totalPlan > 0 ? max(0, min(100, round($totalPaid / $totalPlan * 100))) : 0;

function fmtMoney($n, $cur) {
    $sign = $n < 0 ? "-" : "";
    return $sign . $cur . number_format(abs($n), 2, '.', ',');
}
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
    .fr-wrap {
        --fr-ink: #1f2937;
        --fr-muted: #6b7280;
        --fr-line: #e5e7eb;
        --fr-bg: #f6f7fb;
        --fr-card: #ffffff;
        --fr-blue: #1c7ed6;
        --fr-teal: #15aabf;
        --fr-green: #0ca678;
        --fr-red: #e03131;
        --fr-amber: #f08c00;

        font-family: 'Noto Sans Georgian', sans-serif;
        color: var(--fr-ink);
        max-width: 1100px;
        margin: 24px auto;
        padding: 0 16px;
    }

    .fr-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        padding: 20px 24px;
        border-radius: 16px;
        background: linear-gradient(135deg, var(--fr-blue), var(--fr-teal));
        color: #fff;
        box-shadow: 0 8px 24px rgba(28,126,214,.25);
    }
    .fr-head__label {
        font-size: 12px;
        letter-spacing: .6px;
        text-transform: uppercase;
        opacity: .85;
        margin-bottom: 4px;
    }
    .fr-head__title {
        font-size: 20px;
        font-weight: 700;
        color: #fff !important;
        text-decoration: none !important;
    }
    .fr-head__title:hover { text-decoration: underline !important; }

    .fr-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border: 0;
        border-radius: 999px;
        background: rgba(255,255,255,.18);
        color: #fff;
        font: 600 13px 'Noto Sans Georgian', sans-serif;
        cursor: pointer;
        transition: background .2s, transform .2s;
    }
    .fr-btn:hover { background: rgba(255,255,255,.3); transform: translateY(-1px); }

    .fr-cards {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin: 20px 0;
    }
    .fr-card {
        background: var(--fr-card);
        border: 1px solid var(--fr-line);
        border-radius: 14px;
        padding: 16px 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .fr-card__label {
        font-size: 12px;
        color: var(--fr-muted);
        margin-bottom: 6px;
    }
    .fr-card__value {
        font-size: 22px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .fr-card--paid .fr-card__value { color: var(--fr-green); }
    .fr-card--due  .fr-card__value { color: var(--fr-red); }
    .fr-card--ok   .fr-card__value { color: var(--fr-green); }

    .fr-progress {
        margin-top: 10px;
        height: 6px;
        border-radius: 999px;
        background: #eef0f4;
        overflow: hidden;
    }
    .fr-progress > span {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--fr-green), #38d9a9);
    }
    .fr-progress__txt {
        font-size: 11px;
        color: var(--fr-muted);
        margin-top: 6px;
    }

    .fr-table-card {
        background: var(--fr-card);
        border: 1px solid var(--fr-line);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .fr-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .fr-table thead th {
        background: var(--fr-bg);
        color: var(--fr-muted);
        font-weight: 600;
        font-size: 12px;
        text-align: left;
        padding: 12px 16px;
        border-bottom: 1px solid var(--fr-line);
        white-space: nowrap;
    }
    .fr-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f1f3f5;
        font-variant-numeric: tabular-nums;
    }
    .fr-table tbody tr:last-child td { border-bottom: 0; }
    .fr-table tbody tr:hover { background: #fafbfd; }
    .fr-table .num { text-align: right; }
    .fr-table .idx { color: #adb5bd; width: 40px; }

    .fr-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    .fr-badge--plan   { background: #e7f5ff; color: var(--fr-blue); }
    .fr-badge--pay    { background: #e6fcf5; color: var(--fr-green); }
    .fr-badge--refund { background: #fff4e6; color: var(--fr-amber); }

    .fr-pos { color: var(--fr-red); font-weight: 600; }
    .fr-neg { color: var(--fr-green); font-weight: 600; }
    .fr-dim { color: #ced4da; }

    .fr-empty {
        padding: 40px;
        text-align: center;
        color: var(--fr-muted);
    }

    .fr-head__actions { display: flex; gap: 8px; flex-wrap: wrap; }

    /* ── ცნობის modal ── */
    .crt-modal {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(17,24,39,.45);
        font-family: 'Noto Sans Georgian', sans-serif;
    }
    .crt-modal.is-open { display: flex; }
    .crt-dialog {
        position: relative;
        width: 100%;
        max-width: 440px;
        background: #fff;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 20px 50px rgba(0,0,0,.2);
        color: var(--fr-ink);
    }
    .crt-x {
        position: absolute;
        top: 10px;
        right: 12px;
        border: 0;
        background: none;
        font-size: 24px;
        line-height: 1;
        color: var(--fr-muted);
        cursor: pointer;
    }
    .crt-dialog__title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
    .crt-dialog__sub { font-size: 13px; color: var(--fr-muted); margin-bottom: 16px; }
    .crt-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .crt-opt {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
        padding: 14px 16px;
        border: 1.5px solid var(--fr-line);
        border-radius: 12px;
        background: #fff;
        text-align: left;
        cursor: pointer;
        font-family: inherit;
        color: var(--fr-ink);
        transition: border-color .15s, background .15s, transform .15s;
    }
    .crt-opt:hover { border-color: var(--fr-blue); background: #f3f9ff; transform: translateY(-1px); }
    .crt-opt__tag {
        font-size: 11px;
        font-weight: 700;
        color: var(--fr-blue);
        background: #e7f5ff;
        border-radius: 6px;
        padding: 2px 7px;
    }
    .crt-opt__name { font-size: 14px; font-weight: 600; }
    .crt-opt__hint { font-size: 11px; color: var(--fr-muted); }
    .crt-opt__warn { font-size: 11px; font-weight: 600; color: var(--fr-amber); }
    .crt-back {
        border: 0;
        background: none;
        padding: 0;
        margin-bottom: 10px;
        font: 600 12px 'Noto Sans Georgian', sans-serif;
        color: var(--fr-blue);
        cursor: pointer;
    }

    @media (max-width: 720px) {
        .fr-cards { grid-template-columns: 1fr; }
        .fr-table-card { overflow-x: auto; }
    }
    @media (max-width: 420px) {
        .crt-opts { grid-template-columns: 1fr; }
    }
</style>

<div class="fr-wrap">

    <div class="fr-head">
        <div>
            <div class="fr-head__label">ფინანსური ბარათი</div>
            <a class="fr-head__title" href="<?php echo $href; ?>" target="_blank"><?php echo htmlspecialchars($dealData["TITLE"] ?? ""); ?></a>
        </div>
        <div class="fr-head__actions">
            <button class="fr-btn" type="button" onclick="crtOpen()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M4 1.8h5.2L12.5 5v9.2H4z M9 1.8V5.2h3.4 M6 8.2h4.5 M6 10.8h4.5" stroke="#fff" stroke-width="1.4" stroke-linejoin="round" stroke-linecap="round"/></svg>
                ცნობა
            </button>
            <button class="fr-btn" type="button" onclick="exportTableToExcel()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 12.5h10" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Export
            </button>
        </div>
    </div>

    <!-- ცნობის არჩევა: ენა → ტიპი -->
    <div class="crt-modal" id="crtModal" onclick="if (event.target === this) crtClose()">
        <div class="crt-dialog" role="dialog" aria-modal="true" aria-labelledby="crtTitle">
            <button class="crt-x" type="button" onclick="crtClose()" aria-label="დახურვა">×</button>

            <div class="crt-step" id="crtStepLang">
                <div class="crt-dialog__title" id="crtTitle">ცნობა</div>
                <div class="crt-dialog__sub">აირჩიეთ ენა</div>
                <div class="crt-opts">
                    <button class="crt-opt" type="button" onclick="crtPickLang('ka')">
                        <span class="crt-opt__tag">GE</span>
                        <span class="crt-opt__name">ქართული</span>
                    </button>
                    <button class="crt-opt" type="button" onclick="crtPickLang('en')">
                        <span class="crt-opt__tag">EN</span>
                        <span class="crt-opt__name">English</span>
                    </button>
                </div>
            </div>

            <div class="crt-step" id="crtStepType" hidden>
                <button class="crt-back" type="button" onclick="crtBack()">← ენა: <span id="crtLangLbl"></span></button>
                <div class="crt-dialog__sub">რომელი ცნობა გსურთ?</div>
                <div class="crt-opts">
                    <button class="crt-opt" type="button" onclick="crtPickType('full')">
                        <span class="crt-opt__name">სრულ დაფარვაზე</span>
                        <span class="crt-opt__hint">ღირებულება სრულად გადახდილია</span>
                        <?php if ($balance > 0): ?>
                            <span class="crt-opt__warn">ნაშთი: <?php echo fmtMoney($balance, $valuta); ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="crt-opt" type="button" onclick="crtPickType('partial')">
                        <span class="crt-opt__name">ნაწილობრივ დაფარვაზე</span>
                        <span class="crt-opt__hint">გადახდილი და დარჩენილი თანხა</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="fr-cards">
        <div class="fr-card">
            <div class="fr-card__label">განვადების ჯამი</div>
            <div class="fr-card__value"><?php echo fmtMoney($totalPlan, $valuta); ?></div>
        </div>
        <div class="fr-card fr-card--paid">
            <div class="fr-card__label">გადახდილი</div>
            <div class="fr-card__value"><?php echo fmtMoney($totalPaid, $valuta); ?></div>
            <div class="fr-progress"><span style="width: <?php echo $progress; ?>%"></span></div>
            <div class="fr-progress__txt"><?php echo $progress; ?>%</div>
        </div>
        <div class="fr-card <?php echo $balance > 0 ? 'fr-card--due' : 'fr-card--ok'; ?>">
            <div class="fr-card__label">ნაშთი</div>
            <div class="fr-card__value"><?php echo fmtMoney($balance, $valuta); ?></div>
        </div>
    </div>

    <div class="fr-table-card">
        <table class="fr-table" id="table">
            <thead>
                <tr>
                    <th>N</th>
                    <th>თარიღი</th>
                    <th>ტიპი</th>
                    <th class="num">განვადების თანხა</th>
                    <th class="num">გადახდილი თანხა</th>
                    <th class="num">ნაშთი</th>
                </tr>
            </thead>
            <tbody id="financial_data"></tbody>
        </table>
    </div>

</div>

<script>
    const financeArr = <?php echo json_encode($financeArr); ?>;
    const valuta     = <?php echo json_encode($valuta); ?>;
    const deal_ID    = <?php echo json_encode($deal_ID); ?>;

    // ── ცნობა: ენა → ტიპი → certificate.php ──
    let crtLang = "ka";
    const crtModal = document.getElementById("crtModal");

    function crtOpen() {
        crtBack();
        crtModal.classList.add("is-open");
    }
    function crtClose() {
        crtModal.classList.remove("is-open");
    }
    function crtBack() {
        document.getElementById("crtStepLang").hidden = false;
        document.getElementById("crtStepType").hidden = true;
    }
    function crtPickLang(lang) {
        crtLang = lang;
        document.getElementById("crtLangLbl").textContent = lang === "en" ? "English" : "ქართული";
        document.getElementById("crtStepLang").hidden = true;
        document.getElementById("crtStepType").hidden = false;
    }
    function crtPickType(type) {
        window.open("/crm/deal/certificate.php?dealID=" + encodeURIComponent(deal_ID)
            + "&lang=" + crtLang + "&type=" + type, "_blank");
        crtClose();
    }
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") crtClose();
    });

    function fmt(n) {
        const v = Number(n) || 0;
        return (v < 0 ? "-" : "") + valuta + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function paymentSigned(row) {
        const v = Number(row.PAYMENT) || 0;
        return row.refund === "YES" ? -v : v;
    }

    const tbody = document.getElementById("financial_data");

    if (!financeArr.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="fr-empty">მონაცემები არ მოიძებნა</td></tr>';
    } else {
        let rows = "";
        financeArr.forEach(function (row, i) {
            const isPlan   = row.TYPE === "PLAN";
            const isRefund = !isPlan && row.refund === "YES";

            const badge = isPlan
                ? '<span class="fr-badge fr-badge--plan">გრაფიკი</span>'
                : (isRefund
                    ? '<span class="fr-badge fr-badge--refund">დაბრუნება</span>'
                    : '<span class="fr-badge fr-badge--pay">გადახდა</span>');

            const left = Number(row.leftToPay) || 0;

            rows += '<tr>'
                + '<td class="idx">' + (i + 1) + '</td>'
                + '<td>' + (row.DATE || '') + '</td>'
                + '<td>' + badge + '</td>'
                + '<td class="num">' + (isPlan ? fmt(row.PLAN) : '<span class="fr-dim">—</span>') + '</td>'
                + '<td class="num">' + (!isPlan ? fmt(paymentSigned(row)) : '<span class="fr-dim">—</span>') + '</td>'
                + '<td class="num ' + (left > 0 ? 'fr-pos' : 'fr-neg') + '">' + fmt(left) + '</td>'
                + '</tr>';
        });
        tbody.innerHTML = rows;
    }

    function exportTableToExcel() {
        const data = [["N", "თარიღი", "ტიპი", "განვადების თანხა", "გადახდილი თანხა", "ნაშთი"]];

        financeArr.forEach(function (row, i) {
            const isPlan = row.TYPE === "PLAN";
            const type = isPlan ? "გრაფიკი" : (row.refund === "YES" ? "დაბრუნება" : "გადახდა");
            data.push([
                i + 1,
                row.DATE || "",
                type,
                isPlan ? Number(row.PLAN) || 0 : "",
                !isPlan ? paymentSigned(row) : "",
                Number(row.leftToPay) || 0
            ]);
        });

        const ws = XLSX.utils.aoa_to_sheet(data);
        ws['!cols'] = [{ wch: 5 }, { wch: 12 }, { wch: 12 }, { wch: 18 }, { wch: 18 }, { wch: 14 }];

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "financialReport");
        XLSX.writeFile(wb, "financialReport_<?php echo $deal_ID; ?>.xlsx");
    }
</script>