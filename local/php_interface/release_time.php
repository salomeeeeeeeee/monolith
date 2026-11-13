<?php

// ორშაბათის გათავისუფლება 13:00-ზე (2026-11-13): კვირას არავინ მუშაობს და ვერ ამოწმებენ, რას ეწურება ვადა,
// ამიტომ რაც კვირას ან ორშაბათს 13:00-მდე უნდა გათავისუფლებულიყო, ორშაბათს 13:00-ზე თავისუფლდება
// (დილით "24 სთ-ში ვადა ეწურება" ვიჯეტში ჩანს და ასწრებენ). სად მოქმედებს:
//   - ხელშეკრულების ეტაპი (contract_deadline.php): 5 სამუშაო დღე / გაგრძელებული ვადა
//   - გაგრძელებული ჯავშანი (reservation_extension.php)
//   - ჯავშნის ვადა BP 6 / 99 / 100-ში: პაუზა (DelayActivity) CBPSchedulerService-ის agent-ია, BP-ის შაბლონი
//     არ შეცვლილა - agent "monolithMondayReleaseAgent();" (ყოველ 5 წუთში) ამ პაუზების agent-ებს, რომლებიც
//     [კვირა 00:00, ორშაბათი 13:00) შუალედში უნდა შესრულდეს, ორშაბათ 13:00-ზე გადაწევს. პაუზა უბრალოდ
//     გვიან მთავრდება, BP-ის დანარჩენი ლოგიკა (შემოწმება, გათავისუფლება, კომენტარი) იგივეა.

const MONOLITH_MONDAY_RELEASE_TIME = '13:00:00';

// ჯავშნის ვადის BP-ები: 6 - reservation, 99 - რეზერვაციის ვადა (დაშლილი დილი), 100 - reservation again
const MONOLITH_RESERVATION_BP_IDS = [6, 99, 100];

/** გათავისუფლების დრო: [კვირა 00:00, ორშაბათი 13:00) შუალედში მოსული ვადა -> ორშაბათი 13:00, სხვა უცვლელად */
function monolithReleaseTime($timestamp)
{
    if (!$timestamp) {
        return $timestamp;
    }
    $dow = (int)date("N", $timestamp);   // 1 - ორშაბათი, 7 - კვირა
    if ($dow === 7) {
        $monday = date("Y-m-d", strtotime(date("Y-m-d", $timestamp) . " +1 day"));
    } elseif ($dow === 1) {
        $monday = date("Y-m-d", $timestamp);
    } else {
        return $timestamp;
    }
    $release = strtotime($monday . " " . MONOLITH_MONDAY_RELEASE_TIME);
    return $timestamp < $release ? $release : $timestamp;
}

/** BP 6 / 99 / 100-ის პაუზების (DelayActivity) სახელები: [templateId => [activityName => true]] */
function monolithReservationDelayNames()
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = [];
    if (!CModule::IncludeModule('bizproc')) {
        return $names;
    }
    $walk = function ($activities, $templateId) use (&$walk, &$names) {
        foreach ((array)$activities as $activity) {
            if (($activity["Type"] ?? "") === "DelayActivity") {
                $names[$templateId][$activity["Name"]] = true;
            }
            if (!empty($activity["Children"])) {
                $walk($activity["Children"], $templateId);
            }
        }
    };
    foreach (MONOLITH_RESERVATION_BP_IDS as $templateId) {
        $template = CBPWorkflowTemplateLoader::GetList([], ["ID" => (int)$templateId], false, false, ["ID", "TEMPLATE"])->Fetch();
        if ($template) {
            $walk($template["TEMPLATE"], (int)$templateId);
        }
    }
    return $names;
}

/**
 * ჯავშნის ვადის პაუზების agent-ები (მიმდინარე BP 6 / 99 / 100):
 * [ID (agent), NEXT_EXEC (ts), RUNNING, WORKFLOW_ID, ACTIVITY, TEMPLATE_ID, DEAL_ID, STARTED (ts)]
 */
function monolithReservationDelayAgents()
{
    global $DB;
    $delayNames = monolithReservationDelayNames();
    if (!$delayNames) {
        return [];
    }

    $agents = [];
    $res = $DB->Query("SELECT ID, NAME, NEXT_EXEC, RUNNING FROM b_agent WHERE NAME LIKE 'CBPSchedulerService::OnAgent(%'");
    while ($row = $res->Fetch()) {
        if (!preg_match("/^CBPSchedulerService::OnAgent\('([a-z0-9.]+)', '([a-z0-9._-]+)'/i", $row["NAME"], $m)) {
            continue;
        }
        $agents[] = [
            "ID"          => (int)$row["ID"],
            "NEXT_EXEC"   => strtotime($row["NEXT_EXEC"]),
            "RUNNING"     => (string)$row["RUNNING"],
            "WORKFLOW_ID" => $m[1],
            "ACTIVITY"    => $m[2],
        ];
    }
    if (!$agents) {
        return [];
    }

    $workflows = [];
    $ids = array_map(function ($agent) use ($DB) {
        return "'" . $DB->ForSql($agent["WORKFLOW_ID"]) . "'";
    }, $agents);
    $res = $DB->Query(
        "SELECT ID, WORKFLOW_TEMPLATE_ID, DOCUMENT_ID, STARTED FROM b_bp_workflow_state"
        . " WHERE ID IN (" . implode(",", array_unique($ids)) . ")"
        . " AND WORKFLOW_TEMPLATE_ID IN (" . implode(",", array_map("intval", MONOLITH_RESERVATION_BP_IDS)) . ")"
    );
    while ($row = $res->Fetch()) {
        $workflows[$row["ID"]] = $row;
    }

    $result = [];
    foreach ($agents as $agent) {
        $workflow = $workflows[$agent["WORKFLOW_ID"]] ?? null;
        if (!$workflow || empty($delayNames[(int)$workflow["WORKFLOW_TEMPLATE_ID"]][$agent["ACTIVITY"]])) {
            continue;   // სხვა BP ან არა ვადის პაუზა
        }
        $agent["TEMPLATE_ID"] = (int)$workflow["WORKFLOW_TEMPLATE_ID"];
        $agent["DEAL_ID"]     = (int)preg_replace('/^DEAL_/', '', (string)$workflow["DOCUMENT_ID"]);
        $agent["STARTED"]     = strtotime((string)$workflow["STARTED"]);
        $result[] = $agent;
    }
    return $result;
}

/** ჯავშნის ვადის პაუზა დილზე (ბოლოს დაწყებული BP-დან), ორშაბათის წესით: [dealId => ts] */
function monolithReservationReleaseByDeal()
{
    $byDeal = [];
    foreach (monolithReservationDelayAgents() as $agent) {
        $current = $byDeal[$agent["DEAL_ID"]] ?? null;
        if (!$current || $agent["STARTED"] > $current["STARTED"]) {
            $byDeal[$agent["DEAL_ID"]] = $agent;
        }
    }
    return array_map(function ($agent) {
        return monolithReleaseTime($agent["NEXT_EXEC"]);
    }, $byDeal);
}

/** agent: CAgent "monolithMondayReleaseAgent();" (ყოველ 5 წუთში) - კვირა / ორშაბათი დილის პაუზები -> ორშაბათი 13:00 */
function monolithMondayReleaseAgent()
{
    try {
        $now = time();
        foreach (monolithReservationDelayAgents() as $agent) {
            if ($agent["RUNNING"] === "Y") {
                continue;
            }
            $release = monolithReleaseTime($agent["NEXT_EXEC"]);
            if ($release > $agent["NEXT_EXEC"] && $release > $now) {
                CAgent::Update($agent["ID"], ["NEXT_EXEC" => ConvertTimeStamp($release, "FULL")]);
            }
        }
    } catch (\Throwable $e) {
        // შეცდომისას პაუზა თავის დროზე მთავრდება (ძველებურად)
    }
    return "monolithMondayReleaseAgent();";
}
