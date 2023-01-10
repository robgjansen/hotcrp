<?php
// reviewfield.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// JSON schema for settings["review_form"]:
// [{"id":FIELDID,"name":NAME,"description":DESCRIPTION,"order":ORDER,
//   "display_space":ROWS,"visibility":VISIBILITY,
//   "values":[DESCRIPTION,...],["start":LEVELCHAR | "symbols":[WORD,...]]},...]

class ReviewFieldInfo {
    /** @var non-empty-string
     * @readonly */
    public $short_id;
    /** @var bool
     * @readonly */
    public $is_sfield;
    /** @var ?non-empty-string
     * @readonly */
    public $main_storage;
    /** @var ?non-empty-string
     * @readonly */
    public $json_storage;

    // see also Signature properties in PaperInfo
    /** @var list<?non-empty-string>
     * @readonly */
    static private $new_sfields = [
        "overAllMerit", "reviewerQualification", "novelty", "technicalMerit",
        "interestToCommunity", "longevity", "grammar", "likelyPresentation",
        "suitableForShort", "potential", "fixability"
    ];
    /** @var array<string,ReviewFieldInfo> */
    static private $field_info_map = [];

    /** @param string $short_id
     * @param bool $is_sfield
     * @param ?non-empty-string $main_storage
     * @param ?non-empty-string $json_storage
     * @phan-assert non-empty-string $short_id */
    function __construct($short_id, $is_sfield, $main_storage, $json_storage) {
        $this->short_id = $short_id;
        $this->is_sfield = $is_sfield;
        $this->main_storage = $main_storage;
        $this->json_storage = $json_storage;
    }

    /** @param Conf $conf
     * @param string $id
     * @return ?ReviewFieldInfo */
    static function find($conf, $id) {
        $m = self::$field_info_map[$id] ?? null;
        if (!$m && !array_key_exists($id, self::$field_info_map)) {
            $sv = $conf->sversion;
            if (strlen($id) > 3
                && ($n = array_search($id, self::$new_sfields)) !== false) {
                $id = sprintf("s%02d", $n + 1);
            }
            if (strlen($id) === 3
                && ($id[0] === "s" || $id[0] === "t")
                && ($d1 = ord($id[1])) >= 48
                && $d1 <= 57
                && ($d2 = ord($id[2])) >= 48
                && $d2 <= 57
                && ($n = ($d1 - 48) * 10 + $d2 - 48) > 0) {
                if ($id[0] === "s" && $n < 12) {
                    $storage = $sv >= 260 ? $id : self::$new_sfields[$n - 1];
                    $m = new ReviewFieldInfo($id, true, $storage, null);
                    self::$field_info_map[self::$new_sfields[$n - 1]] = $m;
                } else {
                    $m = new ReviewFieldInfo($id, $id[0] === "s", null, $id);
                }
            }
            self::$field_info_map[$id] = $m;
        }
        return $m;
    }
}

abstract class ReviewField implements JsonSerializable {
    const VALUE_NONE = 0;
    const VALUE_SC = 1;
    const VALUE_TRIM = 2;

    /** @var non-empty-string
     * @readonly */
    public $short_id;
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var string */
    public $name;
    /** @var string */
    public $name_html;
    /** @var string */
    public $type;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $_search_keyword;
    /** @var int */
    public $view_score;
    /** @var ?int */
    public $order;
    /** @var int */
    public $round_mask = 0;
    /** @var ?string */
    public $exists_if;
    /** @var ?SearchTerm */
    private $_exists_search;
    /** @var bool */
    private $_need_exists_search;
    /** @var bool */
    public $required = false;
    /** @var ?non-empty-string
     * @readonly */
    public $main_storage;
    /** @var ?non-empty-string
     * @readonly */
    public $json_storage;
    /** @var bool
     * @readonly */
    public $is_sfield;

    static private $view_score_map = [
        "secret" => VIEWSCORE_ADMINONLY,
        "admin" => VIEWSCORE_REVIEWERONLY,
        "pconly" => VIEWSCORE_PC,
        "re" => VIEWSCORE_REVIEWER, "pc" => VIEWSCORE_REVIEWER,
        "audec" => VIEWSCORE_AUTHORDEC, "authordec" => VIEWSCORE_AUTHORDEC,
        "au" => VIEWSCORE_AUTHOR, "author" => VIEWSCORE_AUTHOR
    ];
    // Hard-code the database's `view_score` values as of January 2016
    static private $view_score_upgrade_map = [
        -2 => "secret", -1 => "admin", 0 => "re", 1 => "au"
    ];
    static private $view_score_rmap = [
        VIEWSCORE_ADMINONLY => "secret",
        VIEWSCORE_REVIEWERONLY => "admin",
        VIEWSCORE_PC => "pconly",
        VIEWSCORE_REVIEWER => "re",
        VIEWSCORE_AUTHORDEC => "audec",
        VIEWSCORE_AUTHOR => "au"
    ];

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        $this->short_id = $finfo->short_id;
        $this->main_storage = $finfo->main_storage;
        $this->json_storage = $finfo->json_storage;
        $this->is_sfield = $finfo->is_sfield;
        $this->conf = $conf;

        $this->name = $j->name ?? "";
        $this->name_html = htmlspecialchars($this->name);
        $this->type = $j->type ?? ($this->is_sfield ? "radio" : "text");
        $this->description = $j->description ?? "";
        $vis = $j->visibility ?? null;
        if ($vis === null /* XXX backward compat */) {
            $vis = $j->view_score ?? null;
            if (is_int($vis)) {
                $vis = self::$view_score_upgrade_map[$vis];
            }
        }
        $this->view_score = VIEWSCORE_REVIEWER;
        if (is_string($vis) && isset(self::$view_score_map[$vis])) {
            $this->view_score = self::$view_score_map[$vis];
        }
        $this->order = $j->order ?? $j->position /* XXX */ ?? null;
        if ($this->order !== null && $this->order < 0) {
            $this->order = 0;
        }
        $this->round_mask = $j->round_mask ?? 0;
        if ($this->exists_if !== ($j->exists_if ?? null)) {
            $this->exists_if = $j->exists_if ?? null;
            $this->_exists_search = null;
            $this->_need_exists_search = ($this->exists_if ?? "") !== "";
        }
        $this->required = !!($j->required ?? false);
    }

    /** @param ReviewFieldInfo $rfi
     * @return ReviewField */
    static function make_json(Conf $conf, $rfi, $j) {
        if ($rfi->is_sfield) {
            return new Score_ReviewField($conf, $rfi, $j);
        } else {
            return new Text_ReviewField($conf, $rfi, $j);
        }
    }

    /** @param ReviewField $a
     * @param ReviewField $b
     * @return int */
    static function order_compare($a, $b) {
        if (!$a->order !== !$b->order) {
            return $a->order ? -1 : 1;
        } else if ($a->order !== $b->order) {
            return $a->order < $b->order ? -1 : 1;
        } else {
            return strcmp($a->short_id, $b->short_id);
        }
    }

    /** @param string $s
     * @return string */
    static function clean_name($s) {
        while ($s !== ""
               && $s[strlen($s) - 1] === ")"
               && ($lparen = strrpos($s, "(")) !== false
               && preg_match('/\A\((?:(?:hidden|invisible|visible|shown)(?:| (?:from|to|from the|to the) authors?)|pc only|shown only to chairs|secret|private)(?:| until decision| and external reviewers)[.?!]?\)\z/', substr($s, $lparen))) {
            $s = rtrim(substr($s, 0, $lparen));
        }
        return $s;
    }

    /** @return string */
    function unparse_round_mask() {
        if ($this->round_mask) {
            $rs = [];
            foreach ($this->conf->round_list() as $i => $rname) {
                if ($this->round_mask & (1 << $i))
                    $rs[] = $i ? "round:{$rname}" : "round:unnamed";
            }
            natcasesort($rs);
            return join(" OR ", $rs);
        } else {
            return "";
        }
    }

    const UJ_EXPORT = 0;
    const UJ_TEMPLATE = 1;
    const UJ_STORAGE = 2;

    /** @param 0|1|2 $style
     * @return object */
    function export_json($style) {
        $j = (object) [];
        if ($style > 0) {
            $j->id = $this->short_id;
        } else {
            $j->uid = $this->uid();
        }
        $j->name = $this->name;
        $j->type = $this->type;
        if ($this->description) {
            $j->description = $this->description;
        }
        if ($this->order) {
            $j->order = $this->order;
        }
        $j->visibility = $this->unparse_visibility();
        if ($this->required) {
            $j->required = true;
        }
        $exists_if = $this->exists_if;
        if ($exists_if !== null && $style === self::UJ_STORAGE) {
            if (($term = $this->exists_term())) {
                list($this->round_mask, $other) = Review_SearchTerm::term_round_mask($term);
                $exists_if = $other ? $exists_if : null;
            } else {
                $exists_if = null;
            }
        }
        if ($exists_if !== null) {
            $j->exists_if = $exists_if;
        } else if ($this->round_mask !== 0 && $style !== self::UJ_STORAGE) {
            $j->exists_if = $this->unparse_round_mask();
        }
        if ($this->round_mask !== 0 && $style === self::UJ_STORAGE) {
            $j->round_mask = $this->round_mask;
        }
        return $j;
    }

    /** @return Rf_Setting */
    function export_setting() {
        $rfs = new Rf_Setting;
        $rfs->id = $this->short_id;
        $rfs->type = $this->type;
        $rfs->name = $this->name;
        $rfs->order = $this->order;
        $rfs->description = $this->description;
        $rfs->visibility = $this->unparse_visibility();
        $rfs->required = $this->required;
        $rm = $this->round_mask;
        if ($this->exists_if || ($rm !== 0 && ($rm & ($rm - 1)) !== 0)) {
            $rfs->presence = "custom";
            $rfs->exists_if = $this->exists_if ?? $this->unparse_round_mask();
        } else if ($rm !== 0) {
            $rfs->presence = $rfs->exists_if = $this->unparse_round_mask();
        } else {
            $rfs->presence = $rfs->exists_if = "all";
        }
        return $rfs;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->export_json(self::UJ_EXPORT);
    }

    /** @return string */
    function unparse_visibility() {
        return self::$view_score_rmap[$this->view_score] ?? (string) $this->view_score;
    }


    /** @return ?string */
    function exists_condition() {
        if ($this->exists_if !== null) {
            return $this->exists_if;
        } else if ($this->round_mask !== 0) {
            return $this->unparse_round_mask();
        } else {
            return null;
        }
    }

    /** @return ?SearchTerm */
    private function exists_term() {
        $st = (new PaperSearch($this->conf->root_user(), $this->exists_if ?? ""))->term();
        return $st instanceof True_SearchTerm ? null : $st;
    }

    /** @return bool */
    function test_exists(ReviewInfo $rrow) {
        if ($this->_need_exists_search) {
            $this->_exists_search = $this->exists_term();
            $this->_need_exists_search = false;
        }
        return (!$this->round_mask || ($this->round_mask & (1 << $rrow->reviewRound)) !== 0)
            && (!$this->_exists_search || $this->_exists_search->test($rrow->prow, $rrow));
    }

    /** @param ?int|?string $fval
     * @return bool */
    abstract function value_empty($fval);

    /** @param ?int|?string $fval
     * @return bool */
    function value_explicit_empty($fval) {
        return false;
    }

    /** @param ?int|?string $fval
     * @return ?int|?string */
    function value_clean($fval) {
        return $fval;
    }

    /** @return bool */
    function include_word_count() {
        return false;
    }

    /** @return string */
    function search_keyword() {
        if ($this->_search_keyword === null) {
            $this->conf->abbrev_matcher();
            assert($this->_search_keyword !== null);
        }
        return $this->_search_keyword;
    }

    /** @return ?string */
    function abbreviation1() {
        $e = new AbbreviationEntry($this->name, $this, Conf::MFLAG_REVIEW);
        return $this->conf->abbrev_matcher()->find_entry_keyword($e, AbbreviationMatcher::KW_UNDERSCORE);
    }

    /** @return string */
    function web_abbreviation() {
        return '<span class="need-tooltip" data-tooltip="' . $this->name_html
            . '" data-tooltip-anchor="s">' . htmlspecialchars($this->search_keyword()) . "</span>";
    }

    /** @return string */
    function uid() {
        return $this->search_keyword();
    }

    /** @return bool */
    function want_column_display() {
        return false;
    }

    /** @param ?int|?float|?string $fval
     * @return mixed */
    abstract function value_unparse_json($fval);

    /** @param int|float|string $fval
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    abstract function value_unparse($fval, $flags = 0, $real_format = null);

    /** @param string $s
     * @return int|string|false */
    abstract function parse_string($s);

    /** @param mixed $j
     * @return int|string|false */
    abstract function parse_json($j);

    /** @param ?string $id
     * @param string $label_for
     * @param ?ReviewValues $rvalues
     * @param ?array{name_html?:string,label_class?:string} $args */
    protected function print_web_edit_open($id, $label_for, $rvalues, $args = null) {
        echo '<div class="rf rfe" data-rf="', $this->uid(),
            '"><h3 class="',
            $rvalues ? $rvalues->control_class($this->short_id, "rfehead") : "rfehead";
        if ($id !== null) {
            echo '" id="', $id;
        }
        echo '"><label class="', $args["label_class"] ?? "revfn";
        if ($this->required) {
            echo ' field-required';
        }
        echo '" for="', $label_for, '">', $args["name_html"] ?? $this->name_html, '</label>';
        if ($this->view_score < VIEWSCORE_AUTHOR) {
            echo '<div class="field-visibility">';
            if ($this->view_score < VIEWSCORE_REVIEWERONLY) {
                echo '(secret)';
            } else if ($this->view_score < VIEWSCORE_PC) {
                echo '(shown only to chairs)';
            } else if ($this->view_score < VIEWSCORE_REVIEWER) {
                echo '(hidden from authors and external reviewers)';
            } else if ($this->view_score < VIEWSCORE_AUTHORDEC) {
                echo '(hidden from authors)';
            } else {
                echo '(hidden from authors until decision)';
            }
            echo '</div>';
        }
        echo '</h3>';
        if ($rvalues) {
            echo $rvalues->feedback_html_at($this->short_id);
        }
        if ($this->description) {
            echo '<div class="field-d">', $this->description, "</div>";
        }
    }

    /** @param int|string $fval
     * @param ?string $reqstr
     * @param ?ReviewValues $rvalues
     * @param array{format:?TextFormat} $args */
    abstract function print_web_edit($fval, $reqstr, $rvalues, $args);

    /** @param list<string> &$t
     * @param array{flowed:bool} $args */
    protected function unparse_text_field_header(&$t, $args) {
        $t[] = "\n";
        if (strlen($this->name) > 75) {
            $t[] = prefix_word_wrap("", $this->name, 0, 75, $args["flowed"]);
            $t[] = "\n";
            $t[] = str_repeat("-", 75);
            $t[] = "\n";
        } else {
            $t[] = "{$this->name}\n";
            $t[] = str_repeat("-", UnicodeHelper::utf8_glyphlen($this->name));
            $t[] = "\n";
        }
    }

    /** @param list<string> &$t
     * @param int|string $fval
     * @param array{flowed:bool} $args */
    abstract function unparse_text_field(&$t, $fval, $args);

    /** @param int|string $fval
     * @return string */
    function unparse_text_field_content($fval) {
        $t = [];
        $this->unparse_text_field($t, $fval, ["flowed" => false]);
        return join("", $t);
    }

    /** @param list<string> &$t */
    protected function unparse_offline_field_header(&$t, $args) {
        $t[] = prefix_word_wrap("==*== ", $this->name, "==*==    ");
        if ($this->view_score < VIEWSCORE_REVIEWERONLY) {
            $t[] = "==-== (secret field)\n";
        } else if ($this->view_score < VIEWSCORE_PC) {
            $t[] = "==-== (shown only to chairs)\n";
        } else if ($this->view_score < VIEWSCORE_REVIEWER) {
            $t[] = "==-== (hidden from authors and external reviewers)\n";
        } else if ($this->view_score < VIEWSCORE_AUTHORDEC) {
            $t[] = "==-== (hidden from authors)\n";
        } else if ($this->view_score < VIEWSCORE_AUTHOR) {
            $t[] = "==-== (hidden from authors until decision)\n";
        }
        if (($args["include_presence"] ?? false)
            && ($this->exists_if || $this->round_mask)) {
            $explanation = $this->exists_if ?? $this->unparse_round_mask();
            if (preg_match('/\Around:[a-zA-Z][-_a-zA-Z0-9]*\z/', $explanation)) {
                $t[] = "==-== (present on " . substr($explanation, 6) . " reviews)\n";
            } else {
                $t[] = "==-== (present on reviews matching `{$explanation}`)\n";
            }
        }
        if ($this->description) {
            $d = cleannl($this->description);
            if (strpbrk($d, "&<") !== false) {
                $d = Text::html_to_text($d);
            }
            $t[] = prefix_word_wrap("==-==    ", trim($d), "==-==    ");
        }
    }

    /** @param list<string> &$t
     * @param ?int|?string $fval
     * @param array{format:?TextFormat,include_presence:bool} $args */
    abstract function unparse_offline(&$t, $fval, $args);
}


class Score_ReviewField extends ReviewField {
    /** @var list<string> */
    private $values = [];
    /** @var list<int|string> */
    private $symbols = []; // NB strings must by URL-safe and HTML-safe
    /** @var ?list<int> */
    private $ids;
    /** @var int */
    private $option_letter = 0;
    /** @var bool
     * @readonly */
    public $flip = false;
    /** @var string
     * @readonly */
    public $scheme = "sv";
    /** @var ?string */
    private $_typical_score;

    // color schemes; NB keys must be URL-safe
    /** @var array<string,list>
     * @readonly */
    static public $scheme_info = [
        "sv" => [0, 9, "svr"], "svr" => [1, 9, "sv"],
        "bupu" => [0, 9, "pubu"], "pubu" => [1, 9, "bupu"],
        "rdpk" => [1, 9, "pkrd"], "pkrd" => [0, 9, "rdpk"],
        "viridisr" => [1, 9, "viridis"], "viridis" => [0, 9, "viridisr"],
        "orbu" => [0, 9, "buor"], "buor" => [1, 9, "orbu"],
        "turbo" => [0, 9, "turbor"], "turbor" => [1, 9, "turbo"],
        "catx" => [2, 10, null], "none" => [2, 1, null]
    ];

    /** @var array<string,string>
     * @readonly */
    static public $scheme_alias = [
        "publ" => "pubu", "blpu" => "bupu"
    ];

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        assert($finfo->is_sfield);
        parent::__construct($conf, $finfo, $j);

        $this->values = $j->values ?? $j->options ?? [];
        $nvalues = count($this->values);
        $ol = $j->start ?? $j->option_letter ?? null;
        $this->option_letter = 0;
        $this->symbols = [];
        $this->flip = false;
        if (isset($j->symbols) && count($j->symbols) === $nvalues) {
            $this->symbols = $j->symbols;
        } else if ($ol && is_string($ol) && ctype_upper($ol) && strlen($ol) === 1) {
            $this->option_letter = ord($ol) + $nvalues;
            $this->flip = true;
            for ($i = 0; $i !== $nvalues; ++$i) {
                $this->symbols[] = chr($this->option_letter - $i - 1);
            }
        } else {
            for ($i = 0; $i !== $nvalues; ++$i) {
                $this->symbols[] = $i + 1;
            }
        }
        if (isset($j->ids) && count($j->ids) === $nvalues) {
            $this->ids = $j->ids;
        }
        if (($sch = $j->scheme ?? null) !== null) {
            if (isset(self::$scheme_info[$sch])) {
                $this->scheme = $sch;
            } else {
                $this->scheme = self::$scheme_alias[$sch] ?? null;
            }
        }
        if (!isset($j->required)) {
            if (isset($j->allow_empty) /* XXX backward compat */) {
                $this->required = !$j->allow_empty;
            } else {
                $this->required = true;
            }
        }
        $this->_typical_score = null;
    }

    /** @return int */
    function nvalues() {
        return count($this->values);
    }

    /** @return list<int|string> */
    function symbols() {
        return $this->symbols;
    }

    /** @return list<string> */
    function values() {
        return $this->values;
    }

    /** @return list<int|string> */
    function ordered_symbols() {
        return $this->flip ? array_reverse($this->symbols) : $this->symbols;
    }

    /** @return list<string> */
    function ordered_values() {
        return $this->flip ? array_reverse($this->values) : $this->values;
    }

    /** @return list<int> */
    function ids() {
        return $this->ids ?? range(1, count($this->values));
    }

    /** @return bool */
    function is_numeric() {
        return $this->option_letter === 0;
    }

    /** @return bool */
    function flip_relation() {
        return $this->option_letter !== 0
            && $this->flip === !$this->conf->opt("smartScoreCompare");
    }

    function export_json($style) {
        $j = parent::export_json($style);
        $j->values = $this->values;
        if (!empty($this->ids)
            && ($style !== self::UJ_STORAGE
                || $this->ids !== range(1, count($this->values)))) {
            $j->ids = $this->ids;
        }
        if ($this->option_letter !== 0) {
            $j->start = chr($this->option_letter - count($this->values));
        }
        if ($this->flip) {
            $j->flip = true;
        }
        if ($this->scheme !== "sv") {
            $j->scheme = $this->scheme;
        }
        $j->required = $this->required;
        return $j;
    }

    function export_setting() {
        $rfs = parent::export_setting();
        $n = count($this->values);
        $rfs->values = $this->values;
        $rfs->ids = $this->ids();
        if ($this->option_letter !== 0) {
            $rfs->start = chr($this->option_letter - $n);
        } else {
            $rfs->start = 1;
        }
        $rfs->flip = $this->flip;
        $rfs->scheme = $this->scheme;

        $rfs->xvalues = [];
        foreach ($this->ordered_symbols() as $i => $symbol) {
            $rfs->xvalues[] = $rfv = new RfValue_Setting;
            $idx = $this->flip ? $n - $i - 1 : $i;
            $rfv->id = $rfs->ids[$idx];
            $rfv->order = $i + 1;
            $rfv->symbol = $symbol;
            $rfv->name = $this->values[$idx];
            $rfv->old_value = $idx + 1;
        }
        return $rfs;
    }

    function want_column_display() {
        return true;
    }

    function value_empty($fval) {
        if (!is_int($fval ?? 0)) {
            error_log("not int: " . debug_string_backtrace());
        }
        return ($fval ?? 0) <= 0;
    }

    function value_explicit_empty($fval) {
        return $fval === -1;
    }

    /** @return ?string */
    function typical_score() {
        if ($this->_typical_score === null) {
            $n = count($this->values);
            if ($n === 1) {
                $this->_typical_score = $this->value_unparse(1);
            } else if ($this->option_letter !== 0) {
                $this->_typical_score = $this->value_unparse(1 + (int) (($n - 1) / 2));
            } else {
                $this->_typical_score = $this->value_unparse(2);
            }
        }
        return $this->_typical_score;
    }

    /** @return ?array{string,string} */
    function typical_score_range() {
        $n = count($this->values);
        if ($n < 2) {
            return null;
        } else if ($this->flip) {
            return [$this->value_unparse($n - ($n > 2 ? 1 : 0)), $this->value_unparse($n - 1 - ($n > 2 ? 1 : 0) - ($n > 3 ? 1 : 0))];
        } else {
            return [$this->value_unparse(1 + ($n > 2 ? 1 : 0)), $this->value_unparse(2 + ($n > 2 ? 1 : 0) + ($n > 3 ? 1 : 0))];
        }
    }

    /** @return ?array{string,string} */
    function full_score_range() {
        $f = $this->flip ? count($this->values) : 1;
        $l = $this->flip ? 1 : count($this->values);
        return [$this->value_unparse($f), $this->value_unparse($l)];
    }

    /** @param int $option_letter
     * @param int|float $fval
     * @return string */
    static function unparse_letter($option_letter, $fval) {
        // see also `value_unparse_json`
        $ivalue = (int) $fval;
        $ch = $option_letter - $ivalue;
        if ($fval < $ivalue + 0.25) {
            return chr($ch);
        } else if ($fval < $ivalue + 0.75) {
            return chr($ch - 1) . chr($ch);
        } else {
            return chr($ch - 1);
        }
    }

    /** @param int|float $fval
     * @return string */
    function value_class($fval) {
        if ($fval < 0.8) {
            return "sv";
        }
        list($schfl, $nsch, $schrev) = self::$scheme_info[$this->scheme];
        $sclass = ($schfl & 1) !== 0 ? $schrev : $this->scheme;
        $schflip = $this->flip !== (($schfl & 1) !== 0);
        $n = count($this->values);
        if ($n <= 1) {
            $x = $schflip ? 1 : $nsch;
        } else {
            if ($schflip) {
                $fval = $n + 1 - $fval;
            }
            if (($schfl & 2) !== 0) {
                $x = (int) round($fval - 1) % $nsch + 1;
            } else {
                $x = (int) round(($fval - 1) * ($nsch - 1) / ($n - 1)) + 1;
            }
        }
        if ($sclass === "sv") {
            return "sv sv{$x}";
        } else {
            return "sv sv-{$sclass}{$x}";
        }
    }

    function value_unparse_json($fval) {
        assert($fval === null || is_int($fval));
        if (($fval ?? 0) === 0) {
            return null;
        } else if ($fval < 0) {
            return false;
        } else {
            return $this->symbols[$fval - 1];
        }
    }

    /** @param int|float|string $fval
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    function value_unparse($fval, $flags = 0, $real_format = null) {
        if (is_string($fval)) {
            error_log("bad value_unparse: " . debug_string_backtrace());
        }
        if ($fval <= 0.8) {
            return null;
        }
        if ($this->option_letter !== 0) {
            $text = self::unparse_letter($this->option_letter, $fval);
        } else if ($real_format) {
            $text = sprintf($real_format, $fval);
        } else {
            $text = (string) $fval;
        }
        if ($flags === self::VALUE_SC) {
            $vc = $this->value_class($fval);
            $text = "<span class=\"{$vc}\">{$text}</span>";
        }
        return $text;
    }

    /** @param int|float $fval */
    function unparse_average($fval) {
        return (string) $this->value_unparse($fval, 0, "%.2f");
    }

    /** @param ScoreInfo $sci
     * @param 1|2 $style
     * @return string */
    function unparse_graph($sci, $style) {
        $n = count($this->values);

        $avgtext = $this->unparse_average($sci->mean());
        if ($sci->count() > 1 && ($stddev = $sci->stddev_s())) {
            $avgtext .= sprintf(" ± %.2f", $stddev);
        }

        $counts = $sci->counts($n);
        $args = "v=" . join(",", $counts);
        if ($sci->my_score() > 0 && $counts[$sci->my_score() - 1] > 0) {
            $args .= "&amp;h=" . $sci->my_score();
        }
        if ($this->option_letter !== 0 || $this->flip) {
            $args .= "&amp;lo=" . $this->symbols[$this->flip ? $n - 1 : 0]
                . "&amp;hi=" . $this->symbols[$this->flip ? 0 : $n - 1];
        }
        if ($this->flip) {
            $args .= "&amp;flip=1";
        }
        if ($this->scheme !== "sv") {
            $args .= "&amp;sv=" . $this->scheme;
        }

        if ($style === 1) {
            $width = 5 * $n + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:{$width}px;height:{$height}px\" data-scorechart=\"{$args}&amp;s=1\" title=\"{$avgtext}\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"{$args}&amp;s=2\" title=\"{$avgtext}\"></div><br>";
            $step = $this->flip ? -1 : 1;
            $sep = "";
            for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
                $vc = $this->value_class($i + 1);
                $retstr .= "{$sep}<span class=\"{$vc}\">{$counts[$i]}</span>";
                $sep = " ";
            }
            $retstr .= "<br><span class=\"sc_sum\">{$avgtext}</span></div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    /** @param string $s
     * @return string */
    static function clean_string($s) {
        $s = trim((string) $s);
        if ($s === "" || $s[0] === "(") {
            return "";
        }
        $dot = 0;
        while (($dot = strpos($s, ".", $dot)) !== false
               && $dot + 1 < strlen($s)
               && !ctype_space($s[$dot + 1])
               && $s[$dot + 1] !== ".") {
            ++$dot;
        }
        if ($dot !== false) {
            $s = trim(substr($s, 0, $dot));
        }
        if ($s === "0" || $s === "-" || $s === "–" || $s === "—") {
            return "";
        } else if (strlen($s) > 2
                   && (strcasecmp($s, "no entry") === 0
                       || strcasecmp($s, "none") === 0
                       || strcasecmp($s, "n/a") === 0
                       || substr_compare($s, "none ", 0, 5, true) === 0)) {
            return "none";
        }
        return $s;
    }

    function parse_string($text) {
        $text = self::clean_string($text);
        if ($text === "") {
            return 0;
        } else if ($text === "none") {
            return -1;
        }
        foreach ($this->symbols as $i => $sym) {
            if (strcasecmp($text, $sym) === 0)
                return $i + 1;
        }
        return false;
    }

    function parse_json($j) {
        if ($j === null || $j === 0) {
            return 0;
        } else if ($j === false) {
            return -1;
        } else if (($i = array_search($j, $this->symbols, true)) !== false) {
            return $i + 1;
        } else {
            return false;
        }
    }

    /** @param int $fval
     * @return string */
    private function value_unparse_web($fval) {
        if ($fval === -1) {
            return "none";
        } else if ($fval > 0 && isset($this->symbols[$fval - 1])) {
            return (string) $this->symbols[$fval - 1];
        } else {
            return "0";
        }
    }

    /** @param int $choiceval
     * @param int $fval
     * @param int $reqval */
    private function print_choice($choiceval, $fval, $reqval) {
        $symstr = $this->value_unparse_web($choiceval);
        echo '<label class="checki svline"><span class="checkc">',
            Ht::radio($this->short_id, $symstr, $choiceval === $reqval, [
                "id" => "{$this->short_id}_{$symstr}",
                "data-default-checked" => $choiceval === $fval
            ]), '</span>';
        if ($choiceval > 0) {
            $vc = $this->value_class($choiceval);
            echo '<strong class="rev_num ', $vc, '">', $symstr;
            if ($this->values[$choiceval - 1] !== "") {
                echo '.</strong> ', htmlspecialchars($this->values[$choiceval - 1]);
            } else {
                echo '</strong>';
            }
        } else {
            echo 'None of the above';
        }
        echo '</label>';
    }

    private function print_web_edit_radio($fval, $reqval, $rvalues) {
        $n = count($this->values);
        $forval = $fval;
        if (($fval ?? 0) === 0) {
            $forval = $this->flip ? $n - 1 : 0;
        }
        $this->print_web_edit_open($this->short_id, "{$this->short_id}_" . $this->value_unparse_web($forval), $rvalues);
        echo '<div class="revev">';
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $this->print_choice($i + 1, $fval, $reqval);
        }
        if (!$this->required) {
            $this->print_choice(-1, $fval, $reqval);
        }
        echo '</div></div>';
    }

    private function print_web_edit_dropdown($fval, $reqval, $rvalues) {
        $n = count($this->values);
        $this->print_web_edit_open($this->short_id, null, $rvalues);
        echo '<div class="revev">';
        $opt = [];
        if (($fval ?? 0) === 0) {
            $opt[0] = "(Choose one)";
        }
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $sym = $this->symbols[$i];
            $val = $this->values[$i];
            $opt[$sym] = $val !== "" ? "{$sym}. {$val}" : $sym;
        }
        if (!$this->required) {
            $opt["none"] = "N/A";
        }
        echo Ht::select($this->short_id, $opt, $this->value_unparse_web($reqval), [
            "data-default-value" => $this->value_unparse_web($fval)
        ]);
        echo '</div></div>';
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $reqval = $reqstr === null ? $fval : $this->parse_string($reqstr);
        if ($this->type === "dropdown") {
            $this->print_web_edit_dropdown($fval, $reqval, $rvalues);
        } else {
            $this->print_web_edit_radio($fval, $reqval, $rvalues);
        }
    }

    function unparse_text_field(&$t, $fval, $args) {
        if ($fval > 0 && ($sym = $this->symbols[$fval - 1] ?? null) !== null) {
            $this->unparse_text_field_header($t, $args);
            if ($this->values[$fval - 1] !== "") {
                $t[] = prefix_word_wrap("{$sym}. ", $this->values[$fval - 1], strlen($sym) + 2, null, $args["flowed"]);
            } else {
                $t[] = "{$sym}\n";
            }
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $this->unparse_offline_field_header($t, $args);
        $t[] = "==-== Choices:\n";
        $n = count($this->values);
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            if ($this->values[$i] !== "") {
                $y = "==-==    {$this->symbols[$i]}. ";
                /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                $t[] = prefix_word_wrap($y, $this->values[$i], str_pad("==-==", strlen($y)));
            } else {
                $t[] = "==-==   {$this->symbols[$i]}\n";
            }
        }
        if (!$this->required) {
            $t[] = "==-==    None of the above\n==-== Enter your choice:\n";
        } else if ($this->option_letter !== 0) {
            $t[] = "==-== Enter the letter of your choice:\n";
        } else {
            $t[] = "==-== Enter the number of your choice:\n";
        }
        $t[] = "\n";
        if (($fval ?? 0) > 0 && $fval <= count($this->symbols)) {
            $i = $fval - 1;
            if ($this->values[$i] !== "") {
                $t[] = "{$this->symbols[$i]}. {$this->values[$i]}\n";
            } else {
                $t[] = "{$this->symbols[$i]}\n";
            }
        } else if ($this->required || ($fval ?? 0) === 0) {
            $t[] = "(Your choice here)\n";
        } else {
            $t[] = "None of the above\n";
        }
    }
}

class Text_ReviewField extends ReviewField {
    /** @var int */
    public $display_space;

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        assert(!$finfo->is_sfield);
        parent::__construct($conf, $finfo, $j);

        $this->display_space = max($this->display_space ?? 0, 3);
    }

    function export_json($style) {
        $j = parent::export_json($style);
        if ($this->display_space > 3) {
            $j->display_space = $this->display_space;
        }
        return $j;
    }

    function value_empty($fval) {
        return $fval === null || $fval === "";
    }

    /** @return bool */
    function include_word_count() {
        return $this->order && $this->view_score >= VIEWSCORE_AUTHORDEC;
    }

    function value_unparse_json($fval) {
        return $fval;
    }

    /** @param int|float|string $fval
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    function value_unparse($fval, $flags = 0, $real_format = null) {
        if ($flags === self::VALUE_TRIM) {
            $fval = rtrim($fval ?? "");
        }
        return $fval;
    }

    function parse_string($text) {
        $text = rtrim($text);
        if ($text !== "") {
            $text .= "\n";
        }
        return $text;
    }

    function parse_json($j) {
        if ($j === null) {
            return null;
        } else if (is_string($j)) {
            return rtrim($j);
        } else {
            return false;
        }
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $this->print_web_edit_open(null, $this->short_id, $rvalues);
        echo '<div class="revev">';
        if (($fi = $args["format"])) {
            echo $fi->description_preview_html();
        }
        $opt = ["class" => "w-text need-autogrow need-suggest suggest-emoji", "rows" => $this->display_space, "cols" => 60, "spellcheck" => true, "id" => $this->short_id];
        if ($reqstr !== null && $fval !== $reqstr) {
            $opt["data-default-value"] = (string) $fval;
        }
        echo Ht::textarea($this->short_id, $reqstr ?? $fval ?? "", $opt), '</div></div>';
    }

    function unparse_text_field(&$t, $fval, $args) {
        if ($fval !== "") {
            $this->unparse_text_field_header($t, $args);
            $t[] = rtrim($fval);
            $t[] = "\n";
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $this->unparse_offline_field_header($t, $args);
        if (($fi = $args["format"])
            && ($desc = $fi->description_text()) !== "") {
            $t[] = prefix_word_wrap("==-== ", $desc, "==-== ");
        }
        $t[] = "\n";
        $t[] = preg_replace('/^(?===[-+*]==)/m', '\\', rtrim($fval ?? ""));
        $t[] = "\n";
    }
}
