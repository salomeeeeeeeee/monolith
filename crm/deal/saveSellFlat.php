<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

use Bitrix\Main\Loader;

if (!Loader::includeModule('crm')) {
    die('CRM module not loaded');
}

$APPLICATION->SetTitle("გაყიდვა");

$deal_id = (int)($_GET["deal_id"] ?? 0);
if (!$deal_id) die("No deal ID");

$contactId = 0;
$firstName = $lastName = $idNumber = '';

$contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($deal_id);
$contactId  = intval($contactIds[0] ?? 0);

if ($contactId > 0) {
    $res = CCrmContact::GetList([], ["ID" => $contactId], ["NAME", "LAST_NAME", "UF_CRM_1781244744534"]);
    if ($arContact = $res->Fetch()) {
        $firstName = $arContact["NAME"] ?? '';
        $lastName  = $arContact["LAST_NAME"] ?? '';
        $idNumber  = $arContact["UF_CRM_1781244744534"] ?? '';
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="ka">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@400;500;600;700&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Noto Sans Georgian', sans-serif;
    background: #f0f4f8;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 0;
  }

  .panel {
    width: 100%;
    max-width: 620px;
    background: #fff;
    min-height: 100vh;
  }

  /* header strip */
  .panel-header {
    background: linear-gradient(135deg, #0f766e 0%, #0d9488 60%, #14b8a6 100%);
    padding: 28px 32px 24px;
    position: relative;
    overflow: hidden;
  }
  .panel-header::after {
    content: '';
    position: absolute;
    right: -30px; top: -30px;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
    pointer-events: none;
  }
  .panel-header::before {
    content: '';
    position: absolute;
    right: 40px; bottom: -50px;
    width: 100px; height: 100px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    pointer-events: none;
  }
  .header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 600;
    color: rgba(255,255,255,0.9);
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 10px;
  }
  .header-badge svg { opacity: 0.8; }
  .panel-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.3px;
  }
  .header-sub {
    font-size: 13px;
    color: rgba(255,255,255,0.7);
    margin-top: 4px;
  }

  /* body */
  .panel-body {
    padding: 28px 32px 32px;
  }

  /* section label */
  .section-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 16px;
    margin-top: 28px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-label:first-child { margin-top: 0; }
  .section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
  }

  /* fields */
  .field { margin-bottom: 14px; }
  .field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 6px;
    letter-spacing: 0.2px;
  }
  .field label .req { color: #f43f5e; margin-left: 2px; }

  .field input[type=text],
  .field input[type=date] {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Noto Sans Georgian', sans-serif;
    color: #1e293b;
    background: #f8fafc;
    outline: none;
    transition: border-color .15s, background .15s, box-shadow .15s;
  }
  .field input:focus {
    border-color: #0d9488;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(13,148,136,0.12);
  }
  .field input[type=date] { cursor: pointer; }

  /* two-col grid */
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  /* file drop */
  .drop-zone {
    border: 2px dashed #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    padding: 24px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    position: relative;
  }
  .drop-zone:hover, .drop-zone.dragover {
    border-color: #0d9488;
    background: #f0fdfa;
  }
  .drop-zone input[type=file] { display: none; }
  .dz-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg,#ccfbf1,#99f6e4);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 10px;
  }
  .dz-label { font-size: 13px; color: #64748b; line-height: 1.5; }
  .dz-label span { color: #0d9488; font-weight: 600; }
  .dz-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
  .file-preview {
    display: none;
    margin-top: 10px;
    background: #f0fdfa;
    border: 1px solid #99f6e4;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 12px;
    color: #0f766e;
    font-weight: 600;
    align-items: center;
    gap: 6px;
  }
  .file-preview.visible { display: flex; }

  /* footer */
  .panel-footer {
    padding: 0 32px 32px;
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .btn-save {
    flex: 1;
    background: linear-gradient(135deg, #0f766e, #0d9488);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 14px 24px;
    font-size: 14px;
    font-weight: 700;
    font-family: 'Noto Sans Georgian', sans-serif;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: opacity .2s, transform .15s, box-shadow .15s;
    box-shadow: 0 4px 14px rgba(13,148,136,0.35);
  }
  .btn-save:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(13,148,136,0.45);
  }
  .btn-save:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

  /* status */
  #status {
    margin: 0 32px 20px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    display: none;
    align-items: center;
    gap: 8px;
  }
  #status.show { display: flex; }
  #status.info  { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
  #status.ok    { background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
  #status.error { background:#fff1f2; color:#be123c; border:1px solid #fecdd3; }
</style>
</head>
<body>
<div class="panel">

  <div class="panel-header">
    <div class="header-badge">
      <svg width="10" height="10" viewBox="0 0 16 16" fill="none">
        <path d="M2 8l4 4 8-9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      გაყიდვის განყოფილება
    </div>
    <h1>ბინის გაყიდვა</h1>
    <div class="header-sub">შეავსეთ ყველა სავალდებულო ველი</div>
  </div>

  <div class="panel-body">

    <div class="section-label">ხელშეკრულება</div>

    <div class="field">
      <label>ხელშეკრულების გაფორმების თარიღი <span class="req">*</span></label>
      <input type="date" id="contr_date" onclick="this.showPicker()" />
    </div>

    <div class="section-label">კლიენტის მონაცემები</div>

    <div class="grid2">
      <div class="field">
        <label>სახელი <span class="req">*</span></label>
        <input type="text" id="firstName" value="<?= htmlspecialchars($firstName) ?>" placeholder="სახელი" />
      </div>
      <div class="field">
        <label>გვარი <span class="req">*</span></label>
        <input type="text" id="lastName" value="<?= htmlspecialchars($lastName) ?>" placeholder="გვარი" />
      </div>
    </div>

    <div class="field">
      <label>პირადი ნომერი <span class="req">*</span></label>
      <input type="text" id="idNumber" value="<?= htmlspecialchars($idNumber) ?>" placeholder="00000000000" />
    </div>

    <div class="section-label">ხელშეკრულება</div>

    <div class="field">
      <div class="drop-zone" id="dropZone" onclick="document.getElementById('passportFile').click()">
        <input type="file" id="passportFile" accept="image/*,.pdf" onchange="handleFile(this.files[0])">
        <div class="dz-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="#0d9488" stroke-width="1.8" stroke-linejoin="round"/>
            <path d="M14 2v6h6M12 18v-6M9 15l3-3 3 3" stroke="#0d9488" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="dz-label">ჩააგდეთ ფაილი ან <span>აირჩიეთ</span></div>
        <div class="dz-hint">ხელშეკრულება · PDF, JPG, PNG</div>
        <div class="file-preview" id="filePreview">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
            <path d="M2 8l4 4 8-9" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span id="fileName"></span>
        </div>
      </div>
    </div>

  </div>

  <div id="status"></div>

  <div class="panel-footer">
    <button class="btn-save" id="saveBtn" onclick="saveSell()">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
        <path d="M2 8l4 4 8-9" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      გაგზავნა
    </button>
  </div>

</div>

<script>
var selectedFile = null;

(function(){
  var dz = document.getElementById('dropZone');
  dz.addEventListener('dragover', function(e){ e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', function(){ dz.classList.remove('dragover'); });
  dz.addEventListener('drop', function(e){
    e.preventDefault(); dz.classList.remove('dragover');
    var f = e.dataTransfer.files[0];
    if (f) handleFile(f);
  });
})();

function handleFile(f) {
  if (!f) return;
  selectedFile = f;
  var preview  = document.getElementById('filePreview');
  document.getElementById('fileName').textContent = f.name;
  preview.classList.add('visible');
}

function setStatus(type, msg) {
  var s = document.getElementById('status');
  s.className = 'show ' + type;
  s.innerHTML = (type === 'ok'
    ? '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 8l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    : type === 'error'
    ? '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5M8 10.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
    : '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5M8 10.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
  ) + ' ' + msg;
}

function saveSell() {
  var deal_id    = <?= json_encode($deal_id) ?>;
  var contact_id = <?= json_encode($contactId) ?>;
  var contr_date = document.getElementById('contr_date').value;
  var firstName  = document.getElementById('firstName').value.trim();
  var lastName   = document.getElementById('lastName').value.trim();
  var idNumber   = document.getElementById('idNumber').value.trim();
  var btn        = document.getElementById('saveBtn');

  if (!contr_date || !firstName || !lastName || !idNumber || !selectedFile) {
    setStatus('error', 'გთხოვთ შეავსოთ ყველა სავალდებულო ველი');
    return;
  }

  btn.disabled = true;
  setStatus('info', 'გაგზავნა...');

  var fd = new FormData();
  fd.append('deal_id',    deal_id);
  fd.append('contact_id', contact_id);
  fd.append('contr_date', contr_date);
  fd.append('firstName',  firstName);
  fd.append('lastName',   lastName);
  fd.append('idNumber',   idNumber);
  fd.append('passport',   selectedFile, selectedFile.name);

  fetch(location.origin + '/rest/local/api/projects/saveSellFlatAction.php', {
    method: 'POST',
    body: fd
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.status === 200) {
      setStatus('ok', 'წარმატებით გაიგზავნა');
      setTimeout(function(){
        var BX = window.top.BX;
        if (BX && BX.SidePanel) {
          var slider = BX.SidePanel.Instance.getTopSlider();
          if (slider) slider.close();
        }
      }, 1200);
    } else {
      setStatus('error', 'შეცდომა: ' + (data.message || 'უცნობი შეცდომა'));
      btn.disabled = false;
    }
  })
  .catch(function(err){
    setStatus('error', 'შეცდომა: ' + err.message);
    btn.disabled = false;
  });
}
</script>
</body>
</html>