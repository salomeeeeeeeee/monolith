<?php
// ხელშეკრულების ეტაპის ვადის გაგრძელების მოთხოვნა (იხსნება დილის ბარათიდან, ღილაკი "ვადის გაგრძელება").
// კომენტარი სავალდებულოა; მოთხოვნას ნუკი (user 9) ადასტურებს BP-ში, იხ. local/php_interface/contract_deadline.php
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

use Bitrix\Main\Loader;

if (!Loader::includeModule('crm')) {
    die('CRM module not loaded');
}
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/contract_deadline.php");

$APPLICATION->SetTitle("ვადის გაგრძელება");

$deal_id = (int)($_GET["deal_id"] ?? 0);
if (!$deal_id) die("No deal ID");

$deal    = CCrmDeal::GetListEx([], ["=ID" => $deal_id, "CHECK_PERMISSIONS" => "N"], false, false, ["ID", "TITLE", "STAGE_ID"])->Fetch();
$info    = $deal ? contractDeadlineForDeal($deal_id) : false;
$pending = $info ? contractDeadlineRequestPending($deal_id) : false;
$minDate = $info ? date("Y-m-d", strtotime("+1 day", strtotime(date("Y-m-d", $info["DEADLINE"])))) : "";

ob_end_clean();
?>

<div style="width:650px; padding:20px; background:#eef2f4; font-family:'Noto Sans Georgian',sans-serif;">

<?php if (!$deal): ?>
    <div style="padding:14px; background:#fee2e2; color:#991b1b; border-radius:10px;">დილი ვერ მოიძებნა.</div>
<?php elseif (!$info): ?>
    <div style="padding:14px; background:#fee2e2; color:#991b1b; border-radius:10px;">
        ვადის გაგრძელება მხოლოდ "ხელშეკრულების" ეტაპზე შეიძლება.
    </div>
<?php else: ?>

    <div style="margin-bottom:15px; padding:14px 18px; background:#fff; border:2px solid #e2e8f0; border-radius:10px; font-size:14px; color:#2d3748;">
        <div>ეტაპზე გადასვლა: <b><?= contractDeadlineFormat($info["ENTERED"]) ?></b></div>
        <div style="margin-top:6px;">მიმდინარე ვადა: <b><?= contractDeadlineFormat($info["DEADLINE"]) ?></b>
            <?php if ($info["EXTENDED"]): ?><span style="color:#718096;">(გაგრძელებული)</span><?php endif; ?>
        </div>
    </div>

<?php if ($pending): ?>
    <div style="padding:14px; background:#fef3c7; color:#92400e; border-radius:10px;">
        გაგრძელების მოთხოვნა უკვე გაგზავნილია და ნუკის დასტურს ელოდება.
    </div>
<?php else: ?>

    <div style="margin-bottom:15px;">
        <label style="display:block; color:#4a5568; font-weight:600; margin-bottom:8px; font-size:14px;">
            <span style="color:red">*</span> ახალი ვადა:
        </label>
        <input type="date" id="contr_date" min="<?= htmlspecialchars($minDate) ?>"
               style="width:100%; padding:14px 18px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; outline:none; background:white; box-sizing:border-box; cursor:pointer;"
               onclick="this.showPicker()" />
    </div>

    <div style="margin-bottom:15px;">
        <label style="display:block; color:#4a5568; font-weight:600; margin-bottom:8px; font-size:14px;">
            <span style="color:red">*</span> კომენტარი (რატომ სჭირდება მეტი დრო):
        </label>
        <textarea id="comment" rows="3"
                  style="width:100%; padding:14px 18px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; outline:none; background:white; box-sizing:border-box; resize:vertical; font-family:inherit;"></textarea>
    </div>

    <button id="saveBtn" onclick="sendExtendRequest()"
            style="background:#38a169; color:#fff; padding:14px 32px; border-radius:10px; border:none; cursor:pointer; font-size:14px;">
        მოთხოვნის გაგზავნა
    </button>

    <div id="status" style="margin-top:15px; padding:10px; display:none; border-radius:5px;"></div>

<?php endif; ?>
<?php endif; ?>

</div>

<script>
    function showStatus(bg, text) {
        var statusDiv = document.getElementById('status');
        statusDiv.style.display    = 'block';
        statusDiv.style.background = bg;
        statusDiv.style.color      = '#fff';
        statusDiv.textContent      = text;
    }

    function sendExtendRequest() {
        var deal_id = <?= json_encode($deal_id) ?>;
        var date    = document.getElementById('contr_date').value;
        var comment = document.getElementById('comment').value.trim();
        var btn     = document.getElementById('saveBtn');

        if (!date) {
            showStatus('#ef4444', 'გთხოვთ აირჩიოთ ახალი ვადა');
            return;
        }
        if (!comment) {
            showStatus('#ef4444', 'კომენტარი სავალდებულოა');
            return;
        }

        btn.disabled = true;
        showStatus('#3b82f6', 'იგზავნება...');

        fetch(location.origin + '/rest/local/api/projects/saveExtendContract.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ deal_id: deal_id, contr_date: date, comment: comment })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 200) {
                showStatus('#38a169', 'მოთხოვნა გაიგზავნა ნუკისთან დასადასტურებლად');
                setTimeout(function() {
                    var BX = window.top.BX;
                    if (BX && BX.SidePanel) {
                        var slider = BX.SidePanel.Instance.getTopSlider();
                        if (slider) slider.close();
                    }
                }, 1200);
            } else {
                showStatus('#ef4444', 'შეცდომა: ' + (data.message || 'უცნობი შეცდომა'));
                btn.disabled = false;
            }
        })
        .catch(function(err) {
            showStatus('#ef4444', 'შეცდომა: ' + err.message);
            btn.disabled = false;
        });
    }
</script>
