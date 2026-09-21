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
       $w=['d.sale_category=:c']; $p=['c'=>$cat];
       $bounds=null;
       if($season!==''){
           $bounds=direct_sale_season_bounds($season);
           if(!$bounds) direct_json(false,'Invalid Sale Season selected.',[],400);
           $w[]='d.invoice_date BETWEEN :sf AND :st';
           $p['sf']=$bounds[0]; $p['st']=$bounds[1];
       }
       $where=implode(' AND ',$w);

       /*
        * Normal categories summarize Net Kg.
        * Local Sale summarizes the ACTUAL remaining LS quantity:
        * LS Balance = LS Net Kg - all season-matched Direct Export Net Kg
        * whose Source Invoice Number equals the LS Invoice Number.
        */
       if($cat==='Local Sale'){
           $deSeasonSql='';
           $deParams=[];
           if($bounds){
               $deSeasonSql=' AND de.invoice_date BETWEEN :de_sf AND :de_st';
               $deParams=['de_sf'=>$bounds[0],'de_st'=>$bounds[1]];
           }

           $balanceExpr="GREATEST(
               COALESCE(d.net_kg,0) -
               COALESCE((
                   SELECT SUM(COALESCE(de.net_kg,0))
                   FROM public.direct_sales de
                   WHERE de.sale_category='Direct Export'
                     AND NULLIF(BTRIM(de.source_invoice_number),'') IS NOT NULL
                     AND UPPER(BTRIM(de.source_invoice_number))=UPPER(BTRIM(d.invoice_number))
                     $deSeasonSql
               ),0),
               0
           )";

           /* Value uses the remaining LS balance, not the original LS Net Kg. */
           $valueExpr="($balanceExpr) * COALESCE(d.price_usd_50kg,0) / 50.0";

           $sqlCoffee="SELECT COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') label,
               COALESCE(SUM(d.net_kg),0) net_kg,
               COALESCE(SUM($balanceExpr),0) ls_balance_kg,
               COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where
               GROUP BY 1 ORDER BY ls_balance_kg DESC,label";
           $st=direct_db()->prepare($sqlCoffee);
           $st->execute(array_merge($p,$deParams));
           $coffee=$st->fetchAll();

           $sqlRegion="SELECT CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END label,
               COALESCE(SUM(d.net_kg),0) net_kg,
               COALESCE(SUM($balanceExpr),0) ls_balance_kg,
               COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where
               GROUP BY 1 ORDER BY ls_balance_kg DESC,label";
           $st=direct_db()->prepare($sqlRegion);
           $st->execute(array_merge($p,$deParams));
           $regions=$st->fetchAll();

           $sqlTotal="SELECT COALESCE(SUM(d.net_kg),0) net_kg,
               COALESCE(SUM($balanceExpr),0) ls_balance_kg,
               COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where";
           $st=direct_db()->prepare($sqlTotal);
           $st->execute(array_merge($p,$deParams));
           $total=$st->fetch();

           $sqlSupplierCoffee="SELECT CASE
 WHEN NULLIF(BTRIM(d.supplier_seller),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '\\s+', ' ', 'g')))
 END party,
               COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') coffee_type,
               COALESCE(SUM($balanceExpr),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,coffee_type";
           $st=direct_db()->prepare($sqlSupplierCoffee); $st->execute(array_merge($p,$deParams)); $supplierCoffee=$st->fetchAll();
           $sqlBuyerCoffee="SELECT CASE
 WHEN NULLIF(BTRIM(d.buyer),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.buyer), '\\s+', ' ', 'g')))
 END party,
               COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') coffee_type,
               COALESCE(SUM($balanceExpr),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,coffee_type";
           $st=direct_db()->prepare($sqlBuyerCoffee); $st->execute(array_merge($p,$deParams)); $buyerCoffee=$st->fetchAll();

           $sqlSupplierRegion="SELECT CASE
 WHEN NULLIF(BTRIM(d.supplier_seller),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '\\s+', ' ', 'g')))
 END party, CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END region,
               COALESCE(SUM($balanceExpr),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,region";
           $st=direct_db()->prepare($sqlSupplierRegion); $st->execute(array_merge($p,$deParams)); $supplierRegion=$st->fetchAll();
           $sqlBuyerRegion="SELECT CASE
 WHEN NULLIF(BTRIM(d.buyer),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.buyer), '\\s+', ' ', 'g')))
 END party, CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END region,
               COALESCE(SUM($balanceExpr),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
               FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,region";
           $st=direct_db()->prepare($sqlBuyerRegion); $st->execute(array_merge($p,$deParams)); $buyerRegion=$st->fetchAll();

           direct_json(true,'',[
              'is_local_sale'=>true,
              'coffee_type'=>$coffee,
              'regions'=>$regions,
              'supplier_coffee'=>$supplierCoffee,
              'buyer_coffee'=>$buyerCoffee,
              'supplier_region'=>$supplierRegion,
              'buyer_region'=>$buyerRegion,
              'total'=>[
                 'net_kg'=>(float)($total['net_kg']??0),
                 'ls_balance_kg'=>(float)($total['ls_balance_kg']??0),
                 'value_usd'=>(float)($total['value_usd']??0)
              ]
           ]);
       }

       $valueExpr="COALESCE(d.net_kg,0) * COALESCE(d.price_usd_50kg,0) / 50.0";

       $sqlCoffee="SELECT COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') label,
           COALESCE(SUM(d.net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where
           GROUP BY 1 ORDER BY net_kg DESC,label";
       $st=direct_db()->prepare($sqlCoffee); $st->execute($p); $coffee=$st->fetchAll();

       $sqlRegion="SELECT CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END label,
           COALESCE(SUM(d.net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where
           GROUP BY 1 ORDER BY net_kg DESC,label";
       $st=direct_db()->prepare($sqlRegion); $st->execute($p); $regions=$st->fetchAll();

       $sqlTotal="SELECT COALESCE(SUM(d.net_kg),0) net_kg,
           COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where";
       $st=direct_db()->prepare($sqlTotal); $st->execute($p); $total=$st->fetch();

       $sqlSupplierCoffee="SELECT CASE
 WHEN NULLIF(BTRIM(d.supplier_seller),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '\\s+', ' ', 'g')))
 END party,
           COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') coffee_type,
           COALESCE(SUM(d.net_kg),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,coffee_type";
       $st=direct_db()->prepare($sqlSupplierCoffee); $st->execute($p); $supplierCoffee=$st->fetchAll();
       $sqlBuyerCoffee="SELECT CASE
 WHEN NULLIF(BTRIM(d.buyer),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.buyer), '\\s+', ' ', 'g')))
 END party,
           COALESCE(NULLIF(BTRIM(d.coffee_type),''),'Unspecified') coffee_type,
           COALESCE(SUM(d.net_kg),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,coffee_type";
       $st=direct_db()->prepare($sqlBuyerCoffee); $st->execute($p); $buyerCoffee=$st->fetchAll();

       $sqlSupplierRegion="SELECT CASE
 WHEN NULLIF(BTRIM(d.supplier_seller),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.supplier_seller), '\\s+', ' ', 'g')))
 END party, CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END region,
           COALESCE(SUM(d.net_kg),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,region";
       $st=direct_db()->prepare($sqlSupplierRegion); $st->execute($p); $supplierRegion=$st->fetchAll();
       $sqlBuyerRegion="SELECT CASE
 WHEN NULLIF(BTRIM(d.buyer),'') IS NULL THEN 'Unspecified'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('A.LE &TOM NORTH AMERICA LLC','ALE &TOM NORTH AMERICA LLC') THEN 'A.LE &TOM North America LLC'
 WHEN UPPER(REGEXP_REPLACE(BTRIM(d.buyer), '[.]', '', 'g')) IN ('BERNHARD ROTHFOS INTERCAFE','BERNHARD ROTHFOS INTERCAFE AG') THEN 'BERNHARD ROTHFOS INTERCAFE'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.buyer), '\\s+', ' ', 'g')))
 END party, CASE WHEN NULLIF(BTRIM(d.region),'') IS NULL THEN 'Unspecified'
 ELSE INITCAP(LOWER(REGEXP_REPLACE(BTRIM(d.region), '\\s+', ' ', 'g'))) END region,
           COALESCE(SUM(d.net_kg),0) net_kg, COALESCE(SUM($valueExpr),0) value_usd
           FROM public.direct_sales d WHERE $where GROUP BY 1,2 ORDER BY party,region";
       $st=direct_db()->prepare($sqlBuyerRegion); $st->execute($p); $buyerRegion=$st->fetchAll();

       direct_json(true,'',[
          'is_local_sale'=>false,
          'coffee_type'=>$coffee,
          'regions'=>$regions,
          'supplier_coffee'=>$supplierCoffee,
          'buyer_coffee'=>$buyerCoffee,
          'supplier_region'=>$supplierRegion,
          'buyer_region'=>$buyerRegion,
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
.summary.show{display:grid}.summary-wide{grid-column:1/-1}.summary-card{background:#fff;border:1px solid #d9d2cf;border-radius:8px;overflow:hidden}
.summary-head{padding:8px 10px;font-size:13px;font-weight:700;border-bottom:1px solid #d9d2cf;background:#faf8f7}
.summary-card table{width:100%;min-width:0;border-collapse:collapse;table-layout:fixed}
.summary-card th,.summary-card td{padding:6px 7px;border:1px solid #ded8d5}
.summary-card th{position:static;background:#4e342e;color:#fff;text-align:right}
.summary-card th:first-child,.summary-card td:first-child{text-align:left;width:34%}
.summary-card tfoot td{font-weight:800;border-top:2px solid #4e342e;background:#faf8f7}
.summary-note{font-size:11px;font-weight:400;color:#795548;margin-left:5px}
@media(max-width:900px){.summary{grid-template-columns:1fr}.summary-card{overflow:auto}.summary-card table{min-width:620px}}
@media(max-width:700px){.toolbar{align-items:flex-start;flex-direction:column}.controls{width:100%}.tablewrap{height:calc(100vh - 155px)}.title{font-size:16px}}

.export-wrap{position:relative}.export-btn{min-width:38px;font-size:16px;padding:0 9px}.export-menu{display:none;position:absolute;right:0;top:38px;z-index:30;min-width:145px;background:#fff;border:1px solid #d5cecb;border-radius:7px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:5px}.export-menu.show{display:block}.export-menu button{display:block;width:100%;border:0;text-align:left;background:#fff;height:32px}.export-menu button:hover{background:#f4f0ee}
@media(max-width:1100px){.app{padding:7px}.toolbar{gap:7px}.controls{gap:5px}.tablewrap{height:calc(100vh - 100px)}th,td{padding:6px;font-size:11px}.summary-card th,.summary-card td{padding:5px 6px}}
@media(max-width:760px){.toolbar{position:relative;align-items:stretch}.controls{display:grid;grid-template-columns:1fr 1fr;gap:6px}.controls select,.controls button,.controls input{width:100%;min-width:0}.upload{grid-column:1/-1;width:100%}.upload input{flex:1}.export-wrap{position:static}.export-menu{right:8px;top:auto;margin-top:3px}.tablewrap{height:calc(100vh - 190px);border-radius:6px}.summary{gap:7px}.summary-card{max-width:100%}.summary-card table{min-width:560px}.summary-card th,.summary-card td{font-size:10.5px;padding:5px}.summary-head{font-size:12px}}
@media(max-width:480px){.app{padding:5px}.controls{grid-template-columns:1fr}.upload{grid-column:auto;display:grid!important;grid-template-columns:1fr auto}.title{font-size:15px}.toolbar{padding:7px}.status{margin:5px 2px}.tablewrap{height:calc(100vh - 250px)}.summary-card table{min-width:520px}.summary-note{display:block;margin:2px 0 0}.export-menu{left:7px;right:7px;min-width:0}}
.summary-toolbar{display:none;justify-content:space-between;align-items:center;margin:8px 0 6px;padding:7px 9px;background:#fff;border:1px solid #d9d2cf;border-radius:8px}.summary-toolbar.show{display:flex}.summary-toolbar select{min-width:230px}.summary.show{display:block}.summary-view{display:none!important}.summary-view.active{display:block!important;width:100%}@media(max-width:600px){.summary-toolbar{flex-direction:column;align-items:stretch;gap:6px}.summary-toolbar select{width:100%;min-width:0}}</style></head>
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
   <div class="export-wrap">
     <button type="button" class="export-btn" id="exportBtn" title="Export current display" onclick="toggleExportMenu(event)">⇩</button>
     <div class="export-menu" id="exportMenu">
       <button type="button" onclick="exportCurrent('pdf')">PDF</button>
       <button type="button" onclick="exportCurrent('excel')">Excel</button>
       <button type="button" onclick="exportCurrent('word')">Word</button>
     </div>
   </div>
   <form class="upload" id="uploadForm"><input type="file" name="direct_excel" accept=".xlsx" required><button>↑ Upload Direct Sales</button></form>
 </div>
</div>
<div class="status" id="status"></div>
<div class="tablewrap" id="tablewrap"><table id="table"></table></div>
<div class="summary-toolbar" id="summaryToolbar"><b>Sale Summary</b><select id="summaryType"><option value="coffee">By Coffee Type</option><option value="region">By Region</option><option value="supplier">By Supplier & Coffee Type</option><option value="buyer">By Buyer & Coffee Type</option>
<option value="supplier_region">By Supplier & Region</option>
<option value="buyer_region">By Buyer & Region</option></select></div><div class="summary" id="summary">
 <section class="summary-card summary-view active" data-summary="coffee"><div class="summary-head">Sales Summary by Coffee Type <span class="summary-note">Value = (USD/50kg ÷ 50) × Net kg</span></div><div id="coffeeSummary"></div></section>
 <section class="summary-card summary-view" data-summary="region"><div class="summary-head">Sales Summary by Region <span class="summary-note">Share based on Grand Total net weight</span></div><div id="regionSummary"></div></section>
 <section class="summary-card summary-wide summary-view" data-summary="supplier"><div class="summary-head">Sales Summary by Supplier & Coffee Type</div><div id="supplierCoffeeSummary"></div></section>
 <section class="summary-card summary-wide summary-view" data-summary="buyer"><div class="summary-head">Sales Summary by Buyer & Coffee Type</div><div id="buyerCoffeeSummary"></div></section>
 <section class="summary-card summary-wide summary-view" data-summary="supplier_region"><div class="summary-head">Sales Summary by Supplier & Region</div><div id="supplierRegionSummary"></div></section>
 <section class="summary-card summary-wide summary-view" data-summary="buyer_region"><div class="summary-head">Sales Summary by Buyer & Region</div><div id="buyerRegionSummary"></div></section>
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
function summaryTable(rows,total,firstTitle,isLocalSale=false){
 if(isLocalSale){
   const grandBalance=Number(total.ls_balance_kg)||0;
   let body=rows.map(r=>{
     const originalKg=Number(r.net_kg)||0;
     const balanceKg=Number(r.ls_balance_kg)||0;
     const share=grandBalance>0?balanceKg/grandBalance*100:0;
     return `<tr><td>${esc(r.label)}</td><td>${num(originalKg,3)}</td><td>${num(balanceKg,3)}</td><td>${num(r.value_usd,2)}</td><td>${num(share,2)}%</td></tr>`;
   }).join('');
   return `<table><thead><tr><th>${firstTitle}</th><th>Net Weight (kg)</th><th>LS Balance (kg)</th><th>Balance Value (USD)</th><th>% Share of LS Balance</th></tr></thead>
   <tbody>${body||'<tr><td colspan="5">No data</td></tr>'}</tbody>
   <tfoot><tr><td>Grand Total</td><td>${num(total.net_kg,3)}</td><td>${num(total.ls_balance_kg,3)}</td><td>${num(total.value_usd,2)}</td><td>${grandBalance>0?'100%':'0%'}</td></tr></tfoot></table>`;
 }
 const grand=Number(total.net_kg)||0;
 let body=rows.map(r=>{
   const kg=Number(r.net_kg)||0,share=grand>0?kg/grand*100:0;
   return `<tr><td>${esc(r.label)}</td><td>${num(kg,3)}</td><td>${num(r.value_usd,2)}</td><td>${num(share,2)}%</td></tr>`;
 }).join('');
 return `<table><thead><tr><th>${firstTitle}</th><th>Net Weight (kg)</th><th>Value (USD)</th><th>% Share</th></tr></thead>
 <tbody>${body||'<tr><td colspan="4">No data</td></tr>'}</tbody>
 <tfoot><tr><td>Grand Total</td><td>${num(total.net_kg,3)}</td><td>${num(total.value_usd,2)}</td><td>${grand>0?'100%':'0%'}</td></tr></tfoot></table>`;
}

function partyCoffeeTable(rows,partyTitle){
 const types=[...new Set(rows.map(r=>r.coffee_type))].sort((a,b)=>String(a).localeCompare(String(b)));
 const parties=[...new Set(rows.map(r=>r.party))].sort((a,b)=>String(a).localeCompare(String(b)));
 const m=new Map(rows.map(r=>[`${r.party}\u0000${r.coffee_type}`,r]));
 const totals=Object.fromEntries(types.map(c=>[c,{kg:0,val:0}])); let gk=0,gv=0;
 let h=`<thead><tr><th rowspan="2">${partyTitle}</th>${types.map(c=>`<th colspan="2">${esc(c)}</th>`).join('')}<th colspan="2">Grand Total</th></tr><tr>${types.map(()=>'<th>Kg</th><th>Value (USD)</th>').join('')}<th>Kg</th><th>Value (USD)</th></tr></thead><tbody>`;
 h+=parties.map(p=>{let pk=0,pv=0,cells=types.map(c=>{let r=m.get(`${p}\u0000${c}`),kg=Number(r?.net_kg)||0,v=Number(r?.value_usd)||0;pk+=kg;pv+=v;totals[c].kg+=kg;totals[c].val+=v;return `<td>${num(kg,3)}</td><td>${num(v,2)}</td>`}).join('');gk+=pk;gv+=pv;return `<tr><td>${esc(p)}</td>${cells}<td>${num(pk,3)}</td><td>${num(pv,2)}</td></tr>`}).join('');
 h+=`</tbody><tfoot><tr><td>Grand Total</td>${types.map(c=>`<td>${num(totals[c].kg,3)}</td><td>${num(totals[c].val,2)}</td>`).join('')}<td>${num(gk,3)}</td><td>${num(gv,2)}</td></tr></tfoot>`;
 return `<div style="overflow:auto">${h}</table></div>`.replace('<thead>','<table><thead>');
}

function showSelectedSummary(){const v=$('summaryType').value;document.querySelectorAll('.summary-view').forEach(x=>x.classList.toggle('active',x.dataset.summary===v));}
$('summaryType').addEventListener('change',showSelectedSummary);
function partyRegionTable(rows,partyTitle){return partyCoffeeTable((rows||[]).map(r=>({...r,coffee_type:r.region})),partyTitle);}
async function loadSummary(){
 try{
   $('status').textContent='Preparing Sale Summary…';
   let d=await api('Direct_sales.php?action=summary&category='+encodeURIComponent(category)+'&season='+encodeURIComponent($('season').value));
   $('coffeeSummary').innerHTML=summaryTable(d.coffee_type,d.total,'Coffee Type',!!d.is_local_sale);
   $('regionSummary').innerHTML=summaryTable(d.regions,d.total,'Region',!!d.is_local_sale);
   $('supplierCoffeeSummary').innerHTML=partyCoffeeTable(d.supplier_coffee||[],'Supplier / Seller');
   $('buyerCoffeeSummary').innerHTML=partyCoffeeTable(d.buyer_coffee||[],'Buyer');
   $('supplierRegionSummary').innerHTML=partyRegionTable(d.supplier_region||[],'Supplier / Seller');
   $('buyerRegionSummary').innerHTML=partyRegionTable(d.buyer_region||[],'Buyer');
   showSelectedSummary();
   $('status').textContent=category+' Sale Summary'+($('season').value?' · '+$('season').value:' · All Sale Seasons')+(d.is_local_sale?' · Value and share based on remaining LS Balance':'');
 }catch(e){$('status').textContent=e.message}
}
async function refreshCurrent(){
 const summary=$('display').value==='summary';
 $('tablewrap').style.display=summary?'none':'block';
 $('summary').classList.toggle('show',summary);
 $('summaryToolbar').classList.toggle('show',summary);
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

function toggleExportMenu(e){
 e.stopPropagation();
 $('exportMenu').classList.toggle('show');
}
document.addEventListener('click',()=>$('exportMenu')?.classList.remove('show'));

function exportFileName(ext){
 const mode=$('display').value==='summary'?'Sale_Summary':'All_Sales';
 const season=$('season').value||'All_Seasons';
 return (category+'_'+mode+'_'+season).replace(/[^\w.-]+/g,'_')+'.'+ext;
}
function currentExportNode(){
 return $('display').value==='summary' ? (document.querySelector('.summary-view.active')||$('summary')) : $('tablewrap');
}
function exportTitle(){
 return `${category} — ${$('display').value==='summary'?'Sale Summary':'All Sales'} — ${$('season').value||'All Sale Seasons'}`;
}
function exportWord(){
 const node=currentExportNode().cloneNode(true);
 node.querySelectorAll('*').forEach(el=>{el.style.maxHeight='none';el.style.height='auto';el.style.overflow='visible'});
 const html=`<!doctype html><html><head><meta charset="utf-8"><style>
 body{font-family:Arial,sans-serif;font-size:10pt}h2{margin-bottom:10px;color:#3e2723}
 table{width:100%;border-collapse:collapse;margin-bottom:16px}th,td{border:1px solid #555;padding:5px;text-align:right}
 th:first-child,td:first-child{text-align:left}th{font-weight:bold}tfoot td{font-weight:bold;border-top:2px solid #333}
 .summary{display:block}.summary-card{margin-bottom:15px}.summary-head{font-weight:bold;margin:7px 0}
 </style></head><body><h2>${esc(exportTitle())}</h2>${node.innerHTML}</body></html>`;
 const blob=new Blob(['\ufeff',html],{type:'application/msword'});
 const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=exportFileName('doc');a.click();URL.revokeObjectURL(a.href);
}
function exportExcel(){
 if(typeof XLSX==='undefined'){alert('Excel export library is not available.');return}
 const wb=XLSX.utils.book_new();

 // Force quantitative fields to real Excel numeric cells, not formatted text.
 const numericHeaders=new Set([
   'Net Kg','Net Weight (kg)','Local Sale Balance (kg)','LS Balance (kg)',
   'Price (USD/50kgs)','Exchange Rate','Value (USD)','Balance Value (USD)',
   '% Share','% Share of LS Balance'
 ]);
 const percentHeaders=new Set(['% Share','% Share of LS Balance']);

 function sheetFromTable(table){
   const matrix=[];
   const trs=[...table.querySelectorAll('tr')];
   trs.forEach(tr=>{
     const row=[...tr.querySelectorAll('th,td')].map(cell=>cell.textContent.trim());
     matrix.push(row);
   });
   if(!matrix.length)return XLSX.utils.aoa_to_sheet([]);

   const headers=matrix[0];
   const numericIndexes=new Set();
   const percentIndexes=new Set();
   headers.forEach((h,i)=>{
     if(numericHeaders.has(h))numericIndexes.add(i);
     if(percentHeaders.has(h))percentIndexes.add(i);
   });

   const aoa=matrix.map((row,ri)=>row.map((v,ci)=>{
     if(ri===0 || !numericIndexes.has(ci)) return v;
     if(v==='' || v==='-' || v===null) return null;
     const cleaned=String(v).replace(/,/g,'').replace(/%/g,'').trim();
     const n=Number(cleaned);
     if(!Number.isFinite(n)) return v;
     return percentIndexes.has(ci) ? n/100 : n;
   }));

   const ws=XLSX.utils.aoa_to_sheet(aoa);
   const range=XLSX.utils.decode_range(ws['!ref']||'A1');
   for(let c=range.s.c;c<=range.e.c;c++){
     const header=headers[c]||'';
     if(!numericHeaders.has(header))continue;
     for(let r=1;r<=range.e.r;r++){
       const addr=XLSX.utils.encode_cell({r,c});
       const cell=ws[addr];
       if(!cell || cell.v===null || typeof cell.v!=='number')continue;
       cell.t='n';
       if(percentHeaders.has(header)) cell.z='0.00%';
       else if(header==='Exchange Rate') cell.z='#,##0.00####';
       else if(header.includes('Price') || header.includes('Value')) cell.z='#,##0.00####';
       else cell.z='#,##0.###';
     }
   }
   return ws;
 }

 if($('display').value==='summary'){
   const opts={coffee:['Coffee Type',$('coffeeSummary').querySelector('table')],region:['Region',$('regionSummary').querySelector('table')],supplier:['Supplier by Coffee',$('supplierCoffeeSummary').querySelector('table')],buyer:['Buyer by Coffee',$('buyerCoffeeSummary').querySelector('table')],
     supplier_region:['Supplier by Region',$('supplierRegionSummary').querySelector('table')],
     buyer_region:['Buyer by Region',$('buyerRegionSummary').querySelector('table')]};
   const [name,table]=opts[$('summaryType').value]; if(table)XLSX.utils.book_append_sheet(wb,sheetFromTable(table),name);
 }else{
   const table=$('table');
   if(table)XLSX.utils.book_append_sheet(wb,sheetFromTable(table),'All Sales');
 }
 XLSX.writeFile(wb,exportFileName('xlsx'),{cellStyles:true});
}
async function exportPdf(){
 if(!window.jspdf||typeof html2canvas==='undefined'){alert('PDF export library is not available.');return}
 $('exportMenu').classList.remove('show');
 const source=currentExportNode();
 const clone=source.cloneNode(true);
 clone.style.display='block';clone.style.position='fixed';clone.style.left='-100000px';clone.style.top='0';
 clone.style.width=$('display').value==='summary'?'1100px':'1800px';clone.style.height='auto';clone.style.maxHeight='none';clone.style.overflow='visible';
 clone.querySelectorAll('*').forEach(el=>{el.style.maxHeight='none';el.style.height='auto';el.style.overflow='visible'});
 document.body.appendChild(clone);
 try{
   const canvas=await html2canvas(clone,{scale:1.5,backgroundColor:'#ffffff',useCORS:true});
   const {jsPDF}=window.jspdf;
   const landscape=canvas.width>canvas.height;
   const pdf=new jsPDF({orientation:landscape?'landscape':'portrait',unit:'mm',format:'a4'});
   const pw=pdf.internal.pageSize.getWidth(),ph=pdf.internal.pageSize.getHeight(),margin=7;
   const iw=pw-margin*2,ih=canvas.height*iw/canvas.width;
   const pageH=ph-margin*2;
   if(ih<=pageH){
     pdf.addImage(canvas.toDataURL('image/jpeg',.94),'JPEG',margin,margin,iw,ih);
   }else{
     const pxPage=Math.floor(canvas.width*pageH/iw);
     let y=0,page=0;
     while(y<canvas.height){
       const h=Math.min(pxPage,canvas.height-y),slice=document.createElement('canvas');
       slice.width=canvas.width;slice.height=h;
       slice.getContext('2d').drawImage(canvas,0,y,canvas.width,h,0,0,canvas.width,h);
       if(page++)pdf.addPage(undefined,landscape?'landscape':'portrait');
       pdf.addImage(slice.toDataURL('image/jpeg',.94),'JPEG',margin,margin,iw,h*iw/canvas.width);
       y+=h;
     }
   }
   pdf.save(exportFileName('pdf'));
 }finally{clone.remove()}
}
function exportCurrent(type){
 $('exportMenu').classList.remove('show');
 if(type==='excel')exportExcel();
 else if(type==='word')exportWord();
 else exportPdf();
}

(async()=>{await loadFilters();await refreshCurrent()})();
</script></body></html>