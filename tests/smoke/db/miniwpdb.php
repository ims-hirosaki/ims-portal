<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// テスト用の最小 $wpdb 互換（mysqli で本物の MariaDB に接続する）。
class MiniWpdb {
  public $prefix='wp_'; public $last_error=''; private $db;
  function __construct(){ $this->db=new mysqli(getenv('IMS_TEST_DB_HOST') ?: 'localhost', getenv('IMS_TEST_DB_USER') ?: 'wp', getenv('IMS_TEST_DB_PASS') ?: 'wp', getenv('IMS_TEST_DB_NAME') ?: 't'); $this->db->set_charset('utf8mb4'); }
  private function q($v){ return $v===null?'NULL':(is_int($v)||is_float($v)?(string)$v:"'".$this->db->real_escape_string((string)$v)."'"); }
  function prepare($sql,...$args){ if(count($args)===1&&is_array($args[0]))$args=$args[0]; $i=0;
    return preg_replace_callback('/%[dsf]/',function($m)use(&$i,$args){ $v=$args[$i++]; return $m[0]==='%d'?(string)(int)$v:($m[0]==='%f'?(string)(float)$v:$this->q((string)$v)); },$sql); }
  function query($sql){ $r=$this->db->query($sql); if($r===false){$this->last_error=$this->db->error; return false;} return $r===true?$this->db->affected_rows:$r->num_rows; }
  function insert($t,$d){ $c=implode(',',array_map(fn($k)=>"`$k`",array_keys($d))); $v=implode(',',array_map([$this,'q'],array_values($d))); return $this->query("INSERT INTO $t ($c) VALUES ($v)"); }
  function update($t,$d,$w){ $set=implode(',',array_map(fn($k,$v)=>"`$k`=".$this->q($v),array_keys($d),$d)); $wh=implode(' AND ',array_map(fn($k,$v)=>$v===null?"`$k` IS NULL":"`$k`=".$this->q($v),array_keys($w),$w)); return $this->query("UPDATE $t SET $set WHERE $wh"); }
  function get_row($sql,$o=null){ $r=$this->db->query($sql); return $r?($r->fetch_assoc()?:null):null; }
  function get_var($sql){ $r=$this->db->query($sql); if(!$r)return null; $row=$r->fetch_row(); return $row?$row[0]:null; }
  function get_results($sql,$o=null){ $r=$this->db->query($sql); return $r?$r->fetch_all(MYSQLI_ASSOC):null; }
}
