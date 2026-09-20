<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in']!==true){ header('Location: login.php'); exit; }
require_once __DIR__.'/Direct_database.php';
direct_ensure_table();

function direct_rows_from_xlsx(string $file): array {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('Unable to open Excel workbook.');

    $shared = [];
    $sx = $zip->getFromName('xl/sharedStrings.xml');
    if ($sx !== false) {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($sx)) {
            $zip->close();
            throw new RuntimeException('The Excel shared strings could not be read.');
        }
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//*[local-name()="si"]') as $si) {
            $parts = [];
            foreach ($xp->query('.//*[local-name()="t"]', $si) as $t) $parts[] = $t->textContent;
            $shared[] = implode('', $parts);
        }
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        $zip->close();
        throw new RuntimeException('First worksheet was not found.');
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($sheet)) {
        $zip->close();
        throw new RuntimeException('The first Excel worksheet could not be read.');
    }

    $xp = new DOMXPath($dom);
    $rows = [];
    foreach ($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $vals = [];
        foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
            $ref = $cell->getAttribute('r');
            preg_match('/^[A-Z]+/', $ref, $m);
            $col = $m[0] ?? '';
            if ($col === '') continue;

            $type = $cell->getAttribute('t');
            if ($type === 'inlineStr') {
                $parts = [];
                foreach ($xp->query('.//*[local-name()="t"]', $cell) as $t) $parts[] = $t->textContent;
                $value = implode('', $parts);
            } else {
                $vn = $xp->query('./*[local-name()="v"]', $cell)->item(0);
                $raw = $vn ? $vn->textContent : '';
                $value = ($type === 's') ? ($shared[(int)$raw] ?? '') : $raw;
            }
            $vals[$col] = $value;
        }
        $rows[] = $vals;
    }
    $zip->close();
    return $rows;
}
function direct_sale_season(?string $invoiceDate): string {
    if (!$invoiceDate || !($ts=strtotime($invoiceDate))) return '';
    $year=(int)date('Y',$ts); $month=(int)date('n',$ts);
    $start=$month>=7 ? $year : $year-1;
    return $start.'/'.($start+1);
}
function direct_sale_season_bounds(string $season): ?array {
    if(!preg_match('/^(\d{4})\/(\d{4})$/',trim($season),$m) || (int)$m[2] !== (int)$m[1]+1) return null;
    return [$m[1].'-07-01',$m[2].'-06-30'];
}

function direct_upload(): void {
    if(empty($_FILES['direct_excel']['tmp_name'])) direct_json(false,'Select the Direct Sales Excel file.',[],400);
    $ext=strtolower(pathinfo($_FILES['direct_excel']['name']??'',PATHINFO_EXTENSION));
    if($ext!=='xlsx') direct_json(false,'Please upload the Direct Sales workbook as .xlsx.',[],400);
    $rows=direct_rows_from_xlsx($_FILES['direct_excel']['tmp_name']); if(!$rows) direct_json(false,'Workbook is empty.',[],400);
    $expected=['Crop Season','Sale Category','Invoice Number','Source Invoice Number','Invoice date','Contract Number','Grade Name','Cofee Type','Net Kg','Price (usd/50kgs)','Exchange Rate','Warehouse Name','Warehouse Location','Region','Supplier/Seller','Buyer'];
    $cols=range('A','P'); $head=[];
    foreach($cols as $c) $head[]=trim((string)($rows[0][$c]??''));

    $normHeader=static function($v): string {
        return strtolower(preg_replace('/\\s+/u',' ',trim((string)$v)));
    };
    foreach($expected as $i=>$name){
        if($normHeader($head[$i]??'')!==$normHeader($name)){
            direct_json(false,'Upload stopped. Column '.($i+1).' must be "'.$name.'". Found "'.($head[$i]??'').'".',['expected_columns'=>$expected,'found_columns'=>$head],400);
        }
    }
    $db=direct_db(); $sql="INSERT INTO public.direct_sales(crop_season,sale_category,invoice_number,source_invoice_number,invoice_date,contract_number,grade_name,coffee_type,net_kg,price_usd_50kg,exchange_rate,warehouse_name,warehouse_location,region,supplier_seller,buyer) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"; $st=$db->prepare($sql); $count=0;
    $db->beginTransaction(); try { foreach(array_slice($rows,1) as $r){ $v=array_map(fn($c)=>trim((string)($r[$c]??'')),$cols); if(!array_filter($v,fn($x)=>$x!==''))continue; if($v[1]==='')continue; $invoiceDate=direct_date($v[4]); $saleSeason=direct_sale_season($invoiceDate); $st->execute([$saleSeason,direct_category($v[1]),$v[2]?:null,$v[3]?:null,$invoiceDate,$v[5]?:null,$v[6]?:null,$v[7]?:null,direct_num($v[8]),direct_num($v[9]),direct_num($v[10]),$v[11]?:null,$v[12]?:null,$v[13]?:null,$v[14]?:null,$v[15]?:null]); $count++; } $db->commit(); } catch(Throwable $e){$db->rollBack();throw $e;}
    direct_json(true,$count.' Direct Sales rows uploaded successfully.',['inserted'=>$count]);
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['direct_excel'])){ try{direct_upload();}catch(Throwable $e){direct_json(false,$e->getMessage(),[],500);} }
if(isset($_GET['action'])){
 try{
   $a=$_GET['action']; $cat=direct_category($_GET['category']??''); $season=trim($_GET['season']??'');
   if($a==='filters'){
       $st=direct_db()->prepare("SELECT DISTINCT CASE WHEN EXTRACT(MONTH FROM invoice_date)>=7 THEN EXTRACT(YEAR FROM invoice_date)::int ELSE EXTRACT(YEAR FROM invoice_date)::int-1 END start_year FROM public.direct_sales WHERE sale_category=:c AND invoice_date IS NOT NULL ORDER BY start_year DESC");
       $st->execute(['c'=>$cat]); $seasons=[];
       foreach($st->fetchAll(PDO::FETCH_COLUMN) as $y){$y=(int)$y;$seasons[]=$y.'/'.($y+1);}
       direct_json(true,'',['seasons'=>$seasons]);
   }
   if($a==='rows'){
       $w=['sale_category=:c']; $p=['c'=>$cat];
       if($season!==''){
           $bounds=direct_sale_season_bounds($season);
           if(!$bounds) direct_json(false,'Invalid Sale Season selected.',[],400);
           $w[]='invoice_date BETWEEN :sf AND :st'; $p['sf']=$bounds[0]; $p['st']=$bounds[1];
       }
       $saleSeasonExpr="CASE WHEN d.invoice_date IS NULL THEN NULL WHEN EXTRACT(MONTH FROM d.invoice_date)>=7 THEN EXTRACT(YEAR FROM d.invoice_date)::int::text||'/'||(EXTRACT(YEAR FROM d.invoice_date)::int+1)::text ELSE (EXTRACT(YEAR FROM d.invoice_date)::int-1)::text||'/'||EXTRACT(YEAR FROM d.invoice_date)::int::text END";

       if($cat==='Local Sale'){
           /*
            * Local Sale Balance is season-specific.
            * For each LS invoice, subtract ALL Direct Export net kg whose
            * Source Invoice Number matches that LS Invoice Number, but only
            * when both records fall inside the selected Sale Season.
            *
            * The correlated SUM also correctly handles one LS invoice later
            * being exported through several DE rows.
            */
           $balanceSeasonSql='';
           $balanceParams=[];
           if($season!==''){
               $bounds=direct_sale_season_bounds($season);
               $balanceSeasonSql=' AND de.invoice_date BETWEEN :de_sf AND :de_st';
               $balanceParams=['de_sf'=>$bounds[0],'de_st'=>$bounds[1]];
           }

           $sql="SELECT d.*, $saleSeasonExpr AS sale_season,
               GREATEST(
                   COALESCE(d.net_kg,0) -
                   COALESCE((
                       SELECT SUM(COALESCE(de.net_kg,0))
                       FROM public.direct_sales de
                       WHERE de.sale_category='Direct Export'
                         AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL
                         AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number))
                         $balanceSeasonSql
                   ),0),
                   0
               ) AS local_sale_balance_kg
               FROM public.direct_sales d
               WHERE ".str_replace('sale_category=:c','d.sale_category=:c',implode(' AND ',$w))."
               ORDER BY d.invoice_date DESC NULLS LAST,d.invoice_number DESC,d.id DESC";
           $st=direct_db()->prepare($sql);
           $st->execute(array_merge($p,$balanceParams));
       }else{
           $sql="SELECT d.*, $saleSeasonExpr AS sale_season
               FROM public.direct_sales d
               WHERE ".str_replace('sale_category=:c','d.sale_category=:c',implode(' AND ',$w))."
               ORDER BY d.invoice_date DESC NULLS LAST,d.invoice_number DESC,d.id DESC";
           $st=direct_db()->prepare($sql); $st->execute($p);
       }
       direct_json(true,'',['rows'=>$st->fetchAll()]);
   }
   if($a==='summary'){
       $w=['sale_category=:c']; $p=['c'=>$cat];
       if($season!==''){
           $bounds=direct_sale_season_bounds($season);
           if(!$bounds) direct_json(false,'Invalid Sale Season selected.',[],400);
           $w[]='invoice_date BETWEEN :sf AND :st'; $p['sf']=$bounds[0]; $p['st']=$bounds[1];
       }
       $where=implode(' AND ',$w);

       /* Value follows the requested Direct Sales formula:
          (Price USD/50kg / 50) × Net Weight kg. */
       $valueExpr="COALESCE(net_kg,0) * COALESCE(price_usd_50kg,0) / 50.0";

       $sqlCoffee="SELECT COALESCE(NULLIF(BTRIM(coffee_type),''),'Unspecified') label,
           COALESCE(SUM(net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales WHERE $where
           GROUP BY 1 ORDER BY net_kg DESC,label";
       $st=direct_db()->prepare($sqlCoffee); $st->execute($p); $coffee=$st->fetchAll();

       $sqlRegion="SELECT COALESCE(NULLIF(BTRIM(region),''),'Unspecified') label,
           COALESCE(SUM(net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales WHERE $where
           GROUP BY 1 ORDER BY net_kg DESC,label";
       $st=direct_db()->prepare($sqlRegion); $st->execute($p); $regions=$st->fetchAll();

       $sqlTotal="SELECT COALESCE(SUM(net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales WHERE $where";
       $st=direct_db()->prepare($sqlTotal); $st->execute($p); $total=$st->fetch();

       direct_json(true,'',[
          'coffee_type'=>$coffee,
          'regions'=>$regions,
          'total'=>[
             'net_kg'=>(float)($total['net_kg']??0),
             'value_usd'=>(float)($total['value_usd']??0)
          ]
       ]);
   }
 }catch(Throwable $e){direct_json(false,$e->getMessage(),[],500);}
}
$category=direct_category($_GET['category']??'Direct Export'); if(!in_array($category,['Direct Export','Local Sale','Local Roast'],true))$category='Direct Export';
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($category)?> - Direct Sales</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f7f5f4;color:#3e2723}.app{padding:10px}
.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;background:#fff;padding:9px 11px;border:1px solid #e3dedc;border-radius:9px}
.title{font-size:18px;font-weight:700}.controls{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
select,button,input{height:34px;border:1px solid #cfc7c4;border-radius:6px;background:#fff;padding:0 9px}
button{cursor:pointer;font-weight:600}.upload{display:flex;gap:6px;align-items:center}.status{font-size:12px;margin:7px 2px;color:#6d4c41}
.tablewrap{background:#fff;border:1px solid #d9d2cf;border-radius:8px;overflow:auto;height:calc(100vh - 105px)}
table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
th{position:sticky;top:0;z-index:2;background:#4e342e;color:#fff;white-space:nowrap}
th,td{padding:7px 8px;border-right:1px solid #e1dcda;border-bottom:1px solid #e1dcda;text-align:right;white-space:nowrap}
th:first-child,td:first-child{text-align:left}td.text{text-align:left}.num{font-variant-numeric:tabular-nums}

.summary{display:none;grid-template-columns:1fr 1fr;gap:10px;align-items:start}
.summary.show{display:grid}.summary-card{background:#fff;border:1px solid #d9d2cf;border-radius:8px;overflow:hidden}
.summary-head{padding:8px 10px;font-size:13px;font-weight:700;border-bottom:1px solid #d9d2cf;background:#faf8f7}
.summary-card table{width:100%;min-width:0;border-collapse:collapse;table-layout:fixed}
.summary-card th,.summary-card td{padding:6px 7px;border:1px solid #ded8d5}
.summary-card th{position:static;background:#4e342e;color:#fff;text-align:right}
.summary-card th:first-child,.summary-card td:first-child{text-align:left;width:34%}
.summary-card tfoot td{font-weight:800;border-top:2px solid #4e342e;background:#faf8f7}
.summary-note{font-size:11px;font-weight:400;color:#795548;margin-left:5px}
@media(max-width:900px){.summary{grid-template-columns:1fr}.summary-card{overflow:auto}.summary-card table{min-width:620px}}
@media(max-width:700px){.toolbar{align-items:flex-start;flex-direction:column}.controls{width:100%}.tablewrap{height:calc(100vh - 155px)}.title{font-size:16px}}
</style></head>
<body><div class="app">
<div class="toolbar">
 <div><div class="title"><?=htmlspecialchars($category)?></div><small>Direct Sales database</small></div>
 <div class="controls">
   <select id="season" title="Sale Season: 1 July to 30 June"><option value="">All Sale Seasons</option></select>
   <select id="display">
     <option value="sales">All <?=htmlspecialchars($category)?> Sales</option>
     <option value="summary">Sale Summary</option>
   </select>
   <button onclick="refreshCurrent()">↻ Refresh</button>
   <form class="upload" id="uploadForm"><input type="file" name="direct_excel" accept=".xlsx" required><button>↑ Upload Direct Sales</button></form>
 </div>
</div>
<div class="status" id="status"></div>
<div class="tablewrap" id="tablewrap"><table id="table"></table></div>
<div class="summary" id="summary">
 <section class="summary-card"><div class="summary-head">Sales Summary by Coffee Type <span class="summary-note">Value = (USD/50kg ÷ 50) × Net kg</span></div><div id="coffeeSummary"></div></section>
 <section class="summary-card"><div class="summary-head">Sales Summary by Region <span class="summary-note">Share based on Grand Total net weight</span></div><div id="regionSummary"></div></section>
</div>
</div>
<script>
const category=<?=json_encode($category)?>;
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num=(v,d=4)=>v===null||v===''?'':Number(v).toLocaleString(undefined,{maximumFractionDigits:d});
const cols=[['sale_season','Sale Season'],['sale_category','Sale Category'],['invoice_number','Invoice Number'],['source_invoice_number','Source Invoice Number'],['invoice_date','Invoice Date'],['contract_number','Contract Number'],['grade_name','Grade Name'],['coffee_type','Coffee Type'],['net_kg','Net Kg'],...(category==='Local Sale'?[['local_sale_balance_kg','Local Sale Balance (kg)']]:[]),['price_usd_50kg','Price (USD/50kgs)'],['exchange_rate','Exchange Rate'],['warehouse_name','Warehouse Name'],['warehouse_location','Warehouse Location'],['region','Region'],['supplier_seller','Supplier/Seller'],['buyer','Buyer']];

async function api(url,opt){let r=await fetch(url,opt),j=await r.json();if(!r.ok||!j.success)throw Error(j.message||'Request failed');return j}

async function loadFilters(){
 let selected=$('season').value;
 let d=await api('Direct_sales.php?action=filters&category='+encodeURIComponent(category));
 $('season').innerHTML='<option value="">All Sale Seasons</option>'+d.seasons.map(s=>`<option value="${esc(s)}">${esc(s)}</option>`).join('');
 if(d.seasons.includes(selected))$('season').value=selected;
}
async function loadRows(){
 try{
  $('status').textContent='Loading…';
  let d=await api('Direct_sales.php?action=rows&category='+encodeURIComponent(category)+'&season='+encodeURIComponent($('season').value));
  let h='<thead><tr>'+cols.map(c=>`<th>${c[1]}</th>`).join('')+'</tr></thead><tbody>';
  h+=d.rows.map(r=>'<tr>'+cols.map(c=>`<td class="${['net_kg','local_sale_balance_kg','price_usd_50kg','exchange_rate'].includes(c[0])?'num':'text'}">${esc(['net_kg','local_sale_balance_kg','price_usd_50kg','exchange_rate'].includes(c[0])?num(r[c[0]]):r[c[0]])}</td>`).join('')+'</tr>').join('');
  $('table').innerHTML=h+'</tbody>';
  $('status').textContent=d.rows.length.toLocaleString()+' '+category+' rows';
 }catch(e){$('status').textContent=e.message}
}
function summaryTable(rows,total,firstTitle){
 const grand=Number(total.net_kg)||0;
 let body=rows.map(r=>{
   const kg=Number(r.net_kg)||0,share=grand>0?kg/grand*100:0;
   return `<tr><td>${esc(r.label)}</td><td>${num(kg,3)}</td><td>${num(r.value_usd,2)}</td><td>${num(share,2)}%</td></tr>`;
 }).join('');
 return `<table><thead><tr><th>${firstTitle}</th><th>Net Weight (kg)</th><th>Value (USD)</th><th>% Share</th></tr></thead>
 <tbody>${body||'<tr><td colspan="4">No data</td></tr>'}</tbody>
 <tfoot><tr><td>Grand Total</td><td>${num(total.net_kg,3)}</td><td>${num(total.value_usd,2)}</td><td>${grand>0?'100%':'0%'}</td></tr></tfoot></table>`;
}
async function loadSummary(){
 try{
   $('status').textContent='Preparing Sale Summary…';
   let d=await api('Direct_sales.php?action=summary&category='+encodeURIComponent(category)+'&season='+encodeURIComponent($('season').value));
   $('coffeeSummary').innerHTML=summaryTable(d.coffee_type,d.total,'Coffee Type');
   $('regionSummary').innerHTML=summaryTable(d.regions,d.total,'Region');
   $('status').textContent=category+' Sale Summary'+($('season').value?' · '+$('season').value:' · All Sale Seasons');
 }catch(e){$('status').textContent=e.message}
}
async function refreshCurrent(){
 const summary=$('display').value==='summary';
 $('tablewrap').style.display=summary?'none':'block';
 $('summary').classList.toggle('show',summary);
 $('uploadForm').style.display=summary?'none':'flex';
 if(summary) await loadSummary(); else await loadRows();
}
$('season').addEventListener('change',refreshCurrent);
$('display').addEventListener('change',refreshCurrent);
$('uploadForm').addEventListener('submit',async e=>{
 e.preventDefault();
 try{
  let fd=new FormData(e.target);$('status').textContent='Uploading…';
  let d=await api('Direct_sales.php',{method:'POST',body:fd});
  $('status').textContent=d.message;await loadFilters();await refreshCurrent();e.target.reset();
 }catch(x){$('status').textContent=x.message}
});
(async()=>{await loadFilters();await refreshCurrent()})();
</script></body></html>