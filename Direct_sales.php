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
       $sql="SELECT *, CASE WHEN invoice_date IS NULL THEN NULL WHEN EXTRACT(MONTH FROM invoice_date)>=7 THEN EXTRACT(YEAR FROM invoice_date)::int::text||'/'||(EXTRACT(YEAR FROM invoice_date)::int+1)::text ELSE (EXTRACT(YEAR FROM invoice_date)::int-1)::text||'/'||EXTRACT(YEAR FROM invoice_date)::int::text END sale_season FROM public.direct_sales WHERE ".implode(' AND ',$w)." ORDER BY invoice_date DESC NULLS LAST,invoice_number DESC,id DESC";
       $st=direct_db()->prepare($sql); $st->execute($p); direct_json(true,'',['rows'=>$st->fetchAll()]);
   }
 }catch(Throwable $e){direct_json(false,$e->getMessage(),[],500);}
}
$category=direct_category($_GET['category']??'Direct Export'); if(!in_array($category,['Direct Export','Local Sale','Local Roast'],true))$category='Direct Export';
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($category)?> - Direct Sales</title>
<style>*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f7f5f4;color:#3e2723}.app{padding:10px}.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;background:#fff;padding:10px 12px;border:1px solid #e3dedc;border-radius:9px}.title{font-size:18px;font-weight:700}.controls{display:flex;gap:7px;flex-wrap:wrap}select,button,input{height:34px;border:1px solid #cfc7c4;border-radius:6px;background:#fff;padding:0 9px}button{cursor:pointer;font-weight:600}.upload{display:flex;gap:6px;align-items:center}.status{font-size:12px;margin:7px 2px;color:#6d4c41}.tablewrap{background:#fff;border:1px solid #d9d2cf;border-radius:8px;overflow:auto;height:calc(100vh - 105px)}table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:12px}th{position:sticky;top:0;z-index:2;background:#4e342e;color:#fff;white-space:nowrap}th,td{padding:7px 8px;border-right:1px solid #e1dcda;border-bottom:1px solid #e1dcda;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}td.text{text-align:left}.num{font-variant-numeric:tabular-nums}@media(max-width:700px){.toolbar{align-items:flex-start;flex-direction:column}.tablewrap{height:calc(100vh - 155px)}.title{font-size:16px}}</style></head><body><div class="app"><div class="toolbar"><div><div class="title"><?=htmlspecialchars($category)?></div><small>Direct Sales database</small></div><div class="controls"><select id="season" title="Sale Season: 1 July to 30 June"><option value="">All Sale Seasons</option></select><button onclick="loadRows()">↻ Refresh</button><form class="upload" id="uploadForm"><input type="file" name="direct_excel" accept=".xlsx" required><button>↑ Upload Direct Sales</button></form></div></div><div class="status" id="status"></div><div class="tablewrap"><table id="table"></table></div></div>
<script>const category=<?=json_encode($category)?>;const $=id=>document.getElementById(id);const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));const num=v=>v===null||v===''?'':Number(v).toLocaleString(undefined,{maximumFractionDigits:4});
const cols=[['sale_season','Sale Season'],['sale_category','Sale Category'],['invoice_number','Invoice Number'],['source_invoice_number','Source Invoice Number'],['invoice_date','Invoice Date'],['contract_number','Contract Number'],['grade_name','Grade Name'],['coffee_type','Coffee Type'],['net_kg','Net Kg'],['price_usd_50kg','Price (USD/50kgs)'],['exchange_rate','Exchange Rate'],['warehouse_name','Warehouse Name'],['warehouse_location','Warehouse Location'],['region','Region'],['supplier_seller','Supplier/Seller'],['buyer','Buyer']];
async function api(url,opt){let r=await fetch(url,opt);let j=await r.json();if(!r.ok||!j.success)throw Error(j.message||'Request failed');return j}async function loadFilters(){let d=await api('Direct_sales.php?action=filters&category='+encodeURIComponent(category));$('season').innerHTML='<option value="">All Sale Seasons</option>'+d.seasons.map(s=>`<option>${esc(s)}</option>`).join('')}async function loadRows(){try{$('status').textContent='Loading…';let d=await api('Direct_sales.php?action=rows&category='+encodeURIComponent(category)+'&season='+encodeURIComponent($('season').value));let h='<thead><tr>'+cols.map(c=>`<th>${c[1]}</th>`).join('')+'</tr></thead><tbody>';h+=d.rows.map(r=>'<tr>'+cols.map(c=>`<td class="${['net_kg','price_usd_50kg','exchange_rate'].includes(c[0])?'num':'text'}">${esc(['net_kg','price_usd_50kg','exchange_rate'].includes(c[0])?num(r[c[0]]):r[c[0]])}</td>`).join('')+'</tr>').join('');$('table').innerHTML=h+'</tbody>';$('status').textContent=d.rows.length.toLocaleString()+' '+category+' rows';}catch(e){$('status').textContent=e.message}}$('season').addEventListener('change',loadRows);$('uploadForm').addEventListener('submit',async e=>{e.preventDefault();try{let fd=new FormData(e.target);$('status').textContent='Uploading…';let d=await api('Direct_sales.php',{method:'POST',body:fd});$('status').textContent=d.message;await loadFilters();await loadRows();e.target.reset()}catch(x){$('status').textContent=x.message}});(async()=>{await loadFilters();await loadRows()})();</script></body></html>
