<?
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("პასუხისმგებლის ცვლილება ტელეფონით");

CJSCore::Init(array("jquery"));
set_time_limit(0);

function issetEx($param) { return (isset($param) && !empty($param)) ? $param : false; }

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

/**
 * Finds a CRM contact ID by phone number.
 * Normalizes the phone (digits only) and searches with a partial match,
 * since stored phone formats can vary (+995, spaces, dashes, etc).
 */
function getContactInfoByPhone($mobileNumber) {
    $digits = preg_replace('/\D+/', '', $mobileNumber);
    if (!$digits) return false;

    // Use the last 9 digits (typical GE mobile number length) to be tolerant
    // of country-code / leading-zero differences in how the number was stored.
    $searchDigits = (strlen($digits) > 9) ? substr($digits, -9) : $digits;

    $searchArr = array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', "%VALUE" => $searchDigits);
    $dbFieldMulti = \CCrmFieldMulti::GetList(array(), $searchArr);

    while ($number = $dbFieldMulti->Fetch()) {
        $contactID = $number["ELEMENT_ID"];
        return $contactID;
    }

    return false;
}

/**
 * Deals linked to a given contact.
 */
function getDealsByContactId($contactId) {
    $arSelect = array("ID", "CONTACT_ID", "ASSIGNED_BY_ID", "LEAD_ID");
    $arFilter = array("CONTACT_ID" => $contactId);
    $arDeals = array();
    $res = CCrmDeal::GetList(array("ID" => "DESC"), $arFilter, $arSelect);
    while ($arDeal = $res->Fetch()) array_push($arDeals, $arDeal);
    return $arDeals;
}

// -------------------------------------------------- //
// Responsible name -> Bitrix user ID map.
// Edit this list to match the names that appear in your Excel file.
// -------------------------------------------------- //
$responsArr = [
    "ნანუკა ფირცხალაიშვილი" => 4,
    "გიორგი კვიცარიძე"      => 6,
    "ნინი სიხარულიძე"       => 5,
    "თათა ქემოკლიძე"        => 8,
    "ლელუ დვალი"            => 7,
    "თამო შანიძე"           => 126,
];

// -------------------------------------------------- //
// File parsing: accepts .csv directly, and .xlsx via PhpSpreadsheet if available.
// Expected columns: A = phone number, B = new responsible name (must match $responsArr keys)
// -------------------------------------------------- //
$rows = array();
$cacheDir = $_SERVER["DOCUMENT_ROOT"] . "/upload/tmp_reassign_cache/";
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

if (issetEx($_FILES) && issetEx($_FILES['datafile']['tmp_name']) && $_FILES['datafile']['error'] === UPLOAD_ERR_OK) {
    // New file uploaded — cache it for this session so later batches don't need re-upload
    $origName = $_FILES['datafile']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $cachedPath = $cacheDir . bitrix_sessid() . "." . $ext;
    move_uploaded_file($_FILES['datafile']['tmp_name'], $cachedPath);
    $_SESSION['reassign_cached_file'] = $cachedPath;
    $_SESSION['reassign_cached_ext'] = $ext;
} elseif (!empty($_SESSION['reassign_cached_file']) && file_exists($_SESSION['reassign_cached_file'])) {
    // Reuse the previously uploaded file for this batch
    $cachedPath = $_SESSION['reassign_cached_file'];
    $ext = $_SESSION['reassign_cached_ext'];
} else {
    $cachedPath = null;
    $ext = null;
}

if ($cachedPath) {
    $tmpName = $cachedPath;

    // Detect actual format by content, not by extension — a file can be named .csv
    // but still be raw xlsx binary (zip signature "PK") if the export didn't really convert it.
    $sniff = @file_get_contents($tmpName, false, null, 0, 4);
    $isZip = $sniff !== false && substr($sniff, 0, 2) === 'PK';
    $actualType = $isZip ? 'xlsx' : 'csv';

    if ($actualType === 'csv') {
        if (($handle = fopen($tmpName, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 30000, ",")) !== FALSE) {
                $rows[] = $data;
            }
            fclose($handle);
        }
    } elseif ($actualType === 'xlsx') {
        $autoload = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/php_interface/vendor/autoload.php";
        if (file_exists($autoload)) {
            require_once($autoload);
        }

        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpName);
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($sheet->toArray(null, true, true, false) as $data) {
                $rows[] = $data;
            }
        } else {
            echo "<p style='color:red'>ეს ფაილი რეალურად xlsx ფორმატშია (მიუხედავად .csv გაფართოებისა), მაგრამ PhpSpreadsheet არ არის ხელმისაწვდომი სერვერზე. ან დააინსტალირეთ composer-ით: phpoffice/phpspreadsheet, ან ატვირთეთ ნამდვილი .csv ფაილი (გახსენით Google Sheets-ში და ჩამოტვირთეთ როგორც CSV).</p>";
        }
    }
}

$mode = issetEx($_POST['mode']) ? $_POST['mode'] : 'preview';
$offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
$limit = isset($_POST['limit']) && (int)$_POST['limit'] > 0 ? (int)$_POST['limit'] : 200;
$results = array();
$debugRows = array();

// total data rows (excluding header), for showing progress / next offset
$totalDataRows = count($rows) > 0 ? count($rows) - 1 : 0;

// Column layout: A = Name, B = Surname, C = Responsible, D = phone
// Only process rows [offset, offset+limit) of the data (header row is index 0 and always skipped)
foreach ($rows as $i => $row) {
    // skip header row
    if ($i === 0) continue;

    $dataIndex = $i - 1; // 0-based position among data rows
    if ($dataIndex < $offset) continue;
    if ($dataIndex >= $offset + $limit) break;

    $rawPhone = isset($row[3]) ? $row[3] : '';
    // xlsx numeric cells come back as int/float (e.g. 995599099132) — cast safely, no scientific notation
    $phone = is_numeric($rawPhone) ? number_format((float)$rawPhone, 0, '', '') : trim($rawPhone);
    $newResponsibleName = isset($row[2]) ? trim($row[2]) : '';

    // DEBUG: raw column dump for this row, shown regardless of whether it gets skipped below
    $debugRows[] = array(
        "ROW_INDEX" => $dataIndex,
        "COL_A" => $row[0] ?? '(missing)',
        "COL_B" => $row[1] ?? '(missing)',
        "COL_C" => $row[2] ?? '(missing)',
        "COL_D" => $row[3] ?? '(missing)',
        "COL_E" => $row[4] ?? '(missing)',
        "PARSED_PHONE" => $phone,
        "PARSED_RESPONSIBLE" => $newResponsibleName,
    );

    if (!$phone || !$newResponsibleName) continue;

    $logEntry = array(
        "PHONE" => $phone,
        "NAME" => $newResponsibleName,
        "STATUS" => "",
    );

    if (!isset($responsArr[$newResponsibleName])) {
        $logEntry["STATUS"] = "უცნობი პასუხისმგებელი: " . $newResponsibleName;
        $results[] = $logEntry;
        continue;
    }
    $newResponsID = $responsArr[$newResponsibleName];

    $contactId = getContactInfoByPhone($phone);
    if (!$contactId) {
        $logEntry["STATUS"] = "კონტაქტი ვერ მოიძებნა";
        $results[] = $logEntry;
        continue;
    }
    $logEntry["CONTACT_ID"] = $contactId;

    // Current responsible, for comparison in preview
    $currentContact = CCrmContact::GetListEx(array(), array("ID" => $contactId), false, false, array("ID", "ASSIGNED_BY_ID"))->Fetch();
    $logEntry["CURRENT_RESPONSIBLE_ID"] = $currentContact ? $currentContact["ASSIGNED_BY_ID"] : '?';
    $logEntry["NEW_RESPONSIBLE_ID"] = $newResponsID;

    $deals = getDealsByContactId($contactId);
    $dealIds = array();
    foreach ($deals as $deal) $dealIds[] = $deal["ID"];
    $logEntry["DEALS_FOUND"] = implode(", ", $dealIds);

    if ($mode === 'run') {
        // Update contact — must pass fields via a variable, Update() takes $arFields by reference
        $CCrmContact = new CCrmContact(false);
        $upd = array("ASSIGNED_BY_ID" => $newResponsID);
        $contactUpdRes = $CCrmContact->Update($contactId, $upd);

        // Update all deals tied to this contact (and their leads, if any)
        foreach ($deals as $deal) {
            $CCrmDeal = new CCrmDeal(false);
            $dealUpd = array("ASSIGNED_BY_ID" => $newResponsID);
            $CCrmDeal->Update($deal["ID"], $dealUpd);

            if (!empty($deal["LEAD_ID"])) {
                $CCrmLead = new CCrmLead(false);
                $leadUpd = array("ASSIGNED_BY_ID" => $newResponsID);
                $CCrmLead->Update($deal["LEAD_ID"], $leadUpd);
            }
        }

        $logEntry["STATUS"] = $contactUpdRes ? "წარმატებით განახლდა" : "კონტაქტის განახლება ვერ მოხერხდა";
    } else {
        $logEntry["STATUS"] = ($logEntry["CURRENT_RESPONSIBLE_ID"] == $newResponsID)
            ? "უცვლელი (უკვე იგივე პასუხისმგებელია)"
            : "შეიცვლება (preview)";
    }

    $results[] = $logEntry;
}

?>

<div align="center">
    <form action="" method="post" enctype="multipart/form-data">
        <table class="formTable">
            <tr>
                <td><label>ფაილის ატვირთვა (მხოლოდ პირველად საჭირო — მერე ქეშდება სესიაში). სვეტები: Name | Surname | Responsible | phone (D)</label></td>
                <td><input type="file" name="datafile"></td>
            </tr>
            <tr>
                <td><label>საწყისი მწკრივი (offset)</label></td>
                <td><input type="number" name="offset" value="<?= (int)$offset ?>" min="0" style="width:100px"></td>
            </tr>
            <tr>
                <td><label>ბატჩის ზომა (limit)</label></td>
                <td><input type="number" name="limit" value="<?= (int)$limit ?>" min="1" style="width:100px"></td>
            </tr>
            <tr>
                <td><label>რეჟიმი</label></td>
                <td>
                    <label><input type="radio" name="mode" value="preview" <?= $mode === 'preview' ? 'checked' : '' ?>> Preview (არაფერი იცვლება)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="mode" value="run" <?= $mode === 'run' ? 'checked' : '' ?>> Run (რეალურად განაახლებს)</label>
                </td>
            </tr>
            <tr>
                <td colspan="2" align="right">
                    <input type="submit" name="" value="ატვირთვა">
                </td>
            </tr>
        </table>
    </form>

    <?php if ($cachedPath): ?>
    <p>ფაილი: <b><?= htmlspecialcars_wrapper($ext) ?></b> — სულ მონაცემთა მწკრივი: <b><?= (int)$totalDataRows ?></b>.
        დამუშავებულია: <b><?= (int)$offset ?> – <?= min($offset + $limit, $totalDataRows) ?></b>.
        <?php if ($offset + $limit < $totalDataRows): ?>
            შემდეგი ბატჩისთვის offset დააყენე: <b><?= $offset + $limit ?></b>
        <?php else: ?>
            ეს იყო ბოლო ბატჩი.
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($debugRows)): ?>
    <p><b>DEBUG — ნედლი მონაცემები (წაშალე ეს ბლოკი მას შემდეგ რაც პრობლემა გადაწყდება):</b></p>
    <table class="excelTable">
        <tr>
            <td><b>Row #</b></td>
            <td><b>A</b></td>
            <td><b>B</b></td>
            <td><b>C</b></td>
            <td><b>D</b></td>
            <td><b>E</b></td>
            <td><b>Parsed phone</b></td>
            <td><b>Parsed responsible</b></td>
        </tr>
        <?php foreach ($debugRows as $d): ?>
        <tr>
            <td><?= (int)$d["ROW_INDEX"] ?></td>
            <td><?= htmlspecialcars_wrapper($d["COL_A"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["COL_B"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["COL_C"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["COL_D"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["COL_E"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["PARSED_PHONE"]) ?></td>
            <td><?= htmlspecialcars_wrapper($d["PARSED_RESPONSIBLE"]) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <table class="excelTable">
        <tr>
            <td><b>ტელეფონი</b></td>
            <td><b>ახალი პასუხისმგებელი</b></td>
            <td><b>Contact ID</b></td>
            <td><b>მიმდინარე Responsible ID</b></td>
            <td><b>ახალი Responsible ID</b></td>
            <td><b>დილები</b></td>
            <td><b>სტატუსი</b></td>
        </tr>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= htmlspecialcars_wrapper($r["PHONE"]) ?></td>
            <td><?= htmlspecialcars_wrapper($r["NAME"]) ?></td>
            <td><?= htmlspecialcars_wrapper($r["CONTACT_ID"] ?? '') ?></td>
            <td><?= htmlspecialcars_wrapper($r["CURRENT_RESPONSIBLE_ID"] ?? '') ?></td>
            <td><?= htmlspecialcars_wrapper($r["NEW_RESPONSIBLE_ID"] ?? '') ?></td>
            <td><?= htmlspecialcars_wrapper($r["DEALS_FOUND"] ?? ($r["DEALS_UPDATED"] ?? '')) ?></td>
            <td><?= htmlspecialcars_wrapper($r["STATUS"]) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php function htmlspecialcars_wrapper($v) { return htmlspecialchars((string)$v); } ?>

<style>
    .formTable {
        border: 1px solid grey;
        border-collapse: collapse;
    }
    .formTable tr td {
        border: 1px solid grey;
        border-collapse: collapse;
        padding: 5px;
    }

    .excelTable {
        border: 1px solid #ddd;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .excelTable tr td {
        border: 1px solid #888;
        border-collapse: collapse;
        padding: 5px;
    }
    .excelTable tr td:first-child {
        background: #616161;
        color: #fff;
    }
</style>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
?>