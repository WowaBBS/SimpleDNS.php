<?
for($d = __DIR__, $o = ''; $d != $o; $d = dirname($o = $d)) if (is_file($f = $d.'/Using.php')) { include $f; break; } unset($o, $d, $f);
if (isset($Loader))
{ // If we have WLib.php
  $Loader->AutoDispose();                 //Destroy loader on shutwon
  $Loader->Load_Lib('/Debug/Format' );
  $Loader->Load_Lib('/Log/Logger'   );
  $Logger = $Loader->GetLogger();
  $Logger->ScriptLog();                   //Create file [FileNick].log 
  $Logger->Handler->OutPut->SetModeLog(); //Redirect output to log
  unset($Loader, $Logger);
}
