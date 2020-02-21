<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GroupedExtensions {
    private $_jall = [];
    private $_potential_members = [];
    private $conf;
    private $user;
    public $root;
    private $_raw = [];
    private $_render_classes;
    private $_render_state;
    private $_render_stack;
    private $_annexes = [];
    static private $next_placeholder;

    function _add_json($fj) {
        if (!isset($fj->name)) {
            $fj->name = "__" . self::$next_placeholder . "__";
            ++self::$next_placeholder;
        }
        if (!isset($fj->group)) {
            if (($pos = strrpos($fj->name, "/")) !== false) {
                $fj->group = substr($fj->name, 0, $pos);
            } else {
                $fj->group = $fj->name;
            }
        }
        if (!isset($fj->anchorid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->anchorid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_jall[$fj->name][] = $fj;
        if ($fj->group === $fj->name) {
            assert(strpos($fj->group, "/") === false);
            $this->_potential_members[""][] = $fj->name;
        } else {
            $this->_potential_members[$fj->group][] = $fj->name;
        }
        return true;
    }
    function __construct(Contact $user, $args /* ... */) {
        $this->conf = $user->conf;
        $this->user = $user;
        self::$next_placeholder = 1;
        foreach (func_get_args() as $i => $arg) {
            if ($i > 0 && $arg)
                expand_json_includes_callback($arg, [$this, "_add_json"]);
        }
        $this->reset_context();
    }
    function get_raw($name) {
        if (!array_key_exists($name, $this->_raw)) {
            if (($xt = $this->conf->xt_search_name($this->_jall, $name, $this->user, null, true))
                && Conf::xt_enabled($xt)) {
                $this->_raw[$name] = $xt;
            } else {
                $this->_raw[$name] = null;
            }
        }
        return $this->_raw[$name];
    }
    function get($name) {
        $gj = $this->get_raw($name);
        for ($nalias = 0; $gj && isset($gj->alias) && $nalias < 5; ++$nalias) {
            $gj = $this->get_raw($gj->alias);
        }
        return $gj;
    }
    function canonical_group($name) {
        if (($gj = $this->get($name))) {
            $pos = strpos($gj->group, "/");
            return $pos === false ? $gj->group : substr($gj->group, 0, $pos);
        } else {
            return false;
        }
    }
    function members($name) {
        if (($gj = $this->get($name))) {
            $name = $gj->name;
        }
        $r = [];
        $alias = false;
        foreach (array_unique(get($this->_potential_members, $name, [])) as $subname) {
            if (($gj = $this->get_raw($subname))
                && $gj->group === ($name === "" ? $gj->name : $name)
                && $gj->name !== $name
                && (!isset($gj->alias) || isset($gj->position))
                && (!isset($gj->position) || $gj->position !== false)) {
                $r[] = $gj;
                $alias = $alias || isset($gj->alias);
            }
        }
        usort($r, "Conf::xt_position_compare");
        if ($alias) {
            $rr = [];
            foreach ($r as $gj) {
                $rr[] = isset($gj->alias) ? $this->get($gj->alias) : $gj;
            }
            return $rr;
        } else {
            return $r;
        }
    }
    function groups() {
        return $this->members("");
    }

    private function call_callback($cb, $gj) {
        $args = $this->_render_state[0];
        $args[] = $gj;
        if ($cb[0] === "*") {
            $colons = strpos($cb, ":");
            $klass = substr($cb, 1, $colons - 1);
            if (!isset($this->_render_classes[$klass])) {
                $this->_render_classes[$klass] = new $klass(...$args);
            }
            $cb = [$this->_render_classes[$klass], substr($cb, $colons + 2)];
        }
        return call_user_func_array($cb, $args);
    }

    function reset_context() {
        assert(empty($this->_render_stack));
        $this->_render_state = [null, null, "h3", null];
        $this->_render_classes = ["Conf" => $this->conf];
    }
    function set_context($options) {
        if (isset($options["args"])) {
            assert(is_array($options["args"]));
            $this->_render_state[0] = $options["args"];
        }
        if (isset($options["htag"])) {
            assert(is_string($options["htag"]));
            $this->_render_state[2] = $options["htag"];
        }
        if (isset($options["hclass"])) {
            assert(is_string($options["hclass"]));
            $this->_render_state[3] = $options["hclass"];
        }
    }

    function request($gj, Qrequest $qreq) {
        if (isset($gj->request_callback)) {
            Conf::xt_resolve_require($gj);
            if (isset($gj->allow_request_if)
                && !$this->conf->xt_check($gj->allow_request_if, $gj, $this->user, $qreq)) {
                if (!$qreq->post_ok() && $qreq->method() === "POST") {
                    $this->conf->msg($this->conf->_i("badpost"), 2);
                }
            } else {
                $this->call_callback($gj->request_callback, $gj);
            }
        }
    }

    function start_render($options = null) {
        $this->_render_stack[] = $this->_render_state;
        $this->_render_state = array_slice($this->_render_state, 0, 4);
        if (!empty($options)) {
            $this->set_context($options);
        }
    }
    function push_render_cleanup($name) {
        assert(!empty($this->_render_stack));
        $this->_render_state[] = $name;
    }
    function end_render() {
        assert(!empty($this->_render_stack));
        for ($i = count($this->_render_state) - 1; $i > 3; --$i) {
            if (($gj = $this->get($this->_render_state[$i]))) {
                $this->render($gj);
            }
        }
        $this->_render_state = array_pop($this->_render_stack);
    }
    function render($gj) {
        if (is_string($gj)) {
            if (!($gj = $this->get($gj))) {
                return null;
            }
        }
        if (isset($gj->title)
            && $gj->title !== $this->_render_state[1]
            && $gj->group !== $gj->name) {
            echo '<', $this->_render_state[2];
            if ($this->_render_state[3]) {
                echo ' class="', $this->_render_state[3], '"';
            }
            if (isset($gj->anchorid)) {
                echo ' id="', htmlspecialchars($gj->anchorid), '"';
            }
            echo '>', $gj->title, '</', $this->_render_state[2], ">\n";
            $this->_render_state[1] = $gj->title;
        }
        if (isset($gj->render_callback)) {
            Conf::xt_resolve_require($gj);
            return $this->call_callback($gj->render_callback, $gj);
        } else if (isset($gj->render_html)) {
            echo $gj->render_html;
            return null;
        } else {
            return null;
        }
    }
    function render_group($name, $options = null) {
        $this->start_render($options);
        $result = null;
        if (!empty($options) && isset($options["top"]) && $options["top"]) {
            if (($gj = $this->get($name))) {
                $result = $this->render($gj);
            }
        }
        foreach ($this->members($name) as $gj) {
            if ($result !== false) {
                $result = $this->render($gj);
            }
        }
        $this->end_render();
        return $result;
    }

    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->_annexes)) {
            $x = $this->_annexes[$name];
        }
        return $x;
    }
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
}
