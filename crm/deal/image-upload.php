<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
CModule::IncludeModule("iblock");

const IBLOCK_ID    = 14;
const PROP_TYPE    = 64;   // ტიპი (ბინა)
const PROP_BLOCK   = 63;   // Block
const PROP_SECTOR  = 77;   // Sector
const PROP_APT     = 67;   // Apartment number
const PROP_RENDER  = 102;  // Target file property

/**
 * "A, B ,C" -> ['A','B','C'] (empty values removed)
 */
function splitValues($str)
{
    return array_values(array_filter(array_map('trim', explode(',', (string)$str)), 'strlen'));
}

/**
 * "00007" -> "7", "7" -> "7", "0" -> "0"
 */
function normalizeNumber($n)
{
    $n = ltrim(trim((string)$n), '0');
    return $n === '' ? '0' : $n;
}

/**
 * Takes the last number before the extension:
 * "sectori 1 bloki A bina_00007.png" -> "7"
 */
function numberFromFileName($name)
{
    if (preg_match('/(\d+)\.[^.]+$/', $name, $m)) {
        return normalizeNumber($m[1]);
    }
    return null;
}

/**
 * Converts $_FILES['files'] (multiple input) into a simple list.
 */
function normalizeFiles($files)
{
    $list = array();
    if (empty($files) || !is_array($files["name"])) {
        return $list;
    }
    foreach ($files["name"] as $i => $name) {
        if ($files["error"][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = array(
            "name"     => $name,
            "tmp_name" => $files["tmp_name"][$i],
            "error"    => $files["error"][$i],
        );
    }
    return $list;
}

function getElementsByFilter($arFilter)
{
    $arElements = array();
    $res = CIBlockElement::GetList(
        array("ID" => "ASC"),
        $arFilter,
        false,
        false,
        array("ID", "NAME", "IBLOCK_ID", "PROPERTY_" . PROP_BLOCK, "PROPERTY_" . PROP_SECTOR)
    );
    while ($row = $res->Fetch()) {
        $arElements[] = $row;
    }
    return $arElements;
}

$errors    = array();
$rows      = array();
$isPreview = false;
$done      = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!check_bitrix_sessid()) {
        $errors[] = "Session expired. Please reload the page and try again.";
    } else {
        $isPreview  = (($_POST["action"] ?? "") === "preview");
        $project    = (int)($_POST["project"] ?? 0);
        $blocks     = splitValues($_POST["blocks"] ?? "");
        $sectors    = splitValues($_POST["sectors"] ?? "");
        $apartments = splitValues($_POST["apartments"] ?? "");
        $files      = normalizeFiles($_FILES["files"] ?? null);

        // ---------- Validation ----------
        if (!$project)          $errors[] = "Please select a project.";
        if (empty($blocks))     $errors[] = "Please enter at least one block.";
        if (empty($sectors))    $errors[] = "Please enter at least one sector.";
        if (empty($apartments)) $errors[] = "Please enter at least one apartment number.";
        if (empty($files))      $errors[] = "Please choose at least one file.";

        foreach ($files as $f) {
            if ($f["error"] !== UPLOAD_ERR_OK) {
                $errors[] = "Upload error for file " . htmlspecialchars($f["name"]) . " (code " . $f["error"] . ")";
            }
        }

        // ---------- Map files to apartment numbers ----------
        $singleMode = (count($files) === 1);
        $fileMap    = array();

        if (empty($errors) && !$singleMode) {
            foreach ($files as $i => $f) {
                $num = numberFromFileName($f["name"]);
                if ($num === null) {
                    $errors[] = "Can't find an apartment number in file name: " . htmlspecialchars($f["name"]);
                } elseif (isset($fileMap[$num])) {
                    $errors[] = "Two files have the same number ($num): " .
                                htmlspecialchars($files[$fileMap[$num]]["name"]) . " and " .
                                htmlspecialchars($f["name"]) . ". Upload one set of files at a time.";
                } else {
                    $fileMap[$num] = $i;
                }
            }
        }

        // ---------- Move files to a temp location (real upload only) ----------
        if (empty($errors) && !$isPreview) {
            $tmpDir = $_SERVER["DOCUMENT_ROOT"] . "/upload/tmp_renders/";
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            foreach ($files as $i => $f) {
                $path = $tmpDir . time() . "_" . $i . "_" . basename($f["name"]);
                if (move_uploaded_file($f["tmp_name"], $path)) {
                    $files[$i]["path"] = $path;
                } else {
                    $errors[] = "Failed to save file " . htmlspecialchars($f["name"]);
                }
            }
        }

        // ---------- Process apartments (only if everything above is OK) ----------
        if (empty($errors)) {
            global $APPLICATION;

            foreach ($apartments as $apt) {
                $num = normalizeNumber($apt);

                if ($singleMode) {
                    $fileIndex = 0;
                } elseif (isset($fileMap[$num])) {
                    $fileIndex = $fileMap[$num];
                } else {
                    $errors[] = "No file found for apartment <b>" . htmlspecialchars($apt) . "</b>";
                    continue;
                }
                $file = $files[$fileIndex];

                $filter = array(
                    "IBLOCK_ID"               => IBLOCK_ID,
                    "IBLOCK_SECTION_ID"       => $project,
                    "PROPERTY_" . PROP_TYPE   => "ბინა",
                    "PROPERTY_" . PROP_BLOCK  => $blocks,
                    "PROPERTY_" . PROP_SECTOR => $sectors,
                    "PROPERTY_" . PROP_APT    => $apt,
                );

                $elements = getElementsByFilter($filter);

                if (empty($elements)) {
                    $errors[] = "No apartment found with number <b>" . htmlspecialchars($apt) .
                                "</b> in block(s) " . htmlspecialchars(implode(", ", $blocks)) .
                                ", sector(s) " . htmlspecialchars(implode(", ", $sectors));
                    continue;
                }

                foreach ($elements as $element) {
                    $row = array(
                        "ID"     => $element["ID"],
                        "NAME"   => $element["NAME"],
                        "BLOCK"  => $element["PROPERTY_" . PROP_BLOCK . "_VALUE"],
                        "SECTOR" => $element["PROPERTY_" . PROP_SECTOR . "_VALUE"],
                        "APT"    => $apt,
                        "FILE"   => $file["name"],
                        "STATUS" => "",
                    );

                    if ($isPreview) {
                        $row["STATUS"] = "Will be updated";
                        $rows[] = $row;
                        continue;
                    }

                    $fileArray = CFile::MakeFileArray($file["path"]);
                    if (!$fileArray || isset($fileArray["error"])) {
                        $errors[] = "File processing error for element ID " . $element["ID"];
                        continue;
                    }
                    $fileArray["name"] = $file["name"]; // keep the original file name

                    $APPLICATION->ResetException();

                    // Only PROPERTY_102 is written, nothing else on the element changes
                    CIBlockElement::SetPropertyValuesEx(
                        $element["ID"],
                        IBLOCK_ID,
                        array(PROP_RENDER => $fileArray)
                    );

                    if ($ex = $APPLICATION->GetException()) {
                        $errors[] = "Failed to update element ID " . $element["ID"] . ": " . $ex->GetString();
                    } else {
                        $row["STATUS"] = "Updated";
                        $rows[] = $row;
                    }
                }
            }
            $done = true;
        }

        // ---------- Remove only our own temp copies ----------
        foreach ($files as $f) {
            if (!empty($f["path"]) && is_file($f["path"])) {
                @unlink($f["path"]);
            }
        }
    }
}

$val = function ($key) {
    return htmlspecialchars($_POST[$key] ?? "");
};
?>

<html>

<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
<div class="container mt-5 mb-5">
    <h1 class="mb-4">Render Upload by Apartment</h1>

    <?php if ($done && !empty($rows)): ?>
        <div class="alert <?= $isPreview ? 'alert-info' : 'alert-success' ?>">
            <?php if ($isPreview): ?>
                <b>Preview only, nothing was saved.</b> <?= count($rows) ?> apartment(s) would be updated.
                To upload, choose the files again and press <b>Upload</b>.
            <?php else: ?>
                Uploaded successfully to <b><?= count($rows) ?></b> apartment(s).
            <?php endif; ?>
        </div>
        <table class="table table-sm table-bordered mb-4">
            <thead>
            <tr><th>ID</th><th>Name</th><th>Block</th><th>Sector</th><th>Apt</th><th>File</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r["ID"] ?></td>
                    <td><?= htmlspecialchars($r["NAME"]) ?></td>
                    <td><?= htmlspecialchars($r["BLOCK"]) ?></td>
                    <td><?= htmlspecialchars($r["SECTOR"]) ?></td>
                    <td><?= htmlspecialchars($r["APT"]) ?></td>
                    <td><?= htmlspecialchars($r["FILE"]) ?></td>
                    <td><?= htmlspecialchars($r["STATUS"]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Errors:</strong><br><?= implode("<br>", $errors) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>

        <div class="mb-3">
            <label for="project" class="form-label">Project</label>
            <select name="project" id="project" class="form-select" required>
                <option value="">Select Project</option>
                <option value="20" <?= ($_POST["project"] ?? "") === "20" ? "selected" : "" ?>>Botanico</option>
                <option value="30" <?= ($_POST["project"] ?? "") === "30" ? "selected" : "" ?>>Silk Tower</option>
            </select>
        </div>

        <div class="mb-3">
            <label for="blocks" class="form-label">Block(s)</label>
            <input type="text" class="form-control" id="blocks" name="blocks" value="<?= $val("blocks") ?>" required>
            <div class="form-text">Comma separated (e.g., A or A, B)</div>
        </div>

        <div class="mb-3">
            <label for="sectors" class="form-label">Sector(s)</label>
            <input type="text" class="form-control" id="sectors" name="sectors" value="<?= $val("sectors") ?>" required>
            <div class="form-text">Comma separated (e.g., 1 or 1, 2)</div>
        </div>

        <div class="mb-3">
            <label for="apartments" class="form-label">Apartment Numbers</label>
            <input type="text" class="form-control" id="apartments" name="apartments" value="<?= $val("apartments") ?>" required>
            <div class="form-text">Comma separated (e.g., 7, 8, 9, 29, 33)</div>
        </div>

        <div class="mb-3">
            <label for="files" class="form-label">Files</label>
            <input type="file" class="form-control" id="files" name="files[]" accept="image/*" multiple required>
            <div class="form-text">
                Several files: each goes to the apartment matching the number at the end of its name
                (<code>bina_00007.png</code> → apartment 7).<br>
                One file: it goes to all apartments listed above.
            </div>
        </div>

        <button type="submit" name="action" value="preview" class="btn btn-outline-secondary">Preview</button>
        <button type="submit" name="action" value="upload" class="btn btn-primary">Upload</button>
    </form>
</div>
</body>

</html>