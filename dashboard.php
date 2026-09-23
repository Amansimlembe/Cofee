<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: login.php'); exit; }

$display=strtolower(trim($_GET['display']??'kagera'));
if(!in_array($display,['sales','preauction','kagera','clean','direct','totalclean'],true))$display='kagera';
$totalCleanView=$_GET['totalclean_view']??'summary';
if(!in_array($totalCleanView,['summary','analysis'],true))$totalCleanView='summary';

function nf($v,$dec=0){$v=(float)$v;$d=(abs($v-round($v))<0.000001)?0:$dec;return number_format($v,$d,'.',',');}
function pct($a,$b){return $b>0?($a/$b*100):0;}


if($display==='preauction'){
 require_once __DIR__.'/kagera_database.php';
 require_once __DIR__.'/clean_database.php';
 require_once __DIR__.'/Direct_database.php';
 ensure_kagera_table(); ensure_kagera_catalogue_table(); ensure_clean_table(); direct_ensure_table();
 $kdb=kagera_db(); $cdb=clean_db(); $ddb=direct_db();
 function season_bounds($s){if(!preg_match('/^(\\d{4})\\/(\\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-07-01',$m[2].'-06-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return$m>=7?$y.'/'.($y+1):($y-1).'/'.$y;}
 $dr=$ddb->query("SELECT invoice_date d FROM public.direct_sales WHERE invoice_date IS NOT NULL UNION SELECT auction_date FROM public.clean_auction_results WHERE auction_date IS NOT NULL UNION SELECT auction_date FROM public.kagera_auction_results WHERE auction_date IS NOT NULL")->fetchAll();
 $seasons=[];foreach($dr as$r){$d=$r['d']??null;if(!$d)continue;$y=(int)substr($d,0,4);$m=(int)substr($d,5,2);$sy=$m>=7?$y:$y-1;$seasons[$sy.'/'.($sy+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $latestQ=$cdb->prepare("SELECT MAX(auction_date) FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t");$latestQ->execute(['f'=>$from,'t'=>$to]);$preLatest=$latestQ->fetchColumn();
 $preGrades=['AAA','AA','A','AB','B','PB','C','Lower Grades'];$preGradeRows=[];$lastOff=$lastSold=$lastVal=0;
 if($preLatest){$gsql="CASE WHEN UPPER(BTRIM(COALESCE(grade,''))) IN ('AAA','AA','A','AB','B','PB','C') THEN UPPER(BTRIM(grade)) ELSE 'Lower Grades' END";$sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";$q=$cdb->prepare("SELECT $gsql g,SUM(COALESCE(n_kgs,0)) offered,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) sold,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) val,MAX(CASE WHEN $sold THEN price_per_50kg END) mx,MIN(CASE WHEN $sold THEN price_per_50kg END) mn,CASE WHEN SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END)>0 THEN SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0) ELSE 0 END)/SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) END av FROM public.clean_auction_results WHERE auction_date=:d GROUP BY 1");$q->execute(['d'=>$preLatest]);foreach($q as$r)$preGradeRows[$r['g']]=$r;foreach($preGradeRows as$r){$lastOff+=(float)$r['offered'];$lastSold+=(float)$r['sold'];$lastVal+=(float)$r['val'];}}
 $lastAvg=$lastSold>0?$lastVal*50/$lastSold:0;$lastPct=pct($lastSold,$lastOff);
 $dtype="CASE WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%robusta%' THEN 'Robusta' WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) IN ('h/arabica','h-arabica','h arabica') OR LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%hard arabica%' THEN 'Hard Arabica' ELSE 'Mild Arabica' END";
 $ls="GREATEST(COALESCE(d.net_kg,0)-COALESCE((SELECT SUM(COALESCE(de.net_kg,0)) FROM public.direct_sales de WHERE UPPER(BTRIM(COALESCE(de.sale_category,''))) IN ('DE','DIRECT EXPORT') AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number)) AND de.invoice_date BETWEEN :lf AND :lt),0),0)";
 $qty="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN $ls ELSE COALESCE(d.net_kg,0) END";
 $preCoffee=['Mild Arabica'=>['kg'=>0,'val'=>0],'Hard Arabica'=>['kg'=>0,'val'=>0],'Robusta'=>['kg'=>0,'val'=>0]];
 $q=$ddb->prepare("SELECT $dtype ct,SUM($qty) kg,SUM(($qty)*COALESCE(d.price_usd_50kg,0)/50.0) val FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to,'lf'=>$from,'lt'=>$to]);foreach($q as$r)if(isset($preCoffee[$r['ct']])){$preCoffee[$r['ct']]['kg']+=(float)$r['kg'];$preCoffee[$r['ct']]['val']+=(float)$r['val'];}
 $act="CASE WHEN LOWER(COALESCE(grade2,'')||' '||COALESCE(grade,'')) LIKE '%robusta%' OR UPPER(BTRIM(COALESCE(grade,''))) LIKE 'R%' THEN 'Robusta' WHEN UPPER(BTRIM(COALESCE(grade,''))) LIKE 'H%' OR LOWER(COALESCE(grade2,'')) LIKE '%hard%' THEN 'Hard Arabica' ELSE 'Mild Arabica' END";$sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
 $q=$cdb->prepare("SELECT $act ct,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) kg,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) val FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to]);foreach($q as$r)if(isset($preCoffee[$r['ct']])){$preCoffee[$r['ct']]['kg']+=(float)$r['kg'];$preCoffee[$r['ct']]['val']+=(float)$r['val'];}
 $preArabicaKg=$preCoffee['Mild Arabica']['kg']+$preCoffee['Hard Arabica']['kg'];$preArabicaVal=$preCoffee['Mild Arabica']['val']+$preCoffee['Hard Arabica']['val'];$preRobustaKg=$preCoffee['Robusta']['kg'];$preRobustaVal=$preCoffee['Robusta']['val'];$preTotalKg=$preArabicaKg+$preRobustaKg;$preTotalVal=$preArabicaVal+$preRobustaVal;
 $nr="COALESCE(NULLIF(INITCAP(LOWER(REGEXP_REPLACE(BTRIM(COALESCE(d.region,'')),'\\s+',' ','g'))),''),'Unspecified')";$q=$ddb->prepare("SELECT $nr region,SUM($qty) kg,SUM(($qty)*COALESCE(d.price_usd_50kg,0)/50.0) val FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to,'lf'=>$from,'lt'=>$to]);$preRegions=$q->fetchAll();
 $dewhere="UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('DE','DIRECT EXPORT')";$q=$ddb->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(d.supplier_seller),'')) active,COUNT(DISTINCT NULLIF(BTRIM(d.buyer),'')) buyers,SUM(COALESCE(d.net_kg,0)) kg,SUM(COALESCE(d.net_kg,0)*COALESCE(d.price_usd_50kg,0)/50.0) val FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $dewhere");$q->execute(['f'=>$from,'t'=>$to]);$preDE=$q->fetch()?:[];
 $partyNorm=function($f){return "COALESCE(NULLIF(INITCAP(LOWER(REGEXP_REPLACE(BTRIM(COALESCE($f,'')),'\\s+',' ','g'))),''),'Unspecified')";};$ns=$partyNorm('d.supplier_seller');$nb=$partyNorm('d.buyer');
 $q=$ddb->prepare("SELECT $ns name,SUM(COALESCE(d.net_kg,0)) kg,SUM(COALESCE(d.net_kg,0)*COALESCE(d.price_usd_50kg,0)/50.0) val FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $dewhere GROUP BY 1 ORDER BY kg DESC");$q->execute(['f'=>$from,'t'=>$to]);$preDESellers=$q->fetchAll();
 $q=$ddb->prepare("SELECT $nb name,SUM(COALESCE(d.net_kg,0)) kg,SUM(COALESCE(d.net_kg,0)*COALESCE(d.price_usd_50kg,0)/50.0) val FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $dewhere GROUP BY 1 ORDER BY kg DESC");$q->execute(['f'=>$from,'t'=>$to]);$preDEBuyers=$q->fetchAll();
 $q=$cdb->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(auction_no),'')) auctions,SUM(COALESCE(n_kgs,0)) offered,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) sold,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) val FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$from,'t'=>$to]);$preClean=$q->fetch()?:[];
 $q=$cdb->prepare("SELECT COALESCE(NULLIF(INITCAP(LOWER(BTRIM(buyer))),''),'Unspecified') name,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) kg,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) val FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1 HAVING SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END)>0 ORDER BY kg DESC");$q->execute(['f'=>$from,'t'=>$to]);$preCleanBuyers=$q->fetchAll();
 $kf=$season;[$ky1,$ky2]=array_map('intval',explode('/',$kf));$kfrom=$ky1.'-06-01';$kto=$ky2.'-05-30';$kcase="CASE WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%dry cherry%' THEN 'Dry Cherry Coffee' WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%clean%' THEN 'Clean Coffee' ELSE NULL END";
 $q=$kdb->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(auction_no),'')) auctions,COUNT(DISTINCT NULLIF(BTRIM(buyer),'')) buyers,SUM(COALESCE(kgs,0)) sold,SUM(COALESCE(kgs,0)*COALESCE(price,0)) val,SUM(CASE WHEN $kcase='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) drykg,SUM(CASE WHEN $kcase='Dry Cherry Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END) dryval,SUM(CASE WHEN $kcase='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) cleankg,SUM(CASE WHEN $kcase='Clean Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END) cleanval FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$kfrom,'t'=>$kto]);$preK=$q->fetch()?:[];
 $q=$kdb->prepare("SELECT SUM(COALESCE(kgs,0)) FROM public.kagera_auction_catalogue WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$kfrom,'t'=>$kto]);$preKOff=(float)$q->fetchColumn();
 $q=$kdb->prepare("SELECT COALESCE(NULLIF(REPLACE(BTRIM(grade),'_',' '),''),'Unspecified') type,SUM(COALESCE(kgs,0)) kg,SUM(COALESCE(kgs,0)*COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1 ORDER BY kg DESC");$q->execute(['f'=>$kfrom,'t'=>$kto]);$preKTypes=$q->fetchAll();
 $q=$kdb->prepare("SELECT COALESCE(NULLIF(INITCAP(LOWER(BTRIM(buyer))),''),'Unspecified') name,SUM(COALESCE(kgs,0)) kg,SUM(COALESCE(kgs,0)*COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1 ORDER BY kg DESC");$q->execute(['f'=>$kfrom,'t'=>$kto]);$preKBuyers=$q->fetchAll();
 $preEnd=max(array_filter([$preLatest,$ddb->query("SELECT MAX(invoice_date) FROM public.direct_sales")->fetchColumn(),$kdb->query("SELECT MAX(auction_date) FROM public.kagera_auction_results")->fetchColumn()]));
 $dashboardTitle='Pre Auction Report';$dashboardSub='Coffee sales performance report';
}else
if($display==='sales'){
 require_once __DIR__.'/kagera_database.php';
 require_once __DIR__.'/clean_database.php';
 require_once __DIR__.'/Direct_database.php';
 ensure_kagera_table(); ensure_kagera_catalogue_table(); ensure_clean_table(); direct_ensure_table();
 $db=kagera_db(); $cdb=clean_db(); $ddb=direct_db();
 function season_bounds($s){if(!preg_match('/^(\\d{4})\\/(\\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-07-01',$m[2].'-06-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return$m>=7?$y.'/'.($y+1):($y-1).'/'.$y;}
 $dateRows=$ddb->query("SELECT invoice_date d FROM public.direct_sales WHERE invoice_date IS NOT NULL UNION SELECT auction_date FROM public.clean_auction_results WHERE auction_date IS NOT NULL UNION SELECT auction_date FROM public.kagera_auction_results WHERE auction_date IS NOT NULL")->fetchAll();
 $seasons=[];foreach($dateRows as$r){$d=$r['d']??null;if(!$d)continue;$y=(int)substr($d,0,4);$m=(int)substr($d,5,2);$sy=$m>=7?$y:$y-1;$seasons[$sy.'/'.($sy+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $endQ=$ddb->prepare("SELECT MAX(d) FROM (SELECT invoice_date d FROM public.direct_sales WHERE invoice_date BETWEEN :f1 AND :t1 UNION ALL SELECT auction_date FROM public.clean_auction_results WHERE auction_date BETWEEN :f2 AND :t2 UNION ALL SELECT auction_date FROM public.kagera_auction_results WHERE auction_date BETWEEN :f3 AND :t3)x");
 $endQ->execute(['f1'=>$from,'t1'=>$to,'f2'=>$from,'t2'=>$to,'f3'=>$from,'t3'=>$to]);$salesEnd=$endQ->fetchColumn()?:$to;
 $weekStart=date('Y-m-d',strtotime('monday this week',strtotime($salesEnd))); if($weekStart<$from)$weekStart=$from;
 $kcase="CASE WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%dry cherry%' THEN 'Dry Cherry' WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%clean%' THEN 'Clean Coffee' ELSE NULL END";
 $salesFarm=['Dry Cherry'=>['weekkg'=>0,'weekval'=>0,'seasonkg'=>0,'seasonval'=>0],'Clean Coffee'=>['weekkg'=>0,'weekval'=>0,'seasonkg'=>0,'seasonval'=>0]];
 $q=$db->prepare("SELECT $kcase typ,SUM(CASE WHEN auction_date BETWEEN :w AND :e THEN COALESCE(kgs,0) ELSE 0 END) weekkg,SUM(CASE WHEN auction_date BETWEEN :w AND :e THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END) weekval,SUM(COALESCE(kgs,0)) seasonkg,SUM(COALESCE(kgs,0)*COALESCE(price,0)) seasonval FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['w'=>$weekStart,'e'=>$salesEnd,'f'=>$from,'t'=>$to]);foreach($q as$r){if(isset($salesFarm[$r['typ']]))foreach(['weekkg','weekval','seasonkg','seasonval']as$k)$salesFarm[$r['typ']][$k]=(float)$r[$k];}
 $coffeeTypes=['M-Arabica','H-Arabica','Robusta'];$channels=['Clean Auction','Direct Export','Local Roast','Local Sale'];$salesClean=[];foreach($channels as$ch)foreach($coffeeTypes as$ct)$salesClean[$ch][$ct]=['weekkg'=>0,'weekval'=>0,'seasonkg'=>0,'seasonval'=>0];
 $ctype="CASE WHEN LOWER(COALESCE(grade2,'')||' '||COALESCE(grade,'')) LIKE '%robusta%' OR UPPER(BTRIM(COALESCE(grade,''))) LIKE 'R%' THEN 'Robusta' WHEN UPPER(BTRIM(COALESCE(grade,''))) LIKE 'H%' OR LOWER(COALESCE(grade2,'')) LIKE '%hard%' THEN 'H-Arabica' ELSE 'M-Arabica' END";
 $sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
 $q=$cdb->prepare("SELECT $ctype ct,SUM(CASE WHEN auction_date BETWEEN :w AND :e AND $sold THEN COALESCE(n_kgs,0) ELSE 0 END) weekkg,SUM(CASE WHEN auction_date BETWEEN :w AND :e AND $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) weekval,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) seasonkg,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0 ELSE 0 END) seasonval FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['w'=>$weekStart,'e'=>$salesEnd,'f'=>$from,'t'=>$to]);foreach($q as$r)if(isset($salesClean['Clean Auction'][$r['ct']]))foreach(['weekkg','weekval','seasonkg','seasonval']as$k)$salesClean['Clean Auction'][$r['ct']][$k]=(float)$r[$k];
 $dtype="CASE WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%robusta%' THEN 'Robusta' WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) IN ('h/arabica','h-arabica','h arabica') OR LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%hard arabica%' THEN 'H-Arabica' ELSE 'M-Arabica' END";
 $dch="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('DE','DIRECT EXPORT') THEN 'Direct Export' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN 'Local Sale' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LR','LOCAL ROAST','LOCAL ROAST SALE','SLS') THEN 'Local Roast' END";
 $lsSeason="GREATEST(COALESCE(d.net_kg,0)-COALESCE((SELECT SUM(COALESCE(de.net_kg,0)) FROM public.direct_sales de WHERE UPPER(BTRIM(COALESCE(de.sale_category,''))) IN ('DE','DIRECT EXPORT') AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number)) AND de.invoice_date BETWEEN :lf AND :lt),0),0)";
 $qty="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN $lsSeason ELSE COALESCE(d.net_kg,0) END";
 $q=$ddb->prepare("SELECT $dch ch,$dtype ct,SUM(CASE WHEN d.invoice_date BETWEEN :w AND :e THEN $qty ELSE 0 END) weekkg,SUM(CASE WHEN d.invoice_date BETWEEN :w AND :e THEN ($qty)*COALESCE(d.price_usd_50kg,0)/50.0 ELSE 0 END) weekval,SUM($qty) seasonkg,SUM(($qty)*COALESCE(d.price_usd_50kg,0)/50.0) seasonval FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $dch IS NOT NULL GROUP BY 1,2");$q->execute(['w'=>$weekStart,'e'=>$salesEnd,'f'=>$from,'t'=>$to,'lf'=>$from,'lt'=>$to]);foreach($q as$r)if(isset($salesClean[$r['ch']][$r['ct']]))foreach(['weekkg','weekval','seasonkg','seasonval']as$k)$salesClean[$r['ch']][$r['ct']][$k]=(float)$r[$k];
 $salesGrandKg=$salesGrandVal=0;foreach($channels as$ch)foreach($coffeeTypes as$ct){$salesGrandKg+=$salesClean[$ch][$ct]['seasonkg'];$salesGrandVal+=$salesClean[$ch][$ct]['seasonval'];}
 $estimatedProduction=85000.0;$productionPct=$estimatedProduction>0?($salesGrandKg/1000)/$estimatedProduction*100:0;
 $dashboardTitle='Sales Dashboard';$dashboardSub='Tanzania Coffee Board · Sales Season (FY)';
}else
if($display==='totalclean'){
 require_once __DIR__.'/clean_database.php';
 require_once __DIR__.'/Direct_database.php';
 ensure_clean_table(); direct_ensure_table();
 $db=clean_db();
 function season_bounds($s){if(!preg_match('/^(\\d{4})\\/(\\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-07-01',$m[2].'-06-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return$m>=7?$y.'/'.($y+1):($y-1).'/'.$y;}
 $rs=$db->query("SELECT DISTINCT EXTRACT(YEAR FROM d)::int y,EXTRACT(MONTH FROM d)::int m FROM (SELECT auction_date d FROM public.clean_auction_results WHERE auction_date IS NOT NULL UNION SELECT invoice_date d FROM public.direct_sales WHERE invoice_date IS NOT NULL)x ORDER BY y DESC,m DESC")->fetchAll();
 $seasons=[];foreach($rs as$r){$y=((int)$r['m']>=7)?(int)$r['y']:(int)$r['y']-1;$seasons[$y.'/'.($y+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $types=['M-Arabica','H-Arabica','Robusta']; $channels=['Auction Sale','Local Sale','Direct Export','Local Roast'];
 $totalClean=[];foreach($types as$ct)foreach($channels as$ch)$totalClean[$ct][$ch]=['kgs'=>0,'value'=>0,'avg'=>0];
 /* Clean auction: only SOLD lots. Classify Robusta explicitly; H grades as H-Arabica; remaining Arabica as M-Arabica. */
 $auctionType="CASE WHEN LOWER(COALESCE(grade2,'')||' '||COALESCE(grade,'')) LIKE '%robusta%' OR UPPER(BTRIM(COALESCE(grade,''))) LIKE 'R%' THEN 'Robusta' WHEN UPPER(BTRIM(COALESCE(grade,''))) LIKE 'H%' OR LOWER(COALESCE(grade2,'')) LIKE '%hard%' THEN 'H-Arabica' ELSE 'M-Arabica' END";
 $q=$db->prepare("SELECT $auctionType coffee_type,SUM(COALESCE(n_kgs,0)) kgs,SUM(COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0) value_usd FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t AND UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S') GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to]);
 foreach($q as$r){if(isset($totalClean[$r['coffee_type']])){$kg=(float)$r['kgs'];$v=(float)$r['value_usd'];$totalClean[$r['coffee_type']]['Auction Sale']=['kgs'=>$kg,'value'=>$v,'avg'=>$kg>0?$v*50/$kg:0];}}
 /* Direct-sales channels. Local Sale ALWAYS uses the season-specific remaining LS balance. */
 $ddb=direct_db();
 $directType="CASE WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%robusta%' THEN 'Robusta' WHEN LOWER(BTRIM(COALESCE(d.coffee_type,''))) IN ('h/arabica','h-arabica','h arabica') OR LOWER(BTRIM(COALESCE(d.coffee_type,''))) LIKE '%hard arabica%' THEN 'H-Arabica' ELSE 'M-Arabica' END";
 $channel="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('DE','DIRECT EXPORT') THEN 'Direct Export' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN 'Local Sale' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LR','LOCAL ROAST','LOCAL ROAST SALE','SLS') THEN 'Local Roast' END";
 $ls="GREATEST(COALESCE(d.net_kg,0)-COALESCE((SELECT SUM(COALESCE(de.net_kg,0)) FROM public.direct_sales de WHERE UPPER(BTRIM(COALESCE(de.sale_category,''))) IN ('DE','DIRECT EXPORT') AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number)) AND de.invoice_date BETWEEN :lf AND :lt),0),0)";
 $qty="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN $ls ELSE COALESCE(d.net_kg,0) END";
 $q=$ddb->prepare("SELECT $directType coffee_type,$channel channel,SUM($qty) kgs,SUM(($qty)*COALESCE(d.price_usd_50kg,0)/50.0) value_usd FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $channel IS NOT NULL GROUP BY 1,2");$q->execute(['f'=>$from,'t'=>$to,'lf'=>$from,'lt'=>$to]);
 foreach($q as$r){if(isset($totalClean[$r['coffee_type']][$r['channel']])){$kg=(float)$r['kgs'];$v=(float)$r['value_usd'];$totalClean[$r['coffee_type']][$r['channel']]=['kgs'=>$kg,'value'=>$v,'avg'=>$kg>0?$v*50/$kg:0];}}
 $tcChannelTotals=[];$tcGrandKg=0;$tcGrandValue=0;foreach($channels as$ch){$kg=$v=0;foreach($types as$ct){$kg+=$totalClean[$ct][$ch]['kgs'];$v+=$totalClean[$ct][$ch]['value'];}$tcChannelTotals[$ch]=['kgs'=>$kg,'value'=>$v,'avg'=>$kg>0?$v*50/$kg:0];$tcGrandKg+=$kg;$tcGrandValue+=$v;}
 /* Analytical detail for Top 10 views. Local Sale continues to use LS Balance. */
 $normParty=function($f){return "COALESCE(NULLIF(INITCAP(LOWER(REGEXP_REPLACE(BTRIM(COALESCE($f,'')),'\\s+',' ','g'))),''),'Unspecified')";};
 $normRegion=function($f){return "COALESCE(NULLIF(INITCAP(LOWER(REGEXP_REPLACE(BTRIM(COALESCE($f,'')),'\\s+',' ','g'))),''),'Unspecified')";};
 $cols=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='clean_auction_results'")->fetchAll(PDO::FETCH_COLUMN);
 $buyerCol=null;foreach(['buyer_name','buyer','buyername'] as$c){if(in_array($c,$cols,true)){$buyerCol=$c;break;}}
 $supplierCol=null;foreach(['seller_name','seller','warehouse_name_amcos','warehouse','sell_mark'] as$c){if(in_array($c,$cols,true)){$supplierCol=$c;break;}}
 $regionCol=null;foreach(['region','warehouse_location','district','location'] as$c){if(in_array($c,$cols,true)){$regionCol=$c;break;}}
 $ab=$buyerCol?$normParty($buyerCol):"'Unspecified'";$as=$supplierCol?$normParty($supplierCol):"'Unspecified'";$ar=$regionCol?$normRegion($regionCol):"'Unspecified'";
 $aq=$db->prepare("SELECT 'Auction Sale' channel,$auctionType coffee_type,$ar region,$ab buyer,$as supplier,SUM(COALESCE(n_kgs,0)) kgs,SUM(COALESCE(n_kgs,0)*COALESCE(price_per_50kg,0)/50.0) value_usd FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t AND UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S') GROUP BY 1,2,3,4,5");
 $aq->execute(['f'=>$from,'t'=>$to]);$auctionDetail=$aq->fetchAll();
 $nb=$normParty('d.buyer');$ns=$normParty('d.supplier_seller');$nr=$normRegion('d.region');
 $dq=$ddb->prepare("SELECT $channel channel,$directType coffee_type,$nr region,$nb buyer,$ns supplier,SUM($qty) kgs,SUM(($qty)*COALESCE(d.price_usd_50kg,0)/50.0) value_usd FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $channel IS NOT NULL GROUP BY 1,2,3,4,5");
 $dq->execute(['f'=>$from,'t'=>$to,'lf'=>$from,'lt'=>$to]);$tcDetail=array_merge($auctionDetail,$dq->fetchAll());
 $tcAgg=function($rows,$keys){$o=[];foreach($rows as$r){$parts=[];foreach($keys as$k)$parts[]=$r[$k]??'Unspecified';$id=implode('|',$parts);if(!isset($o[$id]))$o[$id]=array_combine($keys,$parts)+['kgs'=>0.0,'value_usd'=>0.0];$o[$id]['kgs']+=(float)$r['kgs'];$o[$id]['value_usd']+=(float)$r['value_usd'];}return array_values($o);};
 $tcAnalytics=['buyer_channel'=>$tcAgg($tcDetail,['channel','buyer']),'supplier_channel'=>$tcAgg($tcDetail,['channel','supplier']),'buyer_region'=>$tcAgg($tcDetail,['region','buyer']),'supplier_region'=>$tcAgg($tcDetail,['region','supplier']),'buyer_type'=>$tcAgg($tcDetail,['coffee_type','buyer']),'supplier_type'=>$tcAgg($tcDetail,['coffee_type','supplier'])];

 $dashboardTitle='Total Clean Coffee Summary';$dashboardSub='Auction, Direct Export, Local Sale balance and Local Roast';
}else
if($display==='direct'){
 require_once __DIR__.'/Direct_database.php';
 direct_ensure_table();
 $db=direct_db();
 function season_bounds($s){if(!preg_match('/^(\d{4})\/(\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-07-01',$m[2].'-06-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return$m>=7?$y.'/'.($y+1):($y-1).'/'.$y;}
 $rs=$db->query("SELECT DISTINCT EXTRACT(YEAR FROM invoice_date)::int y,EXTRACT(MONTH FROM invoice_date)::int m FROM public.direct_sales WHERE invoice_date IS NOT NULL ORDER BY y DESC,m DESC")->fetchAll();
 $seasons=[];foreach($rs as$r){$y=((int)$r['m']>=7)?(int)$r['y']:(int)$r['y']-1;$seasons[$y.'/'.($y+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $channelCase="CASE WHEN UPPER(BTRIM(COALESCE(sale_category,''))) IN ('DE','DIRECT EXPORT') THEN 'Direct Export' WHEN UPPER(BTRIM(COALESCE(sale_category,''))) IN ('LS','LOCAL SALE') THEN 'Local Sale' WHEN UPPER(BTRIM(COALESCE(sale_category,''))) IN ('LR','LOCAL ROAST','LOCAL ROAST SALE','SLS') THEN 'Local Roast' ELSE NULL END";
 /* Local Sale dashboard quantity is ALWAYS Local Sale Balance. */
 $lsBalanceExpr="GREATEST(
     COALESCE(d.net_kg,0) -
     COALESCE((
         SELECT SUM(COALESCE(de.net_kg,0))
         FROM public.direct_sales de
         WHERE UPPER(BTRIM(COALESCE(de.sale_category,''))) IN ('DE','DIRECT EXPORT')
           AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL
           AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number))
           AND de.invoice_date BETWEEN :lsf AND :lst
     ),0),
     0
 )";
 $qtyExpr="CASE
     WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE')
     THEN $lsBalanceExpr
     ELSE COALESCE(d.net_kg,0)
 END";
 $valueExpr="($qtyExpr)*COALESCE(d.price_usd_50kg,0)/50.0";
 $channelCaseD="CASE WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('DE','DIRECT EXPORT') THEN 'Direct Export' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LS','LOCAL SALE') THEN 'Local Sale' WHEN UPPER(BTRIM(COALESCE(d.sale_category,''))) IN ('LR','LOCAL ROAST','LOCAL ROAST SALE','SLS') THEN 'Local Roast' ELSE NULL END";
 $q=$db->prepare("SELECT $channelCaseD channel,COALESCE(NULLIF(INITCAP(LOWER(BTRIM(d.coffee_type))),''),'Unspecified') coffee_type,SUM($qtyExpr) kgs,SUM($valueExpr) value_usd FROM public.direct_sales d WHERE d.invoice_date BETWEEN :f AND :t AND $channelCaseD IS NOT NULL GROUP BY 1,2 ORDER BY 1,2");
 $q->execute(['f'=>$from,'t'=>$to,'lsf'=>$from,'lst'=>$to]);$directRows=$q->fetchAll();
 $channelTotals=[];$coffeeTotals=[];$grandSold=0;$grandValue=0;
 foreach($directRows as$r){$c=$r['channel'];$ct=$r['coffee_type'];$kg=(float)$r['kgs'];$v=(float)$r['value_usd'];$channelTotals[$c]['kgs']=($channelTotals[$c]['kgs']??0)+$kg;$channelTotals[$c]['value']=($channelTotals[$c]['value']??0)+$v;$coffeeTotals[$ct]['kgs']=($coffeeTotals[$ct]['kgs']??0)+$kg;$coffeeTotals[$ct]['value']=($coffeeTotals[$ct]['value']??0)+$v;$grandSold+=$kg;$grandValue+=$v;}
 $dashboardTitle='Direct Sales — Season Summary';$dashboardSub='Sales channels and coffee type performance';
}else
if($display==='clean'){
 require_once __DIR__.'/clean_database.php'; ensure_clean_table(); $db=clean_db();
 function season_bounds($s){if(!preg_match('/^(\d{4})\/(\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-07-01',$m[2].'-06-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return $m>=7?$y.'/'.($y+1):($y-1).'/'.$y;}
 $rs=$db->query("SELECT DISTINCT EXTRACT(YEAR FROM auction_date)::int y,EXTRACT(MONTH FROM auction_date)::int m FROM public.clean_auction_results WHERE auction_date IS NOT NULL ORDER BY y DESC,m DESC")->fetchAll();
 $seasons=[];foreach($rs as $r){$y=((int)$r['m']>=7)?(int)$r['y']:(int)$r['y']-1;$seasons[$y.'/'.($y+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
 $valid="($sold AND price_per_50kg IS NOT NULL AND price_per_50kg>0)";
 $q=$db->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(auction_no),'')) FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$from,'t'=>$to]);$auctions=(int)$q->fetchColumn();
 $q=$db->prepare("SELECT COALESCE(SUM(n_kgs),0) offered,COALESCE(SUM(CASE WHEN $sold THEN n_kgs ELSE 0 END),0) sold,
 COALESCE(SUM(CASE WHEN $valid THEN n_kgs*price_per_50kg/50.0 ELSE 0 END),0) val,
 CASE WHEN SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END)>0 THEN SUM(CASE WHEN $valid THEN n_kgs*price_per_50kg ELSE 0 END)/SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END) ELSE 0 END avg
 FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$from,'t'=>$to]);$s=$q->fetch()?:[];
 $offered=(float)$s['offered'];$grandSold=(float)$s['sold'];$grandValue=(float)$s['val'];$avgPrice=(float)$s['avg'];$soldPct=pct($grandSold,$offered);
 function clean_rank($db,$f,$t,$col){if(!in_array($col,['buyer','seller'],true))return[];$sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
  $sql="SELECT COALESCE(NULLIF(BTRIM($col),''),'Unspecified') name,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) total_qty,
  SUM(CASE WHEN $sold AND price_per_50kg IS NOT NULL AND price_per_50kg>0 THEN COALESCE(n_kgs,0)*price_per_50kg/50.0 ELSE 0 END) total_value
  FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1 HAVING SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END)>0 ORDER BY total_qty DESC,total_value DESC";
  $q=$db->prepare($sql);$q->execute(['f'=>$f,'t'=>$t]);return$q->fetchAll();}
 function top5c($rows,$label){$top=array_slice($rows,0,5);$other=array_slice($rows,5);if($other){$a=['name'=>'Other ('.count($other).' '.$label.')','total_qty'=>0,'total_value'=>0,'_other'=>1];foreach($other as$r){$a['total_qty']+=(float)$r['total_qty'];$a['total_value']+=(float)$r['total_value'];}$top[]=$a;}return$top;}
 $buyersCombined=top5c(clean_rank($db,$from,$to,'buyer'),'buyers');$amcosCombined=top5c(clean_rank($db,$from,$to,'seller'),'sellers');
 $q=$db->prepare("SELECT auction_no,MIN(auction_date) auction_date,SUM(CASE WHEN $sold THEN COALESCE(n_kgs,0) ELSE 0 END) qty,
 CASE WHEN SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END)>0 THEN SUM(CASE WHEN $valid THEN n_kgs*price_per_50kg ELSE 0 END)/SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END) END avg_price
 FROM public.clean_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY auction_no ORDER BY MIN(auction_date),CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::int ELSE NULL END NULLS LAST,auction_no");
 $q->execute(['f'=>$from,'t'=>$to]);$auctionTrend=$q->fetchAll();
 $dashboardTitle='Clean Auction — Season Performance';$dashboardSub='Clean coffee auction analytical summary';$priceUnit='USD/50kg';$rank2='Top 5 Sellers';$rank2First='Seller';
}else{
 require_once __DIR__.'/kagera_database.php';ensure_kagera_table();ensure_kagera_catalogue_table();$db=kagera_db();
 function season_bounds($s){if(!preg_match('/^(\d{4})\/(\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)return null;return[$m[1].'-06-01',$m[2].'-05-30'];}
 function current_season(){$y=(int)date('Y');$m=(int)date('n');return$m>=6?$y.'/'.($y+1):($y-1).'/'.$y;}
 function coffee_case_sql(){return"CASE WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%dry cherry%' THEN 'Dry Cherry Coffee' WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%clean%' THEN 'Clean Coffee' ELSE NULL END";}
 $rs=$db->query("SELECT DISTINCT EXTRACT(YEAR FROM auction_date)::int y,EXTRACT(MONTH FROM auction_date)::int m FROM(SELECT auction_date FROM public.kagera_auction_results UNION ALL SELECT auction_date FROM public.kagera_auction_catalogue)d WHERE auction_date IS NOT NULL ORDER BY y DESC,m DESC")->fetchAll();
 $seasons=[];foreach($rs as$r){$y=((int)$r['m']>=6)?(int)$r['y']:(int)$r['y']-1;$seasons[$y.'/'.($y+1)]=1;}$seasons=array_keys($seasons);rsort($seasons);
 $season=$_GET['season']??($seasons[0]??current_season());if(!season_bounds($season))$season=$seasons[0]??current_season();[$from,$to]=season_bounds($season);
 $summary=['Dry Cherry Coffee'=>['offered'=>0,'sold'=>0,'value'=>0,'avg'=>0,'pct'=>0],'Clean Coffee'=>['offered'=>0,'sold'=>0,'value'=>0,'avg'=>0,'pct'=>0]];$case=coffee_case_sql();
 $q=$db->prepare("SELECT $case type,SUM(COALESCE(kgs,0)) offered FROM public.kagera_auction_catalogue WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to]);foreach($q as$r)if(isset($summary[$r['type']]))$summary[$r['type']]['offered']=(float)$r['offered'];
 $q=$db->prepare("SELECT $case type,SUM(COALESCE(kgs,0)) sold,SUM(COALESCE(kgs,0)*COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to]);
 foreach($q as$r){
   $type=$r['type']??null;
   if(!isset($summary[$type])) continue;
   $soldQty=(float)$r['sold'];
   $soldValue=(float)$r['val'];
   $summary[$type]['sold']=$soldQty;
   $summary[$type]['value']=$soldValue;
   $summary[$type]['avg']=$soldQty>0?$soldValue/$soldQty:0;
   $summary[$type]['pct']=pct($soldQty,$summary[$type]['offered']);
 }
 /* Freeze Kagera season totals in independent scalars.
    Do not keep a reference to $summary: later display loops reuse $x. */
 $kageraDryOffered=(float)$summary['Dry Cherry Coffee']['offered'];
 $kageraDrySold=(float)$summary['Dry Cherry Coffee']['sold'];
 $kageraDryValue=(float)$summary['Dry Cherry Coffee']['value'];
 $kageraCleanOffered=(float)$summary['Clean Coffee']['offered'];
 $kageraCleanSold=(float)$summary['Clean Coffee']['sold'];
 $kageraCleanValue=(float)$summary['Clean Coffee']['value'];

 $q=$db->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(auction_no),'')) FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$from,'t'=>$to]);$auctions=(int)$q->fetchColumn();
 $offered=$kageraDryOffered+$kageraCleanOffered;
 $grandSold=$kageraDrySold+$kageraCleanSold;
 $grandValue=$kageraDryValue+$kageraCleanValue;
 $avgPrice=$grandSold>0?$grandValue/$grandSold:0;
 $soldPct=pct($grandSold,$offered);
 function kr($db,$f,$t,$col){$case=coffee_case_sql();$sql="SELECT COALESCE(NULLIF(BTRIM($col),''),'Unspecified') name,SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) cherry_qty,SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) clean_qty,SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0) ELSE 0 END) total_qty,SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END) total_value FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t AND $case IS NOT NULL GROUP BY 1 HAVING SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0) ELSE 0 END)>0 ORDER BY total_qty DESC,total_value DESC";$q=$db->prepare($sql);$q->execute(['f'=>$f,'t'=>$t]);return$q->fetchAll();}
 function top5k($rows,$label){$top=array_slice($rows,0,5);$o=array_slice($rows,5);if($o){$a=['name'=>'Other ('.count($o).' '.$label.')','cherry_qty'=>0,'clean_qty'=>0,'total_qty'=>0,'total_value'=>0,'_other'=>1];foreach($o as$r)foreach(['cherry_qty','clean_qty','total_qty','total_value']as$k)$a[$k]+=(float)$r[$k];$top[]=$a;}return$top;}
 $buyersCombined=top5k(kr($db,$from,$to,'buyer'),'buyers');$amcosCombined=top5k(kr($db,$from,$to,'warehouse'),'AMCOS / warehouses');
 $q=$db->prepare("SELECT auction_no,MIN(auction_date) auction_date,SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) dry_qty,SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) clean_qty,CASE WHEN SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END)>0 THEN SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END)/SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) END dry_avg_price,CASE WHEN SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END)>0 THEN SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END)/SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) END clean_avg_price FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t AND $case IS NOT NULL GROUP BY auction_no ORDER BY CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::int ELSE NULL END,auction_no");
 $q->execute(['f'=>$from,'t'=>$to]);$auctionTrend=$q->fetchAll();
 $dashboardTitle='Kagera Auction — Season Performance';$dashboardSub='Compact analytical summary';$priceUnit='TZS/kg';$rank2='Top 5 AMCOS / Warehouses';$rank2First='AMCOS / Warehouse';
}
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auction Sales Dashboard</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#f6f3f1;color:#352720;font-family:Arial,sans-serif}
.dashboard{height:100vh;padding:6px 9px;display:grid;grid-template-rows:auto auto minmax(190px,.95fr) minmax(170px,1.05fr);gap:5px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px}
.title{display:flex;align-items:baseline;gap:9px;min-width:0}
.title h1{font-size:16px;margin:0;color:#3f2b24;white-space:nowrap}
.title span{font-size:10px;color:#8a7a72;white-space:nowrap}
.season{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.season label{font-size:10px;font-weight:700;color:#6b554b}
.season select{height:22px;padding:0 27px 0 9px;border:1px solid #d7ccc7;border-radius:7px;background:#fff;color:#4b3830;font-size:11px;font-weight:700}

.kpis{display:grid;grid-template-columns:100px 74px repeat(2,minmax(0,1fr));gap:5px}
.kpi{min-width:0;background:#fff;border:1px solid #e7ddd8;border-radius:7px;padding:4px 6px;box-shadow:0 1px 4px rgba(62,39,35,.03)}
.kpi .label{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.35px;color:#8a7971;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi strong{display:block;font-size:13px;color:#3f2b24;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi .sub{font-size:8px;color:#9a8b84;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi.dry{border-top:2px solid #6d4c41}.kpi.clean{border-top:2px solid #9b7b68}

.analytics{min-height:0;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.panel{min-height:0;background:#fff;border:1px solid #e7ddd8;border-radius:9px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 2px 7px rgba(62,39,35,.04)}
.panel-head{height:30px;flex:0 0 30px;padding:0 9px;display:flex;align-items:center;justify-content:space-between;background:#4b342c;color:#fff}
.panel-head strong{font-size:10px;letter-spacing:.1px}
.panel-head span{font-size:8px;color:#dfd2cc}
.table-box{min-height:0;flex:1;overflow:hidden}
table{width:100%;height:100%;border-collapse:collapse;table-layout:fixed}
thead th{height:24px;background:#f5f0ed;color:#6a5147;font-size:8px;font-weight:700;border-bottom:1px solid #e7ddd8}
th,td{padding:3px 6px;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
th:first-child,td:first-child{text-align:left}
tbody td{font-size:8px;border-bottom:1px solid #f0e9e5;color:#493931}
tbody tr:last-child td{border-bottom:0}
tbody td:first-child{font-weight:600}
.empty{text-align:center!important;color:#a2958e!important} tfoot td{font-size:9px;font-weight:700;background:#f7f3f0;border-top:1px solid #dfd5cf;color:#3f2b24}
.rank{display:inline-flex;width:15px;height:15px;border-radius:50%;align-items:center;justify-content:center;background:#eee6e1;color:#654b40;font-size:7px;margin-right:4px}
.type-tag{font-size:8px;font-weight:700;padding:2px 5px;border-radius:8px;background:#f1e9e5;color:#654a40}

.sales-ppt{height:calc(100vh - 38px);min-height:0;overflow:auto;border:1px solid #b78a62;background:#f6eee5;box-shadow:0 3px 14px rgba(74,45,25,.12);font-family:Arial,sans-serif}
.sales-ppt-header{height:120px;background:url('sales_dashboard_header.jpg') center 28%/cover no-repeat;display:flex;align-items:center;justify-content:center;text-align:center;padding-top:6px}
.sales-ppt-header .gov-title{font-weight:800;color:#9a4f18;line-height:1.55;font-size:14px;text-shadow:0 1px rgba(255,255,255,.7)}
.sales-ppt-title{text-align:center;font-size:26px;font-weight:800;color:#9a4f18;margin:3px 0 2px;letter-spacing:.3px}
.sales-ppt-body{padding:0 2px;background:rgba(255,255,255,.74)}
.sales-meta,.sales-table{width:100%;border-collapse:collapse;table-layout:fixed;color:#17110e}
.sales-meta th,.sales-meta td,.sales-table th,.sales-table td{border:1px solid #5f4a3f;padding:2px 5px;font-size:10px;line-height:1.15}
.sales-meta th,.sales-table th{font-weight:800;background:rgba(239,231,223,.78)}
.sales-band td{background:#ef8f42!important;font-weight:800;text-align:left;font-size:11px}
.sales-section{font-weight:800;text-align:left}.sales-label{font-weight:700;text-align:left}.sales-num{text-align:right;font-variant-numeric:tabular-nums}
.sales-subhead{font-weight:800;text-align:center;background:rgba(239,231,223,.82)}
.sales-grand td{font-weight:800;background:rgba(247,238,229,.9)}
.sales-production td{font-weight:700;font-style:italic}
.sales-ppt-footer{height:145px;background:url('sales_dashboard_footer.png') center 40%/cover no-repeat;position:relative}
.sales-ppt-footer .contact{position:absolute;left:20px;bottom:9px;color:#23160f;font-size:9px;line-height:1.55}.sales-ppt-footer .social{position:absolute;right:24px;bottom:22px;color:#23160f;font-size:10px;font-weight:700}
.sales-na{color:#77675f;text-align:center}
@media(max-width:850px){.sales-ppt{height:calc(100vh - 72px)}.sales-ppt-header{height:95px}.sales-ppt-title{font-size:21px}.sales-meta th,.sales-meta td,.sales-table th,.sales-table td{font-size:8px;padding:2px}.sales-ppt-footer{height:110px}}

@media(max-width:1050px){
 .kpis{grid-template-columns:repeat(4,1fr)}
 .title span{display:none}
}

.trend-panel{min-height:0;background:#fff;border:1px solid #e7ddd8;border-radius:9px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 2px 7px rgba(62,39,35,.04)}
.trend-head{height:28px;flex:0 0 28px;padding:0 10px;display:flex;align-items:center;justify-content:space-between;background:#4b342c;color:#fff}
.trend-head strong{font-size:10px}.trend-head span{font-size:8px;color:#dfd2cc}
.trend-wrap{position:relative;min-height:0;flex:1;padding:4px 8px 3px}
#auctionTrendChart{width:100%!important;height:100%!important}


.metric-group{display:grid;grid-template-columns:1.18fr 1.18fr .9fr;gap:0;background:#fff;border:1px solid #e7ddd8;border-radius:7px;overflow:hidden}
.metric-group.dry{border-top:2px solid #6d4c41}.metric-group.clean{border-top:2px solid #9b7b68}
.metric{padding:4px 7px;min-width:0;border-right:1px solid #eee7e2}.metric:last-child{border-right:0}
.metric .m-label{font-size:7px;text-transform:uppercase;letter-spacing:.25px;color:#8a7971;white-space:nowrap}
.metric strong{display:block;margin-top:2px;font-size:11px;color:#3f2b24;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.metric small{display:block;margin-top:1px;font-size:7px;color:#9a8b84;white-space:nowrap}
.metric-group .coffee-name{font-weight:700;color:#5d4439}
.panel{overflow:visible!important}
.table-box{overflow:visible!important}
table{height:auto!important}
thead th{height:20px!important;padding:2px 4px!important}
tbody td{height:20px;padding:2px 4px!important}
tfoot td{height:21px;padding:2px 4px!important;position:relative;z-index:2}


/* Responsive dashboard */
.panel{min-width:0}
.table-box{width:100%;min-width:0;overflow-x:auto!important;overflow-y:hidden!important}
table{min-width:680px}
@media (max-width:1200px){
  .dashboard{height:auto;min-height:100vh;overflow:visible;grid-template-rows:auto auto auto minmax(240px,38vh)}
  html,body{height:auto;min-height:100%;overflow:auto}
  .kpis{grid-template-columns:90px 68px 1fr 1fr}
  .analytics{grid-template-columns:1fr 1fr}
  .trend-wrap{min-height:240px}
}
@media (max-width:900px){
  .dashboard{padding:6px;display:block}
  .topbar,.kpis,.analytics,.trend-panel{margin-bottom:6px}
  .topbar{align-items:flex-start;flex-wrap:wrap}
  .title{display:block}
  .title h1{font-size:14px}
  .kpis{display:grid;grid-template-columns:1fr 1fr}
  .metric-group{grid-column:1/-1}
  .analytics{display:grid;grid-template-columns:1fr}
  .panel{margin-bottom:6px}
  .trend-panel{height:330px}
  .trend-wrap{min-height:290px}
}
@media (max-width:560px){
  .kpis{grid-template-columns:1fr 1fr}
  .metric-group{grid-template-columns:1fr 1fr 1fr}
  .metric{padding:4px}
  .metric strong{font-size:10px}
  .season{width:100%;justify-content:space-between}
  .season select{flex:1;max-width:180px}
  table{min-width:640px}
  .trend-panel{height:300px}
  .trend-head span{display:none}
}


/* Compact ranking tables */
.analytics{gap:5px}
.panel-head{padding:0 7px!important}
.table-box{overflow-x:hidden!important}
table{width:100%!important;min-width:0!important;table-layout:fixed!important}
th,td{padding:2px 3px!important}
thead th{font-size:7.5px!important;line-height:1.08!important;white-space:normal!important}
tbody td,tfoot td{font-size:7.8px!important;line-height:1.05!important}
th:nth-child(1),td:nth-child(1){width:30%;text-align:left}
th:nth-child(2),td:nth-child(2){width:13%}
th:nth-child(3),td:nth-child(3){width:12%}
th:nth-child(4),td:nth-child(4){width:13%}
th:nth-child(5),td:nth-child(5){width:22%}
th:nth-child(6),td:nth-child(6){width:10%}
.rank{width:13px!important;height:13px!important;font-size:6.5px!important;margin-right:2px!important}
tfoot td{font-weight:700!important}
@media(max-width:900px){
  .table-box{overflow-x:auto!important}
  table{min-width:590px!important}
}
@media(max-width:560px){
  table{min-width:570px!important}
}

/* Keep X-axis auction labels clearly visible */
.trend-wrap{padding:3px 8px 16px!important}
#auctionTrendChart{display:block}

@media(max-width:560px){.season{width:100%;justify-content:flex-end}.season select{max-width:145px!important}}

.direct-kpis{grid-template-columns:110px repeat(3,minmax(0,1fr))}
.direct-analytics{grid-template-columns:1fr 1fr}
@media(max-width:900px){.direct-kpis{grid-template-columns:1fr 1fr}.direct-kpis>.kpi{grid-column:1/-1}}
@media(max-width:560px){.direct-kpis,.direct-analytics{grid-template-columns:1fr}}

.totalclean-kpis{grid-template-columns:110px repeat(4,minmax(0,1fr))}
.totalclean-panel{min-height:0;width:100%;overflow:hidden}
.totalclean-panel .table-box{width:100%;overflow-x:hidden!important}
.totalclean-table{width:100%!important;min-width:0!important;table-layout:fixed!important;font-size:7.2px!important}
.totalclean-table th,.totalclean-table td{padding:3px 2px!important;line-height:1.05!important;white-space:nowrap!important}
.totalclean-table thead th{font-size:7px!important;text-align:center!important;white-space:normal!important}
.totalclean-table th:first-child,.totalclean-table td:first-child{width:8.5%!important;text-align:left!important}
.totalclean-table tbody td:not(:first-child),.totalclean-table tfoot td:not(:first-child){text-align:right}
.totalclean-table .share-row td{background:#fbf8f6;font-weight:700}
@media(max-width:1100px){
 .totalclean-kpis{grid-template-columns:1fr 1fr}
 .totalclean-kpis>.kpi{grid-column:1/-1}
 .totalclean-table th,.totalclean-table td{font-size:6.8px!important;padding:2px 1.5px!important}
}
@media(max-width:760px){
 .totalclean-panel .table-box{overflow-x:auto!important}
 .totalclean-table{min-width:760px!important}
}
@media(max-width:560px){
 .totalclean-kpis{grid-template-columns:1fr}
 .totalclean-kpis>.kpi{grid-column:auto}
 .totalclean-table{min-width:720px!important}
}

.totalclean-head{display:flex!important;align-items:center;justify-content:space-between;gap:8px}
.totalclean-head>div:first-child{display:flex;align-items:baseline;gap:8px;min-width:0}
.tc-export{position:relative;flex:0 0 auto}
.tc-export-btn{border:1px solid #6f4e37;background:#fff;color:#4b2e20;border-radius:6px;padding:4px 8px;font-size:8px;font-weight:800;cursor:pointer}
.tc-export-menu{display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:30;min-width:92px;background:#fff;border:1px solid #d8cec8;border-radius:7px;box-shadow:0 7px 20px rgba(0,0,0,.12);padding:3px}
.tc-export-menu.show{display:block}.tc-export-menu button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:6px 7px;font-size:8px;border-radius:4px;cursor:pointer}.tc-export-menu button:hover{background:#f4eee9}
@media(max-width:560px){.totalclean-head{align-items:flex-start}.totalclean-head>div:first-child{display:block}.tc-export-btn{padding:4px 6px}}

.tc-analysis-panel{margin-top:6px;min-width:0}
.tc-analysis-head{display:flex!important;justify-content:space-between;align-items:center;gap:6px}
.tc-analysis-head>div:first-child{display:flex;gap:6px;align-items:baseline;min-width:0}
.tc-analysis-controls{display:flex!important;align-items:center!important;gap:4px!important;flex-wrap:wrap;justify-content:flex-end}
.tc-analysis-select{min-width:220px;max-width:285px;padding:3px 5px;border:1px solid #d8cec8;border-radius:6px;background:#fff;font-size:7.7px}
.tc-party-search{width:155px;padding:3px 6px;border:1px solid #d8cec8;border-radius:6px;background:#fff;font-size:7.7px}
.tc-analysis-export{position:relative}
.tc-analysis-status{padding:2px 5px;color:#76655d;font-size:7.2px}
.tc-analysis-panel .table-box{width:100%;max-width:100%;overflow-x:auto!important;overflow-y:visible}
.tc-pivot-table{width:100%!important;min-width:0!important;table-layout:auto!important;border-collapse:collapse!important}
.tc-pivot-table th,.tc-pivot-table td{padding:2px 3px!important;font-size:7px!important;line-height:1.05!important;white-space:nowrap!important}
.tc-pivot-table th:first-child,.tc-pivot-table td:first-child{width:22px!important;min-width:22px!important;max-width:22px!important;text-align:center!important}
.tc-pivot-table th:nth-child(2),.tc-pivot-table td:nth-child(2){width:150px!important;min-width:120px!important;max-width:185px!important;text-align:left!important;overflow:hidden!important;text-overflow:ellipsis!important}
.tc-pivot-table td:not(:nth-child(2)){text-align:right!important}
.tc-pivot-table thead th{text-align:center!important;vertical-align:middle!important;padding-left:2px!important;padding-right:2px!important}
.tc-pivot-table thead tr:nth-child(2) th{min-width:66px!important;width:66px!important}
.tc-pivot-table .pivot-total,.tc-pivot-table tfoot td{font-weight:800}
.tc-other-row{font-weight:800;cursor:pointer;background:#faf7f5}
.tc-other-row:hover{background:#f4eee9}
.tc-other-row .tc-expand{text-align:left!important}
.tc-detail-row td:nth-child(2){padding-left:10px!important}
.tc-overall-row{font-weight:900}
@media(min-width:901px){
 .tc-pivot-table{font-size:7px!important}
}
@media(max-width:900px){
 .tc-analysis-head{flex-direction:column;align-items:stretch}
 .tc-analysis-head>div:first-child{display:block}
 .tc-analysis-controls{justify-content:flex-start}
 .tc-analysis-select,.tc-party-search{flex:1 1 190px;max-width:none}
 .tc-pivot-table{width:max-content!important;min-width:100%!important}
}
@media(max-width:560px){
 .tc-analysis-select,.tc-party-search{width:100%;min-width:0;flex-basis:100%}
 .tc-pivot-table th,.tc-pivot-table td{padding:2px!important;font-size:6.8px!important}
 .tc-pivot-table th:nth-child(2),.tc-pivot-table td:nth-child(2){width:130px!important;min-width:110px!important;max-width:150px!important}
 .tc-pivot-table thead tr:nth-child(2) th{min-width:60px!important;width:60px!important}
}
.totalclean-view-select{min-width:205px;font-weight:700}
@media(max-width:1050px){.topbar{align-items:flex-start}.season{flex-wrap:wrap;justify-content:flex-end}.season select{max-width:230px}}
@media(max-width:720px){.topbar{display:block}.topbar .title{margin-bottom:6px}.season{display:grid!important;grid-template-columns:auto minmax(0,1fr);gap:4px 6px!important;width:100%;align-items:center}.season label{margin:0!important}.season select,.totalclean-view-select{width:100%!important;max-width:none!important;min-width:0!important}}

.pre-report{min-height:0;overflow:auto;padding:4px 0 14px}.pre-paper{max-width:980px;margin:0 auto;background:#fff;padding:26px 30px 40px;color:#111;font-family:Arial,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.08)}.pre-paper h1{text-align:center;font-size:15px;margin:28px 0 12px;text-decoration:underline}.pre-paper h2{font-size:14px;margin:20px 0 8px}.pre-paper h3{font-size:13px;font-style:italic;margin:18px 0 7px}.pre-paper p{font-size:13px;line-height:1.55;margin:7px 0 14px}.pre-paper table{width:100%;height:auto;table-layout:auto;border-collapse:collapse;margin:6px 0 20px}.pre-paper th,.pre-paper td{border:1px solid #555;padding:4px 7px;font-size:12px;line-height:1.2;white-space:normal;overflow:visible;text-overflow:clip}.pre-paper th{background:#d9e8f6;color:#111;text-align:center;font-weight:700}.pre-paper td{text-align:right;color:#111}.pre-paper td:nth-child(1),.pre-paper td:nth-child(2){text-align:left}.pre-paper .gt td{font-weight:700}.pre-scroll{width:100%;overflow-x:auto}@media(max-width:720px){.pre-paper{padding:16px 12px}.pre-paper h1{font-size:14px}.pre-paper h2{font-size:13px}.pre-paper h3,.pre-paper p{font-size:11px}.pre-paper table{min-width:620px}.pre-paper th,.pre-paper td{font-size:10px;padding:3px 4px}.pre-report{overflow:auto}}
</style>
</head>
<body>
<div class="dashboard">
    <div class="topbar">
        <div class="title">
            <h1><?=htmlspecialchars($dashboardTitle)?></h1>
            <span><?=htmlspecialchars($dashboardSub)?></span>
        </div>
        <form class="season" method="get">
            <label>Season</label>
            <select name="season" onchange="this.form.submit()"><?php foreach($seasons ?: [$season] as $s): ?><option value="<?=htmlspecialchars($s)?>" <?=$s===$season?'selected':''?>><?=htmlspecialchars($s)?></option><?php endforeach ?></select>
            <label>Display</label>
            <select name="display" onchange="this.form.submit()"><option value="sales" <?=$display==='sales'?'selected':''?>>Sales Dashboard</option><option value="preauction" <?=$display==='preauction'?'selected':''?>>Pre Auction Report</option><option value="kagera" <?=$display==='kagera'?'selected':''?>>Kagera Auction</option><option value="clean" <?=$display==='clean'?'selected':''?>>Clean Auction</option><option value="direct" <?=$display==='direct'?'selected':''?>>Direct Sales Summary</option><option value="totalclean" <?=$display==='totalclean'?'selected':''?>>Total Clean Coffee Summary</option></select>
            <?php if($display==='preauction'): ?>
<section class="pre-report">
 <div class="pre-paper">
  <h2>1.1 Summary of the Last Clean Auction Conducted On <?= $preLatest?date('dS F Y',strtotime($preLatest)):'—' ?></h2>
  <p>During the last auction, <b><?=nf($lastOff)?> Kgs</b> of coffee were offered, of which <b><?=nf($lastSold)?> Kgs (<?=nf($lastPct,2)?>%)</b> were sold, generating a total value of <b>USD <?=nf($lastVal,2)?></b>. The average price achieved was <b><?=nf($lastAvg,2)?> per 50 Kgs</b>.</p>
  <div class="pre-scroll"><table><thead><tr><th>Grade</th><th>Kgs Offered</th><th>Kgs Sold</th><th>% Sold</th><th>Value (USD)</th><th>Max Price/50kg</th><th>Average Price/50kg</th><th>Min Price/50kg</th></tr></thead><tbody>
  <?php foreach($preGrades as$g):$r=$preGradeRows[$g]??null;$o=(float)($r['offered']??0);$so=(float)($r['sold']??0);?><tr><td><?=htmlspecialchars($g)?></td><td><?=$o?nf($o):'—'?></td><td><?=$so?nf($so):'—'?></td><td><?=$so?nf(pct($so,$o),2):'—'?></td><td><?=$so?nf($r['val'],2):'—'?></td><td><?=$so?nf($r['mx'],2):'—'?></td><td><?=$so?nf($r['av'],2):'—'?></td><td><?=$so?nf($r['mn'],2):'—'?></td></tr><?php endforeach;?>
  <tr class="gt"><td>Total</td><td><?=nf($lastOff)?></td><td><?=nf($lastSold)?></td><td><?=nf($lastPct,2)?></td><td><?=nf($lastVal,2)?></td><td colspan="1"></td><td><?=nf($lastAvg,2)?></td><td></td></tr></tbody></table></div>

  <h1>2.0 PRODUCTION ESTIMATE AND COFFEE SALES PERFORMANCE</h1>
  <p>The estimated production for the <?=htmlspecialchars($season)?> coffee trade season is <b>85,000 metric tons</b> of clean coffee, comprising <b>45,000 metric tons of Arabica</b> and <b>40,000 metric tons of Robusta</b>.</p>
  <h3>2.1: Table 1: Coffee Production Estimate vs. Actual Sales</h3>
  <table><thead><tr><th>Type of Coffee</th><th>Production Estimate (MT)</th><th>Actual Sales (MT)</th><th>% Sold</th></tr></thead><tbody><tr><td>Arabica</td><td>45,000</td><td><?=nf($preArabicaKg/1000,2)?></td><td><?=nf(($preArabicaKg/1000)/45000*100,2)?></td></tr><tr><td>Robusta</td><td>40,000</td><td><?=nf($preRobustaKg/1000,2)?></td><td><?=nf(($preRobustaKg/1000)/40000*100,2)?></td></tr><tr class="gt"><td>Total</td><td>85,000</td><td><?=nf($preTotalKg/1000,2)?></td><td><?=nf(($preTotalKg/1000)/85000*100,2)?></td></tr></tbody></table>
  <h3>2.2: Table 2: Breakdown of Sales by Coffee Type</h3>
  <table><thead><tr><th>Coffee Type</th><th>Quantity (MT)</th><th>Value (USD)</th><th>% Share</th></tr></thead><tbody><?php foreach(['Mild Arabica','Hard Arabica'] as$ct):?><tr><td><?=$ct?></td><td><?=nf($preCoffee[$ct]['kg']/1000,2)?></td><td><?=nf($preCoffee[$ct]['val'],2)?></td><td><?=nf(pct($preCoffee[$ct]['kg'],$preTotalKg),2)?></td></tr><?php endforeach;?><tr class="gt"><td>Total Arabica</td><td><?=nf($preArabicaKg/1000,2)?></td><td><?=nf($preArabicaVal,2)?></td><td><?=nf(pct($preArabicaKg,$preTotalKg),2)?></td></tr><tr><td>Robusta</td><td><?=nf($preRobustaKg/1000,2)?></td><td><?=nf($preRobustaVal,2)?></td><td><?=nf(pct($preRobustaKg,$preTotalKg),2)?></td></tr><tr class="gt"><td>Grand Total</td><td><?=nf($preTotalKg/1000,2)?></td><td><?=nf($preTotalVal,2)?></td><td>100</td></tr></tbody></table>

  <h1>3.0 REGIONAL COFFEE SALES</h1><h3>3.1: Table 4: Coffee Sales Per Region</h3>
  <table><thead><tr><th>No.</th><th>Region</th><th>Net Weight (Kgs)</th><th>Value (USD)</th><th>% Share</th></tr></thead><tbody><?php $i=1;foreach($preRegions as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['region'])?></td><td><?=nf($r['kg'])?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$preTotalKg),2)?></td></tr><?php endforeach;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf(array_sum(array_column($preRegions,'kg')))?></td><td><?=nf(array_sum(array_column($preRegions,'val')),2)?></td><td>100</td></tr></tbody></table>

  <h1>4.0: CLEAN COFFEE MARKET</h1><h2>4.1: Direct Export Market</h2><h3>4.1.1: Table 5: Direct Export Market Summary</h3>
  <table><thead><tr><th>Indicator</th><th>Performance</th></tr></thead><tbody><tr><td>Licensed Direct Export Companies</td><td>—</td></tr><tr><td>Active Participants/Exporters</td><td><?=nf($preDE['active']??0)?></td></tr><tr><td>Overseas Buyers</td><td><?=nf($preDE['buyers']??0)?></td></tr><tr><td>Total DE sold</td><td><?=nf(($preDE['kg']??0)/1000,2)?> MT</td></tr><tr><td>Contribution to Total Coffee Sold</td><td><?=nf(pct($preDE['kg']??0,$preTotalKg),2)?>%</td></tr><tr><td>Total Export Value</td><td>USD <?=nf($preDE['val']??0,2)?></td></tr></tbody></table>
  <h3>4.1.2: Table 6: Top 10 Direct Export Coffee sellers.</h3><?php $top=array_slice($preDESellers,0,10);$oth=array_slice($preDESellers,10);?><table><thead><tr><th>S/N</th><th>Seller Name</th><th>N/Weight (MT)</th><th>Value (USD)</th><th>% Share</th></tr></thead><tbody><?php $i=1;foreach($top as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['kg']/1000,2)?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$preDE['kg']??0),2)?></td></tr><?php endforeach;if($oth):$ok=array_sum(array_column($oth,'kg'));$ov=array_sum(array_column($oth,'val'));?><tr><td><?=$i?></td><td>Others</td><td><?=nf($ok/1000,2)?></td><td><?=nf($ov,2)?></td><td><?=nf(pct($ok,$preDE['kg']??0),2)?></td></tr><?php endif;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf(($preDE['kg']??0)/1000,2)?></td><td><?=nf($preDE['val']??0,2)?></td><td>100</td></tr></tbody></table>
  <h3>4.1.3: Table 7: Top 10 Direct Export Coffee Buyers.</h3><?php $top=array_slice($preDEBuyers,0,10);$oth=array_slice($preDEBuyers,10);?><table><thead><tr><th>S/N</th><th>Buyer</th><th>Net Weight (MT)</th><th>Value (USD)</th><th>% Share</th></tr></thead><tbody><?php $i=1;foreach($top as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['kg']/1000,2)?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$preDE['kg']??0),2)?></td></tr><?php endforeach;if($oth):$ok=array_sum(array_column($oth,'kg'));$ov=array_sum(array_column($oth,'val'));?><tr><td></td><td>Others</td><td><?=nf($ok/1000,2)?></td><td><?=nf($ov,2)?></td><td><?=nf(pct($ok,$preDE['kg']??0),2)?></td></tr><?php endif;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf(($preDE['kg']??0)/1000,2)?></td><td><?=nf($preDE['val']??0,2)?></td><td>100</td></tr></tbody></table>

  <h2>4.2. Clean Coffee Auction Market</h2><p>As of <?=date('dS F Y',strtotime($preEnd))?>, <b><?=nf($preClean['auctions']??0)?> clean coffee auctions</b> have been conducted whereby about <b><?=nf($preClean['offered']??0)?> Kgs</b> were offered and <b><?=nf($preClean['sold']??0)?> Kgs (<?=nf(pct($preClean['sold']??0,$preClean['offered']??0),2)?>%)</b> were sold corresponding to a value of <b>USD <?=nf($preClean['val']??0,2)?></b>.</p>
  <h3>4.2.2: Table 8: Coffee Clean Auction Buyers Season <?=htmlspecialchars($season)?> Ending <?=date('dS F Y',strtotime($preEnd))?>.</h3><table><thead><tr><th>S/No</th><th>Buyer</th><th>Net Weight (MT)</th><th>Value (USD)</th><th>% Share</th></tr></thead><tbody><?php $i=1;$cbkg=array_sum(array_column($preCleanBuyers,'kg'));$cbv=array_sum(array_column($preCleanBuyers,'val'));foreach($preCleanBuyers as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['kg']/1000,3)?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$cbkg),2)?>%</td></tr><?php endforeach;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf($cbkg/1000,3)?></td><td><?=nf($cbv,2)?></td><td>100%</td></tr></tbody></table>

  <h1>5.0 KAGERA CHERRY AUCTION</h1><p>As of <?=date('dS F Y',strtotime($preEnd))?>, <b><?=nf($preK['auctions']??0)?> auctions</b> have been conducted in Kagera Region involving <b><?=nf($preK['buyers']??0)?> buyers</b>. Out of <b><?=nf($preKOff)?> Kgs offered</b>, <b><?=nf($preK['sold']??0)?> Kgs (<?=nf(pct($preK['sold']??0,$preKOff),2)?>%)</b> were sold. This included <b><?=nf($preK['drykg']??0)?> Kgs</b> of coffee cherry, valued at <b>TZS <?=nf($preK['dryval']??0,2)?></b> and <b><?=nf($preK['cleankg']??0)?> Kgs</b> of clean coffee, valued at <b>TZS <?=nf($preK['cleanval']??0,2)?></b>.</p>
  <h2>5.1 Coffee Cherry Sales Summary</h2><h3>5.1.1: Table 9: Cherry Coffee Sales by Type Season <?=htmlspecialchars($season)?> Up to <?=date('dS F Y',strtotime($preEnd))?>.</h3><table><thead><tr><th>S/N</th><th>Type</th><th>Weight (Kgs)</th><th>Value (TZS)</th><th>% Share</th></tr></thead><tbody><?php $i=1;$kt=array_sum(array_column($preKTypes,'kg'));$kv=array_sum(array_column($preKTypes,'val'));foreach($preKTypes as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['type'])?></td><td><?=nf($r['kg'])?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$kt),2)?></td></tr><?php endforeach;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf($kt)?></td><td><?=nf($kv,2)?></td><td>100</td></tr></tbody></table>
  <h3>5.1.1: Table 10: Cherry Coffee Sales per Buyers Season <?=htmlspecialchars($season)?> Up to <?=date('dS F Y',strtotime($preEnd))?></h3><table><thead><tr><th>No.</th><th>Buyer</th><th>Weight (Kgs)</th><th>Value (TZS)</th><th>% Share</th></tr></thead><tbody><?php $i=1;$kbkg=array_sum(array_column($preKBuyers,'kg'));$kbv=array_sum(array_column($preKBuyers,'val'));foreach($preKBuyers as$r):?><tr><td><?=$i++?></td><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['kg'])?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['kg'],$kbkg),2)?></td></tr><?php endforeach;?><tr class="gt"><td colspan="2">Grand Total</td><td><?=nf($kbkg)?></td><td><?=nf($kbv,2)?></td><td>100</td></tr></tbody></table>
 </div>
</section>
<?php elseif($display==='sales'): ?>
<section class="sales-ppt">
 <div class="sales-ppt-header"><div class="gov-title">THE UNITED REPUBLIC OF TANZANIA<br>MINISTRY OF AGRICULTURE<br>TANZANIA COFFEE BOARD</div></div>
 <div class="sales-ppt-title">SALES DASHBOARD</div>
 <div class="sales-ppt-body">
  <table class="sales-meta"><tr><th>Sale Season (FY)</th><td><b><?=htmlspecialchars($season)?></b></td><th>End Date</th><td><b><?=date('d/m/Y',strtotime($salesEnd))?></b></td></tr><tr><th>Terminal Market</th><td>Arabica ($/kg) &nbsp; <b>7.5</b></td><td colspan="2">Robusta ($/kg) &nbsp; <b>3.6</b></td></tr></table>
  <table class="sales-table">
   <tr class="sales-band"><td colspan="5">Farmgate Market</td></tr>
   <tr><th style="width:29%">1&nbsp; Kagera Auction</th><th>This Week (MT)</th><th>Value (TZS)</th><th>Season Total (MT)</th><th>Total Value (TZS)</th></tr>
   <?php foreach(['Dry Cherry','Clean Coffee'] as$ct):$r=$salesFarm[$ct];?><tr><td class="sales-label"><?=htmlspecialchars($ct)?></td><td class="sales-num"><?=nf($r['weekkg']/1000,3)?></td><td class="sales-num"><?=nf($r['weekval'],2)?></td><td class="sales-num"><?=nf($r['seasonkg']/1000,3)?></td><td class="sales-num"><?=nf($r['seasonval'],2)?></td></tr><?php endforeach;?>
   <tr><td class="sales-label">2&nbsp; Certified Coffee</td><td class="sales-na">—</td><td class="sales-na">—</td><td class="sales-na">—</td><td class="sales-na">—</td></tr>
   <tr><td class="sales-label">3&nbsp; Parchment</td><td class="sales-na">—</td><td class="sales-na">—</td><td class="sales-na">—</td><td class="sales-na">—</td></tr>
   <tr class="sales-band"><td colspan="5">Clean Coffee Market</td></tr>
   <?php foreach($channels as$ci=>$ch): ?>
   <tr><th><?=$ci+1?>&nbsp; <?=htmlspecialchars($ch)?></th><th>This Week (MT)</th><th>Value ($)</th><th>Season Total (MT)</th><th>Total Value ($)</th></tr>
   <?php foreach($coffeeTypes as$ct):$r=$salesClean[$ch][$ct];?><tr><td class="sales-label"><?=htmlspecialchars($ct)?></td><td class="sales-num"><?=$r['weekkg']?nf($r['weekkg']/1000,2):'—'?></td><td class="sales-num"><?=$r['weekval']?nf($r['weekval'],2):'—'?></td><td class="sales-num"><?=$r['seasonkg']?nf($r['seasonkg']/1000,2):'—'?></td><td class="sales-num"><?=$r['seasonval']?nf($r['seasonval'],2):'—'?></td></tr><?php endforeach;?>
   <?php endforeach;?>
   <tr class="sales-grand"><td colspan="3">Grand Total (Clean Auctions, Direct Export, Local Roast and Local Sale)</td><td class="sales-num"><?=nf($salesGrandKg/1000,2)?></td><td class="sales-num"><?=nf($salesGrandVal,2)?></td></tr>
   <tr class="sales-band"><td colspan="5">Production</td></tr>
   <tr class="sales-production"><td colspan="3">Estimated Production (MT)</td><td colspan="2" class="sales-num"><?=nf($estimatedProduction,2)?></td></tr>
   <tr class="sales-production"><td colspan="3">Percentage Achieved</td><td colspan="2" class="sales-num"><?=nf($productionPct,2)?>%</td></tr>
  </table>
 </div>
 <div class="sales-ppt-footer"><div class="contact">www.coffee.go.tz<br>info@coffee.go.tz<br>+255 27 2752324</div><div class="social">coffeeboardtz</div></div>
</section>
<?php elseif($display==='totalclean'): ?>
            <label>View</label>
            <select name="totalclean_view" class="totalclean-view-select" onchange="this.form.submit()">
              <option value="summary" <?=$totalCleanView==='summary'?'selected':''?>>Coffee Sales Summary</option>
              <option value="analysis" <?=$totalCleanView==='analysis'?'selected':''?>>Buyer / Supplier-Seller Analysis</option>
            </select>
            <?php endif; ?>
        </form>
    </div>


<?php if($display==='totalclean'): ?>
<div class="kpis totalclean-kpis">
 <div class="kpi"><span class="label">Season</span><strong><?=htmlspecialchars($season)?></strong><div class="sub"><?=date('d M Y',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></div></div>
 <?php foreach($channels as$ch):$x=$tcChannelTotals[$ch];?><div class="metric-group"><div class="metric"><span class="m-label coffee-name"><?=htmlspecialchars($ch)?></span><strong><?=nf($x['kgs'],2)?> kg</strong><small><?=nf(pct($x['kgs'],$tcGrandKg),2)?>% of total</small></div><div class="metric"><span class="m-label">Value</span><strong><?=nf($x['value'],2)?></strong><small>USD</small></div><div class="metric"><span class="m-label">Avg. Price</span><strong><?=nf($x['avg'],2)?></strong><small>USD/50kg</small></div></div><?php endforeach;?>
</div>
<?php if($totalCleanView==='summary'): ?>
<section class="panel totalclean-panel"><div class="panel-head totalclean-head"><div><strong>Coffee Sales Summary</strong><span>Clean coffee · <?=date('d M Y',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></span></div><div class="tc-export"><button type="button" id="tcExportBtn" class="tc-export-btn" title="Export Coffee Sales Summary">⇩ Export</button><div id="tcExportMenu" class="tc-export-menu"><button type="button" data-format="pdf">PDF</button><button type="button" data-format="xlsx">Excel</button><button type="button" data-format="doc">Word</button></div></div></div><div class="table-box"><table id="totalCleanSalesTable" class="totalclean-table">
<thead><tr><th rowspan="2">Type of Coffee</th><?php foreach($channels as$ch):?><th colspan="3"><?=htmlspecialchars($ch)?></th><?php endforeach;?><th colspan="3">Total</th></tr><tr><?php foreach($channels as$ch):?><th>Kg</th><th>USD</th><th>$/50kg</th><?php endforeach;?><th>Kg</th><th>USD</th><th>%</th></tr></thead><tbody>
<?php foreach($types as$ct):$rk=$rv=0;foreach($channels as$ch){$rk+=$totalClean[$ct][$ch]['kgs'];$rv+=$totalClean[$ct][$ch]['value'];}?><tr><td><?=htmlspecialchars($ct)?></td><?php foreach($channels as$ch):$x=$totalClean[$ct][$ch];?><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf($x['avg'],2)?></td><?php endforeach;?><td><?=nf($rk,2)?></td><td><?=nf($rv,2)?></td><td><?=nf(pct($rk,$tcGrandKg),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td>Grand Total</td><?php foreach($channels as$ch):$x=$tcChannelTotals[$ch];?><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf($x['avg'],2)?></td><?php endforeach;?><td><?=nf($tcGrandKg,2)?></td><td><?=nf($tcGrandValue,2)?></td><td><?=$tcGrandKg>0?'100%':'0%'?></td></tr><tr class="share-row"><td>Channel Share</td><?php foreach($channels as$ch):$x=$tcChannelTotals[$ch];?><td><?=nf(pct($x['kgs'],$tcGrandKg),2)?>%</td><td colspan="2"></td><?php endforeach;?><td colspan="3"></td></tr></tfoot>
</table></div></section>
<?php else: ?>
<section class="panel tc-analysis-panel">
 <div class="panel-head tc-analysis-head">
  <div><strong>Buyer / Supplier-Seller Analysis</strong><span>Top 10 + Other + Overall Total · Kg and Value (USD)</span></div>
  <div class="tc-analysis-controls">
   <select id="tcAnalysisType" class="tc-analysis-select">
    <option value="buyer_channel">Buyers · Sales Channels as Columns</option>
    <option value="supplier_channel">Suppliers/Sellers · Sales Channels as Columns</option>
    <option value="buyer_region">Buyers · Regions as Columns</option>
    <option value="supplier_region">Suppliers/Sellers · Regions as Columns</option>
    <option value="buyer_type">Buyers · Coffee Types as Columns</option>
    <option value="supplier_type">Suppliers/Sellers · Coffee Types as Columns</option>
   </select>
   <input id="tcPartySearch" class="tc-party-search" type="search" placeholder="Search buyer / supplier..." autocomplete="off">
   <div class="tc-analysis-export"><button type="button" id="tcAnalysisExportBtn" class="tc-export-btn">⇩ Export</button><div id="tcAnalysisExportMenu" class="tc-export-menu"><button type="button" data-format="pdf">PDF</button><button type="button" data-format="xlsx">Excel</button><button type="button" data-format="doc">Word</button></div></div>
  </div>
 </div>
 <div id="tcAnalysisStatus" class="tc-analysis-status"></div>
 <div id="tcAnalysisTable" class="table-box"></div>
</section>
<?php endif; ?>
<?php elseif($display==='direct'): ?>
<div class="kpis direct-kpis">
 <div class="kpi"><span class="label">Season</span><strong><?=htmlspecialchars($season)?></strong><div class="sub"><?=date('d M Y',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></div></div>
 <?php foreach(['Direct Export','Local Sale','Local Roast'] as $ch):$x=$channelTotals[$ch]??['kgs'=>0,'value'=>0];?>
 <div class="metric-group <?=$ch==='Direct Export'?'dry':'clean'?>"><div class="metric"><span class="m-label coffee-name"><?=htmlspecialchars($ch)?></span><strong><?=nf($x['kgs'],2)?> kg</strong><small>Net weight</small></div><div class="metric"><span class="m-label">Value</span><strong><?=nf($x['value'],2)?></strong><small>USD</small></div><div class="metric"><span class="m-label">Qty Share</span><strong><?=nf(pct($x['kgs'],$grandSold),2)?>%</strong><small>of total</small></div></div>
 <?php endforeach;?>
</div>
<div class="analytics direct-analytics">
<section class="panel"><div class="panel-head"><strong>Sales Channels Summary</strong><span>Quantity & value</span></div><div class="table-box"><table>
<thead><tr><th>Sales Channel</th><th>Net Weight (kg)</th><th>Value USD</th><th>Qty Share</th></tr></thead><tbody>
<?php foreach(['Direct Export','Local Sale','Local Roast'] as $ch):$x=$channelTotals[$ch]??['kgs'=>0,'value'=>0];?><tr><td><?=htmlspecialchars($ch)?></td><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf(pct($x['kgs'],$grandSold),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td>Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot></table></div></section>
<section class="panel"><div class="panel-head"><strong>Sales by Coffee Type</strong><span>All direct sales channels</span></div><div class="table-box"><table>
<thead><tr><th>Coffee Type</th><th>Net Weight (kg)</th><th>Value USD</th><th>Qty Share</th></tr></thead><tbody>
<?php foreach($coffeeTotals as $ct=>$x):?><tr><td><?=htmlspecialchars($ct)?></td><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf(pct($x['kgs'],$grandSold),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td>Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot></table></div></section>
</div>
<section class="trend-panel"><div class="trend-head"><strong>Sales Channel × Coffee Type</strong><span>Net weight (kg) and value (USD)</span></div><div class="table-box"><table>
<thead><tr><th>Sales Channel</th><th>Coffee Type</th><th>Net Weight (kg)</th><th>Value USD</th><th>Qty Share</th></tr></thead><tbody>
<?php foreach($directRows as$r):?><tr><td><?=htmlspecialchars($r['channel'])?></td><td><?=htmlspecialchars($r['coffee_type'])?></td><td><?=nf($r['kgs'],2)?></td><td><?=nf($r['value_usd'],2)?></td><td><?=nf(pct((float)$r['kgs'],$grandSold),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td colspan="2">Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot></table></div></section>
<?php else: ?>
    <div class="kpis">
 <div class="kpi"><span class="label">Season</span><strong><?=htmlspecialchars($season)?></strong><div class="sub"><?=date('d M',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></div></div>
 <div class="kpi"><span class="label">Auctions</span><strong><?=nf($auctions)?></strong><div class="sub">Held</div></div>
 <?php if($display==='clean'): ?>
 <div class="metric-group dry"><div class="metric"><span class="m-label coffee-name">Clean Coffee · Offered</span><strong><?=nf($offered)?> kg</strong><small>All lots</small></div><div class="metric"><span class="m-label">Sold</span><strong><?=nf($grandSold)?> kg</strong><small><?=nf($soldPct,2)?>% of offered</small></div><div class="metric"><span class="m-label">Avg. Price</span><strong><?=nf($avgPrice,2)?></strong><small><?=$priceUnit?></small></div></div>
 <div class="metric-group clean"><div class="metric"><span class="m-label coffee-name">Sales Value</span><strong><?=nf($grandValue,2)?></strong><small>USD</small></div><div class="metric"><span class="m-label">Unsold</span><strong><?=nf(max(0,$offered-$grandSold))?> kg</strong><small><?=nf(max(0,100-$soldPct),2)?>%</small></div><div class="metric"><span class="m-label">Sale Rate</span><strong><?=nf($soldPct,2)?>%</strong><small>Season</small></div></div>
 <?php else: foreach(['Dry Cherry Coffee','Clean Coffee'] as $type):$x=$summary[$type];$cls=$type==='Dry Cherry Coffee'?'dry':'clean'; ?>
 <div class="metric-group <?=$cls?>"><div class="metric"><span class="m-label coffee-name"><?=$type==='Dry Cherry Coffee'?'Dry Cherry':'Clean Coffee'?> · Offered</span><strong><?=nf($x['offered'])?> kg</strong><small>Catalogue</small></div><div class="metric"><span class="m-label">Sold</span><strong><?=nf($x['sold'])?> kg</strong><small><?=nf($x['pct'],2)?>%</small></div><div class="metric"><span class="m-label">Avg. Price</span><strong><?=nf($x['avg'],2)?></strong><small><?=$priceUnit?></small></div></div>
 <?php endforeach;endif;?>
 </div>
 <div class="analytics">
 <?php foreach([['Top 5 Buyers',$buyersCombined,'Buyer'],[$rank2,$amcosCombined,$rank2First]] as[$heading,$rows,$firstLabel]):?>
 <section class="panel"><div class="panel-head"><strong><?=$heading?></strong><span>Ranked by sold quantity</span></div><div class="table-box"><table>
 <?php if($display==='clean'):?>
 <thead><tr><th><?=$firstLabel?></th><th>Sold Qty<br>(kg)</th><th>Value<br>(USD)</th><th>Share<br>(%)</th></tr></thead><tbody>
 <?php if(!$rows):?><tr><td colspan="4" class="empty">No sold data for this season</td></tr><?php endif;foreach($rows as$i=>$r):?><tr><td title="<?=htmlspecialchars($r['name'])?>"><?php if(empty($r['_other'])):?><span class="rank"><?=$i+1?></span><?php endif;?><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['total_qty'],2)?></td><td><?=nf($r['total_value'],2)?></td><td><?=nf(pct((float)$r['total_qty'],$grandSold),2)?>%</td></tr><?php endforeach;?></tbody><tfoot><tr><td>Season Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot>
 <?php else:?>
 <thead><tr><th><?=$firstLabel?></th><th>Dry Cherry<br>(kg)</th><th>Clean<br>(kg)</th><th>Total<br>(kg)</th><th>Value<br>(TZS)</th><th>Share<br>(%)</th></tr></thead><tbody>
 <?php if(!$rows):?><tr><td colspan="6" class="empty">No sales data for this season</td></tr><?php endif;foreach($rows as$i=>$r):?><tr><td title="<?=htmlspecialchars($r['name'])?>"><?php if(empty($r['_other'])):?><span class="rank"><?=$i+1?></span><?php endif;?><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['cherry_qty'],2)?></td><td><?=nf($r['clean_qty'],2)?></td><td><?=nf($r['total_qty'],2)?></td><td><?=nf($r['total_value'],2)?></td><td><?=nf(pct((float)$r['total_qty'],$grandSold),2)?>%</td></tr><?php endforeach;?></tbody><tfoot><tr><td>Season Grand Total</td><td><?=nf($kageraDrySold,2)?></td><td><?=nf($kageraCleanSold,2)?></td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot>
 <?php endif;?></table></div></section><?php endforeach;?></div>
<section class="trend-panel">
 <div class="trend-head">
  <strong>Auction Performance Trend</strong>
  <span><?=$display==='clean'?'Clean Coffee · quantity sold & weighted average price':'Dry Cherry and Clean Coffee · quantity sold & weighted average price'?></span>
 </div>
 <div class="trend-wrap"><canvas id="auctionTrendChart"></canvas></div>
</section>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.4/dist/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function(){
 const sel=document.getElementById('tcAnalysisType'),box=document.getElementById('tcAnalysisTable'),
 search=document.getElementById('tcPartySearch'),status=document.getElementById('tcAnalysisStatus'),
 exportBtn=document.getElementById('tcAnalysisExportBtn'),exportMenu=document.getElementById('tcAnalysisExportMenu');
 if(!sel||!box)return;
 const D=<?=json_encode($display==='totalclean'?$tcAnalytics:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const M={
  buyer_channel:{party:'buyer',dim:'channel',partyLabel:'Buyer',dimLabel:'Sales Channel'},
  supplier_channel:{party:'supplier',dim:'channel',partyLabel:'Supplier / Seller',dimLabel:'Sales Channel'},
  buyer_region:{party:'buyer',dim:'region',partyLabel:'Buyer',dimLabel:'Region'},
  supplier_region:{party:'supplier',dim:'region',partyLabel:'Supplier / Seller',dimLabel:'Region'},
  buyer_type:{party:'buyer',dim:'coffee_type',partyLabel:'Buyer',dimLabel:'Coffee Type'},
  supplier_type:{party:'supplier',dim:'coffee_type',partyLabel:'Supplier / Seller',dimLabel:'Coffee Type'}
 };
 const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
 const fmt=(v,d=2)=>{v=Number(v)||0;return v.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:Number.isInteger(v)?0:Math.min(d,2)})};
 let expanded=false,current={};

 function build(){
  const cfg=M[sel.value], all=D[sel.value]||[], q=(search?.value||'').trim().toLowerCase();
  const partyTotals=new Map(),cells=new Map(),dimTotals=new Map();
  all.forEach(r=>{
   const p=r[cfg.party]||'Unspecified',d=r[cfg.dim]||'Unspecified',kg=+r.kgs||0,val=+r.value_usd||0;
   let x=partyTotals.get(p)||{kg:0,val:0};x.kg+=kg;x.val+=val;partyTotals.set(p,x);
   x=cells.get(p+'\x1f'+d)||{kg:0,val:0};x.kg+=kg;x.val+=val;cells.set(p+'\x1f'+d,x);
   x=dimTotals.get(d)||{kg:0,val:0};x.kg+=kg;x.val+=val;dimTotals.set(d,x);
  });
  const allParties=[...partyTotals.keys()].sort((a,b)=>partyTotals.get(b).kg-partyTotals.get(a).kg);
  const filtered=q?allParties.filter(p=>p.toLowerCase().includes(q)):allParties;
  const top=q?filtered:allParties.slice(0,10), others=q?[]:allParties.slice(10);
  let dims=[...dimTotals.keys()];
  if(cfg.dim==='channel'){const o=['Auction Sale','Direct Export','Local Sale','Local Roast'];dims.sort((a,b)=>(o.indexOf(a)<0?999:o.indexOf(a))-(o.indexOf(b)<0?999:o.indexOf(b)));}
  else dims.sort((a,b)=>dimTotals.get(b).kg-dimTotals.get(a).kg);
  current={cfg,allParties,filtered,top,others,dims,partyTotals,cells,q};
 }

 function row(p,label,cls=''){
  const {dims,partyTotals,cells}=current,pt=partyTotals.get(p)||{kg:0,val:0};
  let h=`<tr class="${cls}"><td>${label}</td><td title="${esc(p)}">${esc(p)}</td>`;
  dims.forEach(d=>{const c=cells.get(p+'\x1f'+d)||{kg:0,val:0};h+=`<td>${fmt(c.kg,3)}</td><td>${fmt(c.val,2)}</td>`});
  return h+`<td class="pivot-total">${fmt(pt.kg,3)}</td><td class="pivot-total">${fmt(pt.val,2)}</td></tr>`;
 }
 function aggregate(parties){
  const a={kg:0,val:0,by:{}};
  current.dims.forEach(d=>a.by[d]={kg:0,val:0});
  parties.forEach(p=>{const pt=current.partyTotals.get(p)||{kg:0,val:0};a.kg+=pt.kg;a.val+=pt.val;current.dims.forEach(d=>{const c=current.cells.get(p+'\x1f'+d)||{kg:0,val:0};a.by[d].kg+=c.kg;a.by[d].val+=c.val})});
  return a;
 }
 function aggRow(name,a,cls=''){
  let h=`<tr class="${cls}"><td></td><td>${esc(name)}</td>`;
  current.dims.forEach(d=>h+=`<td>${fmt(a.by[d].kg,3)}</td><td>${fmt(a.by[d].val,2)}</td>`);
  return h+`<td>${fmt(a.kg,3)}</td><td>${fmt(a.val,2)}</td></tr>`;
 }
 function draw(){
  build();const {cfg,top,others,dims,q,filtered,allParties}=current;
  let h=`<table id="tcPivotExportTable" class="tc-pivot-table"><thead><tr><th rowspan="2">#</th><th rowspan="2">${esc(cfg.partyLabel)}</th>`;
  dims.forEach(d=>h+=`<th colspan="2">${esc(d)}</th>`);h+='<th colspan="2">Total</th></tr><tr>';
  dims.forEach(()=>h+='<th>Kg</th><th>Value USD</th>');h+='<th>Kg</th><th>Value USD</th></tr></thead><tbody>';
  top.forEach((p,i)=>h+=row(p,i+1));
  if(!q&&others.length){
   const a=aggregate(others);
   h+=`<tr id="tcOtherToggle" class="tc-other-row"><td>${expanded?'−':'+'}</td><td class="tc-expand">Other (${others.length} ${cfg.partyLabel.toLowerCase()}${others.length===1?'':'s'})</td>`;
   dims.forEach(d=>h+=`<td>${fmt(a.by[d].kg,3)}</td><td>${fmt(a.by[d].val,2)}</td>`);
   h+=`<td>${fmt(a.kg,3)}</td><td>${fmt(a.val,2)}</td></tr>`;
   if(expanded)others.forEach((p,i)=>h+=row(p,11+i,'tc-detail-row'));
  }
  if(q&&!filtered.length)h+=`<tr><td colspan="${4+dims.length*2}" style="text-align:center">No matching ${esc(cfg.partyLabel.toLowerCase())} found.</td></tr>`;
  h+='</tbody><tfoot>'+aggRow(q?`Filtered Total (${filtered.length})`:'Overall Total',aggregate(q?filtered:allParties),'tc-overall-row')+'</tfoot></table>';
  box.innerHTML=h;
  status.textContent=q?`${filtered.length} matching ${cfg.partyLabel.toLowerCase()} record(s) · export will use the filtered result.`:`Showing Top ${Math.min(10,allParties.length)} of ${allParties.length} ${cfg.partyLabel.toLowerCase()} records · ${others.length} other${others.length===1?'':'s'}.`;
  document.getElementById('tcOtherToggle')?.addEventListener('click',()=>{expanded=!expanded;draw()});
 }
 sel.addEventListener('change',()=>{expanded=false;if(search)search.value='';draw()});
 search?.addEventListener('input',()=>{expanded=false;draw()});
 draw();

 if(exportBtn&&exportMenu){
  exportBtn.addEventListener('click',e=>{e.stopPropagation();exportMenu.classList.toggle('show')});
  document.addEventListener('click',()=>exportMenu.classList.remove('show'));
  const exportTable=()=>document.getElementById('tcPivotExportTable');
  const fname=ext=>`Clean_Coffee_${sel.value}_${search?.value?'Filtered':'Top10'}_<?=preg_replace('/[^0-9A-Za-z_-]/','_', $season)?>.${ext}`;
  function xlsx(){
   const table=exportTable();if(!table||!window.XLSX)return;
   const wb=XLSX.utils.table_to_book(table,{sheet:'Analysis',raw:true});
   const ws=wb.Sheets.Analysis,range=XLSX.utils.decode_range(ws['!ref']);
   for(let r=2;r<=range.e.r;r++)for(let c=2;c<=range.e.c;c++){const a=XLSX.utils.encode_cell({r,c}),cell=ws[a];if(!cell)continue;const n=Number(String(cell.v).replace(/,/g,''));if(Number.isFinite(n)){cell.v=n;cell.t='n';cell.z='#,##0.00####';}}
   XLSX.writeFile(wb,fname('xlsx'));
  }
  function pdf(){
   const table=exportTable();if(!table||!window.jspdf)return;const {jsPDF}=window.jspdf,doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
   doc.setFontSize(11);doc.text('Clean Coffee Buyer / Supplier-Seller Analysis',10,10);
   doc.setFontSize(7);doc.text(`Sale Season <?=htmlspecialchars($season)?> · ${sel.options[sel.selectedIndex].text}${search?.value?' · Filter: '+search.value:''}`,10,15);
   doc.autoTable({html:table,startY:19,theme:'grid',styles:{fontSize:4.8,cellPadding:.8},headStyles:{fontStyle:'bold'},footStyles:{fontStyle:'bold'}});
   doc.save(fname('pdf'));
  }
  function word(){
   const table=exportTable();if(!table)return;const clone=table.cloneNode(true);clone.querySelectorAll('th,td').forEach(c=>c.style.cssText='border:1px solid #555;padding:3px;font-family:Arial;font-size:8pt');clone.style.cssText='border-collapse:collapse;width:100%';
   const html=`<html><head><meta charset="utf-8"></head><body><h3>Clean Coffee Buyer / Supplier-Seller Analysis</h3><p>Sale Season <?=htmlspecialchars($season)?> · ${esc(sel.options[sel.selectedIndex].text)}${search?.value?' · Filter: '+esc(search.value):''}</p>${clone.outerHTML}</body></html>`;
   const b=new Blob(['\ufeff',html],{type:'application/msword'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=fname('doc');a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500);
  }
  exportMenu.addEventListener('click',e=>{const f=e.target.dataset.format;if(!f)return;e.stopPropagation();exportMenu.classList.remove('show');f==='xlsx'?xlsx():f==='pdf'?pdf():word()});
 }
})();
</script>

<script>
(function(){
 const btn=document.getElementById('tcExportBtn'),menu=document.getElementById('tcExportMenu'),table=document.getElementById('totalCleanSalesTable');
 if(!btn||!menu||!table)return;
 const season=<?=json_encode($season)?>;
 const safe=s=>String(s).replace(/[^A-Za-z0-9_-]+/g,'_');
 const filename=ext=>`Coffee_Sales_Summary_${safe(season)}.${ext}`;
 btn.addEventListener('click',e=>{e.stopPropagation();menu.classList.toggle('show')});
 document.addEventListener('click',()=>menu.classList.remove('show'));

 function matrix(){
   return [...table.rows].map(r=>[...r.cells].map(c=>c.innerText.trim()));
 }
 function numeric(v,percent=false){
   if(v===''||v==='-')return null;
   const n=Number(String(v).replace(/,/g,'').replace(/%/g,'').trim());
   return Number.isFinite(n)?(percent?n/100:n):v;
 }
 function exportExcel(){
   if(!window.XLSX){alert('Excel export library is not available.');return}
   const aoa=matrix();
   const ws=XLSX.utils.aoa_to_sheet(aoa);
   const range=XLSX.utils.decode_range(ws['!ref']);
   // Rows 3 onward contain data. First column is text; all remaining populated cells are numeric.
   for(let r=2;r<=range.e.r;r++){
     for(let c=1;c<=range.e.c;c++){
       const a=XLSX.utils.encode_cell({r,c}),cell=ws[a];
       if(!cell||cell.v==='')continue;
       const isPct=(c===range.e.c)||(aoa[r]&&aoa[r][0]==='Channel Share'&&(c===1||c===4||c===7||c===10));
       const n=numeric(cell.v,isPct);
       if(typeof n==='number'){cell.v=n;cell.t='n';cell.z=isPct?'0.00%':'#,##0.00####';}
     }
   }
   ws['!cols']=[{wch:18},...Array(15).fill({wch:12})];
   const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Coffee Sales Summary');
   XLSX.writeFile(wb,filename('xlsx'),{cellStyles:true});
 }
 function exportPDF(){
   if(!window.jspdf||!window.jspdf.jsPDF){alert('PDF export library is not available.');return}
   const {jsPDF}=window.jspdf,doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
   doc.setFont('helvetica','bold');doc.setFontSize(13);doc.text('Coffee Sales Summary',14,13);
   doc.setFont('helvetica','normal');doc.setFontSize(8);doc.text(`Clean coffee · Sale Season ${season}`,14,18);
   doc.autoTable({html:'#totalCleanSalesTable',startY:22,theme:'grid',
     styles:{fontSize:5.7,cellPadding:1.2,textColor:[40,31,27],lineColor:[91,64,51],lineWidth:.15,fillColor:false},
     headStyles:{fontStyle:'bold',textColor:[58,39,30],fillColor:[245,240,236],lineWidth:.25},
     footStyles:{fontStyle:'bold',textColor:[58,39,30],fillColor:[250,247,245],lineWidth:.25},
     columnStyles:{0:{cellWidth:24,halign:'left'}},
     didParseCell:d=>{if(d.column.index>0)d.cell.styles.halign='right';}
   });
   doc.save(filename('pdf'));
 }
 function exportWord(){
   const cloned=table.cloneNode(true);
   cloned.querySelectorAll('th,td').forEach(c=>c.setAttribute('style','border:1px solid #5b4033;padding:4px;font-family:Arial;font-size:9pt;'));
   cloned.setAttribute('style','border-collapse:collapse;width:100%;');
   const html=`<!doctype html><html><head><meta charset="utf-8"><title>Coffee Sales Summary</title></head><body><h2 style="font-family:Arial;color:#4b2e20;margin-bottom:3px">Coffee Sales Summary</h2><div style="font-family:Arial;font-size:9pt;margin-bottom:10px">Clean coffee · Sale Season ${season}</div>${cloned.outerHTML}</body></html>`;
   const blob=new Blob(['\ufeff',html],{type:'application/msword'});
   const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=filename('doc');document.body.appendChild(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},500);
 }
 menu.addEventListener('click',e=>{const f=e.target.dataset.format;if(!f)return;e.stopPropagation();menu.classList.remove('show');if(f==='pdf')exportPDF();else if(f==='xlsx')exportExcel();else exportWord();});
})();
</script>

<script>
const auctionTrend=<?=json_encode(in_array($display,['sales','direct','totalclean'],true)?[]:$auctionTrend,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,cleanMode=<?=json_encode($display==='clean')?>,ctx=document.getElementById('auctionTrendChart');
if(ctx&&window.Chart){const datasets=cleanMode?[
{type:'bar',label:'Quantity Sold (kg)',data:auctionTrend.map(r=>Number(r.qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:24},
{type:'line',label:'Weighted Avg Price (USD/50kg)',data:auctionTrend.map(r=>r.avg_price===null?null:Number(r.avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25}
]:[
{type:'bar',label:'Dry Cherry Qty (kg)',data:auctionTrend.map(r=>Number(r.dry_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
{type:'bar',label:'Clean Coffee Qty (kg)',data:auctionTrend.map(r=>Number(r.clean_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
{type:'line',label:'Dry Cherry Avg Price',data:auctionTrend.map(r=>r.dry_avg_price===null?null:Number(r.dry_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25},
{type:'line',label:'Clean Coffee Avg Price',data:auctionTrend.map(r=>r.clean_avg_price===null?null:Number(r.clean_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25}];
new Chart(ctx,{data:{labels:auctionTrend.map(r=>'A'+r.auction_no),datasets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{boxWidth:9,boxHeight:9,font:{size:8},padding:8}}},scales:{x:{grid:{display:false},ticks:{font:{size:8},autoSkip:false},title:{display:true,text:'Auction No.',font:{size:8}}},yQty:{position:'left',beginAtZero:true,title:{display:true,text:'Quantity sold (kg)',font:{size:8}},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}},yPrice:{position:'right',title:{display:true,text:cleanMode?'Avg. price (USD/50kg)':'Avg. price (TZS/kg)',font:{size:8}},grid:{drawOnChartArea:false},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}}}}});}
</script>
</body>
</html>
