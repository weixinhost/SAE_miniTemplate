<?php

/*
    [miniTemplate for SAE	作者：李博]

    使用方法：

    include_once './template.class.php';
    $view = new template();
    $view->set_debug(false);
    $view->set_cache_status(1);
    $view->set_rewrite_status(1);

    $preg_searchs = array();
    $preg_replaces = array();

    $preg_searchs[] = "/index.php\?m=([a-z]+)/i";
    $preg_replaces[] = "$1.html";

    $view->set_rewrite_rules($preg_searchs, $preg_replaces);

    $view->set_base_dir('./templates');

    $data = array('a' => 'aaa', 'b' => 'bbb');
    $name = 'name';
    $flag = 1;

    $view->assign('data', $data);
    $view->assign('name', $name);
    $view->assign('flag', $flag);

    $view->display('index.htm');
 */

class template {

    private $debug = false;
    private $cache_enable = true;
    private $rewrite_enable = true;
    private $tplfolder;
    private $tplfile;
    private $objfile;
    private $vars = array();
    private $files = array();
    private $include_files = array();
    private $var_regexp = "\@?\\\$[a-zA-Z_]\w*(?:\[[\w\.\"\'\[\]\$]+\])*";
    private $vtag_regexp = "\<\?=(\@?\\\$[a-zA-Z_]\w*(?:\[[\w\.\"\'\[\]\$]+\])*)\?\>";
    private $const_regexp = "\{([\w]+)\}";
    private $page_content;
    private $ori_content;
    private $preg_searchs = array();
    private $preg_replaces = array();
    private static $memcache;

    public function __construct() {
        ob_start();
    }

    public function set_debug($status) {
        $this->debug = $status;
    }

    public function set_base_dir($tplfolder) {
        $this->tplfolder = $tplfolder;
    }

    public function set_cache_status($status) {
        $this->cache_enable = empty($status) ? false : true;
    }

    public function set_rewrite_status($status) {
        $this->rewrite_enable = empty($status) ? false : true;
    }

    public function set_rewrite_rules($searchs, $replaces) {
        $this->preg_searchs = $searchs;
        $this->preg_replaces = $replaces;
    }

    public function assign($k, $v) {
        $this->vars[$k] = $v;
    }

    public function display($file) {
        sae_set_display_errors($this->debug);

        extract($this->vars, EXTR_SKIP);
        $this->gettpl($file);

        if ($this->cache_enable) {
            eval('?>'.self::$memcache->get($this->objfile));
        } else {
            eval('?>'.$this->page_content);
        }
    }

    private function gettpl($file) {
        $this->objfile = $_SERVER['HTTP_APPVERSION'] . '_' . $this->tplfolder.'_'.$file;
        $this->objfile = md5($this->objfile);

        $this->tplfile = $this->tplfolder.'/'.$file;

        $this->pre_process();

        if ($this->cache_enable) {
            self::$memcache = memcache_init();

            if(self::$memcache == false) {
                header("Content-type: text/html; charset=utf-8"); 
                exit('miniTemplate错误提示：Memcache未激活或者初始化错误，请确认Memcache服务是否正常');
            }

            $update = $this->checkupdate();
        }

        if ($update || !$this->cache_enable) {
            $this->complie();
        }
    }

    private function pre_process() {
        $this->ori_content = file_get_contents($this->tplfile);

        $this->files[] = $this->tplfile;

        $res = preg_match_all("/\{template\s+(.+?)\}/ise", $this->ori_content, $matches);

        if ($res) {
            foreach ($matches[1] as $file) {
                $this->files[] = $this->tplfolder.'/'.$file;
                $this->include_files[] = $file;
            }
        }
    }

    private function checkupdate() {
        $flag = 0;

        foreach($this->files as $file) {
            $filetime = filemtime($file);
            $time_key = $this->objfile . $file . '_' . 'time';
            $lasttime = self::$memcache->get($time_key);

            if ($filetime > $lasttime || empty($lasttime)) {
                self::$memcache->set($time_key, $filetime);
                $flag = 1;
                break;
            }
        }

        return $flag;
    }

    private function complie() {
        $template = $this->ori_content;
        $template = preg_replace("/\<\!\-\-\{(.+?)\}\-\-\>/s", "{\\1}", $template);

        foreach ($this->include_files as $file) {
            $file_data = file_get_contents($this->tplfolder.'/'.$file);
            $template = str_replace('{template ' . $file . '}', $file_data, $template);
        }

        $template = preg_replace("/\{($this->var_regexp)\}/", "<?=\\1?>", $template);
        $template = preg_replace("/\{($this->const_regexp)\}/", "<?=\\1?>", $template);
        $template = preg_replace("/(?<!\<\?\=|\\\\)$this->var_regexp/", "<?=\\0?>", $template);


        $template = preg_replace("/\<\?=(\@?\\\$[a-zA-Z_]\w*)((\[[\\$\[\]\w]+\])+)\?\>/ies", "self::arrayindex('\\1', '\\2')", $template);

        $template = preg_replace("/\{\{eval (.*?)\}\}/ies", "self::stripvtag('<? \\1?>')", $template);
        $template = preg_replace("/\{eval (.*?)\}/ies", "self::stripvtag('<? \\1?>')", $template);

        $template = preg_replace("/\{elseif\s+(.+?)\}/ies", "self::stripvtag('<? } elseif(\\1) { ?>')", $template);

        for($i=0; $i<2; $i++) {
            $template = preg_replace("/\{loop\s+$this->vtag_regexp\s+$this->vtag_regexp\s+$this->vtag_regexp\}(.+?)\{\/loop\}/ies", "self::loopsection('\\1', '\\2', '\\3', '\\4')", $template);
            $template = preg_replace("/\{loop\s+$this->vtag_regexp\s+$this->vtag_regexp\}(.+?)\{\/loop\}/ies", "self::loopsection('\\1', '', '\\2', '\\3')", $template);
        }

        $template = preg_replace("/\{if\s+(.+?)\}/ies", "self::stripvtag('<? if(\\1) { ?>')", $template);


        $template = preg_replace("/\{else\}/is", "<? } else { ?>", $template);
        $template = preg_replace("/\{\/if\}/is", "<? } ?>", $template);

        $template = preg_replace("/$this->const_regexp/", "<?=\\1?>", $template);

        $template = preg_replace("/(\\\$[a-zA-Z_]\w+\[)([a-zA-Z_]\w+)\]/i", "\\1'\\2']", $template);

        $this->page_content = $template;

        if ($this->cache_enable) {
            self::$memcache->set($this->objfile, $template);
        }
    }

    private static function arrayindex($name, $items) {
        $items = preg_replace("/\[([a-zA-Z_]\w*)\]/is", "['\\1']", $items);
        return "<?=$name$items?>";
    }

    private function stripvtag($s) {
        return preg_replace("/$this->vtag_regexp/is", "\\1", str_replace("\\\"", '"', $s));
    }

    private function loopsection($arr, $k, $v, $statement) {
        $arr = self::stripvtag($arr);
        $k = self::stripvtag($k);
        $v = self::stripvtag($v);
        $statement = str_replace("\\\"", '"', $statement);
        return $k ? "<? foreach((array)$arr as $k => $v) {?>$statement<?}?>" : "<? foreach((array)$arr as $v) {?>$statement<? } ?>";
    }

    private function rewrite($content) {

        $content = preg_replace($this->preg_searchs, $this->preg_replaces, $content);

        return $content;
    }

    public function __destruct() {
        $content = ob_get_contents();
        ob_end_clean();

        if ($this->rewrite_enable) {
            $content = self::rewrite($content);
        }

        echo $content;
    }
}
?>
