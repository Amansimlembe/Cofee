<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/kagera_database.php';
ensure_kagera_table();
ensure_kagera_catalogue_table();
$db = kagera_db();

function season_bounds($season) {
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $season, $m) || (int)$m[2] !== (int)$m[1] + 1) return null;
    return [$m[1].'-06-01', $m[2].'-05-30'];
}
function current_season() {
    $y=(int)date('Y'); $m=(int)date('n');
    return $m>=6 ? $y.'/'.($y+1) : ($y-1).'/'.$y;
}
function nf($v,$dec=0){
    $v=(float)$v; $d=(abs($v-round($v))<0.000001)?0:$dec; return number_format($v,$d,'.',',');
}
function pct($a,$b){ return $b>0 ? ($a/$b*100) : 0; }
function coffee_case_sql() {
    // Keep Dashboard classification identical to the working High & Low report:
    // Grade2 is the source of Dry Cherry Coffee / Clean Coffee.
    return "CASE
        WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%dry cherry%' THEN 'Dry Cherry Coffee'
        WHEN LOWER(BTRIM(COALESCE(grade2,''))) LIKE '%clean%' THEN 'Clean Coffee'
        ELSE NULL
    END";
}

$seasonRows=$db->query("SELECT DISTINCT EXTRACT(YEAR FROM auction_date)::int AS y, EXTRACT(MONTH FROM auction_date)::int AS m FROM (SELECT auction_date FROM public.kagera_auction_results UNION ALL SELECT auction_date FROM public.kagera_auction_catalogue) d WHERE auction_date IS NOT NULL ORDER BY y DESC,m DESC")->fetchAll(PDO::FETCH_ASSOC);
$seasons=[]; foreach($seasonRows as $r){$start=((int)$r['m']>=6)?(int)$r['y']:(int)$r['y']-1; $s=$start.'/'.($start+1); $seasons[$s]=true;}
$seasons=array_keys($seasons); rsort($seasons);
$season=$_GET['season'] ?? current_season(); if(!season_bounds($season)) $season=current_season(); [$from,$to]=season_bounds($season);

$summary=[];
foreach(['Dry Cherry Coffee','Clean Coffee'] as $type){$summary[$type]=['offered'=>0,'sold'=>0,'value'=>0,'avg'=>0,'pct'=>0];}
$catType=coffee_case_sql();
$resType=coffee_case_sql();
$stmt=$db->prepare("SELECT $catType type, SUM(COALESCE(kgs,0)) offered FROM public.kagera_auction_catalogue WHERE auction_date BETWEEN :f AND :t GROUP BY 1"); $stmt->execute(['f'=>$from,'t'=>$to]);
foreach($stmt as $r) if(isset($summary[$r['type']])) $summary[$r['type']]['offered']=(float)$r['offered'];
$stmt=$db->prepare("SELECT $resType type, SUM(COALESCE(kgs,0)) sold, SUM(COALESCE(kgs,0) * COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t GROUP BY 1"); $stmt->execute(['f'=>$from,'t'=>$to]);
foreach($stmt as $r) if(isset($summary[$r['type']])){$x=&$summary[$r['type']];$x['sold']=(float)$r['sold'];$x['value']=(float)$r['val'];$x['avg']=$x['sold']>0?$x['value']/$x['sold']:0;$x['pct']=pct($x['sold'],$x['offered']);}

$stmt=$db->prepare("SELECT COUNT(DISTINCT TRIM(auction_no)) FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t AND NULLIF(TRIM(auction_no),'') IS NOT NULL");$stmt->execute(['f'=>$from,'t'=>$to]);$auctions=(int)$stmt->fetchColumn();

function top_rows($db,$from,$to,$type,$column,$limit=5){
    $case=coffee_case_sql(); $allowed=['buyer','warehouse']; if(!in_array($column,$allowed,true)) return [];
    $sql="SELECT COALESCE(NULLIF(TRIM($column),''),'Unspecified') name, SUM(COALESCE(kgs,0)) qty, SUM(COALESCE(kgs,0) * COALESCE(price,0)) val FROM public.kagera_auction_results WHERE auction_date BETWEEN :f AND :t AND $case=:type GROUP BY 1 ORDER BY qty DESC, val DESC LIMIT $limit";
    $s=$db->prepare($sql);$s->execute(['f'=>$from,'t'=>$to,'type'=>$type]);return $s->fetchAll(PDO::FETCH_ASSOC);
}
$buyers=[];$amcos=[]; foreach(array_keys($summary) as $type){$buyers[$type]=top_rows($db,$from,$to,$type,'buyer');$amcos[$type]=top_rows($db,$from,$to,$type,'warehouse');}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kagera Auction Dashboard</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f4f2;color:#33231f;font:14px Arial,sans-serif}.wrap{padding:22px;max-width:1600px;margin:auto}.head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:18px}.head h1{margin:0 0 5px;font-size:25px}.muted{color:#806f68}.filter label{font-size:12px;font-weight:700;display:block;margin-bottom:5px}.filter select{padding:9px 32px 9px 10px;border:1px solid #cdbfb9;border-radius:7px;background:#fff}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.panel{background:#fff;border:1px solid #eadfda;border-radius:12px;box-shadow:0 3px 14px #3e27230d;overflow:hidden}.panel-head{padding:14px 17px;background:#4e342e;color:#fff;font-weight:700}.cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:18px}.card{background:#fff;border:1px solid #eadfda;border-radius:10px;padding:14px;border-top:3px solid #6d4c41}.card small{display:block;color:#806f68;margin-bottom:7px}.card strong{font-size:18px}.type{font-weight:700;color:#5d4037;margin-bottom:4px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px 12px;border-bottom:1px solid #eee5e1;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}th{font-size:12px;background:#f7f2ef;color:#5d4037}.section-title{margin:24px 0 10px;font-size:18px}.empty{text-align:center!important;color:#999;padding:20px!important}@media(max-width:1100px){.cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:760px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:repeat(2,1fr)}.head{align-items:stretch;flex-direction:column}}
</style></head><body><div class="wrap">
<div class="head"><div><h1>Kagera Auction Dashboard</h1><div class="muted">Season performance summary — Dry Cherry and Clean Coffee reported separately.</div></div><form class="filter" method="get"><label>Season</label><select name="season" onchange="this.form.submit()"><?php foreach($seasons ?: [$season] as $s):?><option value="<?=htmlspecialchars($s)?>" <?=$s===$season?'selected':''?>><?=htmlspecialchars($s)?></option><?php endforeach?></select></form></div>
<div class="cards">
<div class="card"><small>Season</small><strong><?=htmlspecialchars($season)?></strong></div><div class="card"><small>Auctions Held</small><strong><?=nf($auctions)?></strong></div>
<?php foreach($summary as $type=>$x):?><div class="card"><div class="type"><?=htmlspecialchars($type)?></div><small>Offered / Sold (Kg)</small><strong><?=nf($x['offered'],2)?> / <?=nf($x['sold'],2)?></strong></div><div class="card"><div class="type"><?=htmlspecialchars($type)?></div><small>Average Price / Sold</small><strong>TZS <?=nf($x['avg'],2)?> · <?=nf($x['pct'],2)?>%</strong></div><?php endforeach?>
</div>
<div class="grid">
<?php foreach($summary as $type=>$x):?><div class="panel"><div class="panel-head"><?=htmlspecialchars($type)?> — Season Summary</div><div class="table-wrap"><table><tr><th>Metric</th><th>Result</th></tr><tr><td>Coffee Offered</td><td><?=nf($x['offered'],2)?> Kg</td></tr><tr><td>Coffee Sold</td><td><?=nf($x['sold'],2)?> Kg</td></tr><tr><td>Total Value</td><td>TZS <?=nf($x['value'],2)?></td></tr><tr><td>Average Price</td><td>TZS <?=nf($x['avg'],2)?>/Kg</td></tr><tr><td>Percentage Sold</td><td><?=nf($x['pct'],2)?>%</td></tr></table></div></div><?php endforeach?>
</div>
<h2 class="section-title">Top Five Buyers</h2><div class="grid"><?php foreach($buyers as $type=>$rows):?><div class="panel"><div class="panel-head"><?=htmlspecialchars($type)?></div><div class="table-wrap"><table><thead><tr><th>Buyer</th><th>Quantity (Kg)</th><th>Value (TZS)</th><th>Qty Contribution</th><th>Value Contribution</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty">No sales data</td></tr><?php endif; foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['qty'],2)?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['qty'],$summary[$type]['sold']),2)?>%</td><td><?=nf(pct($r['val'],$summary[$type]['value']),2)?>%</td></tr><?php endforeach?></tbody></table></div></div><?php endforeach?></div>
<h2 class="section-title">Top Five AMCOS / Warehouses by Quantity Sold</h2><div class="grid"><?php foreach($amcos as $type=>$rows):?><div class="panel"><div class="panel-head"><?=htmlspecialchars($type)?></div><div class="table-wrap"><table><thead><tr><th>AMCOS / Warehouse</th><th>Quantity Sold (Kg)</th><th>Value (TZS)</th><th>Qty Contribution</th><th>Value Contribution</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty">No sales data</td></tr><?php endif; foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['name'])?></td><td><?=nf($r['qty'],2)?></td><td><?=nf($r['val'],2)?></td><td><?=nf(pct($r['qty'],$summary[$type]['sold']),2)?>%</td><td><?=nf(pct($r['val'],$summary[$type]['value']),2)?>%</td></tr><?php endforeach?></tbody></table></div></div><?php endforeach?></div>
</div></body></html>
