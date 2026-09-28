<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
CModule::IncludeModule("iblock");

const IBLOCK_ID    = 14;
const PROP_TYPE    = 64;   // ტიპი (ბინა)
const PROP_BLOCK   = 63;   // Block
const PROP_SECTOR  = 77;   // Sector
const PROP_FLOOR   = 66;    // <-- SET THE FLOOR PROPERTY ID HERE
const PROP_PLAN    = 255;  // Target file property (floor plan)

$projects = array(
    "155" => "Monolith New Depo",
    "154" => "Ethno city",
    "152" => "Green city",
    "150" => "Dighomi",
);

/**
 * "A, B ,C" -> ['A','B','C'] (empty values removed)
 */
function splitValues($str)
{
    return array_values(array_filter(array_map('trim', explode(',', (string)$str)), 'strlen'));
}

/**
 * "00007" -> "7", "-1" -> "-1", "0" -> "0"
 */
function normalizeNumber($n)
{
    return (string)(int)trim((string)$n);
}

/**
 * "1, 3, 5-8" -> ['1','3','5','6','7','8']
 * Supports negative floors: "-1-3" -> ['-1','0','1','2','3']
 */
function parseFloors($str, &$errors)
{
    $floors = array();
    foreach (splitValues($str) as $part) {
        if (preg_match('/^(-?\d+)\s*-\s*(-?\d+)$/', $part, $m)) {
            $from = (int)$m[1];
            $to   = (int)$m[2];
            if ($from > $to) {
                $errors[] = "Wrong floor range: " . htmlspecialchars($part);
                continue;
            }
            for ($i = $from; $i <= $to; $i++) {
                $floors[] = (string)$i;
            }
        } elseif (preg_match('/^-?\d+$/', $part)) {
            $floors[] = normalizeNumber($part);
        } else {
            $errors[] = "Wrong floor value: " . htmlspecialchars($part);
        }
    }
    return array_values(array_unique($floors));
}

/**
 * Takes the last number before the extension:
 * "sectori 1 bloki A sartuli 00003.png" -> "3"
 */
function floorFromFileName($name)
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

function propertyExists($propId)
{
    if (!$propId) {
        return false;
    }
    $res = CIBlockProperty::GetList(array(), array("IBLOCK_ID" => IBLOCK_ID, "ID" => $propId));
    return (bool)$res->Fetch();
}

function getElementsByFilter($arFilter)
{
    $arElements = array();
    $res = CIBlockElement::GetList(
        array("ID" => "ASC"),
        $arFilter,
        false,
        false,
        array(
            "ID", "NAME", "IBLOCK_ID",
            "PROPERTY_" . PROP_BLOCK,
            "PROPERTY_" . PROP_SECTOR,
            "PROPERTY_" . PROP_FLOOR,
        )
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
    } elseif (!propertyExists(PROP_FLOOR)) {
        $errors[] = "Floor property ID is not set or doesn't exist. Set PROP_FLOOR at the top of the file.";
    } elseif (!propertyExists(PROP_PLAN)) {
        $errors[] = "Property " . PROP_PLAN . " doesn't exist in iblock " . IBLOCK_ID . ".";
    } else {
        $isPreview = (($_POST["action"] ?? "") === "preview");
        $project   = (string)($_POST["project"] ?? "");
        $blocks    = splitValues($_POST["blocks"] ?? "");
        $sectors   = splitValues($_POST["sectors"] ?? "");
        $floors    = parseFloors($_POST["floors"] ?? "", $errors);
        $files     = normalizeFiles($_FILES["files"] ?? null);

        // ---------- Validation ----------
        if (!isset($projects[$project])) $errors[] = "Please select a project.";
        if (empty($blocks))              $errors[] = "Please enter at least one block.";
        if (empty($sectors))             $errors[] = "Please enter at least one sector.";
        if (empty($floors))              $errors[] = "Please enter at least one floor.";
        if (empty($files))               $errors[] = "Please choose at least one file.";

        foreach ($files as $f) {
            if ($f["error"] !== UPLOAD_ERR_OK) {
                $errors[] = "Upload error for file " . htmlspecialchars($f["name"]) . " (code " . $f["error"] . ")";
            }
        }

        // ---------- Map files to floors (file number = floor number) ----------
        $singleMode = (count($files) === 1);
        $fileMap    = array();

        if (empty($errors) && !$singleMode) {
            foreach ($files as $i => $f) {
                $floor = floorFromFileName($f["name"]);
                if ($floor === null) {
                    $errors[] = "Can't find a floor number in file name: " . htmlspecialchars($f["name"]);
                } elseif (isset($fileMap[$floor])) {
                    $errors[] = "Two files have the same floor number ($floor): " .
                                htmlspecialchars($files[$fileMap[$floor]]["name"]) . " and " .
                                htmlspecialchars($f["name"]) . ".";
                } else {
                    $fileMap[$floor] = $i;
                }
            }
        }

        // ---------- Move files to a temp location (real upload only) ----------
        if (empty($errors) && !$isPreview) {
            $tmpDir = $_SERVER["DOCUMENT_ROOT"] . "/upload/tmp_plans/";
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

        // ---------- Process floors (only if everything above is OK) ----------
        if (empty($errors)) {
            global $APPLICATION;

            foreach ($floors as $floor) {

                if ($singleMode) {
                    $fileIndex = 0;
                } elseif (isset($fileMap[$floor])) {
                    $fileIndex = $fileMap[$floor];
                } else {
                    $errors[] = "No file found for floor <b>" . htmlspecialchars($floor) . "</b>";
                    continue;
                }
                $file = $files[$fileIndex];

                $filter = array(
                    "IBLOCK_ID"               => IBLOCK_ID,
                    "SECTION_ID"              => (int)$project,
                    "INCLUDE_SUBSECTIONS"     => "Y",
                    "PROPERTY_" . PROP_BLOCK  => $blocks,
                    "PROPERTY_" . PROP_SECTOR => $sectors,
                    "PROPERTY_" . PROP_FLOOR  => $floor,
                );

                $elements = getElementsByFilter($filter);

                if (empty($elements)) {
                    $errors[] = "No apartments found on floor <b>" . htmlspecialchars($floor) .
                                "</b> in block(s) " . htmlspecialchars(implode(", ", $blocks)) .
                                ", sector(s) " . htmlspecialchars(implode(", ", $sectors));
                    continue;
                }

                foreach ($elements as $element) {
                    $elFloor = normalizeNumber($element["PROPERTY_" . PROP_FLOOR . "_VALUE"]);

                    // Safety check: never write to an apartment on a different floor
                    if ($elFloor !== $floor) {
                        $errors[] = "Skipped element ID " . $element["ID"] . ": its floor is " .
                                    htmlspecialchars($element["PROPERTY_" . PROP_FLOOR . "_VALUE"]) .
                                    ", expected " . htmlspecialchars($floor);
                        continue;
                    }

                    $row = array(
                        "ID"     => $element["ID"],
                        "NAME"   => $element["NAME"],
                        "BLOCK"  => $element["PROPERTY_" . PROP_BLOCK . "_VALUE"],
                        "SECTOR" => $element["PROPERTY_" . PROP_SECTOR . "_VALUE"],
                        "FLOOR"  => $floor,
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

                    // Only PROPERTY_255 is written, nothing else on the element changes
                    CIBlockElement::SetPropertyValuesEx(
                        $element["ID"],
                        IBLOCK_ID,
                        array(PROP_PLAN => $fileArray)
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
    <h1 class="mb-4">Floor Plan Upload</h1>

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
            <tr><th>ID</th><th>Name</th><th>Block</th><th>Sector</th><th>Floor</th><th>File</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r["ID"] ?></td>
                    <td><?= htmlspecialchars($r["NAME"]) ?></td>
                    <td><?= htmlspecialchars($r["BLOCK"]) ?></td>
                    <td><?= htmlspecialchars($r["SECTOR"]) ?></td>
                    <td><?= htmlspecialchars($r["FLOOR"]) ?></td>
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
                <?php foreach ($projects as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($_POST["project"] ?? "") === (string)$id ? "selected" : "" ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
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
            <label for="floors" class="form-label">Floor(s)</label>
            <input type="text" class="form-control" id="floors" name="floors" value="<?= $val("floors") ?>" required>
            <div class="form-text">Comma separated, ranges allowed (e.g., 1, 2, 5-12)</div>
        </div>

        <div class="mb-3">
            <label for="files" class="form-label">Floor Plan Files</label>
            <input type="file" class="form-control" id="files" name="files[]" accept="image/*" multiple required>
            <div class="form-text">
                Several files: the number at the end of the name is the floor
                (<code>sartuli 00005.png</code> → floor 5).<br>
                One file: it goes to all floors listed above.
            </div>
        </div>

        <button type="submit" name="action" value="preview" class="btn btn-outline-secondary">Preview</button>
        <button type="submit" name="action" value="upload" class="btn btn-primary">Upload</button>
    </form>
</div>
</body>

</html>