<?php
// Usage: php capture.php <plugin main file>  -> JSON {name:{category,required,props:{p:type}}}
define('ABSPATH','/');
$GLOBALS['HOOKS']=array(); $GLOBALS['REG']=array();
function add_action($h,$cb){ $GLOBALS['HOOKS'][$h][]=$cb; } function add_filter(){}
function apply_filters($t,$v){return $v;}
function get_file_data($f,$h){ preg_match('/Version:\s*(\S+)/', file_get_contents($f), $m); return array('Version'=>$m[1]); }
function wp_register_ability_category(){}
function wp_register_ability($name,$a){ $s=$a['input_schema']??array(); $p=array();
  foreach(($s['properties']??array()) as $k=>$v){ $t=$v['type']??'any'; $p[$k]=is_array($t)?implode('|',$t):$t; if(isset($v['enum'])) $p[$k].=' enum:'.implode(',',$v['enum']); }
  ksort($p); $r=$s['required']??array(); sort($r);
  $GLOBALS['REG'][$name]=array('category'=>$a['category']??null,'required'=>$r,'props'=>$p,'perm'=>is_string($a['permission_callback']??null)?$a['permission_callback']:'closure'); }
foreach(array('__','esc_html__') as $f) if(!function_exists($f)) eval("function $f(\$s){return \$s;}");
function class_exists_stub(){}
require $argv[1];
foreach(array('wp_abilities_api_init') as $h) foreach($GLOBALS['HOOKS'][$h]??array() as $cb) call_user_func($cb);
ksort($GLOBALS['REG']); echo json_encode(array('hooks'=>array_keys($GLOBALS['HOOKS']),'abilities'=>$GLOBALS['REG']));
