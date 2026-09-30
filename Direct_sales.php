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
   if($a==='update' && ($_SERVER['REQUEST_METHOD']??'')==='POST'){
       $in=json_decode(file_get_contents('php://input'),true)?:[];
       $id=(int)($in['id']??0);
       if($id<=0) direct_json(false,'Invalid database row.',[],400);
       $fields=['sale_category','invoice_number','source_invoice_number','invoice_date','contract_number','grade_name','coffee_type','net_kg','price_usd_50kg','exchange_rate','warehouse_name','warehouse_location','region','supplier_seller','buyer'];
       $set=[];$p=['id'=>$id];
       foreach($fields as $f){
           if(!array_key_exists($f,$in)) continue;
           $v=$in[$f];
           if($f==='invoice_date'){
               $v=direct_date($v);
               if(!$v) direct_json(false,'A valid Invoice Date is required.',[],400);
               $set[]='invoice_date=:invoice_date';$p['invoice_date']=$v;
               $set[]='crop_season=:crop_season';$p['crop_season']=direct_sale_season($v);
               continue;
           }
           if($f==='sale_category'){
               $v=direct_category((string)$v);
               if(!in_array($v,['Direct Export','Local Sale','Local Roast'],true)) direct_json(false,'Invalid Sale Category.',[],400);
           }
           if(in_array($f,['net_kg','price_usd_50kg','exchange_rate'],true)){
               $v=direct_num($v);
               if($f==='net_kg' && ($v===null || $v<=0)) direct_json(false,'Net Kg must be greater than zero.',[],400);
           }else{
               $v=trim((string)$v);
               if($v==='') $v=null;
           }
           $set[]="$f=:$f";$p[$f]=$v;
       }
       if(!$set) direct_json(false,'No changes were supplied.',[],400);
       $st=direct_db()->prepare('UPDATE public.direct_sales SET '.implode(',',$set).' WHERE id=:id');
       $st->execute($p);
       if(!$st->rowCount()) direct_json(false,'The Direct Sales row was not found or no value changed.',[],404);
       direct_json(true,'Row updated successfully.');
   }
   if($a==='delete' && ($_SERVER['REQUEST_METHOD']??'')==='POST'){
       $in=json_decode(file_get_contents('php://input'),true)?:[];
       $id=(int)($in['id']??0);
       if($id<=0) direct_json(false,'Invalid database row.',[],400);
       $st=direct_db()->prepare('DELETE FROM public.direct_sales WHERE id=:id');
       $st->execute(['id'=>$id]);
       if(!$st->rowCount()) direct_json(false,'The Direct Sales row was not found.',[],404);
       direct_json(true,'Row deleted successfully.');
   }
   if($a==='delete_all' && ($_SERVER['REQUEST_METHOD']??'')==='POST'){
       $st=direct_db()->prepare('DELETE FROM public.direct_sales WHERE sale_category=:c');
       $st->execute(['c'=>$cat]);
       direct_json(true,'All '.$cat.' records were deleted.',['deleted'=>$st->rowCount()]);
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
*{box-sizing:border-box}html,body{margin:0;height:100%;font-family:Arial,sans-serif;background:#f7f4f2;color:#382b26}
body{overflow:hidden}.app{height:100vh;padding:8px;display:grid;grid-template-rows:auto auto minmax(0,1fr);gap:6px}
.toolbar,.filters{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.toolbar{justify-content:space-between}
.title{font-size:15px;font-weight:800;color:#4b342c}.muted{font-size:9px;color:#8b7d77}.controls{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
select,button,input{height:30px;border:1px solid #d9cfca;border-radius:6px;background:#fff;padding:0 9px;font-size:11px;color:#4b342c}button{cursor:pointer;font-weight:700}.primary{background:#5d4037;color:#fff;border-color:#5d4037}
.export-wrap,.settings{position:relative}.export-menu,.menu{display:none;position:absolute;right:0;top:34px;background:#fff;border:1px solid #ddd2cd;border-radius:7px;padding:5px;z-index:30;box-shadow:0 8px 22px #0002}.export-menu{min-width:110px}.menu{min-width:175px}.export-menu.show,.menu.open{display:block}.export-menu button,.menu button{display:block;width:100%;text-align:left;border:0;background:#fff}.export-menu button:hover,.menu button:hover{background:#f5f1ef}
.uploadbox{display:none;align-items:center;gap:5px}.uploadbox.open{display:flex}.upload{display:flex;gap:5px;align-items:center}.status{font-size:10px;padding:2px 2px;color:#6d4c41;min-height:14px}
.tablewrap{min-height:0;background:#fff;border:1px solid #e6ddd9;border-radius:8px;overflow:auto;height:auto}table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:10px}th{position:sticky;top:0;z-index:2;background:#4b342c;color:#fff;white-space:nowrap}th,td{padding:5px 6px;border-right:1px solid #eee7e4;border-bottom:1px solid #eee7e4;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}td.text{text-align:left}.num{font-variant-numeric:tabular-nums}.actions{display:none}.editmode .actions{display:table-cell}.rowbtn{height:23px;padding:0 5px;font-size:9px}.danger{color:#8a3029}
.summary{display:none}.summary.show{display:block;min-height:0;overflow:auto}.summary-card{background:#fff;border:1px solid #d9d2cf;border-radius:8px;overflow:auto}.summary-head{padding:7px 9px;font-size:12px;font-weight:700;border-bottom:1px solid #d9d2cf;background:#faf8f7}.summary-card table{width:100%;min-width:650px;border-collapse:collapse;table-layout:fixed}.summary-card th,.summary-card td{padding:5px 6px;border:1px solid #ded8d5}.summary-card th{position:static;background:#4b342c;color:#fff}.summary-card th:first-child,.summary-card td:first-child{text-align:left;width:34%}.summary-card tfoot td{font-weight:800;border-top:2px solid #4e342e;background:#faf8f7}.summary-note{font-size:10px;font-weight:400;color:#795548;margin-left:5px}
.summary-toolbar{display:none;justify-content:space-between;align-items:center;padding:6px 8px;background:#fff;border:1px solid #d9d2cf;border-radius:8px}.summary-toolbar.show{display:flex}.summary-toolbar select{min-width:230px}.summary-view{display:none!important}.summary-view.active{display:block!important;width:100%}
@media(max-width:900px){body{overflow:auto}.app{height:auto;min-height:100vh;overflow:visible}.toolbar{align-items:flex-start}.controls,.filters{width:100%}.tablewrap{min-height:65vh}.summary.show{overflow:visible}}
@media(max-width:600px){.app{padding:5px}.title{font-size:13px}select,button,input{height:28px;font-size:10px}.controls>*{flex:1 1 auto}.uploadbox.open{width:100%;flex-wrap:wrap}.upload{width:100%;flex-wrap:wrap}.upload input{flex:1 1 180px;min-width:0}.summary-toolbar{flex-direction:column;align-items:stretch;gap:6px}.summary-toolbar select{width:100%;min-width:0}.export-menu,.menu{right:0}}
</style></head>
<body><div class="app">
<div class="toolbar">
 <div><span class="title"><?=htmlspecialchars($category)?></span> <span class="muted">Direct Sales database · PostgreSQL</span></div>
 <div class="controls">
   <select id="display">
     <option value="sales">All <?=htmlspecialchars($category)?> Sales</option>
     <option value="summary">Sale Summary</option>
   </select>
   <div class="export-wrap">
     <button type="button" id="exportBtn" onclick="toggleExportMenu(event)">⇩ Export</button>
     <div class="export-menu" id="exportMenu">
       <button type="button" onclick="exportCurrent('pdf')">PDF</button>
       <button type="button" onclick="exportCurrent('excel')">Excel</button>
       <button type="button" onclick="exportCurrent('word')">Word</button>
     </div>
   </div>
   <div class="settings">
     <button type="button" onclick="toggleSettings(event)">⚙ Settings</button>
     <div class="menu" id="settingsMenu"><button type="button" onclick="showUpload()">↑ Upload Direct Sales</button><button type="button" onclick="toggleEdit()">✎ Edit selected display</button><button type="button" class="danger" onclick="deleteAll()">⌫ Delete all <?=htmlspecialchars($category)?> data</button></div>
   </div>
 </div>
</div>
<div>
 <div class="filters">
   <select id="season" title="Sale Season: 1 July to 30 June"><option value="">All Sale Seasons</option></select>
   <button type="button" onclick="refreshCurrent()">↻ Refresh</button>
   <div class="uploadbox" id="uploadbox"><form class="upload" id="uploadForm"><input type="file" name="direct_excel" accept=".xlsx" required><button class="primary">Upload Direct Sales</button></form></div>
 </div>
 <div class="status" id="status"></div>
</div>
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
let rows=[],editMode=false;
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
  rows=d.rows||[];
  let h='<thead><tr>'+cols.map(c=>`<th>${c[1]}</th>`).join('')+'<th class="actions">Actions</th></tr></thead><tbody>';
  h+=rows.map(r=>'<tr>'+cols.map(c=>`<td class="${['net_kg','local_sale_balance_kg','price_usd_50kg','exchange_rate'].includes(c[0])?'num':'text'}">${esc(['net_kg','local_sale_balance_kg','price_usd_50kg','exchange_rate'].includes(c[0])?num(r[c[0]]):r[c[0]])}</td>`).join('')+`<td class="actions"><button class="rowbtn" onclick="editRow(${r.id})">✎</button> <button class="rowbtn danger" onclick="deleteRow(${r.id})">⌫</button></td></tr>`).join('');
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
 if(summary) $('uploadbox').classList.remove('open');
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
 $('settingsMenu')?.classList.remove('open');
 $('exportMenu').classList.toggle('show');
}
function toggleSettings(e){
 e?.stopPropagation();
 $('exportMenu')?.classList.remove('show');
 $('settingsMenu')?.classList.toggle('open');
}
function showUpload(){
 $('uploadbox')?.classList.toggle('open');
 $('settingsMenu')?.classList.remove('open');
}
function toggleEdit(){
 if($('display').value!=='sales'){
   $('status').textContent='Edit is available for the All '+category+' Sales table.';
   $('settingsMenu')?.classList.remove('open');
   return;
 }
 editMode=!editMode;
 $('tablewrap').classList.toggle('editmode',editMode);
 $('settingsMenu')?.classList.remove('open');
 loadRows();
}
async function deleteRow(id){
 if(!confirm('Delete this row permanently?'))return;
 try{
   let d=await api('Direct_sales.php?action=delete&category='+encodeURIComponent(category),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
   $('status').textContent=d.message;
   await loadFilters();await refreshCurrent();
 }catch(e){$('status').textContent=e.message}
}
async function deleteAll(){
 $('settingsMenu')?.classList.remove('open');
 if(!confirm('Delete ALL '+category+' records permanently?'))return;
 try{
   let d=await api('Direct_sales.php?action=delete_all&category='+encodeURIComponent(category),{method:'POST'});
   $('status').textContent=d.message;
   await loadFilters();await refreshCurrent();
 }catch(e){$('status').textContent=e.message}
}
async function editRow(id){
 const r=rows.find(x=>Number(x.id)===Number(id));if(!r)return;
 const editable=cols.filter(([k])=>!['sale_season','local_sale_balance_kg'].includes(k));
 const p={id};
 for(const [k,label] of editable){
   let v=prompt(label,r[k]??'');if(v===null)return;p[k]=v;
 }
 try{
   let d=await api('Direct_sales.php?action=update&category='+encodeURIComponent(category),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});
   $('status').textContent=d.message;
   await loadFilters();await refreshCurrent();
 }catch(e){$('status').textContent=e.message}
}
document.addEventListener('click',()=>{ $('exportMenu')?.classList.remove('show'); $('settingsMenu')?.classList.remove('open'); });

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