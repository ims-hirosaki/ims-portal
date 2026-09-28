<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH','/'); require IMS_SMOKE_ROOT.'/src/Core/AdminAccessPolicy.php';
use IMS\Core\AdminAccessPolicy as P;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$allow=['pages'=>['ims-monthly-submissions','ims-attendance-grid'],'actions'=>['ims_monthly_check_approve']];
t('許可した画面', P::is_allowed('admin.php','ims-monthly-submissions','',$allow), true);
t('許可していない画面', P::is_allowed('admin.php','ims-employees','',$allow), false);
t('page なしの admin.php', P::is_allowed('admin.php','','',$allow), false);
t('許可した処理', P::is_allowed('admin-post.php','','ims_monthly_check_approve',$allow), true);
t('許可していない処理', P::is_allowed('admin-post.php','','ims_monthly_final_approve',$allow), false);
t('action の名前で画面は開けない', P::is_allowed('admin.php','ims_monthly_check_approve','',$allow), false);
t('ダッシュボード', P::is_allowed('index.php','','',$allow), false);
t('プロフィール', P::is_allowed('profile.php','','',$allow), false);
t('page 名を付けても他のファイルは不可', P::is_allowed('users.php','ims-monthly-submissions','',$allow), false);
t('許可なし（一般社員）', P::is_allowed('admin.php','ims-monthly-submissions','',['pages'=>[],'actions'=>[]]), false);
t('has_any', [P::has_any($allow), P::has_any(['pages'=>[]]), P::has_any([])], [true,false,false]);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
