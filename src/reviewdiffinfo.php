<?php
// reviewdiffinfo.php -- HotCRP class representing review diffs
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewDiffInfo {
    /** @var Conf */
    public $conf;
    /** @var PaperInfo */
    public $prow;
    /** @var ReviewInfo */
    public $rrow;
    /** @var list<ReviewField> */
    private $fields = [];
    /** @var list<null|int|string> */
    private $newv = [];
    /** @var int */
    public $view_score = VIEWSCORE_EMPTY;
    /** @var bool */
    public $notify = false;
    /** @var bool */
    public $notify_author = false;

    function __construct(PaperInfo $prow, ReviewInfo $rrow) {
        $this->conf = $prow->conf;
        $this->prow = $prow;
        $this->rrow = $rrow;
    }
    /** @param ReviewField $f
     * @param null|int|string $newv */
    function add_field($f, $newv) {
        $this->fields[] = $f;
        $this->newv[] = $newv;
        $this->add_view_score($f->view_score);
    }
    /** @param int $view_score */
    function add_view_score($view_score) {
        if ($view_score > $this->view_score) {
            if ($view_score === VIEWSCORE_AUTHORDEC
                && $this->prow->can_author_view_decision()) {
                $view_score = VIEWSCORE_AUTHOR;
            }
            $this->view_score = $view_score;
        }
    }
    /** @return bool */
    function nonempty() {
        return $this->view_score > VIEWSCORE_EMPTY;
    }
    /** @return list<ReviewField> */
    function fields() {
        return $this->fields;
    }

    /** @param 0|1 $dir
     * @return array */
    function make_patch($dir = 0) {
        if (!$this->rrow || !$this->rrow->reviewId) {
            return null;
        }
        $use_xdiff = $this->conf->opt("diffMethod") === "xdiff";
        $patch = [];
        $dmp = null;
        foreach ($this->fields as $i => $f) {
            $sn = $f->short_id;
            $v = [$this->rrow->fields[$f->order], $this->newv[$i]];
            if ($f->has_options) {
                $v[$dir] = (int) $v[$dir];
            } else if (($v[$dir] ?? "") !== "") {
                if ($use_xdiff) {
                    $bdiff = xdiff_string_bdiff($v[1 - $dir], $v[$dir]);
                    if (strlen($bdiff) < strlen($v[$dir]) - 32) {
                        $patch["{$sn}:x"] = $bdiff;
                        continue;
                    }
                } else {
                    $dmp = $dmp ?? new dmp\diff_match_patch;
                    $diffs = $dmp->diff($v[1 - $dir], $v[$dir]);
                    $xdelta = $dmp->diff_toHCDelta($diffs);

                    // validate that toHCDelta can recreate $v[$dir]
                    $xdiffs = $dmp->diff_fromHCDelta($v[1 - $dir], $xdelta);
                    if ($dmp->diff_text1($xdiffs) !== $v[1 - $dir]
                        || $dmp->diff_text2($xdiffs) !== $v[$dir]) {
                        error_log("problem encoding delta for {$f->name}");
                        file_put_contents("/tmp/hotcrp-baddiff.txt", "======" . strlen($v[1-$dir]) . "\n" . $v[1-$dir] . "\n======" . strlen($v[$dir]) . "\n" . $v[$dir] . "\n\n", FILE_APPEND);
                    }

                    if (strlen($xdelta) < strlen($v[$dir]) - 32) {
                        $patch["{$sn}:p"] = $xdelta;
                        continue;
                    }
                }
            }
            $patch[$sn] = $v[$dir];
        }
        return $patch;
    }
    /** @param array $patch
     * @return string */
    static function unparse_patch($patch) {
        $upatch = [];
        $bdata = "";
        foreach ($patch as $n => $v) {
            if (str_ends_with($n, ":x") || str_ends_with($n, ":p")) {
                $upatch[$n] = [strlen($bdata), strlen($v)];
                $bdata .= $v;
            } else {
                $upatch[$n] = $v;
            }
        }
        $str = json_encode($upatch);
        if ($bdata !== "") {
            $str = strlen($str) . $str . $bdata;
        }
        return $str;
    }
    /** @param string $str
     * @return array */
    static function parse_patch($str) {
        $bdata = "";
        if ($str !== "" && ctype_digit($str[0])) {
            $pos = strpos($str, "{");
            $lenx = substr($str, 0, $pos);
            if (!ctype_digit($lenx)
                || ($len = intval($lenx, 10)) <= $pos
                || $len > strlen($str)) {
                return null;
            }
            $bdata = substr($str, $pos + $len);
            $str = substr($str, $pos, $len);
        }
        $data = json_decode($str);
        if (!is_object($data)) {
            return null;
        }
        $data = (array) $data;
        foreach ($data as $n => &$v) {
            if (is_array($v)
                && (str_ends_with($n, ":x") || str_ends_with($n, ":p"))
                && count($v) === 2
                && $v[0] < strlen($bdata)
                && $v[0] + $v[1] <= strlen($bdata)) {
                $v = substr($bdata, $v[0], $v[1]);
            }
        }
        return $data;
    }
    /** @param array $patch
     * @return bool */
    static function apply_patch(ReviewInfo $rrow, $patch) {
        $ok = true;
        $has_xpatch = null;
        $dmp = null;
        foreach ($patch as $n => $v) {
            if (str_ends_with($n, ":x")
                && is_string($v)
                && ($has_xpatch = $has_xpatch ?? function_exists("xdiff_string_bpatch"))
                && ($fi = ReviewFieldInfo::find($rrow->conf, substr($n, 0, -2)))
                && !$fi->has_options) {
                $oldv = $rrow->finfoval($fi);
                $rrow->set_finfoval($fi, xdiff_string_bpatch($oldv, $v));
            } else if (str_ends_with($n, ":p")
                       && is_string($v)
                       && ($fi = ReviewFieldInfo::find($rrow->conf, substr($n, 0, -2)))
                       && !$fi->has_options) {
                $dmp = $dmp ?? new dmp\diff_match_patch;
                $oldv = $rrow->finfoval($fi);
                $rrow->set_finfoval($fi, $dmp->diff_applyHCDelta($oldv, $v));
            } else if (($fi = ReviewFieldInfo::find($rrow->conf, $n))) {
                $rrow->set_finfoval($fi, $v);
            } else {
                $ok = false;
            }
        }
        return $ok;
    }
}
