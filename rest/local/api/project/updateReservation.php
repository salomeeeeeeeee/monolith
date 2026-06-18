<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

use Bitrix\Main\Loader;

if (!Loader::includeModule('crm')) {
    die('CRM module not loaded');
}

$APPLICATION->SetTitle("რეზერვაციის ცვლილება");

$deal_id = (int)($_GET["deal_id"] ?? 0);
if (!$deal_id) die("No deal ID");

$res  = CCrmDeal::GetList([], ["ID" => $deal_id]);
$deal = $res->Fetch();

ob_end_clean();
?>

<div style="width:650px; padding:20px; background:#eef2f4;">

    <div style="margin-bottom:15px;">
        <label style="display:block; color:#4a5568; font-weight:600; margin-bottom:8px; font-size:14px;">
            <span style="color:red">*</span> რეზერვაციის ვადა:
        </label>
        <input type="date" id="contr_date"
       style="width:100%; padding:14px 18px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; outline:none; background:white; box-sizing:border-box; cursor:pointer;"
       onclick="this.showPicker()" />
    </div>

    <div style="margin-bottom:15px;">
        <label style="display:block; color:#4a5568; font-weight:600; margin-bottom:8px; font-size:14px;">
            კომენტარი:
        </label>
        <input type="text" id="comment"
               style="width:100%; padding:14px 18px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; outline:none; background:white; box-sizing:border-box;" />
    </div>

    <button id="saveBtn" onclick="saveUpdate()"
            style="background:#38a169; color:#fff; padding:14px 32px; border-radius:10px; border:none; cursor:pointer; font-size:14px;">
        Save
    </button>

    <div id="status" style="margin-top:15px; padding:10px; display:none; border-radius:5px;"></div>

</div>

<script>
    function saveUpdate() {
        var deal_id   = <?= json_encode($deal_id) ?>;
        var date      = document.getElementById('contr_date').value;
        var comment   = document.getElementById('comment').value;
        var statusDiv = document.getElementById('status');
        var btn       = document.getElementById('saveBtn');

        if (!date) {
            statusDiv.style.display    = 'block';
            statusDiv.style.background = '#ef4444';
            statusDiv.style.color      = '#fff';
            statusDiv.textContent      = 'გთხოვთ აირჩიოთ თარიღი';
            return;
        }

        btn.disabled = true;
        statusDiv.style.display    = 'block';
        statusDiv.style.background = '#3b82f6';
        statusDiv.style.color      = '#fff';
        statusDiv.textContent      = 'შენახვა...';

        fetch(location.origin + '/rest/local/api/projects/saveUpdatereservation.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ deal_id: deal_id, contr_date: date, comment: comment })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 200) {
                statusDiv.style.background = '#38a169';
                statusDiv.textContent      = 'მოთხოვნა წარმატებით გაიგზავნა';
                setTimeout(function() {
                    var BX = window.top.BX;
                    if (BX && BX.SidePanel) {
                        var slider = BX.SidePanel.Instance.getTopSlider();
                        if (slider) slider.close();
                    }
                }, 1000);
            } else {
                statusDiv.style.background = '#ef4444';
                statusDiv.textContent      = 'შეცდომა: ' + (data.message || 'უცნობი შეცდომა');
                btn.disabled = false;
            }
        })
        .catch(function(err) {
            statusDiv.style.background = '#ef4444';
            statusDiv.textContent      = 'შეცდომა: ' + err.message;
            btn.disabled = false;
        });
    }
</script>