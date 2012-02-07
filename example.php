<?php
//包含模板引擎文件
include_once './template.class.php';

//实例化
$view = new template();
//设置是否开启缓存：0关闭 1开启 （true or false也可以0
$view->set_cache_status(1);
//设置模板文件所在目录，可以是相对目录也可以是绝对目录，推荐绝对目录
$view->set_base_dir('./templates');

$data = array('a' => 'aaa', 'b' => 'bbb');
$name = 'name';
$flag = 1;

//把变量推送到模板中，和smarty用法很像。
//其中assign函数的第一个参数为变量在模板中读取时的名字（可以和变量名不同）。
//例如assign('test', $m);，那么在模板里$test变量表示的就是$m
$view->assign('data', $data);
$view->assign('name', $name);
$view->assign('flag', $flag);

//显示模板文件，和smarty用法很像
//这个文件在："./view/index.htm"
$view->display('index.htm');
?>
