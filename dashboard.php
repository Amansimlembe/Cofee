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

function combined_rank_rows($db,$from,$to,$column,$limit=5){
    if(!in_array($column,['buyer','warehouse'],true)) return [];
    $case=coffee_case_sql();
    $sql="SELECT
        COALESCE(NULLIF(TRIM($column),''),'Unspecified') name,
        SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) cherry_qty,
        SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) clean_qty,
        SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0) ELSE 0 END) total_qty,
        SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END) total_value
      FROM public.kagera_auction_results
      WHERE auction_date BETWEEN :f AND :t AND $case IS NOT NULL
      GROUP BY 1
      HAVING SUM(CASE WHEN $case IS NOT NULL THEN COALESCE(kgs,0) ELSE 0 END)>0
      ORDER BY total_qty DESC,total_value DESC";
    $s=$db->prepare($sql); $s->execute(['f'=>$from,'t'=>$to]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
$grandSold=$summary['Dry Cherry Coffee']['sold']+$summary['Clean Coffee']['sold'];
$grandValue=$summary['Dry Cherry Coffee']['value']+$summary['Clean Coffee']['value'];
function top5_with_other($rows,$entityLabel){
    $top=array_slice($rows,0,5);
    $other=array_slice($rows,5);

    if($other){
        $agg=[
            'name'=>'Other ('.count($other).' '.$entityLabel.')',
            'cherry_qty'=>0,
            'clean_qty'=>0,
            'total_qty'=>0,
            'total_value'=>0,
            '_other'=>true
        ];
        foreach($other as $r){
            $agg['cherry_qty']+=(float)$r['cherry_qty'];
            $agg['clean_qty']+=(float)$r['clean_qty'];
            $agg['total_qty']+=(float)$r['total_qty'];
            $agg['total_value']+=(float)$r['total_value'];
        }
        $top[]=$agg;
    }
    return $top;
}
$buyersCombined=top5_with_other(combined_rank_rows($db,$from,$to,'buyer'),'buyers');

$amcosCombined=top5_with_other(combined_rank_rows($db,$from,$to,'warehouse'),'AMCOS / warehouses');

/*
|--------------------------------------------------------------------------
| AUCTION TREND — DRY CHERRY VS CLEAN
|--------------------------------------------------------------------------
*/
$case=coffee_case_sql();
$trendSql="SELECT
    auction_no,
    MIN(auction_date) AS auction_date,
    SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END) AS dry_qty,
    SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END) AS clean_qty,
    CASE WHEN SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END)>0
      THEN SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END)
         / SUM(CASE WHEN $case='Dry Cherry Coffee' THEN COALESCE(kgs,0) ELSE 0 END)
      ELSE NULL END AS dry_avg_price,
    CASE WHEN SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END)>0
      THEN SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0)*COALESCE(price,0) ELSE 0 END)
         / SUM(CASE WHEN $case='Clean Coffee' THEN COALESCE(kgs,0) ELSE 0 END)
      ELSE NULL END AS clean_avg_price
  FROM public.kagera_auction_results
  WHERE auction_date BETWEEN :f AND :t AND $case IS NOT NULL
  GROUP BY auction_no
  ORDER BY
    CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::integer ELSE NULL END ASC NULLS LAST,
    auction_no ASC";
$trendStmt=$db->prepare($trendSql);
$trendStmt->execute(['f'=>$from,'t'=>$to]);
$auctionTrend=$trendStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kagera Auction Dashboard</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#f6f3f1;color:#352720;font-family:Arial,sans-serif}
.dashboard{height:100vh;padding:6px 9px;display:grid;grid-template-rows:auto auto minmax(190px,.95fr) minmax(170px,1.05fr);gap:5px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px}
.title{display:flex;align-items:baseline;gap:9px;min-width:0}
.title h1{font-size:16px;margin:0;color:#3f2b24;white-space:nowrap}
.title span{font-size:10px;color:#8a7a72;white-space:nowrap}
.season{display:flex;align-items:center;gap:6px}
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

</style>
</head>
<body>
<div class="dashboard">
    <div class="topbar">
        <div class="title">
            <h1>Kagera Auction — Season Performance</h1>
            <span>Compact analytical summary</span>
        </div>
        <form class="season" method="get">
            <label>Season</label>
            <select name="season" onchange="this.form.submit()">
                <?php foreach($seasons ?: [$season] as $s): ?>
                    <option value="<?=htmlspecialchars($s)?>" <?=$s===$season?'selected':''?>><?=htmlspecialchars($s)?></option>
                <?php endforeach ?>
            </select>
        </form>
    </div>

    <div class="kpis">
 <div class="kpi">
  <span class="label">Season</span>
  <strong><?=htmlspecialchars($season)?></strong>
  <div class="sub"><?=date('d M',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></div>
 </div>
 <div class="kpi">
  <span class="label">Auctions</span>
  <strong><?=nf($auctions)?></strong>
  <div class="sub">Held</div>
 </div>
 <?php foreach(['Dry Cherry Coffee','Clean Coffee'] as $type): $x=$summary[$type]; $cls=$type==='Dry Cherry Coffee'?'dry':'clean'; ?>
 <div class="metric-group <?=$cls?>">
  <div class="metric">
   <span class="m-label coffee-name"><?=$type==='Dry Cherry Coffee'?'Dry Cherry':'Clean Coffee'?> · Offered</span>
   <strong><?=nf($x['offered'],0)?> kg</strong><small>Catalogue</small>
  </div>
  <div class="metric">
   <span class="m-label">Sold</span>
   <strong><?=nf($x['sold'],0)?> kg</strong><small><?=nf($x['pct'],2)?>% of offered</small>
  </div>
  <div class="metric">
   <span class="m-label">Avg. Price</span>
   <strong><?=nf($x['avg'],2)?></strong><small>TZS/kg</small>
  </div>
 </div>
 <?php endforeach ?>
</div>

<div class="analytics">
<?php foreach([
 ['Top 5 Buyers',$buyersCombined,'Buyer'],
 ['Top 5 AMCOS / Warehouses',$amcosCombined,'AMCOS / Warehouse']
] as [$heading,$rows,$firstLabel]): ?>
<section class="panel">
 <div class="panel-head"><strong><?=$heading?></strong><span>Ranked by total quantity</span></div>
 <div class="table-box"><table>
  <thead><tr>
   <th style="width:27%"><?=$firstLabel?></th>
   <th>Dry Cherry (kg)</th><th>Clean Coffee (kg)</th>
   <th>Total Qty (kg)</th><th>Total Value (TZS)</th><th>Qty Share</th>
  </tr></thead>
  <tbody>
  <?php if(!$rows): ?><tr><td colspan="6" class="empty">No sales data for this season</td></tr><?php endif; ?>
  <?php foreach($rows as $i=>$r): ?>
   <tr>
    <td title="<?=htmlspecialchars($r['name'])?>">
      <?php if(empty($r['_other'])): ?><span class="rank"><?=$i+1?></span><?php endif; ?>
      <?=htmlspecialchars($r['name'])?>
    </td>
    <td><?=nf($r['cherry_qty'],2)?></td>
    <td><?=nf($r['clean_qty'],2)?></td>
    <td><?=nf($r['total_qty'],2)?></td>
    <td><?=nf($r['total_value'],2)?></td>
    <td><?=nf(pct((float)$r['total_qty'],$grandSold),2)?>%</td>
   </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr>
   <td>Season Grand Total</td>
   <td><?=nf($summary['Dry Cherry Coffee']['sold'],2)?></td>
   <td><?=nf($summary['Clean Coffee']['sold'],2)?></td>
   <td><?=nf($grandSold,2)?></td>
   <td><?=nf($grandValue,2)?></td>
   <td>100%</td>
  </tr></tfoot>
 </table></div>
</section>
<?php endforeach ?>
</div>

<section class="trend-panel">
 <div class="trend-head">
  <strong>Auction Performance Trend</strong>
  <span>Dry Cherry and Clean Coffee · quantity sold & weighted average price</span>
 </div>
 <div class="trend-wrap"><canvas id="auctionTrendChart"></canvas></div>
</section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const auctionTrend = <?=json_encode($auctionTrend, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const ctx = document.getElementById('auctionTrendChart');
if (ctx && window.Chart) {
 new Chart(ctx,{
  data:{
   labels:auctionTrend.map(r=>'A'+r.auction_no),
   datasets:[
    {type:'bar',label:'Dry Cherry Qty (kg)',data:auctionTrend.map(r=>Number(r.dry_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
    {type:'bar',label:'Clean Coffee Qty (kg)',data:auctionTrend.map(r=>Number(r.clean_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
    {type:'line',label:'Dry Cherry Avg Price',data:auctionTrend.map(r=>r.dry_avg_price===null?null:Number(r.dry_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25,spanGaps:false},
    {type:'line',label:'Clean Coffee Avg Price',data:auctionTrend.map(r=>r.clean_avg_price===null?null:Number(r.clean_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25,spanGaps:false}
   ]
  },
  options:{
   responsive:true,maintainAspectRatio:false,
   interaction:{mode:'index',intersect:false},
   plugins:{
    legend:{position:'top',labels:{boxWidth:9,boxHeight:9,font:{size:8},padding:8}},
    tooltip:{callbacks:{label:c=>c.dataset.label+': '+(Number(c.raw)||0).toLocaleString(undefined,{maximumFractionDigits:2})}}
   },
   scales:{
    x:{grid:{display:false},ticks:{font:{size:8},maxRotation:0,autoSkip:false}},
    yQty:{position:'left',beginAtZero:true,title:{display:true,text:'Quantity sold (kg)',font:{size:8}},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}},
    yPrice:{position:'right',beginAtZero:false,title:{display:true,text:'Avg. price (TZS/kg)',font:{size:8}},grid:{drawOnChartArea:false},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}}
   }
  }
 });
}
</script>
</body>
</html>
