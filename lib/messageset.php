<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MessageItem implements JsonSerializable {
    /** @var ?string */
    public $field;
    /** @var string */
    public $message;
    /** @var int */
    public $status;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;
    /** @var ?string */
    public $context;
    /** @var ?string */
    public $landmark;

    /** @param ?string $field
     * @param string $message
     * @param int $status */
    function __construct($field, $message, $status) {
        $this->field = $field;
        $this->message = $message;
        $this->status = $status;
    }

    /** @param int $format
     * @return string */
    function message_as($format) {
        return Ftext::unparse_as($this->message, $format);
    }

    /** @param ?string $field
     * @return MessageItem */
    function with_field($field) {
        $field = $field === "" ? null : $field;
        if ($this->field !== $field) {
            $mi = clone $this;
            $mi->field = $field;
            return $mi;
        } else {
            return $this;
        }
    }

    /** @param int $status
     * @return MessageItem */
    function with_status($status) {
        if ($this->status !== $status) {
            $mi = clone $this;
            $mi->status = $status;
            return $mi;
        } else {
            return $this;
        }
    }

    /** @param string $text
     * @return MessageItem */
    function with_prefix($text) {
        if ($this->message !== "" && $text !== "") {
            $mi = clone $this;
            list($fmt, $s) = Ftext::parse($this->message);
            if ($fmt !== null) {
                $mi->message = "<{$fmt}>{$text}{$s}";
            } else {
                $mi->message = "{$text}{$s}";
            }
            return $mi;
        } else {
            return $this;
        }
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [];
        if ($this->field !== null) {
            $x["field"] = $this->field;
        }
        if ($this->message !== "") {
            $x["message"] = $this->message;
        }
        $x["status"] = $this->status;
        if ($this->context !== null && $this->pos1 !== null) {
            $x["context"] = Ht::make_mark_substring($this->context, $this->pos1, $this->pos2);
        }
        return (object) $x;
    }

    /** @param ?string $message
     * @return array{ok:false,message_list:list<MessageItem>} */
    static function make_error_json($message) {
        return ["ok" => false, "message_list" => [new MessageItem(null, $message ?? "", 2)]];
    }
}

class MessageSet {
    /** @var list<MessageItem> */
    private $msgs = [];
    /** @var array<string,int> */
    private $errf = [];
    /** @var int */
    private $problem_status = 0;
    /** @var ?array<string,int> */
    private $pstatus_at;
    /** @var int */
    private $_ms_flags = 0;

    const IGNORE_MSGS = 1;
    const IGNORE_DUPS = 2;
    const HAS_INTRO = 4;
    const WANT_FTEXT = 8;
    const DEFAULT_FTEXT_HTML = 16;
    const DEFAULT_FTEXT_TEXT = 32;
    const ITEMS_ONLY = 64;

    const INFORM = -5;
    const WARNING_NOTE = -4;
    const SUCCESS = -3;
    const URGENT_NOTE = -2;
    const MARKED_NOTE = -1;
    const PLAIN = 0;
    const WARNING = 1;
    const ERROR = 2;
    const ESTOP = 3;

    /** @deprecated */
    const INFO = 0;
    /** @deprecated */
    const NOTE = -1;

    function __construct() {
    }

    function clear_messages() {
        $this->errf = $this->msgs = [];
        $this->problem_status = 0;
    }

    function clear() {
        $this->clear_messages();
    }

    /** @param int $clearf
     * @param int $wantf */
    private function change_ms_flags($clearf, $wantf) {
        $this->_ms_flags = ($this->_ms_flags & ~$clearf) | $wantf;
    }
    /** @param bool $x
     * @return bool */
    function swap_ignore_messages($x) {
        $oim = ($this->_ms_flags & self::IGNORE_MSGS) !== 0;
        $this->change_ms_flags(self::IGNORE_MSGS, $x ? self::IGNORE_MSGS : 0);
        return $oim;
    }
    /** @param bool $x
     * @return $this */
    function set_ignore_duplicates($x) {
        $this->change_ms_flags(self::IGNORE_DUPS, $x ? self::IGNORE_DUPS : 0);
        return $this;
    }
    /** @param bool $x
     * @param ?int $default_format
     * @return $this */
    function set_want_ftext($x, $default_format = null) {
        $this->change_ms_flags(self::WANT_FTEXT, $x ? self::WANT_FTEXT : 0);
        if ($x && $default_format !== null) {
            assert($default_format === 0 || $default_format === 5);
            $this->change_ms_flags(self::DEFAULT_FTEXT_TEXT | self::DEFAULT_FTEXT_HTML,
                                   $default_format === 0 ? self::DEFAULT_FTEXT_TEXT : self::DEFAULT_FTEXT_HTML);
        }
        return $this;
    }
    /** @param string $field
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status */
    function set_status_for_problem_at($field, $status) {
        $this->pstatus_at[$field] = $status;
    }
    /** @return void */
    function clear_status_for_problem_at() {
        $this->pstatus_at = [];
    }

    /** @param MessageItem $mi
     * @return int|false */
    private function message_index($mi) {
        if ($mi->field === null
            ? $this->problem_status >= $mi->status
            : ($this->errf[$mi->field] ?? -5) >= $mi->status) {
            foreach ($this->msgs as $i => $m) {
                if ($m->field === $mi->field
                    && $m->message === $mi->message
                    && $m->status === $mi->status)
                    return $i;
            }
        }
        return false;
    }

    /** @param MessageItem $mi
     * @return MessageItem */
    function append_item($mi) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            if ($mi->field !== null) {
                $old_status = $this->errf[$mi->field] ?? -5;
                $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
            } else {
                $old_status = $this->problem_status;
            }
            $this->problem_status = max($this->problem_status, $mi->status);
            if ($mi->message !== ""
                && (!($this->_ms_flags & self::IGNORE_DUPS)
                    || $old_status < $mi->status
                    || $this->message_index($mi) === false)) {
                $this->msgs[] = $mi;
                if (($this->_ms_flags & self::WANT_FTEXT)
                    && !Ftext::is_ftext($mi->message)) {
                    error_log("not ftext: " . debug_string_backtrace());
                    if ($this->_ms_flags & self::DEFAULT_FTEXT_TEXT) {
                        $mi->message = "<0>{$mi->message}";
                    } else if ($this->_ms_flags & self::DEFAULT_FTEXT_HTML) {
                        $mi->message = "<5>{$mi->message}";
                    }
                }
            }
        }
        return $mi;
    }

    /** @deprecated */
    function add($mi) {
        $this->append_item($mi);
    }

    /** @param MessageSet $ms */
    function append_set($ms) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            foreach ($ms->msgs as $mi) {
                $this->append_item($mi);
            }
            foreach ($ms->errf as $field => $status) {
                $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
            }
        }
    }

    /** @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return $this */
    function set_intro_msg($msg, $status) {
        $mi = new MessageItem(null, $msg, $status);
        if ($this->_ms_flags & self::HAS_INTRO) {
            $this->msgs[0] = $mi;
        } else {
            array_unshift($this->msgs, $mi);
            $this->_ms_flags |= self::HAS_INTRO;
        }
        return $this;
    }

    /** @param ?string $field
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        if ($field === false || $field === "") { /* XXX false backward compat */
            $field = null;
        }
        if ($msg === null || $msg === false) { /* XXX false backward compat */
            $msg = "";
        }
        return $this->append_item(new MessageItem($field, $msg, $status));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function estop_at($field, $msg) {
        return $this->msg_at($field, $msg, self::ESTOP);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function error_at($field, $msg) {
        return $this->msg_at($field, $msg, self::ERROR);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function warning_at($field, $msg) {
        return $this->msg_at($field, $msg, self::WARNING);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @param null|0|1|2|3 $default_status
     * @return MessageItem */
    function problem_at($field, $msg, $default_status = 1) {
        $status = $this->pstatus_at[$field] ?? $default_status ?? 1;
        return $this->msg_at($field, $msg, $status);
    }

    /** @param MessageItem $mi
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status */
    function change_item_status($mi, $status) {
        if ($mi->status <= 0 || $status > $mi->status) {
            $mi->status = $status;
            if (!($this->_ms_flags & self::IGNORE_MSGS)) {
                if ($mi->field !== null) {
                    $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
                }
                $this->problem_status = max($this->problem_status, $mi->status);
            }
        }
    }


    /** @return bool
     * @deprecated */
    function has_messages() {
        return !empty($this->msgs);
    }
    /** @return bool */
    function has_message() {
        return !empty($this->msgs);
    }
    /** @return ?MessageItem */
    function back_message() {
        return empty($this->msgs) ? null : $this->msgs[count($this->msgs) - 1];
    }
    /** @return int */
    function message_count() {
        return count($this->msgs);
    }
    /** @return int */
    function problem_status() {
        return $this->problem_status;
    }
    /** @return bool */
    function has_problem() {
        return $this->problem_status >= self::WARNING;
    }
    /** @return bool */
    function has_error() {
        return $this->problem_status >= self::ERROR;
    }
    /** @return bool */
    function has_warning() {
        if ($this->problem_status >= self::WARNING) {
            foreach ($this->msgs as $mi) {
                if ($mi->status === self::WARNING)
                    return true;
            }
        }
        return false;
    }
    /** @param int $msgcount
     * @return bool */
    function has_error_since($msgcount) {
        for (; isset($this->msgs[$msgcount]); ++$msgcount) {
            if ($this->msgs[$msgcount]->status >= self::ERROR)
                return true;
        }
        return false;
    }

    /** @param string $field
     * @return int */
    function problem_status_at($field) {
        if ($this->problem_status >= self::WARNING) {
            return $this->errf[$field] ?? 0;
        } else {
            return 0;
        }
    }
    /** @param string $field
     * @return bool */
    function has_message_at($field) {
        if (!empty($this->errf)) {
            if (isset($this->errf[$field])) {
                foreach ($this->msgs as $mi) {
                    if ($mi->field === $field)
                        return true;
                }
            }
        }
        return false;
    }
    /** @deprecated */
    function has_messages_at($field) {
        return $this->has_message_at($field);
    }
    /** @param string $field
     * @return bool */
    function has_problem_at($field) {
        return $this->problem_status_at($field) >= self::WARNING;
    }
    /** @param string $field
     * @return bool */
    function has_error_at($field) {
        return $this->problem_status_at($field) >= self::ERROR;
    }

    /** @param list<string> $fields
     * @return int */
    function max_problem_status_at($fields) {
        $ps = 0;
        if ($this->problem_status > $ps) {
            foreach ($fields as $f) {
                $ps = max($ps, $this->errf[$f] ?? 0);
            }
        }
        return $ps;
    }

    /** @param int $status
     * @param string $rest
     * @return string */
    static function status_class($status, $rest = "", $prefix = "has-") {
        if ($status >= self::ERROR) {
            $sclass = "error";
        } else if ($status === self::WARNING) {
            $sclass = "warning";
        } else if ($status === self::SUCCESS) {
            $sclass = "success";
        } else if ($status === self::MARKED_NOTE) {
            $sclass = "note";
        } else if ($status === self::URGENT_NOTE) {
            $sclass = "urgent-note";
        } else if ($status === self::WARNING_NOTE) {
            $sclass = "warning-note";
        } else {
            $sclass = "";
        }
        if ($sclass !== "" && $rest !== "") {
            return "$rest $prefix$sclass";
        } else if ($sclass !== "") {
            return "$prefix$sclass";
        } else {
            return $rest;
        }
    }
    /** @param ?string|false $field
     * @param string $rest
     * @param string $prefix
     * @return string */
    function control_class($field, $rest = "", $prefix = "has-") {
        return self::status_class($field ? $this->errf[$field] ?? 0 : 0, $rest, $prefix);
    }

    /** @param iterable<MessageItem> $ms
     * @return list<string> */
    static private function list_texts($ms) {
        $t = [];
        foreach ($ms as $mi) {
            $t[] = $mi->message;
        }
        return $t;
    }
    /** @return array<string,int> */
    function message_field_map() {
        return $this->errf;
    }
    /** @return list<string> */
    function message_fields() {
        return array_keys($this->errf);
    }
    /** @param int $min_status
     * @return list<string> */
    private function min_status_fields($min_status) {
        $fs = [];
        if ($this->problem_status >= $min_status) {
            foreach ($this->errf as $f => $v) {
                if ($v >= $min_status) {
                    $fs[] = $f;
                }
            }
        }
        return $fs;
    }
    /** @param int $min_status
     * @return \Generator<MessageItem> */
    private function min_status_list($min_status) {
        if ($this->problem_status >= $min_status) {
            foreach ($this->msgs as $mi) {
                if ($mi->status >= $min_status) {
                    yield $mi;
                }
            }
        }
    }
    /** @return list<string> */
    function error_fields() {
        return $this->min_status_fields(self::ERROR);
    }
    /** @return list<string> */
    function problem_fields() {
        return $this->min_status_fields(self::WARNING);
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->msgs;
    }
    /** @return list<string> */
    function message_texts() {
        return self::list_texts($this->msgs);
    }
    /** @return \Generator<MessageItem> */
    function error_list() {
        return $this->min_status_list(self::ERROR);
    }
    /** @return list<string> */
    function error_texts() {
        return self::list_texts($this->error_list());
    }
    /** @return \Generator<MessageItem> */
    function problem_list() {
        return $this->min_status_list(self::WARNING);
    }
    /** @return list<string> */
    function problem_texts() {
        return self::list_texts($this->problem_list());
    }
    /** @param string $field
     * @return \Generator<MessageItem> */
    function message_list_at($field) {
        if (isset($this->errf[$field])) {
            foreach ($this->msgs as $mi) {
                if ($mi->field === $field) {
                    yield $mi;
                }
            }
        }
    }
    /** @param string $field
     * @return list<string> */
    function message_texts_at($field) {
        return self::list_texts($this->message_list_at($field));
    }


    /** @param iterable<MessageItem> $message_list
     * @param int $flags
     * @return string */
    static function feedback_html($message_list, $flags = 0) {
        $t = [];
        foreach ($message_list as $mi) {
            if ($mi->message !== "") {
                $s = $mi->message_as(5);
                if ($mi->landmark !== null && $mi->landmark !== "") {
                    $lm = htmlspecialchars($mi->landmark);
                    $s = "<span class=\"lineno\">{$lm}:</span> {$s}";
                }
                if ($mi->status !== self::INFORM || empty($t)) {
                    $k = self::status_class($mi->status, "is-diagnostic", "is-");
                    $t[] = "<li><div class=\"{$k}\">{$s}</div>";
                } else {
                    // overwrite last `</li>`
                    $t[count($t) - 1] = "<div class=\"msg-inform\">{$s}</div>";
                }
                if (($mi->pos1 || $mi->pos2) && $mi->context !== null) {
                    $t[] = "<div class=\"msg-context\">"
                        . Ht::mark_substring($mi->context, $mi->pos1, $mi->pos2, $mi->status)
                        . "</div>";
                }
                $t[] = "</li>";
            }
        }
        if (empty($t)) {
            return "";
        } else if ($flags & self::ITEMS_ONLY) {
            return join("", $t);
        } else {
            return "<ul class=\"feedback-list\">" . join("", $t) . "</ul>";
        }
    }

    /** @param string $field
     * @return string */
    function feedback_html_at($field) {
        return self::feedback_html($this->message_list_at($field));
    }

    /** @return string */
    function full_feedback_html() {
        return self::feedback_html($this->message_list());
    }

    /** @param iterable<MessageItem> $message_list
     * @return string */
    static function feedback_text($message_list) {
        $t = [];
        foreach ($message_list as $mi) {
            if ($mi->message !== "") {
                if (!empty($t) && $mi->status === self::INFORM) {
                    $t[] = "    ";
                }
                if ($mi->landmark !== null && $mi->landmark !== "") {
                    $t[] = "{$mi->landmark}: ";
                }
                $t[] = $mi->message_as(0);
                $t[] = "\n";
                if (($mi->pos1 || $mi->pos2) && $mi->context !== null) {
                    $t[] = Ht::mark_substring_text($mi->context, $mi->pos1, $mi->pos2, "    ");
                }
            }
        }
        return empty($t) ? "" : join("", $t);
    }

    /** @param string $field
     * @return string */
    function feedback_text_at($field) {
        return self::feedback_text($this->message_list_at($field));
    }

    /** @return string */
    function full_feedback_text() {
        return self::feedback_text($this->message_list());
    }
}
