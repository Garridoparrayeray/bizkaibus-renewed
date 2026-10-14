<?php
// Mide la precision de la ETA que muestra la app contra el paso real por parada (cambio de VisitNumber en el feed).
// Uso: php scripts/eval-eta.php <carpeta con capturas s01.xml, s02.xml... o vm_*.xml>
// Captura: descargar bizkaibus-vehicle-positions.xml cada ~60 s (el feed se actualiza cada minuto) durante 15-30 min.
// El paso real se conoce con una resolucion de +-30 s (la distancia entre capturas), asi que el error medido incluye ese margen.
date_default_timezone_set('Europe/Madrid');
spl_autoload_register(function($c){$p='/home/user/bizkaibus-renewed/bideplus/api/'.str_replace('\\','/',$c).'.php'; if(is_file($p)) require $p;});
$Pdo=Core\Database::connection(); $JM=new Models\ServiceJourney($Pdo);
$dir=$argv[1]??''; if(!is_dir($dir)){ fwrite(STDERR,"Uso: php scripts/eval-eta.php <carpeta con capturas>\n"); exit(1);}
$files=array_merge(glob(rtrim($dir,'/').'/s*.xml') ?: [], glob(rtrim($dir,'/').'/vm_*.xml') ?: []); sort($files);
$snaps=[]; // [ [t, vm] ]
foreach($files as $f){ $vm=Services\SiriVehicleMonitoringClient::parse(file_get_contents($f)); $t=0; foreach($vm as $es) foreach($es as $e) if(isset($e['locationSeconds'])) $t=max($t,$e['locationSeconds']); $snaps[]=[$t,$vm]; }
// orden por vehiculo y viaje en cada snapshot
$byVeh=[]; foreach($snaps as $i=>[$t,$vm]) foreach($vm as $k=>$es) foreach($es as $e) $byVeh[$e['vehicleRef']][$i]=[$k,$e];
$stopsCache=[];
$G=[]; $stat=['app'=>[], 'horario'=>[]]; $cnt=['pred'=>0,'vivo'=>0,'programado'=>0];
$H=[[0,180],[180,360],[360,600],[600,1200]];
foreach($snaps as $i=>[$t,$vm]){
  $M=new Services\RealtimeMatcher($vm,$JM,$t);
  foreach($vm as $k=>$es) foreach($es as $e){
    $j=$M->journeyIdFor($e); if(!$j||!isset($e['order'])) continue;
    [$sLine,$sTrip]=explode('|',$k);
    $stopsCache[$j]??=$JM->stopsWithCoordinates($j); $st=$stopsCache[$j]; if(!$st) continue;
    $firstDep=(int)$st[0]['departure_seconds'];
    foreach($st as $s){ $seq=(int)$s['seq_order']; if($seq<(int)$e['order']||$seq>(int)$e['order']+8) continue;
      // paso real: primer snapshot posterior donde este bus (mismo viaje) ya marca orden > seq
      $actual=null; for($n=$i+1;$n<count($snaps);$n++){ if(!isset($byVeh[$e['vehicleRef']][$n])) break; [$k2,$e2]=$byVeh[$e['vehicleRef']][$n]; if($k2!==$k) break; if(isset($e2['order'])&&(int)$e2['order']>$seq){ $actual=($snaps[$n][0]+$snaps[$n-1][0])/2; break; } }
      if($actual===null) continue;
      $row=['service_journey_id'=>$j,'line_id'=>$sLine,'trip_number'=>$sTrip,'first_departure_seconds'=>$firstDep,'arrival_seconds'=>(int)$s['arrival_seconds'],'stop_seq'=>$seq];
      $out=$M->enrich([$row])[0]; $cnt['pred']++;
      $h=$actual-$t; $b=null; foreach($H as $bi=>[$lo,$hi]) if($h>=$lo&&$h<$hi) $b=$bi; if($b===null) continue;
      $sched=(int)$s['arrival_seconds'];
      $pos=$M->positionFor($j,$e); $grp=$pos?'GPS':'parada'; if($out['status']!=='live') $grp='horario';
      $by=array_column($st,null,'seq_order'); $gp=(isset($by[(int)$e['order']],$by[(int)$e['order']-1]))?((int)$by[(int)$e['order']]['arrival_seconds']-(int)$by[(int)$e['order']-1]['arrival_seconds'])/60:0; $seg=$gp>10?'tramo largo':'tramo corto';
      if($h<600) $G[$grp.' / '.$seg][]=($out['status']==='live'?$out['etaSeconds']:$sched)-$actual;
      $stat['horario'][$b][]=$sched-$actual;
      if($out['status']==='live'){ $cnt['vivo']++; $stat['app'][$b][]=$out['etaSeconds']-$actual; } else { $cnt['programado']++; $stat['app'][$b][]=$sched-$actual; }
    }
  }
}
function pct($a,$lim){ if(!$a) return 'n/d'; return sprintf('%.0f%%',100*count(array_filter($a,fn($x)=>abs($x)<=$lim))/count($a)); }
function sgn($a){ if(!$a) return 'n/d'; sort($a); return sprintf('%+.1f',$a[intdiv(count($a),2)]/60); }
function med($a){ if(!$a) return 'n/d'; $b=array_map('abs',$a); sort($b); return sprintf('%.1f',$b[intdiv(count($b),2)]/60); }
echo "predicciones con paso real conocido: {$cnt['pred']} (en vivo {$cnt['vivo']}, horario oficial {$cnt['programado']})\n";
foreach($H as $bi=>[$lo,$hi]){ foreach(['app','horario'] as $m){ $a=$stat[$m][$bi]??[]; printf("%-8s llega en %2d-%2d min: n=%4d  sesgo %5s | error mediano %5s min | dentro de ±1 min %4s | ±2 min %4s | ±3 min %4s\n",$m,$lo/60,$hi/60,count($a),sgn($a),med($a),pct($a,60),pct($a,120),pct($a,180)); } }

echo "\n== por origen del dato (llegadas en menos de 10 min)\n"; foreach($G as $g=>$a) printf("%-24s n=%4d  ±1 min %4s  ±2 min %4s  ±3 min %4s  error mediano %s min\n",$g,count($a),pct($a,60),pct($a,120),pct($a,180),med($a));
