<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
header('Content-Type: text/html; charset=UTF-8');
global $USER, $DB;

CModule::IncludeModule('crm');

$user_id_for_info = $USER->GetID();

// ============================================================
// HELPERS
// ============================================================

function getUserName($id) {
    $res = CUser::GetByID($id)->Fetch();
    return $res["NAME"] . " " . $res["LAST_NAME"];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array());
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL  = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK',  "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

function getCompanyInfo($companyId) {
    $arContact = array();
    $res = CCrmCompany::GetList(array("ID" => "ASC"), array("ID" => $companyId), array());
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'COMPANY', 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL  = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'COMPANY', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK',  "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

function getDealInfo($dealID) {
    $res = CCrmDeal::GetList(array("ID" => "ASC"), array("ID" => $dealID), array());
    if ($arDeal = $res->Fetch()) return $arDeal;
    return array();
}

function getCIBlockElementsByFilter($arFilter) {
    $arElements = array();
    $res = CIBlockElement::GetList(array("ID" => "ASC"), $arFilter, false, array("nPageSize" => 99999), array());
    while ($ob = $res->GetNextElement()) {
        $arFields = $ob->GetFields();
        $arProps  = $ob->GetProperties();
        $row = array();
        foreach ($arFields as $k => $v) $row[$k] = $v;
        foreach ($arProps  as $k => $p) $row[$k] = $p["VALUE"];
        $arElements[] = $row;
    }
    return $arElements;
}

function addCIBlockElement($arForAdd, $arProps = array()) {
    $el = new CIBlockElement;
    $arForAdd["PROPERTY_VALUES"] = $arProps;
    if ($id = $el->Add($arForAdd)) return $id;
    return 'Error: ' . $el->LAST_ERROR;
}

function updateDealGadaxdebi($dealid) {
    global $DB;
    $res = CCrmDeal::GetList(array(), array("CHECK_PERMISSIONS" => "N", "ID" => $dealid),
        array("ID", "OPPORTUNITY", "UF_CRM_1684226981", "UF_CRM_1684931758250", "UF_CRM_1684931748592"));
    $afterSaleDeal = $res->Fetch();
    if (!$afterSaleDeal) return;

    $salesDealId  = $afterSaleDeal["UF_CRM_1684226981"];
    $saleDealIdNum = (int)explode("_", $salesDealId)[1];

    $res2 = CCrmDeal::GetList(array(), array("CHECK_PERMISSIONS" => "N", "ID" => $saleDealIdNum),
        array("ID", "OPPORTUNITY", "UF_CRM_1684931758250", "UF_CRM_1684931748592"));
    $salesDealInfo = $res2->Fetch();

    $arFilter  = array("PROPERTY_DEAL" => $saleDealIdNum, "IBLOCK_ID" => 19);
    $payments  = getCIBlockElementsByFilter($arFilter);

    $moneyToPay  = (float)($salesDealInfo['OPPORTUNITY'] ?? 0);
    $payedMoney  = 0;
    foreach ($payments as $p) $payedMoney += (float)($p['TANXA'] ?? 0);
    $moneyLeft = $moneyToPay - $payedMoney;

    $CCrmDeal = new CCrmDeal(false);
    if ($salesDealInfo["UF_CRM_1684931758250"] != $moneyLeft || $salesDealInfo["UF_CRM_1684931748592"] != $payedMoney) {
        $CCrmDeal->Update($saleDealIdNum, array("UF_CRM_1684931758250" => $moneyLeft, "UF_CRM_1684931748592" => $payedMoney));
    }
    if ($afterSaleDeal["UF_CRM_1684931758250"] != $moneyLeft || $afterSaleDeal["UF_CRM_1684931748592"] != $payedMoney) {
        $CCrmDeal->Update($dealid, array("UF_CRM_1684931758250" => $moneyLeft, "UF_CRM_1684931748592" => $payedMoney));
    }
}

// ============================================================
// TABLE GENERATORS
// ============================================================

function generateProductsTable($dealId, $geo = true) {
    $products = CCrmDeal::LoadProductRows($dealId);
    $head = $geo
        ? array('#', 'პროდუქტის სახელი', 'რაოდენობა', 'ფასი')
        : array('#', 'Product name', 'Quantity', 'Price');
    $total_label = $geo ? 'სულ' : 'Total';

    $t  = "<table style='border-collapse:collapse;margin:0;float:left;'>";
    $t .= "<tr>";
    foreach ($head as $h) $t .= "<th style='padding:10px;border:1px solid black;font-family:sylfaen;'>" . htmlspecialchars($h) . "</th>";
    $t .= "</tr>";

    $n = $totalQty = $totalPrice = 0;
    foreach ($products as $product) {
        $n++;
        $name  = htmlspecialchars($product["PRODUCT_NAME"] ?? '');
        $qty   = (float)($product["QUANTITY"] ?? 0);
        $price = (float)($product["PRICE"] ?? 0);
        $totalQty   += $qty;
        $totalPrice += $qty * $price;
        $t .= "<tr>
            <td style='padding:10px;border:1px solid black;text-align:center;'>$n</td>
            <td style='padding:10px;border:1px solid black;font-family:sylfaen;'>$name</td>
            <td style='padding:10px;border:1px solid black;text-align:center;'>" . number_format($qty, 2, '.', '') . "</td>
            <td style='padding:10px;border:1px solid black;text-align:center;'>$ " . number_format($price, 2, '.', '') . "</td>
        </tr>";
    }
    $t .= "<tr>
        <td colspan='2' style='padding:10px;border:1px solid black;font-weight:bold;text-align:right;font-family:sylfaen;'>$total_label</td>
        <td style='padding:10px;border:1px solid black;font-weight:bold;text-align:center;'>" . number_format($totalQty, 2, '.', '') . "</td>
        <td style='padding:10px;border:1px solid black;font-weight:bold;text-align:center;'>$ " . number_format($totalPrice, 2, '.', '') . "</td>
    </tr>";
    $t .= "</table>";
    return $t;
}

function generateScheduleTable($data, $totalPrice, $lang) {
    mb_internal_encoding('UTF-8');

    $labelDate      = $lang === 'GEO' ? 'გადახდის დრო'    : 'payment date';
    $labelAmount    = $lang === 'GEO' ? 'თანხა $'          : 'amount $';
    $labelRemaining = $lang === 'GEO' ? 'დარჩენილი თანხა $' : 'remaining amount $';

    $paymentLabels = array(
        'პირველადი შენატანი' => 'Initial payment',
        'ბოლო გადახდა'       => 'Final payment',
        'პირველი გადახდა'    => 'First payment',
        'რესტრუქტურიზაცია'   => 'Restructured',
    );

    $t  = "<table style='border-collapse:collapse;width:85%;font-family:Arial,sans-serif;margin:auto;'>";
    $t .= "<thead><tr style='background-color:#f2f2f2;'>";
    foreach (array('#', $labelDate, $labelAmount, $labelRemaining) as $h) {
        $t .= "<th style='border:1px solid black;padding:2px;text-align:center;font-weight:bold;font-size:10px;font-family:sylfaen;'>" . htmlspecialchars($h) . "</th>";
    }
    $t .= "</tr></thead><tbody>";

    foreach ($data as $row) {
        $payment = $row["payment"];
        $date    = $row["date"];
        $amount  = (float)$row["amount"];

        if ($lang !== 'GEO' && isset($paymentLabels[$payment])) {
            $payment = $paymentLabels[$payment];
        }

        $totalPrice -= $amount;
        $t .= "<tr>
            <td style='border:1px solid black;padding:2px;text-align:center;font-size:10px;font-family:sylfaen;'>$payment</td>
            <td style='border:1px solid black;padding:2px;text-align:center;font-size:10px;font-family:sylfaen;'>$date</td>
            <td style='border:1px solid black;padding:2px;text-align:center;font-size:10px;font-family:sylfaen;'>" . number_format($amount, 2, '.', ',') . "</td>
            <td style='border:1px solid black;padding:2px;text-align:center;font-size:10px;font-family:sylfaen;'>" . number_format($totalPrice, 2, '.', ',') . "</td>
        </tr>";
    }

    $t .= "</tbody></table>";
    return $t;
}

// ============================================================
// DOCX / PDF GENERATION
// ============================================================

function wd_rpr_signature(DOMElement $run): string {
    foreach ($run->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === 'rPr') {
            return $c->ownerDocument->saveXML($c);
        }
    }
    return '';
}

function wd_generate_drawing_xml($rId, $wPx, $hPx) {
    $wEmu = round($wPx * 9525);
    $hEmu = round($hPx * 9525);
    $id   = rand(1000, 9999);
    return '
    <w:r xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
        <w:drawing>
            <wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">
                <wp:extent cx="' . $wEmu . '" cy="' . $hEmu . '"/>
                <wp:effectExtent l="0" t="0" r="0" b="0"/>
                <wp:docPr id="' . $id . '" name="Image ' . $id . '"/>
                <wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>
                <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:nvPicPr>
                                <pic:cNvPr id="' . $id . '" name="Picture ' . $id . '"/>
                                <pic:cNvPicPr/>
                            </pic:nvPicPr>
                            <pic:blipFill>
                                <a:blip r:embed="' . $rId . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>
                                <a:stretch><a:fillRect/></a:stretch>
                            </pic:blipFill>
                            <pic:spPr>
                                <a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $wEmu . '" cy="' . $hEmu . '"/></a:xfrm>
                                <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                            </pic:spPr>
                        </pic:pic>
                    </a:graphicData>
                </a:graphic>
            </wp:inline>
        </w:drawing>
    </w:r>';
}

function wd_replace_placeholder_with_xml($dom, $xp, $placeholder, $xmlFragment) {
    if (empty($xmlFragment)) return;
    $nodes = array();
    foreach ($xp->query('//w:t') as $tNode) {
        if (strpos($tNode->nodeValue, $placeholder) !== false) $nodes[] = $tNode;
    }
    foreach ($nodes as $tNode) {
        $parentRun  = $tNode->parentNode;
        $parentPara = $parentRun->parentNode;
        $importDoc  = new DOMDocument();
        @$importDoc->loadXML($xmlFragment);
        $importedNode = $dom->importNode($importDoc->documentElement, true);
        if ($importedNode->localName === 'tbl') {
            $parentPara->parentNode->insertBefore($importedNode, $parentPara);
            $parentPara->parentNode->removeChild($parentPara);
        } else {
            $parentPara->insertBefore($importedNode, $parentRun);
            $parentPara->removeChild($parentRun);
        }
    }
}

function wd_merge_runs_in_paragraph(DOMElement $para, DOMXPath $xp): void {
    $changed = true;
    while ($changed) {
        $changed = false;
        $runs    = array();
        foreach ($para->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') $runs[] = $child;
        }
        $i = 0;
        while ($i < count($runs) - 1) {
            $a = $runs[$i]; $b = $runs[$i + 1];
            $aTexts = $xp->query('w:t', $a);
            $bTexts = $xp->query('w:t', $b);
            $aHasOther = $bHasOther = false;
            foreach ($a->childNodes as $c) if ($c instanceof DOMElement && !in_array($c->localName, ['rPr','t'], true)) { $aHasOther = true; break; }
            foreach ($b->childNodes as $c) if ($c instanceof DOMElement && !in_array($c->localName, ['rPr','t'], true)) { $bHasOther = true; break; }
            if ($aHasOther || $bHasOther || $aTexts->length === 0 || $bTexts->length === 0) { $i++; continue; }
            $combinedPreview = '';
            foreach ($aTexts as $t) $combinedPreview .= $t->nodeValue;
            foreach ($bTexts as $t) $combinedPreview .= $t->nodeValue;
            $signaturesMatch    = (wd_rpr_signature($a) === wd_rpr_signature($b));
            $looksLikePlaceholder = strpos($combinedPreview, '$') !== false;
            if (!$signaturesMatch && !$looksLikePlaceholder) { $i++; continue; }
            $combined = '';
            foreach ($aTexts as $t) $combined .= $t->nodeValue;
            foreach ($bTexts as $t) $combined .= $t->nodeValue;
            foreach (iterator_to_array($aTexts) as $t) $a->removeChild($t);
            $wns  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            $newT = $a->ownerDocument->createElementNS($wns, 'w:t');
            $newT->setAttribute('xml:space', 'preserve');
            $newT->appendChild($a->ownerDocument->createTextNode($combined));
            $a->appendChild($newT);
            $b->parentNode->removeChild($b);
            array_splice($runs, $i + 1, 1);
            $changed = true;
        }
    }
}

function wd_replace_text_node_with_breaks(DOMElement $tNode, string $text): void {
    $run  = $tNode->parentNode;
    if (!$run instanceof DOMElement) return;
    $dom  = $tNode->ownerDocument;
    $wns  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $parts    = explode("\n", $text);
    $fragment = $dom->createDocumentFragment();
    foreach ($parts as $idx => $part) {
        if ($idx > 0) $fragment->appendChild($dom->createElementNS($wns, 'w:br'));
        if ($part !== '') {
            $t = $dom->createElementNS($wns, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($part));
            $fragment->appendChild($t);
        }
    }
    $run->insertBefore($fragment, $tNode);
    $run->removeChild($tNode);
}

function wd_html_table_to_ooxml(string $html): string {
    $hdoc = new DOMDocument();
    libxml_use_internal_errors(true);
    $hdoc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $hxp = new DOMXPath($hdoc);

    $table = $hxp->query('//table')->item(0);
    if (!$table) return '';

    $colPcts = array();
    foreach ($hxp->query('.//colgroup/col', $table) as $col) {
        if (preg_match('/width\s*:\s*([\d.]+)%/', $col->getAttribute('style'), $m)) {
            $colPcts[] = floatval($m[1]);
        }
    }
    $totalDxa = 9000;
    $gridCols = array();
    foreach ($colPcts as $p) $gridCols[] = (int)round($totalDxa * $p / 100);

    $o  = '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
    $o .= '<w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
    foreach (['top','left','bottom','right','insideH','insideV'] as $s) {
        $o .= '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
    }
    $o .= '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';
    if ($gridCols) {
        $o .= '<w:tblGrid>';
        foreach ($gridCols as $w) $o .= '<w:gridCol w:w="' . $w . '"/>';
        $o .= '</w:tblGrid>';
    }

    $extractColor = function(string $style): string {
        if (preg_match('/background-color\s*:\s*#([0-9a-fA-F]{6})/i', $style, $m)) return strtoupper($m[1]);
        if (preg_match('/background-color\s*:\s*#([0-9a-fA-F]{3})\b/i', $style, $m)) {
            $s = $m[1]; return strtoupper($s[0].$s[0].$s[1].$s[1].$s[2].$s[2]);
        }
        return '';
    };
    $extractFontColor = function(string $style): string {
        if (preg_match('/(?:^|;)\s*color\s*:\s*#([0-9a-fA-F]{6})/i', $style, $m)) return strtoupper($m[1]);
        if (preg_match('/(?:^|;)\s*color\s*:\s*(white|#fff)\b/i', $style)) return 'FFFFFF';
        return '';
    };

    foreach ($hxp->query('.//tr', $table) as $tr) {
        $firstStyle = '';
        foreach ($tr->childNodes as $fc) if ($fc instanceof DOMElement) { $firstStyle = $fc->getAttribute('style'); break; }
        if (stripos($firstStyle, 'display:none') !== false) continue;

        $trStyle   = $tr->getAttribute('style');
        $trBgColor = $extractColor($trStyle);
        $isHeaderRow = ($tr->parentNode instanceof DOMElement && strtolower($tr->parentNode->localName) === 'thead');

        $o .= $isHeaderRow
            ? '<w:tr><w:trPr><w:trHeight w:val="400" w:hRule="atLeast"/><w:tblHeader/></w:trPr>'
            : '<w:tr><w:trPr><w:trHeight w:val="400" w:hRule="atLeast"/></w:trPr>';

        $cells  = $hxp->query('./th | ./td', $tr);
        $colIdx = 0;
        foreach ($cells as $cell) {
            $cellStyle = $cell->getAttribute('style');
            $isTh      = strtolower($cell->localName) === 'th';
            $bgColor   = $extractColor($cellStyle) ?: $trBgColor;
            $fontColor = $extractFontColor($cellStyle) ?: $extractFontColor($trStyle);
            $align     = 'left';
            if (preg_match('/text-align\s*:\s*(\w+)/i', $cellStyle, $m)) $align = strtolower($m[1]);
            $colspanAttr = $cell->getAttribute('colspan');
            $colspan     = $colspanAttr ? max(1, (int)$colspanAttr) : 1;
            $cellW       = 0;
            for ($c = 0; $c < $colspan; $c++) $cellW += $gridCols[$colIdx + $c] ?? 0;
            if ($cellW === 0) $cellW = (int)round($totalDxa / max($cells->length, 1));

            $cellText = '';
            $hasBold  = false;
            foreach ($cell->childNodes as $cn) {
                if ($cn instanceof DOMElement && in_array(strtolower($cn->localName), ['b','strong'], true)) $hasBold = true;
                $cellText .= $cn->textContent;
            }
            $cellText = trim($cellText);

            $jcMap = ['center'=>'center','right'=>'right','justify'=>'both','left'=>'left'];
            $jc    = $jcMap[$align] ?? 'left';

            $o .= '<w:tc><w:tcPr>';
            $o .= '<w:tcW w:w="' . $cellW . '" w:type="dxa"/>';
            if ($colspan > 1) $o .= '<w:gridSpan w:val="' . $colspan . '"/>';
            $o .= '<w:tcBorders>';
            foreach (['top','left','bottom','right'] as $s) {
                $o .= '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
            }
            $o .= '</w:tcBorders>';
            if ($bgColor !== '') $o .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $bgColor . '"/>';
            $o .= '<w:vAlign w:val="center"/></w:tcPr>';
            $o .= '<w:p><w:pPr><w:jc w:val="' . $jc . '"/><w:spacing w:before="0" w:after="0"/></w:pPr>';
            $o .= '<w:r><w:rPr>';
            $o .= '<w:rFonts w:ascii="FreeSerif" w:hAnsi="FreeSerif" w:cs="FreeSerif"/>';
            $o .= '<w:sz w:val="22"/><w:szCs w:val="22"/>';
            if ($isTh || $hasBold) $o .= '<w:b/><w:bCs/>';
            if ($fontColor !== '') $o .= '<w:color w:val="' . $fontColor . '"/>';
            $o .= '</w:rPr>';
            $o .= '<w:t xml:space="preserve">' . htmlspecialchars($cellText, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>';
            $o .= '</w:r></w:p></w:tc>';
            $colIdx += $colspan;
        }
        $o .= '</w:tr>';
    }
    $o .= '</w:tbl>';
    return $o;
}

function generateDocument($fileData, $variables, $convertToPdf = true) {
    $tempDocx = tempnam(sys_get_temp_dir(), 'fmg_') . '.docx';
    file_put_contents($tempDocx, $fileData);

    $zip = new ZipArchive();
    if ($zip->open($tempDocx) !== true) {
        @unlink($tempDocx);
        throw new Exception("Cannot open DOCX");
    }

    $documentXml = $zip->getFromName('word/document.xml');
    $relsXml     = $zip->getFromName('word/_rels/document.xml.rels');
    if ($documentXml === false) {
        $zip->close(); @unlink($tempDocx);
        throw new Exception("document.xml not found");
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    @$dom->loadXML($documentXml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    foreach ($xp->query('//w:p') as $para) wd_merge_runs_in_paragraph($para, $xp);

    $scalars   = array();
    $tableVars = array();
    $imageVars = array();

    foreach ($variables as $var) {
        $type = $var['VarType'] ?? '';
        if ($type === 'T') {
            $tableVars[$var['VarName']] = $var['VarValue'];
        } elseif ($type === 'P' && !empty($var['VarValue'])) {
            $imageVars[$var['VarName']] = $var;
        } else {
            $scalars[$var['VarName']] = (string)($var['VarValue'] ?? '');
        }
    }

    uksort($scalars, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($xp->query('//w:t') as $tNode) {
        $replaced = str_replace(array_keys($scalars), array_values($scalars), $tNode->nodeValue);
        if ($replaced !== $tNode->nodeValue) {
            if (strpos($replaced, "\n") !== false) {
                wd_replace_text_node_with_breaks($tNode, $replaced);
            } else {
                $tNode->nodeValue = htmlspecialchars($replaced, ENT_XML1);
            }
        }
    }

    foreach ($tableVars as $placeholder => $html) {
        if (empty($html)) continue;
        wd_replace_placeholder_with_xml($dom, $xp, $placeholder, wd_html_table_to_ooxml($html));
    }

    if (!empty($imageVars)) {
        $relsDom = new DOMDocument();
        $relsDom->loadXML($relsXml);
        foreach ($imageVars as $placeholder => $imgData) {
            $rId       = 'rIdImg' . uniqid();
            $imagePath = 'word/media/' . $rId . '.jpg';
            $zip->addFromString($imagePath, base64_decode($imgData['VarValue']));
            $relNode = $relsDom->createElement('Relationship');
            $relNode->setAttribute('Id', $rId);
            $relNode->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
            $relNode->setAttribute('Target', 'media/' . $rId . '.jpg');
            $relsDom->documentElement->appendChild($relNode);
            wd_replace_placeholder_with_xml($dom, $xp, $placeholder, wd_generate_drawing_xml($rId, $imgData['Width'] ?? 250, $imgData['Heigh'] ?? 350));
        }
        $zip->addFromString('word/_rels/document.xml.rels', $relsDom->saveXML());
    }

    $documentXmlStr = $dom->saveXML();
    $documentXmlStr = str_replace(
        ['w:ascii="Sylfaen"', 'w:hAnsi="Sylfaen"', 'w:cs="Sylfaen"', 'w:eastAsia="Sylfaen"'],
        ['w:ascii="FreeSerif"', 'w:hAnsi="FreeSerif"', 'w:cs="FreeSerif"', 'w:eastAsia="FreeSerif"'],
        $documentXmlStr
    );
    $zip->addFromString('word/document.xml', $documentXmlStr);

    $settingsXml = $zip->getFromName('word/settings.xml');
    if ($settingsXml !== false) {
        $settingsXml = str_replace(
            '</w:settings>',
            '<w:embedTrueTypeFonts/><w:embedSystemFonts/></w:settings>',
            $settingsXml
        );
        $zip->addFromString('word/settings.xml', $settingsXml);
    }
    $zip->close();

    if ($convertToPdf) {
        $pdfData = convertDocxToPdf($tempDocx);
        @unlink($tempDocx);
        return $pdfData;
    }
    $result = file_get_contents($tempDocx);
    @unlink($tempDocx);
    return $result;
}

function convertDocxToPdf($docxPath) {
    $outputDir   = sys_get_temp_dir();
    $loConfigDir = sys_get_temp_dir() . '/lo_config_' . uniqid();
    mkdir($loConfigDir, 0777, true);
    putenv('HOME=/tmp');
    putenv('DCONF_PROFILE=/dev/null');

    $cmd = sprintf(
        'libreoffice --headless --norestore --nofirststartwizard -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
        escapeshellarg($loConfigDir),
        escapeshellarg($outputDir),
        escapeshellarg($docxPath)
    );
    exec($cmd, $output, $exitCode);

    array_map('unlink', glob($loConfigDir . '/*'));
    @rmdir($loConfigDir);

    $pdfPath = $outputDir . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
    if ($exitCode === 0 && file_exists($pdfPath)) {
        $data = file_get_contents($pdfPath);
        @unlink($pdfPath);
        return $data;
    }
    throw new Exception('LibreOffice conversion failed: ' . implode(' ', $output));
}

// ============================================================
// BOOT
// ============================================================

$today   = date("d/m/Y");
$dealid  = !empty($_GET["dealid"]) ? $_GET["dealid"] : (!empty($_POST["deal_id"]) ? $_POST["deal_id"] : "");

if (!empty($dealid)) updateDealGadaxdebi($dealid);

$popup_mode  = 'nopop';
$empty_get   = false;
$error_code  = "";

if (isset($_GET["popup"]) && $_GET["popup"] == true) $popup_mode = 'ispop';

$deal = getDealInfo($dealid);

// File list for selector
$filesarr = array();
$dbRes = $DB->query('SELECT ID, NAME FROM b_disk_object WHERE PARENT_ID = 38');


if ($dbRes) {
    while ($object = $dbRes->Fetch()) $filesarr[] = array("NAME" => $object["NAME"], "ID" => $object["ID"]);
}

// Filter files by project (for non-popup mode)
$filtered_files = array();
foreach ($filesarr as $f) {
    $parts = explode("$", $f["NAME"]);
    if ($parts[0] === "ყველა" || $parts[0] === $deal["UF_CRM_1779277729207"]) {
        $filtered_files[] = $f;
    }
}

if (empty($dealid)) {
    $empty_get  = true;
    $error_code = "Empty Deal Id";
}

// ============================================================
// POST HANDLER
// ============================================================

if (!empty($_POST)) {

    $popup_mode = $_POST['popup'];
    $doc_id     = $_POST["docs"];
    $deal_id    = $_POST["deal_id"];
    $file_type  = $_POST["type"];

    $deal = getDealInfo($deal_id);

    // ---- Contacts ----
    $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($deal["ID"]);
    if (!empty($deal["UF_CRM_1755260753"])) {
        $contactIds[] = (int)explode("_", $deal["UF_CRM_1755260753"])[1];
    }
    $resContractArrIDInfo = array();
    foreach ($contactIds as $cid) $resContractArrIDInfo[] = getContactInfo($cid);

    // ---- Old owners ----
    $old_owner_contact = array();
    $old_owner_company = array();
    if (!empty($deal["UF_CRM_1720001343"])) {
        foreach ($deal["UF_CRM_1720001343"] as $value) {
            $parts = explode("_", $value);
            if ($parts[0] === "CO") $old_owner_company[] = getCompanyInfo($parts[1]);
            elseif ($parts[0] === "C") $old_owner_contact[] = getContactInfo($parts[1]);
        }
    }

    // ---- Company ----
    $company = getCompanyInfo($deal["COMPANY_ID"]);

    // Blank tech placeholders
    $tech_contact = getContactInfo(1792);
    $tech_company = getCompanyInfo(2);
    foreach ($tech_contact as $k => $v) $tech_contact[$k] = "";
    foreach ($tech_company as $k => $v) $tech_company[$k] = "";

    // Multi-contact shortcuts
    if (count($resContractArrIDInfo) === 2) $deal["DOUBLE_CONTACT_INFO"] = $resContractArrIDInfo;
    elseif (count($resContractArrIDInfo) === 3) $deal["TRIPLE_CONTACT_INFO"] = $resContractArrIDInfo;

    $combinedArray = array();
    foreach ($resContractArrIDInfo as $info) {
        foreach ($info as $k => $v) {
            $combinedArray[$k] = isset($combinedArray[$k]) ? $combinedArray[$k] . "," . $v : $v;
        }
    }

    $combinedArray_old_company = array();
    foreach ($old_owner_company as $info) {
        foreach ($info as $k => $v) {
            $combinedArray_old_company[$k] = isset($combinedArray_old_company[$k]) ? $combinedArray_old_company[$k] . "," . $v : $v;
        }
    }

    $combinedArray_old_contact = array();
    foreach ($old_owner_contact as $info) {
        foreach ($info as $k => $v) {
            $combinedArray_old_contact[$k] = isset($combinedArray_old_contact[$k]) ? $combinedArray_old_contact[$k] . "," . $v : $v;
        }
    }

    $deal["CONTACT_ARR"]     = !empty($combinedArray)             ? $combinedArray             : $tech_contact;
    $deal["COMPANY_ARR"]     = !empty($company)                   ? $company                   : $tech_company;
    $deal["CONTACT_ARR_OLD"] = !empty($combinedArray_old_contact) ? $combinedArray_old_contact : $tech_contact;
    $deal["COMPANY_ARR_OLD"] = !empty($combinedArray_old_company) ? $combinedArray_old_company : $tech_company;

    if (empty($deal["CONTACT_ARR"]) && empty($deal["COMPANY_ARR"])) {
        $empty_get   = true;
        $error_code .= " ,Empty Contact or Company";
    } elseif (empty($deal["UF_CRM_1779277729207"])) {
        $empty_get   = true;
        $error_code .= " ,Empty Project Field";
    } else {

        $proj  = $deal["UF_CRM_1779277729207"];
        $codes = getCIBlockElementsByFilter(array("IBLOCK_ID" => 47, "PROPERTY_PROJECT_NAME" => $proj));

        // ---- Build variable array ----
        $fullarr = array();

        // Deal scalars
        foreach ($deal as $key => $value) {
            if (is_array($value)) continue;
            $value = ($value === "" || $value === null) ? "" : $value;
            // Suppress raw OPPORTUNITY – use formatted version below if needed
            // if ($key === "OPPORTUNITY") $value = " ";
            $fullarr[] = array('VarName' => '$' . $key . '$', 'VarValue' => $value);
        }

        // Project codes (IBlock 47)
        foreach ($codes as $code) {
            $fullarr[] = array(
                'VarName'  => '$' . $code["NAME"] . '$',
                'VarValue' => htmlspecialchars_decode($code["TEXT"] ?? '')
            );
        }

        // Contact / company arrays
        $mapContact     = array('_USER', '_USER');
        $mapCompany     = array('_COM');
        $mapOldContact  = array('_OLD_CON');
        $mapOldCompany  = array('_OLD_COM');

        $addArrayVars = function($arr, $suffix) use (&$fullarr) {
            foreach ($arr as $key => $value) {
                if (is_array($value)) continue;
                $fullarr[] = array('VarName' => '$' . $key . $suffix . '$', 'VarValue' => $value ?? '');
            }
        };
        $addArrayVars($deal["CONTACT_ARR"],     '_USER');
        $addArrayVars($deal["COMPANY_ARR"],     '_COM');
        $addArrayVars($deal["CONTACT_ARR_OLD"], '_OLD_CON');
        $addArrayVars($deal["COMPANY_ARR_OLD"], '_OLD_COM');

        // Multi-contact (_USER_1, _USER_2, _USER_3)
        $multiKey = count($resContractArrIDInfo) === 2 ? "DOUBLE_CONTACT_INFO"
                  : (count($resContractArrIDInfo) === 3 ? "TRIPLE_CONTACT_INFO" : null);
        if ($multiKey) {
            foreach ($deal[$multiKey] as $idx => $info) {
                $suffix = '_USER_' . ($idx + 1);
                $addArrayVars($info, $suffix);
            }
        }

        // Today
        $fullarr[] = array('VarName' => '$TODAY_DATE$', 'VarValue' => $today);

        // ---- IBlock 22 – Payment schedule ----
        $scheduleRows = getCIBlockElementsByFilter(array("IBLOCK_ID" => 22, "PROPERTY_DEAL" => $deal_id));
        usort($scheduleRows, function($a, $b) {
            $dateA = DateTime::createFromFormat('d/m/Y', $a['TARIGI'] ?? '');
            $dateB = DateTime::createFromFormat('d/m/Y', $b['TARIGI'] ?? '');
            if (!$dateA && !$dateB) return 0;
            if (!$dateA) return 1;
            if (!$dateB) return -1;
            return $dateA <=> $dateB;
        });

        $scheduleData = array();
        foreach ($scheduleRows as $row) {
            $amount = (float)explode("|", $row["TANXA"] ?? "")[0];
            $scheduleData[] = array(
                "payment" => $row["PLAN_TYPE"] ?? "",
                "date"    => $row["TARIGI"]    ?? "",
                "amount"  => $amount
            );
        }

        $firstPayment     = !empty($scheduleData) ? number_format($scheduleData[0]["amount"], 2, '.', ',') : '';
        $firstPaymentDate = !empty($scheduleData) ? $scheduleData[0]["date"] : '';
        $lastPayment      = !empty($scheduleData) ? number_format(end($scheduleData)["amount"], 2, '.', ',') : '';
        $lastPaymentDate  = !empty($scheduleData) ? end($scheduleData)["date"] : '';

        $fullarr[] = array('VarName' => '$FIRST_PAYMENT$',      'VarValue' => $firstPayment);
        $fullarr[] = array('VarName' => '$FIRST_PAYMENT_DATE$', 'VarValue' => $firstPaymentDate);
        $fullarr[] = array('VarName' => '$LAST_PAYMENT$',       'VarValue' => $lastPayment);
        $fullarr[] = array('VarName' => '$LAST_PAYMENT_DATE$',  'VarValue' => $lastPaymentDate);

        $fasdaklebuli = (float)$deal['OPPORTUNITY'];

        $fullarr[] = array('VarName' => 'grapik_geo', 'VarValue' => !empty($scheduleData) ? generateScheduleTable($scheduleData, $fasdaklebuli, 'GEO') : '', 'VarType' => 'T');
        $fullarr[] = array('VarName' => 'grapik_eng', 'VarValue' => !empty($scheduleData) ? generateScheduleTable($scheduleData, $fasdaklebuli, 'ENG') : '', 'VarType' => 'T');

        // ---- Products table ----
        $fullarr[] = array('VarName' => 'products_geo', 'VarValue' => generateProductsTable($deal['ID'], true),  'VarType' => 'T');
        $fullarr[] = array('VarName' => 'products_eng', 'VarValue' => generateProductsTable($deal['ID'], false), 'VarType' => 'T');

        // ---- Load and generate document ----
        $dbRes = $DB->query('SELECT * FROM b_disk_object WHERE PARENT_ID = 38 AND ID = ' . (int)$doc_id);
        while ($object = $dbRes->Fetch()) {
            if ((int)$object["ID"] !== (int)$doc_id) continue;

            $nameParts = explode("$", $object["NAME"]);
            $name_docs = count($nameParts) > 2 ? $nameParts[2] : $object["NAME"];

            $fileRow = $DB->query('SELECT SUBDIR, FILE_NAME FROM b_file WHERE ID = ' . (int)$object["FILE_ID"])->Fetch();
            $filePath = $fileRow
                ? $_SERVER["DOCUMENT_ROOT"] . '/upload/' . $fileRow["SUBDIR"] . '/' . $fileRow["FILE_NAME"]
                : $_SERVER["DOCUMENT_ROOT"] . CFile::GetPath($object["FILE_ID"]);

            $fileData = file_get_contents($filePath);
            if (!$fileData || strlen($fileData) < 100) {
                echo "File load failed. Path: $filePath | Size: " . strlen($fileData);
                exit;
            }

            try {
                $convertToPdf  = ($file_type === "pdf");
                $generatedFile = generateDocument($fileData, $fullarr, $convertToPdf);

                // Log generation
                addCIBlockElement(
                    array('IBLOCK_ID' => 53, 'NAME' => "გენერაცია", 'ACTIVE' => 'Y'),
                    array(
                        'DATE_CREATION' => date("d/m/Y H:i:s"),
                        'DOC_NAME'      => $name_docs,
                        'USER'          => $user_id_for_info,
                        'DEAL'          => $deal["ID"],
                        'CLIENT'        => $deal["CONTACT_ARR"]["ID"]
                    )
                );

                ob_end_clean();
                if ($convertToPdf) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="document.pdf"');
                } else {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                    header('Content-Disposition: attachment; filename="document.docx"');
                }
                header('Content-Length: ' . strlen($generatedFile));
                echo $generatedFile;
                exit;
            } catch (Exception $e) {
                echo 'Document generation error: ' . $e->getMessage();
                exit;
            }
        }
    }
}
?>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background: #F0F4F8;
        font-family: 'Inter', sans-serif;
        color: #1a202c;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }
    .shell { width: 100%; max-width: 480px; }
    .page-head { text-align: center; margin-bottom: 28px; }
    .page-head h1 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 700; color: #1a202c; margin-bottom: 4px; }
    .page-head p  { font-size: 13px; color: #718096; }
    .card { background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,.06), 0 8px 32px rgba(0,0,0,.06); }
    .field-group { margin-bottom: 20px; }
    .field-label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .7px; color: #718096; margin-bottom: 7px; }
    select.styled {
        width: 100%; padding: 11px 36px 11px 14px;
        border: 1.5px solid #E2E8F0; border-radius: 10px;
        background: #F7FAFC url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23718096' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 13px center;
        font-family: 'Inter', sans-serif; font-size: 14px; color: #2D3748;
        appearance: none; -webkit-appearance: none;
        cursor: pointer; outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    select.styled:focus { border-color: #4299E1; box-shadow: 0 0 0 3px rgba(66,153,225,.15); background-color: #fff; }
    .format-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .format-option { position: relative; }
    .format-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
    .format-option label {
        display: flex; align-items: center; justify-content: center; gap: 7px;
        padding: 11px 14px; border: 1.5px solid #E2E8F0; border-radius: 10px;
        background: #F7FAFC; font-size: 13px; font-weight: 500; color: #718096;
        cursor: pointer; transition: all .18s; user-select: none;
    }
    .format-option label svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .format-option input[type="radio"]:checked + label { border-color: #4299E1; background: #EBF8FF; color: #2B6CB0; }
    .format-option label:hover { border-color: #CBD5E0; color: #2D3748; background: #EDF2F7; }
    .divider { height: 1px; background: #EDF2F7; margin: 24px 0; }
    .btn-submit {
        width: 100%; padding: 13px 20px; border: none; border-radius: 10px;
        background: #3182CE; color: #fff;
        font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 600;
        cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: background .2s, transform .15s, box-shadow .2s;
        box-shadow: 0 2px 8px rgba(49,130,206,.3);
    }
    .btn-submit svg { width: 16px; height: 16px; stroke: #fff; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .btn-submit:hover { background: #2B6CB0; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(49,130,206,.35); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit:disabled { opacity: .65; cursor: not-allowed; transform: none; }
    .error-box { background: #FFF5F5; border: 1.5px solid #FEB2B2; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #C53030; text-align: center; }
</style>
</head>
<body>
<div class="shell">
    <div class="page-head">
        <h1>დოკუმენტის გენერაცია</h1>
        <p>აირჩიეთ შაბლონი და ფაილის ფორმატი</p>
    </div>
    <div class="card">
        <form method="post" action="<?php echo $_SERVER['PHP_SELF'] . '?dealid=' . $dealid; ?>" id="docForm">
            <input name="deal_id" id="deal_id" type="hidden">
            <input name="popup"   id="popup"   type="hidden">
            <div class="field-group">
                <label class="field-label" for="docs">შაბლონი</label>
                <select name="docs" id="docs" class="styled"></select>
            </div>
            <div class="field-group">
                <label class="field-label">ფორმატი</label>
                <div class="format-row">
                    <div class="format-option">
                        <input type="radio" name="type" id="type_pdf" value="pdf" checked>
                        <label for="type_pdf">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            PDF
                        </label>
                    </div>
                    <div class="format-option">
                        <input type="radio" name="type" id="type_docx" value="docx">
                        <label for="type_docx">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            Word
                        </label>
                    </div>
                </div>
            </div>
            <div class="divider"></div>
            <button type="submit" class="btn-submit">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                გენერაცია და ჩამოტვირთვა
            </button>
        </form>
    </div>
</div>

<script>
    var files      = <?= json_encode($filtered_files); ?>;
    var deal_id    = <?= json_encode($dealid); ?>;
    var get        = <?= json_encode($empty_get); ?>;
    var code       = <?= json_encode($error_code); ?>;
    var pop_up     = <?= json_encode($popup_mode); ?>;
    var pop_files  = <?= json_encode($filesarr); ?>;

    if (get) {
        document.querySelector('.card').innerHTML = '<div class="error-box">&#9888; ' + code + '</div>';
    } else {
        document.getElementById('popup').value   = pop_up;
        document.getElementById('deal_id').value = deal_id;

        var select = document.getElementById('docs');
        var list   = pop_files;
        
        for (var i = 0; i < list.length; i++) {
            var opt = document.createElement('option');
            opt.value       = list[i]["ID"];
            opt.textContent = list[i]["NAME"];
            select.appendChild(opt);
        }

        document.getElementById('docForm').addEventListener('submit', function () {
            var btn = this.querySelector('.btn-submit');
            btn.textContent = 'მიმდინარეობს...';
            btn.disabled = true;
        });
    }
</script>
</body>
</html>