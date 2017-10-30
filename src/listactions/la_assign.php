<?php
// listactions/la_assign.php -- HotCRP helper classes for list actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Assign_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->privChair && Navigation::page() !== "reviewprefs";
    }
    static function render(PaperList $pl) {
        Ht::stash_script("plactions_dofold()", "plactions_dofold");
        $pl->conf->stash_hotcrp_pc($pl->user);
        return [Ht::select("assignfn",
                          array("auto" => "Automatic assignments",
                                "zzz1" => null,
                                "conflict" => "Conflict",
                                "clearconflict" => "No conflict",
                                "zzz2" => null,
                                "primaryreview" => "Primary review",
                                "secondaryreview" => "Secondary review",
                                "pcreview" => "Optional review",
                                "clearreview" => "Clear review",
                                "zzz3" => null,
                                "lead" => "Discussion lead",
                                "shepherd" => "Shepherd"),
                          $pl->qreq->assignfn,
                          ["class" => "want-focus", "onchange" => "plactions_dofold()"])
            . '<span class="fx"> &nbsp;<span id="atab_assign_for">for</span> &nbsp;'
            . Ht::select("markpc", [], 0, ["id" => "markpc", "class" => "need-pcselector", "data-pcselector-selected" => $pl->qreq->markpc])
            . "</span> &nbsp;" . Ht::submit("fn", "Go", ["value" => "assign", "onclick" => "return plist_submit.call(this)"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        $mt = $qreq->assignfn;
        if ($mt === "auto") {
            $t = in_array($qreq->t, ["acc", "s"]) ? $qreq->t : "all";
            $q = join("+", $ssel->paper_ids());
            go(hoturl("autoassign", "q=$q&amp;t=$t&amp;pap=$q"));
        }

        $mpc = (string) $qreq->markpc;
        if ($mpc === "" || $mpc === "0" || strcasecmp($mpc, "none") == 0)
            $mpc = "none";
        else if (($pc = $user->conf->user_by_email($mpc)))
            $mpc = $pc->email;
        else
            return "“" . htmlspecialchars($mpc) . "” is not a PC member.";
        if ($mpc === "none" && $mt !== "lead" && $mt !== "shepherd")
            return "A PC member is required.";
        $mpc = CsvGenerator::quote($mpc);

        if (!in_array($mt, ["lead", "shepherd", "conflict", "clearconflict",
                            "pcreview", "secondaryreview", "primaryreview",
                            "clearreview"]))
            return "Unknown assignment type.";

        $text = "paper,action,user\n";
        foreach ($ssel->paper_ids() as $pid)
            $text .= "$pid,$mt,$mpc\n";
        $assignset = new AssignmentSet($user);
        $assignset->enable_papers($ssel->paper_ids());
        $assignset->parse($text);
        return $assignset->execute(true);
    }
}
