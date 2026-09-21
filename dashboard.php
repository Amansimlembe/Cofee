<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: login.php'); exit; }

$display=strtolower(trim($_GET['display']??'kagera'));
if(!in_array($display,['kagera','clean','direct'],true))$display='kagera';

function nf($v,$dec=0){$v=(float)$v;$d=(abs($v-round($v))<0.000001)?0:$dec;return number_format($v,$d,'.',',');}
function pct($a,$b){return $b>0?($a/$b*100):0;}


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
 $valueExpr="COALESCE(net_kg,0)*COALESCE(price_usd_50kg,0)/50.0";
 $q=$db->prepare("SELECT $channelCase channel,COALESCE(NULLIF(INITCAP(LOWER(BTRIM(coffee_type))),''),'Unspecified') coffee_type,SUM(COALESCE(net_kg,0)) kgs,SUM($valueExpr) value_usd FROM public.direct_sales WHERE invoice_date BETWEEN :f AND :t AND $channelCase IS NOT NULL GROUP BY 1,2 ORDER BY 1,2");
 $q->execute(['f'=>$from,'t'=>$to]);$directRows=$q->fetchAll();
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
 $q=$db->prepare("SELECT $case type,SUM(COALESCE(kgs,0)) sold,SUM(COALESCE(kgs,0)*COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1");$q->execute(['f'=>$from,'t'=>$to]);foreach($q as$r)if(isset($summary[$r['type']])){$x=&$summary[$r['type']];$x['sold']=(float)$r['sold'];$x['value']=(float)$r['val'];$x['avg']=$x['sold']?$x['value']/$x['sold']:0;$x['pct']=pct($x['sold'],$x['offered']);}
 $q=$db->prepare("SELECT COUNT(DISTINCT NULLIF(BTRIM(auction_no),'')) FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t");$q->execute(['f'=>$from,'t'=>$to]);$auctions=(int)$q->fetchColumn();
 $offered=$summary['Dry Cherry Coffee']['offered']+$summary['Clean Coffee']['offered'];$grandSold=$summary['Dry Cherry Coffee']['sold']+$summary['Clean Coffee']['sold'];$grandValue=$summary['Dry Cherry Coffee']['value']+$summary['Clean Coffee']['value'];$avgPrice=$grandSold?$grandValue/$grandSold:0;$soldPct=pct($grandSold,$offered);
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
            <select name="display" onchange="this.form.submit()"><option value="kagera" <?=$display==='kagera'?'selected':''?>>Kagera Auction</option><option value="clean" <?=$display==='clean'?'selected':''?>>Clean Auction</option><option value="direct" <?=$display==='direct'?'selected':''?>>Direct Sales Summary</option></select>
        </form>
    </div>


<?php if($display==='direct'): ?>
<div class="kpis direct-kpis">
 <div class="kpi"><span class="label">Season</span><strong><?=htmlspecialchars($season)?></strong><div class="sub"><?=date('d M Y',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></div></div>
 <?php foreach(['Direct Export','Local Sale','Local Roast'] as $ch):$x=$channelTotals[$ch]??['kgs'=>0,'value'=>0];?>
 <div class="metric-group <?=$ch==='Direct Export'?'dry':'clean'?>"><div class="metric"><span class="m-label coffee-name"><?=htmlspecialchars($ch)?></span><strong><?=nf($x['kgs'],2)?> kg</strong><small>Net weight</small></div><div class="metric"><span class="m-label">Value</span><strong><?=nf($x['value'],2)?></strong><small>USD</small></div><div class="metric"><span class="m-label">Qty Share</span><strong><?=nf(pct($x['kgs'],$grandSold),2)?>%</strong><small>of total</small></div></div>
 <?php endforeach;?>
</div>
<div class="analytics direct-analytics">
<section class="panel"><div class="panel-head"><strong>Sales Channels Summary</strong><span>Quantity & value</span></div><div class="table-box"><table>
<thead><tr><th>Sales Channel</th><th>Net Weight (kg)</th><th>Value (USD)</th><th>Qty Share</th></tr></thead><tbody>
<?php foreach(['Direct Export','Local Sale','Local Roast'] as $ch):$x=$channelTotals[$ch]??['kgs'=>0,'value'=>0];?><tr><td><?=htmlspecialchars($ch)?></td><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf(pct($x['kgs'],$grandSold),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td>Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot></table></div></section>
<section class="panel"><div class="panel-head"><strong>Sales by Coffee Type</strong><span>All direct sales channels</span></div><div class="table-box"><table>
<thead><tr><th>Coffee Type</th><th>Net Weight (kg)</th><th>Value (USD)</th><th>Qty Share</th></tr></thead><tbody>
<?php foreach($coffeeTotals as $ct=>$x):?><tr><td><?=htmlspecialchars($ct)?></td><td><?=nf($x['kgs'],2)?></td><td><?=nf($x['value'],2)?></td><td><?=nf(pct($x['kgs'],$grandSold),2)?>%</td></tr><?php endforeach;?>
</tbody><tfoot><tr><td>Grand Total</td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot></table></div></section>
</div>
<section class="trend-panel"><div class="trend-head"><strong>Sales Channel × Coffee Type</strong><span>Net weight (kg) and value (USD)</span></div><div class="table-box"><table>
<thead><tr><th>Sales Channel</th><th>Coffee Type</th><th>Net Weight (kg)</th><th>Value (USD)</th><th>Qty Share</th></tr></thead><tbody>
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
 <?php if(!$rows):?><tr><td colspan="6" class="empty">No sales data for this season</td></tr><?php endif;foreach($rows as$i=>$r):?><tr><td title="<?=htmlspecialchars($r['name'])?>"><?php if(empty($r['_other'])):?><span class="rank"><?=$i+1?></span><?php endif;?><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['cherry_qty'],2)?></td><td><?=nf($r['clean_qty'],2)?></td><td><?=nf($r['total_qty'],2)?></td><td><?=nf($r['total_value'],2)?></td><td><?=nf(pct((float)$r['total_qty'],$grandSold),2)?>%</td></tr><?php endforeach;?></tbody><tfoot><tr><td>Season Grand Total</td><td><?=nf($summary['Dry Cherry Coffee']['sold'],2)?></td><td><?=nf($summary['Clean Coffee']['sold'],2)?></td><td><?=nf($grandSold,2)?></td><td><?=nf($grandValue,2)?></td><td><?=$grandSold>0?'100%':'0%'?></td></tr></tfoot>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const auctionTrend=<?=json_encode($display==='direct'?[]:$auctionTrend,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,cleanMode=<?=json_encode($display==='clean')?>,ctx=document.getElementById('auctionTrendChart');
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
