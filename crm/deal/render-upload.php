<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
CModule::IncludeModule("iblock");

const IBLOCK_ID      = 14;
const PROP_TYPE      = 64;   // ტიპი (ბინა)
const PROP_BLOCK     = 63;   // Block
const PROP_SECTOR    = 77;   // Sector
const PROP_RENDER    = 152;  // Render file property

function printArr($arr)
{
    echo "<pre>";
    print_r($arr);
    echo "</pre>";
}

/**
 * Splits "A, B ,C" into ['A','B','C'], dropping empty values.
 */
function splitValues($str)
{
    return array_values(array_filter(array_map('trim', explode(',', (string)$str)), 'strlen'));
}

/**
 * Returns only ID + NAME of matching elements (no need to load all properties).
 */
function getElementsByFilter($arFilter)
{
    $arElements = array();
    $res = CIBlockElement::GetList(
        array("ID" => "ASC"),
        $arFilter,
        false,
        false,
        array("ID", "NAME", "IBLOCK_ID")
    );
    while ($row = $res->Fetch()) {
        $arElements[] = $row;
    }
    return $arElements;
}

$successCount = 0;
$errors = array();
$updated = array();

if ($_SERVER["REQUEST_METHOD"] === "POST" && check_bitrix_sessid()) {
    $project = (int)($_POST["project"] ?? 0);
    $blocks  = splitValues($_POST["blocks"] ?? "");
    $sectors = splitValues($_POST["sectors"] ?? "");
    $file    = $_FILES["file"] ?? null;

    if (!$project) {
        $errors[] = "Please select a project.";
    }
    if (empty($blocks)) {
        $errors[] = "Please enter at least one block.";
    }
    if (empty($sectors)) {
        $errors[] = "Please enter at least one sector.";
    }
    if (!$file || $file["error"] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload failed (error code: " . ($file["error"] ?? "no file") . ").";
    }

    if (empty($errors)) {
        $filePath = $_SERVER["DOCUMENT_ROOT"] . "/upload/" . time() . "_" . basename($file["name"]);

        if (move_uploaded_file($file["tmp_name"], $filePath)) {

            foreach ($blocks as $block) {
                foreach ($sectors as $sector) {

                    $filter = array(
                        "IBLOCK_ID"                 => IBLOCK_ID,
                        "IBLOCK_SECTION_ID"         => $project,
                        "PROPERTY_" . PROP_TYPE     => "ბინა",
                        "PROPERTY_" . PROP_BLOCK    => $block,
                        "PROPERTY_" . PROP_SECTOR   => $sector,
                    );

                    $elements = getElementsByFilter($filter);

                    if (empty($elements)) {
                        $errors[] = "No apartments found for block <b>" . htmlspecialchars($block) .
                                    "</b>, sector <b>" . htmlspecialchars($sector) . "</b>";
                        continue;
                    }

                    foreach ($elements as $element) {
                        // Fresh file array per element so each gets its own saved copy
                        $fileArray = CFile::MakeFileArray($filePath);

                        if (!$fileArray || isset($fileArray["error"])) {
                            $errors[] = "File processing error for element ID " . $element["ID"];
                            continue;
                        }

                        global $APPLICATION;
                        $APPLICATION->ResetException();

                        CIBlockElement::SetPropertyValuesEx(
                            $element["ID"],
                            IBLOCK_ID,
                            array(PROP_RENDER => $fileArray)
                        );

                        if ($ex = $APPLICATION->GetException()) {
                            $errors[] = "Failed to update element ID " . $element["ID"] . ": " . $ex->GetString();
                        } else {
                            $successCount++;
                            $updated[] = array(
                                "ID"     => $element["ID"],
                                "NAME"   => $element["NAME"],
                                "BLOCK"  => $block,
                                "SECTOR" => $sector,
                            );
                        }
                    }
                }
            }

            // Remove the temp copy after all elements are processed
            @unlink($filePath);

        } else {
            $errors[] = "Failed to move uploaded file to " . htmlspecialchars($filePath);
        }
    }
}
?>

<html>

<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
<div class="container mt-5">
    <h1 class="mb-4">Render Upload by Block &amp; Sector</h1>

    <?php if ($successCount > 0): ?>
        <div class="alert alert-success">
            Render uploaded successfully to <b><?= $successCount ?></b> apartment(s).
        </div>
        <table class="table table-sm table-bordered mb-4">
            <thead>
            <tr><th>ID</th><th>Name</th><th>Block</th><th>Sector</th></tr>
            </thead>
            <tbody>
            <?php foreach ($updated as $row): ?>
                <tr>
                    <td><?= (int)$row["ID"] ?></td>
                    <td><?= htmlspecialchars($row["NAME"]) ?></td>
                    <td><?= htmlspecialchars($row["BLOCK"]) ?></td>
                    <td><?= htmlspecialchars($row["SECTOR"]) ?></td>
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
                <option value="155">Monolith New Depo</option>
                <option value="154">Ethno city</option>
                <option value="152">Green city</option>
                <option value="150">Dighomi</option>

            </select>
        </div>

        <div class="mb-3">
            <label for="blocks" class="form-label">Block(s)</label>
            <input type="text" class="form-control" id="blocks" name="blocks" required>
            <div class="form-text">One or more blocks, comma separated (e.g., A, B)</div>
        </div>

        <div class="mb-3">
            <label for="sectors" class="form-label">Sector(s)</label>
            <input type="text" class="form-control" id="sectors" name="sectors" required>
            <div class="form-text">One or more sectors, comma separated (e.g., 1, 2, 3)</div>
        </div>

        <div class="mb-3">
            <label for="file" class="form-label">Render</label>
            <input type="file" class="form-control" id="file" name="file" accept="image/*" required>
        </div>

        <button type="submit" class="btn btn-primary">Upload</button>
    </form>
</div>
</body>

</html>