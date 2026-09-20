<?php
ob_start();
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $api = isset($_GET['action']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($api) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Your session has expired. Please log in again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/clean_database.php';

function clean_json(bool $success, string $message='', array $data=[], int $status=200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function clean_norm($v): string {
    $s=strtolower(trim((string)$v));
    $s=str_replace(['\\','/','(',')','.','#'],[' ',' ',' ',' ',' ',' '],$s);
    $s=preg_replace('/[^a-z0-9]+/','_',$s);
    return trim($s,'_');
}
function clean_number($v): ?float {
    if ($v===null || trim((string)$v)==='') return null;
    $s=str_replace([',',' '],'',trim((string)$v));
    return is_numeric($s) ? (float)$s : null;
}
function clean_date($v): ?string {
    if ($v===null || trim((string)$v)==='') return null;
    $s=preg_replace('/\s+/',' ',trim((string)$v));
    if (is_numeric($s)) {
        $n=(float)$s;
        if ($n>20000 && $n<80000) {
            $base=new DateTime('1899-12-30');
            $base->modify('+'.(int)floor($n).' days');
            return $base->format('Y-m-d');
        }
    }
    foreach (['Y-m-d','d/m/Y','d-m-Y','d M Y','j F Y','F j Y','F j, Y','j F, Y'] as $fmt) {
        $d=DateTime::createFromFormat('!'.$fmt,$s);
        if ($d && $d->format($fmt)===$s) return $d->format('Y-m-d');
    }
    $ts=strtotime($s);
    return $ts===false ? null : date('Y-m-d',$ts);
}
function clean_col_letters_to_index(string $letters): int {
    $n=0;
    foreach(str_split($letters) as $c) $n=$n*26+(ord(strtoupper($c))-64);
    return $n-1;
}
function clean_parse_xlsx(string $file): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required to read .xlsx files.');
    $zip=new ZipArchive();
    if ($zip->open($file)!==true) throw new RuntimeException('The Excel workbook could not be opened.');

    $shared=[];
    $ss=$zip->getFromName('xl/sharedStrings.xml');
    if ($ss!==false) {
        $xml=simplexml_load_string($ss);
        if ($xml) foreach($xml->si as $si) {
            $parts=[];
            if (isset($si->t)) $parts[]=(string)$si->t;
            if (isset($si->r)) foreach($si->r as $r) $parts[]=(string)$r->t;
            $shared[]=implode('',$parts);
        }
    }

    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet===false) { $zip->close(); throw new RuntimeException('Worksheet sheet1.xml was not found.'); }
    $xml=simplexml_load_string($sheet);
    $rows=[];
    foreach($xml->sheetData->row as $row) {
        $out=[];
        foreach($row->c as $c) {
            $ref=(string)$c['r'];
            preg_match('/([A-Z]+)\d+/',$ref,$m);
            $idx=clean_col_letters_to_index($m[1] ?? 'A');
            $type=(string)$c['t'];
            $value='';
            if ($type==='s') $value=$shared[(int)$c->v] ?? '';
            elseif ($type==='inlineStr') $value=(string)$c->is->t;
            else $value=(string)$c->v;
            $out[$idx]=$value;
        }
        if ($out) {
            ksort($out);
            $max=max(array_keys($out));
            $full=array_fill(0,$max+1,'');
            foreach($out as $i=>$v) $full[$i]=$v;
            $rows[]=$full;
        }
    }
    $zip->close();
    return $rows;
}
function clean_parse_csv(string $file): array {
    $rows=[]; $h=fopen($file,'rb');
    if (!$h) return [];
    while(($r=fgetcsv($h))!==false) $rows[]=$r;
    fclose($h); return $rows;
}
function clean_parse_file(string $file,string $ext): array {
    if ($ext==='xlsx' || $ext==='xlsm' || $ext==='xltx') return clean_parse_xlsx($file);
    if ($ext==='csv') return clean_parse_csv($file);
    throw new RuntimeException('Please upload an .xlsx, .xlsm, .xltx or .csv file.');
}

function clean_expected_headers(): array {
    return [
        'lot_no'=>['lot_no','lot_number','lot'],
        'auction_no'=>['auction_no','auction_number','auction'],
        'auction_date'=>['auction_date','date'],
        'seller'=>['seller'],
        'crop_season'=>['crop_season','season'],
        'grade'=>['grade'],
        'grade2'=>['grade2','grade_2'],
        'n_kgs'=>['n_kgs','kgs','n_kg','net_kgs'],
        'warehouse'=>['warehouse'],
        'district'=>['district'],
        'region'=>['region'],
        'price_per_50kg'=>['price_50kg','price_per_50kg','price'],
        'status'=>['status'],
        'buyer'=>['buyer','buyer_name'],
    ];
}
function clean_map_headers(array $raw): array {
    $norm=array_map('clean_norm',$raw);
    $map=[];
    foreach(clean_expected_headers() as $field=>$aliases) {
        $map[$field]=null;
        foreach($aliases as $alias) {
            $i=array_search($alias,$norm,true);
            if ($i!==false) { $map[$field]=$i; break; }
        }
    }
    return $map;
}
function clean_validate_headers(array $raw): array {
    $nonEmpty=array_values(array_filter($raw,fn($x)=>trim((string)$x)!==''));
    $map=clean_map_headers($raw);
    $missing=[];
    $labels=[
        'lot_no'=>'LOT NO.','auction_no'=>'AUCTION NO.','auction_date'=>'AUCTION DATE',
        'seller'=>'SELLER','crop_season'=>'CROP SEASON','grade'=>'GRADE','grade2'=>'GRADE2',
        'n_kgs'=>'N/KGS','warehouse'=>'WAREHOUSE','district'=>'DISTRICT','region'=>'REGION',
        'price_per_50kg'=>'PRICE (/50Kg)','status'=>'STATUS','buyer'=>'BUYER'
    ];
    foreach($map as $k=>$v) if($v===null) $missing[]=$labels[$k];
    if (count($nonEmpty)!==14 || $missing) {
        $msg='Upload stopped. Clean Auction Results requires exactly 14 columns.';
        if($missing) $msg.=' Missing column(s): '.implode(', ',$missing).'.';
        $msg.=' Expected: '.implode(', ',array_values($labels)).'.';
        clean_json(false,$msg,['expected_columns'=>array_values($labels)],400);
    }
    return $map;
}

function clean_upload(): void {
    if (!isset($_FILES['clean_excel'])) clean_json(false,'Please select a Clean Auction Results Excel file.',[],400);
    $f=$_FILES['clean_excel'];
    if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) clean_json(false,'The selected file could not be uploaded.',[],400);
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    try { $rows=clean_parse_file($f['tmp_name'],$ext); }
    catch(Throwable $e){ clean_json(false,$e->getMessage(),[],400); }
    if(count($rows)<2) clean_json(false,'The workbook does not contain enough data.',[],400);

    $headerIndex=null;
    for($i=0;$i<min(15,count($rows));$i++){
        $m=clean_map_headers($rows[$i]);
        if($m['lot_no']!==null && $m['auction_no']!==null && $m['auction_date']!==null){$headerIndex=$i;break;}
    }
    if($headerIndex===null) clean_json(false,'The Clean Auction Results header row could not be identified.',[],400);
    $map=clean_validate_headers($rows[$headerIndex]);

    $records=[];$errors=[];
    foreach($rows as $ri=>$row){
        if($ri<=$headerIndex) continue;
        $get=function($k)use($row,$map){$i=$map[$k];return trim((string)($row[$i]??''));};
        $lot=$get('lot_no'); $auction=$get('auction_no'); $date=clean_date($get('auction_date'));
        if($lot==='' && $auction==='' && $date===null) continue;

        $kgs=clean_number($get('n_kgs'));
        $status=strtolower(trim($get('status')));
        $price=clean_number($get('price_per_50kg'));
        $missing=[];
        if($lot==='')$missing[]='Lot No.';
        if($auction==='')$missing[]='Auction No.';
        if($date===null)$missing[]='Auction Date';
        if($kgs===null || $kgs<=0)$missing[]='valid N/KGS';
        if($status==='')$missing[]='Status';
        if($status==='sold' && ($price===null || $price<=0))$missing[]='Price (/50Kg) for sold lot';
        if($missing){$errors[]=['excel_row'=>$ri+1,'missing_or_invalid'=>$missing];continue;}

        $key=$lot."\x1f".$auction."\x1f".$date;
        $records[$key]=[
            'lot_no'=>$lot,'auction_no'=>$auction,'auction_date'=>$date,
            'seller'=>$get('seller'),'crop_season'=>$get('crop_season'),
            'grade'=>$get('grade'),'grade2'=>$get('grade2'),'n_kgs'=>$kgs,
            'warehouse'=>$get('warehouse'),'district'=>$get('district'),
            'region'=>$get('region'),'price_per_50kg'=>$price,
            'status'=>$get('status'),'buyer'=>$get('buyer')
        ];
    }
    if($errors) clean_json(false,'Some rows contain blank or invalid mandatory fields. Nothing was uploaded.',['errors'=>$errors],400);
    if(!$records) clean_json(false,'No valid Clean Auction records were found.',[],400);

    ensure_clean_table(); $db=clean_db();
    $existing=[];
    $q=$db->query("SELECT id,lot_no,auction_no,auction_date FROM public.clean_auction_results");
    foreach($q as $r) $existing[trim($r['lot_no'])."\x1f".trim($r['auction_no'])."\x1f".$r['auction_date']]=$r;
    $dupes=array_values(array_intersect(array_keys($records),array_keys($existing)));
    $confirmed=($_POST['confirm_replace']??'')==='1';
    if($dupes && !$confirmed) clean_json(true,'Existing matching Clean Auction records were found. Confirm replacement to continue.',[
        'requires_confirmation'=>true,'existing_count'=>count($dupes),
        'new_record_count'=>count($records)-count($dupes)
    ]);

    $db->beginTransaction();
    try{
        $del=$db->prepare("DELETE FROM public.clean_auction_results WHERE lot_no=:lot_no AND auction_no=:auction_no AND auction_date=:auction_date");
        $ins=$db->prepare("INSERT INTO public.clean_auction_results
          (lot_no,auction_no,auction_date,seller,crop_season,grade,grade2,n_kgs,warehouse,district,region,price_per_50kg,status,buyer)
          VALUES(:lot_no,:auction_no,:auction_date,:seller,:crop_season,:grade,:grade2,:n_kgs,:warehouse,:district,:region,:price_per_50kg,:status,:buyer)");
        foreach($records as $key=>$r){
            if(isset($existing[$key])) $del->execute(['lot_no'=>$r['lot_no'],'auction_no'=>$r['auction_no'],'auction_date'=>$r['auction_date']]);
            $ins->execute($r);
        }
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        error_log('Clean Auction upload error: '.$e->getMessage());
        clean_json(false,'The Clean Auction records could not be saved to the database.',[],500);
    }
    clean_json(true,'Clean Auction Results uploaded successfully.',[
        'requires_confirmation'=>false,'processed_records'=>count($records),
        'replaced_records'=>count($dupes),'new_records'=>count($records)-count($dupes)
    ]);
}

function clean_filters(): array {
    ensure_clean_table(); $db=clean_db();

    /* Sale Season is derived ONLY from Auction Date:
       01 July YYYY through 30 June YYYY+1 = YYYY/YYYY+1. */
    $seasonExpr = "(CASE
        WHEN EXTRACT(MONTH FROM auction_date) >= 7
        THEN EXTRACT(YEAR FROM auction_date)::int
        ELSE (EXTRACT(YEAR FROM auction_date)::int - 1)
    END)";

    $seasons = $db->query("
        SELECT DISTINCT
            ($seasonExpr)::text || '/' || (($seasonExpr) + 1)::text AS sale_season
        FROM public.clean_auction_results
        WHERE auction_date IS NOT NULL
        ORDER BY sale_season DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $latest = $db->query("
        SELECT auction_no, auction_date,
               ($seasonExpr)::text || '/' || (($seasonExpr) + 1)::text AS sale_season
        FROM public.clean_auction_results
        WHERE auction_date IS NOT NULL
        ORDER BY auction_date DESC,
          CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::integer ELSE NULL END DESC NULLS LAST,
          id DESC
        LIMIT 1
    ")->fetch();

    clean_json(true,'',['seasons'=>$seasons,'latest'=>$latest?:null]);
}
function clean_fetch(): void {
    ensure_clean_table(); $db=clean_db();
    $season=trim($_GET['season']??'');$auction=trim($_GET['auction_no']??'');
    $where=[];$p=[];
    if($season!==''){
        [$from,$to]=clean_sale_season_range($season);
        $where[]='auction_date BETWEEN :season_from AND :season_to';
        $p['season_from']=$from;
        $p['season_to']=$to;
    }
    if($auction!==''){$where[]='auction_no=:auction';$p['auction']=$auction;}
    $sql="SELECT id,lot_no,auction_no,TO_CHAR(auction_date,'DD/MM/YYYY') auction_date,seller,crop_season,grade,grade2,
                 n_kgs,warehouse,district,region,price_per_50kg,status,buyer
          FROM public.clean_auction_results";
    if($where)$sql.=' WHERE '.implode(' AND ',$where);
    $sql.=" ORDER BY auction_date DESC,
       CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::integer ELSE NULL END DESC NULLS LAST,
       auction_no DESC,
       CASE WHEN lot_no ~ '^[0-9]+$' THEN lot_no::integer ELSE NULL END ASC NULLS LAST, lot_no ASC";
    $s=$db->prepare($sql);$s->execute($p);
    clean_json(true,'',['rows'=>$s->fetchAll()]);
}
function clean_sale_season_range(string $season): array {
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $season, $m) || (int)$m[2] !== (int)$m[1] + 1) {
        throw new InvalidArgumentException('Invalid Sale Season.');
    }
    $start = $m[1] . '-07-01';
    $end   = $m[2] . '-06-30';
    return [$start, $end];
}

function clean_auctions(): void {
    ensure_clean_table(); $db=clean_db();
    $season=trim($_GET['season']??'');

    $where='';
    $params=[];
    if ($season !== '') {
        [$from,$to]=clean_sale_season_range($season);
        $where='WHERE auction_date BETWEEN :from AND :to';
        $params=['from'=>$from,'to'=>$to];
    }

    $s=$db->prepare("
        SELECT auction_no, MAX(auction_date) AS d
        FROM public.clean_auction_results
        $where
        GROUP BY auction_no
        ORDER BY MAX(auction_date) DESC,
          CASE WHEN auction_no ~ '^[0-9]+$' THEN auction_no::integer ELSE NULL END DESC NULLS LAST,
          auction_no DESC
    ");
    $s->execute($params);
    clean_json(true,'',['auctions'=>$s->fetchAll()]);
}
function clean_report(): void {
    ensure_clean_table(); $db=clean_db();
    $season=trim($_GET['season']??'');
    $auction=trim($_GET['auction_no']??'');
    if($auction==='') clean_json(false,'Select an Auction No. to view High & Low.',[],400);

    $where="auction_no=:a";
    $p=['a'=>$auction];
    if($season!==''){
        [$from,$to]=clean_sale_season_range($season);
        $where.=" AND auction_date BETWEEN :season_from AND :season_to";
        $p['season_from']=$from;
        $p['season_to']=$to;
    }

    /*
     * Excel PRICE (/50Kg) is already USD per 50 kg.
     * A sold lot is recognised after trimming/case-normalising STATUS.
     * Price statistics exclude blank/zero prices but do NOT divide the displayed
     * lowest/average/highest price by 50.
     */
    $sold = "UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
    $validPrice = "($sold AND price_per_50kg IS NOT NULL AND price_per_50kg > 0)";
    $gradeNorm = "UPPER(REGEXP_REPLACE(BTRIM(COALESCE(grade,'')), '[^A-Za-z0-9]+', '', 'g'))";

    $sql="SELECT
        MAX(auction_date) AS auction_date,
        COALESCE(SUM(n_kgs),0) AS offered_kgs,
        COALESCE(SUM(CASE WHEN $sold THEN n_kgs ELSE 0 END),0) AS sold_kgs,
        COALESCE(SUM(CASE WHEN $validPrice THEN (n_kgs * price_per_50kg / 50.0) ELSE 0 END),0) AS total_value,

        MIN(CASE WHEN $validPrice AND $gradeNorm IN ('AAA','AA','AB','A','B','PB') THEN price_per_50kg END) AS top_low,
        CASE WHEN SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AAA','AA','AB','A','B','PB') THEN n_kgs ELSE 0 END)>0
             THEN SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AAA','AA','AB','A','B','PB') THEN n_kgs*price_per_50kg ELSE 0 END)
                / SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AAA','AA','AB','A','B','PB') THEN n_kgs ELSE 0 END)
             ELSE NULL END AS top_avg,
        MAX(CASE WHEN $validPrice AND $gradeNorm IN ('AAA','AA','AB','A','B','PB') THEN price_per_50kg END) AS top_high,

        MIN(CASE WHEN $validPrice AND $gradeNorm='C' THEN price_per_50kg END) AS c_low,
        CASE WHEN SUM(CASE WHEN $validPrice AND $gradeNorm='C' THEN n_kgs ELSE 0 END)>0
             THEN SUM(CASE WHEN $validPrice AND $gradeNorm='C' THEN n_kgs*price_per_50kg ELSE 0 END)
                / SUM(CASE WHEN $validPrice AND $gradeNorm='C' THEN n_kgs ELSE 0 END)
             ELSE NULL END AS c_avg,
        MAX(CASE WHEN $validPrice AND $gradeNorm='C' THEN price_per_50kg END) AS c_high,

        MIN(CASE WHEN $validPrice AND $gradeNorm IN ('AF','F','E','TT','UG') THEN price_per_50kg END) AS lower_low,
        CASE WHEN SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AF','F','E','TT','UG') THEN n_kgs ELSE 0 END)>0
             THEN SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AF','F','E','TT','UG') THEN n_kgs*price_per_50kg ELSE 0 END)
                / SUM(CASE WHEN $validPrice AND $gradeNorm IN ('AF','F','E','TT','UG') THEN n_kgs ELSE 0 END)
             ELSE NULL END AS lower_avg,
        MAX(CASE WHEN $validPrice AND $gradeNorm IN ('AF','F','E','TT','UG') THEN price_per_50kg END) AS lower_high
      FROM public.clean_auction_results
      WHERE $where";

    $s=$db->prepare($sql);
    $s->execute($p);
    $r=$s->fetch() ?: [];

    $offered=(float)($r['offered_kgs']??0);
    $soldKgs=(float)($r['sold_kgs']??0);
    $r['percentage_sold']=$offered>0 ? ($soldKgs/$offered*100) : 0;

    /* Keep the report response aligned with the High & Low renderer.
       Flat fields are retained for backward compatibility. */
    $r['prices'] = [
        'top' => [
            'low_price'  => $r['top_low'] ?? null,
            'avg_price'  => $r['top_avg'] ?? null,
            'high_price' => $r['top_high'] ?? null,
        ],
        'c' => [
            'low_price'  => $r['c_low'] ?? null,
            'avg_price'  => $r['c_avg'] ?? null,
            'high_price' => $r['c_high'] ?? null,
        ],
        'lower' => [
            'low_price'  => $r['lower_low'] ?? null,
            'avg_price'  => $r['lower_avg'] ?? null,
            'high_price' => $r['lower_high'] ?? null,
        ],
    ];

    clean_json(true,'',['report'=>$r]);
}

function clean_sale_summary(): void {
    ensure_clean_table(); $db=clean_db();
    $season=trim($_GET['season']??''); $auction=trim($_GET['auction_no']??'');
    if($auction==='') clean_json(false,'Select an Auction No. to view Sale Summary.',[],400);

    $where="auction_no=:a"; $p=['a'=>$auction]; $from=null; $to=null;
    if($season!==''){
        [$from,$to]=clean_sale_season_range($season);
        $where.=" AND auction_date BETWEEN :sf AND :st";
        $p['sf']=$from; $p['st']=$to;
    }

    $sold="UPPER(BTRIM(COALESCE(status,''))) IN ('SOLD','S')";
    $valid="($sold AND price_per_50kg IS NOT NULL AND price_per_50kg>0)";
    $gn="UPPER(REGEXP_REPLACE(BTRIM(COALESCE(grade,'')), '[^A-Za-z0-9]+', '', 'g'))";
    $g2="UPPER(REGEXP_REPLACE(BTRIM(COALESCE(grade2,'')), '[^A-Za-z0-9]+', '', 'g'))";

    $ds=$db->prepare("SELECT MAX(auction_date) FROM public.clean_auction_results WHERE $where");
    $ds->execute($p); $held=$ds->fetchColumn();

    $previous=null;
    if($held){
        $sql="SELECT auction_no,MAX(auction_date) d FROM public.clean_auction_results WHERE auction_date<:held";
        $pp=['held'=>$held];
        if($from&&$to){$sql.=" AND auction_date BETWEEN :pf AND :pt";$pp['pf']=$from;$pp['pt']=$to;}
        $sql.=" GROUP BY auction_no ORDER BY MAX(auction_date) DESC LIMIT 1";
        $q=$db->prepare($sql);$q->execute($pp);$previous=$q->fetch();
    }

    $defs=[
      ['AAA','grade','AAA'],['AA','grade','AA'],['A','grade','A'],['AB','grade','AB'],
      ['B','grade','B'],['PB','grade','PB'],['C','grade','C'],['Lower Grades','grade2','LOWERGRADES']
    ];
    $rows=[];
    foreach($defs as [$label,$mode,$value]){
        $cond=($mode==='grade2'?$g2:$gn)."=:g";
        $rp=$p; $rp['g']=$value;
        $sql="SELECT COALESCE(SUM(n_kgs),0) offered,
          COALESCE(SUM(CASE WHEN $sold THEN n_kgs ELSE 0 END),0) sold,
          MIN(CASE WHEN $valid THEN price_per_50kg END) low,
          CASE WHEN SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END)>0
            THEN SUM(CASE WHEN $valid THEN n_kgs*price_per_50kg ELSE 0 END)/
                 SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END) ELSE NULL END avg,
          MAX(CASE WHEN $valid THEN price_per_50kg END) high
          FROM public.clean_auction_results WHERE $where AND $cond";
        $q=$db->prepare($sql);$q->execute($rp);$r=$q->fetch();

        $prev=null;
        if($previous){
            $pw="auction_no=:pa";$pr=['pa'=>$previous['auction_no'],'g'=>$value];
            if($from&&$to){$pw.=" AND auction_date BETWEEN :pf AND :pt";$pr['pf']=$from;$pr['pt']=$to;}
            $sql2="SELECT CASE WHEN SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END)>0
              THEN SUM(CASE WHEN $valid THEN n_kgs*price_per_50kg ELSE 0 END)/
                   SUM(CASE WHEN $valid THEN n_kgs ELSE 0 END) ELSE NULL END
              FROM public.clean_auction_results WHERE $pw AND $cond";
            $q2=$db->prepare($sql2);$q2->execute($pr);$prev=$q2->fetchColumn();
        }
        $rows[]=['grade'=>$label,'offered'=>(float)$r['offered'],'sold'=>(float)$r['sold'],
          'low'=>$r['low']===null?null:(float)$r['low'],'avg'=>$r['avg']===null?null:(float)$r['avg'],
          'high'=>$r['high']===null?null:(float)$r['high'],
          'prev_avg'=>($prev===false||$prev===null)?null:(float)$prev];
    }
    $off=array_sum(array_column($rows,'offered'));$soldTotal=array_sum(array_column($rows,'sold'));
    $weighted=0;foreach($rows as $r){if($r['avg']!==null)$weighted+=$r['sold']*$r['avg'];}
    clean_json(true,'',['summary'=>['auction_no'=>$auction,'held_on'=>$held,
      'previous_auction'=>$previous['auction_no']??null,'rows'=>$rows,
      'total'=>['offered'=>$off,'sold'=>$soldTotal,'avg'=>$soldTotal>0?$weighted/$soldTotal:null]]]);
}

function clean_update(): void {
    ensure_clean_table();$db=clean_db();
    $in=json_decode(file_get_contents('php://input'),true)?:[];
    $id=(int)($in['id']??0); if($id<=0)clean_json(false,'Invalid database row.',[],400);
    $fields=['lot_no','auction_no','auction_date','seller','crop_season','grade','grade2','n_kgs','warehouse','district','region','price_per_50kg','status','buyer'];
    $set=[];$p=['id'=>$id];
    foreach($fields as $f) if(array_key_exists($f,$in)){
        $v=$in[$f];
        if($f==='auction_date')$v=clean_date($v);
        if(in_array($f,['n_kgs','price_per_50kg'],true))$v=clean_number($v);
        $set[]="$f=:$f";$p[$f]=$v;
    }
    if(!$set)clean_json(false,'No changes were supplied.',[],400);
    try{$s=$db->prepare("UPDATE public.clean_auction_results SET ".implode(',',$set).",updated_at=CURRENT_TIMESTAMP WHERE id=:id");$s->execute($p);}
    catch(Throwable $e){clean_json(false,'The row could not be updated. Check required fields and duplicate Lot/Auction/Date.',[],400);}
    clean_json(true,'Row updated successfully.');
}
function clean_delete(): void {
    ensure_clean_table();$db=clean_db();$in=json_decode(file_get_contents('php://input'),true)?:[];$id=(int)($in['id']??0);
    if($id<=0)clean_json(false,'Invalid database row.',[],400);
    $s=$db->prepare("DELETE FROM public.clean_auction_results WHERE id=:id");$s->execute(['id'=>$id]);
    clean_json(true,'Row deleted successfully.');
}

try {
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_FILES['clean_excel'])) clean_upload();
    $action=$_GET['action']??'';
    if($action==='filters')clean_filters();
    if($action==='auctions')clean_auctions();
    if($action==='fetch')clean_fetch();
    if($action==='report')clean_report();
    if($action==='sale_summary')clean_sale_summary();
    if($action==='update' && ($_SERVER['REQUEST_METHOD']??'')==='POST')clean_update();
    if($action==='delete' && ($_SERVER['REQUEST_METHOD']??'')==='POST')clean_delete();
    if($action==='delete_all' && ($_SERVER['REQUEST_METHOD']??'')==='POST'){
        $n=clean_delete_all();clean_json(true,"All Clean Auction records were deleted.",['deleted'=>$n]);
    }
    ensure_clean_table();
} catch(Throwable $e) {
    error_log('Clean Auction error: '.$e->getMessage());
    if(isset($_GET['action']) || ($_SERVER['REQUEST_METHOD']??'GET')==='POST') clean_json(false,'Clean Auction operation failed. '.$e->getMessage(),[],500);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clean Auction</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;font-family:Arial,sans-serif;background:#f7f4f2;color:#382b26}
body{overflow:hidden}.app{height:100vh;padding:8px;display:grid;grid-template-rows:auto auto minmax(0,1fr);gap:6px}
.toolbar,.filters{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.toolbar{justify-content:space-between}
.title{font-size:15px;font-weight:800;color:#4b342c}.muted{font-size:9px;color:#8b7d77}
.controls{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
select,button,input{height:30px;border:1px solid #d9cfca;border-radius:6px;background:#fff;padding:0 9px;font-size:11px;color:#4b342c}
button{cursor:pointer;font-weight:700}.primary{background:#5d4037;color:#fff;border-color:#5d4037}
.danger{color:#9a2f28}.exportwrap{position:relative}
.exportmenu{display:none;position:absolute;right:0;top:34px;background:#fff;border:1px solid #ddd2cd;border-radius:7px;padding:5px;min-width:110px;z-index:30;box-shadow:0 8px 22px #0002}
.exportmenu.open{display:block}
.exportmenu button{display:block;width:100%;text-align:left;border:0;background:#fff}
.exportmenu button:hover{background:#f5f1ef}
.settings{position:relative}.menu{display:none;position:absolute;right:0;top:34px;background:#fff;border:1px solid #ddd2cd;border-radius:7px;padding:6px;min-width:175px;z-index:20;box-shadow:0 8px 22px #0002}.menu.open{display:block}.menu button{width:100%;text-align:left;border:0}
.uploadbox{display:none;align-items:center;gap:5px}.uploadbox.open{display:flex}.msg{font-size:10px;padding:5px 8px;border-radius:5px;display:none}.msg.ok{display:block;background:#eaf5ec;color:#276536}.msg.err{display:block;background:#faecea;color:#8a3029}
.card{min-height:0;background:#fff;border:1px solid #e6ddd9;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.tablewrap{min-height:0;overflow:auto;flex:1}table{border-collapse:collapse;width:100%;min-width:1250px;font-size:9px}
th,td{padding:4px 5px;border-bottom:1px solid #eee7e4;white-space:nowrap;text-align:right}th:first-child,td:first-child{text-align:left}
thead th{position:sticky;top:0;background:#4b342c;color:#fff;z-index:3;font-size:8px}.text{text-align:left}
.actions{display:none}.editmode .actions{display:table-cell}.rowbtn{height:23px;padding:0 5px;font-size:9px}
.report{padding:12px;display:none}.report.show{display:block}.report h2{margin:0 0 3px;font-size:15px}.report-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:10px}
.metric{border:1px solid #e5dbd6;border-radius:7px;padding:8px;background:#faf8f7}.metric span{font-size:8px;color:#8b7d77;text-transform:uppercase}.metric strong{display:block;margin-top:3px;font-size:12px}
.summaryreport{display:none;padding:8px;overflow:auto;flex:1}
.summaryreport.show{display:block}
.summary-sheet{width:min(100%,1040px);margin:0 auto;background:#fff;color:#000}
.summary-title{text-align:center;font-weight:800;font-size:12px;padding:5px}
.summary-table{width:100%;min-width:850px;border-collapse:collapse;table-layout:fixed;border:3px solid #000;background:#fff;font-size:9px}
.summary-table th,.summary-table td{border:1px solid #000;padding:4px 5px;text-align:right;background:#fff!important;color:#000!important}
.summary-table th{text-align:center;font-weight:800;position:sticky;top:0;z-index:2}
.summary-table td:first-child{text-align:left;font-weight:700}
.summary-table tr:first-child>*{border-top-width:3px}.summary-table tr:last-child>*{border-bottom-width:3px}
.summary-table tr>*:first-child{border-left-width:3px}.summary-table tr>*:last-child{border-right-width:3px}
.summary-table .total td{font-weight:800;border-top:2px solid #000}
@media(max-width:900px){.app{height:auto;min-height:100vh;overflow:visible}body{overflow:auto}.toolbar{align-items:flex-start}.controls{width:100%}.filters{width:100%}.card{min-height:65vh}.report-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.app{padding:5px}.title{font-size:13px}select,button,input{height:28px;font-size:10px}.controls>*{flex:1 1 auto}.uploadbox.open{width:100%;flex-wrap:wrap}.report-grid{grid-template-columns:repeat(2,1fr)}}

.highlow{
    width:min(100%,760px);
    margin:0 auto;
    padding:5px 7px 8px;
    overflow:visible;
    background:#fff
}
.hl-title{
    text-align:center;
    font-weight:800;
    font-size:12px;
    line-height:1.25;
    padding:1px 3px;
    background:transparent
}
.hl-sub{
    text-align:center;
    font-weight:700;
    font-size:10px;
    line-height:1.2;
    padding:1px 3px 3px;
    background:transparent
}
.hl-table{
    width:100%!important;
    min-width:0!important;
    table-layout:fixed!important;
    border-collapse:collapse;
    margin:0;
    font-size:9.5px;
    border:3px solid #000!important;
    background:#fff
}
.hl-table col{width:33.333%}
.hl-table th,.hl-table td{
    border:1px solid #000!important;
    padding:3px 5px;
    height:22px;
    line-height:1.15;
    text-align:center;
    vertical-align:middle;
    white-space:normal;
    overflow-wrap:break-word;
    background:#fff!important;
    color:#000!important
}
.hl-table th{
    position:static!important;
    font-size:8.5px;
    font-weight:800
}
.hl-section td{
    font-weight:800;
    text-align:center;
    height:22px
}
.hl-values td{
    font-weight:700;
    font-variant-numeric:tabular-nums
}
.hl-percent td{
    text-align:right;
    font-weight:800;
    height:23px;
    padding-right:8px
}
/* Reinforce the workbook-style thick perimeter. */
.hl-table tr:first-child > *{border-top-width:3px!important}
.hl-table tr:last-child > *{border-bottom-width:3px!important}
.hl-table tr > *:first-child{border-left-width:3px!important}
.hl-table tr > *:last-child{border-right-width:3px!important}
.hl-money{font-variant-numeric:tabular-nums}
@media(max-width:900px){
    .report{padding:7px}
    .highlow{width:100%;max-width:720px;padding:4px}
}
@media(max-width:600px){
    .report{padding:4px}
    .highlow{padding:2px}
    .hl-title{font-size:10px}
    .hl-sub{font-size:8.5px}
    .hl-table{font-size:7.7px}
    .hl-table th{font-size:7px}
    .hl-table th,.hl-table td{padding:3px 2px;height:20px}
    .hl-percent td{padding-right:4px}
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>
<div class="app">
 <div class="toolbar">
  <div><span class="title">Clean Auction</span> <span class="muted">Results stored in PostgreSQL</span></div>
  <div class="controls">
   <select id="display"><option value="report">High & Low</option><option value="summary">Sale Summary</option><option value="results">Auction Results</option></select>
   <div class="exportwrap" id="exportWrap">
    <button type="button" id="exportButton" onclick="toggleExportMenu(event)">⇩ Export</button>
    <div class="exportmenu" id="exportMenu">
      <button type="button" onclick="exportHighLow('pdf')">PDF</button>
      <button type="button" onclick="exportHighLow('excel')">Excel</button>
      <button type="button" onclick="exportHighLow('word')">Word</button>
    </div>
   </div>
   <div class="settings"><button onclick="toggleSettings()">⚙ Settings</button><div class="menu" id="settingsMenu">
    <button onclick="showUpload()">↑ Upload Results</button>
    <button onclick="toggleEdit()">✎ Edit selected display</button>
    <button class="danger" onclick="deleteAll()">⌫ Delete all data</button>
   </div></div>
  </div>
 </div>
 <div>
  <div class="filters">
   <select id="season" title="Sale Season: 1 July to 30 June"><option value="">Select Sale Season</option></select>
   <select id="auction"><option value="">Select Auction</option></select>
   <button onclick="refreshAll()">↻ Refresh</button>
   <div class="uploadbox" id="uploadbox">
    <input type="file" id="file" accept=".xlsx,.xlsm,.xltx,.csv">
    <button class="primary" onclick="upload(false)">Upload Clean Auction Results</button>
   </div>
  </div>
  <div class="msg" id="msg"></div>
 </div>
 <div class="card" id="card">
  <div class="report" id="report"></div>
  <div class="summaryreport" id="summaryreport"></div>
  <div class="tablewrap" id="tablewrap"><table id="table"></table></div>
 </div>
</div>
<script>
const $=id=>document.getElementById(id);
const cols=[
 ['lot_no','Lot No.'],['auction_no','Auction No.'],['auction_date','Auction Date'],['seller','Seller'],
 ['crop_season','Crop Season'],['grade','Grade'],['grade2','Grade2'],['n_kgs','N/Kgs'],
 ['warehouse','Warehouse'],['district','District'],['region','Region'],['price_per_50kg','Price (/50Kg)'],
 ['status','Status'],['buyer','Buyer']
];
let rows=[],editMode=false;
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function num(v,d=2){let n=Number(v);if(!Number.isFinite(n))return '-';return n.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:d})}
function message(text,ok=true){let m=$('msg');m.className='msg '+(ok?'ok':'err');m.textContent=text;setTimeout(()=>{m.className='msg'},6500)}
async function api(url,opt){let r=await fetch(url,opt);let j=await r.json();if(!r.ok||j.success===false)throw new Error(j.message||'Request failed');return j.data}

function toggleExportMenu(event){
 if(event)event.stopPropagation();
 $('exportMenu').classList.toggle('open');
 $('settingsMenu').classList.remove('open');
}
function highLowExportName(ext){
 const auction=$('auction').value||'Auction';
 const season=($('season').value||'Season').replace('/','-');
 return `Clean_Auction_High_Low_${season}_Auction_${auction}.${ext}`;
}
function downloadBlob(blob,name){
 const a=document.createElement('a');
 a.href=URL.createObjectURL(blob);
 a.download=name;
 document.body.appendChild(a);
 a.click();
 setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},500);
}
function exportTableMarkup(){
 const report=$('report');
 if(!report || !$('auction').value) throw new Error('Select an Auction No. before exporting High & Low.');
 const highlow=report.querySelector('.highlow');
 if(!highlow) throw new Error('High & Low report is not ready yet.');
 return highlow.outerHTML;
}
function exportHighLow(type){
 $('exportMenu').classList.remove('open');
 if($('display').value!=='report'){message('High & Low must be selected before exporting.',false);return}
 try{
   if(type==='excel') return exportHighLowExcel();
   if(type==='word') return exportHighLowWord();
   if(type==='pdf') return exportHighLowPdf();
 }catch(e){message(e.message,false)}
}
function exportHighLowExcel(){
 const body=exportTableMarkup();
 const html=`<!doctype html><html><head><meta charset="utf-8">
 <style>
 body{font-family:Arial,sans-serif}.highlow{width:760px;margin:auto}.hl-title,.hl-sub{text-align:center;font-weight:bold}
 table{border-collapse:collapse;width:100%;border:3px solid #000}th,td{border:1px solid #000;padding:5px;text-align:center;background:#fff;color:#000}
 tr:first-child>*{border-top:3px solid #000}tr:last-child>*{border-bottom:3px solid #000}
 tr>*:first-child{border-left:3px solid #000}tr>*:last-child{border-right:3px solid #000}
 </style></head><body>${body}</body></html>`;
 downloadBlob(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}),highLowExportName('xls'));
 message('High & Low Excel export prepared successfully.');
}
function exportHighLowWord(){
 const body=exportTableMarkup();
 const html=`<!doctype html><html><head><meta charset="utf-8">
 <style>
 @page{size:A4 portrait;margin:16mm}body{font-family:Arial,sans-serif}.highlow{width:100%}.hl-title,.hl-sub{text-align:center;font-weight:bold}
 table{border-collapse:collapse;width:100%;border:3px solid #000}th,td{border:1px solid #000;padding:5px;text-align:center;background:#fff;color:#000}
 tr:first-child>*{border-top:3px solid #000}tr:last-child>*{border-bottom:3px solid #000}
 tr>*:first-child{border-left:3px solid #000}tr>*:last-child{border-right:3px solid #000}
 </style></head><body>${body}</body></html>`;
 downloadBlob(new Blob(['\ufeff',html],{type:'application/msword;charset=utf-8'}),highLowExportName('doc'));
 message('High & Low Word export prepared successfully.');
}
async function exportHighLowPdf(){
 const report=$('report');
 if(!report || !$('auction').value) throw new Error('Select an Auction No. before exporting High & Low.');
 const source=report.querySelector('.highlow');
 if(!source) throw new Error('High & Low report is not ready yet.');
 if(!window.html2canvas || !window.jspdf || !window.jspdf.jsPDF){
   throw new Error('PDF exporter did not load. Check the internet connection and refresh the page.');
 }

 const button=$('exportButton');
 const originalText=button ? button.textContent : '';
 if(button){button.disabled=true;button.textContent='Preparing PDF…';}

 /* Clone the report so PDF rendering never changes the visible page. */
 const clone=source.cloneNode(true);
 clone.style.width='760px';
 clone.style.maxWidth='760px';
 clone.style.margin='0';
 clone.style.padding='10px';
 clone.style.background='#fff';
 clone.style.color='#000';

 clone.querySelectorAll('table').forEach(table=>{
   table.style.width='100%';
   table.style.borderCollapse='collapse';
   table.style.border='3px solid #000';
   table.style.background='#fff';
 });
 clone.querySelectorAll('th,td').forEach(cell=>{
   cell.style.border='1px solid #000';
   cell.style.background='#fff';
   cell.style.color='#000';
 });
 clone.querySelectorAll('table tr:first-child > *').forEach(cell=>cell.style.borderTop='3px solid #000');
 clone.querySelectorAll('table tr:last-child > *').forEach(cell=>cell.style.borderBottom='3px solid #000');
 clone.querySelectorAll('table tr > *:first-child').forEach(cell=>cell.style.borderLeft='3px solid #000');
 clone.querySelectorAll('table tr > *:last-child').forEach(cell=>cell.style.borderRight='3px solid #000');

 const stage=document.createElement('div');
 stage.style.position='fixed';
 stage.style.left='-10000px';
 stage.style.top='0';
 stage.style.width='780px';
 stage.style.background='#fff';
 stage.appendChild(clone);
 document.body.appendChild(stage);

 try{
   const canvas=await html2canvas(clone,{
     scale:2.5,
     backgroundColor:'#ffffff',
     useCORS:true,
     logging:false
   });

   const {jsPDF}=window.jspdf;
   const pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4',compress:true});
   const pageW=pdf.internal.pageSize.getWidth();
   const pageH=pdf.internal.pageSize.getHeight();
   const margin=10;
   const usableW=pageW-(margin*2);
   const imgH=canvas.height*usableW/canvas.width;

   if(imgH <= pageH-(margin*2)){
     pdf.addImage(canvas.toDataURL('image/jpeg',0.96),'JPEG',margin,margin,usableW,imgH,undefined,'FAST');
   }else{
     /* Split unusually tall reports cleanly across A4 pages. */
     const pxPerMm=canvas.width/usableW;
     const pagePx=Math.floor((pageH-(margin*2))*pxPerMm);
     let y=0, page=0;
     while(y<canvas.height){
       const sliceH=Math.min(pagePx,canvas.height-y);
       const part=document.createElement('canvas');
       part.width=canvas.width;
       part.height=sliceH;
       part.getContext('2d').drawImage(canvas,0,y,canvas.width,sliceH,0,0,canvas.width,sliceH);
       if(page>0)pdf.addPage();
       const partHmm=sliceH/pxPerMm;
       pdf.addImage(part.toDataURL('image/jpeg',0.96),'JPEG',margin,margin,usableW,partHmm,undefined,'FAST');
       y+=sliceH;page++;
     }
   }

   /* jsPDF.save() creates and downloads an actual application/pdf file. */
   pdf.save(highLowExportName('pdf'));
   message('High & Low PDF downloaded successfully.');
 }finally{
   stage.remove();
   if(button){button.disabled=false;button.textContent=originalText;}
 }
}

function syncExportVisibility(){
 const wrap=$('exportWrap');
 if(wrap)wrap.style.display=$('display').value==='report'?'block':'none';
}

function toggleSettings(){$('settingsMenu').classList.toggle('open')}
function showUpload(){$('uploadbox').classList.toggle('open');$('settingsMenu').classList.remove('open')}
function toggleEdit(){editMode=!editMode;$('card').classList.toggle('editmode',editMode);$('settingsMenu').classList.remove('open');renderTable()}
async function loadFilters(){
 let d=await api('clean_auction.php?action=filters');
 $('season').innerHTML='<option value="">Select Sale Season</option>'+d.seasons.map(s=>`<option>${esc(s)}</option>`).join('');
 if(d.latest){$('season').value=d.latest.sale_season||'';await loadAuctions();$('auction').value=d.latest.auction_no||''}
}
async function loadAuctions(){
 let s=encodeURIComponent($('season').value);let d=await api('clean_auction.php?action=auctions&season='+s);
 $('auction').innerHTML='<option value="">Select Auction</option>'+d.auctions.map(x=>`<option value="${esc(x.auction_no)}">Auction ${esc(x.auction_no)}</option>`).join('');
}
async function loadRows(){
 let u='clean_auction.php?action=fetch&season='+encodeURIComponent($('season').value)+'&auction_no='+encodeURIComponent($('auction').value);
 let d=await api(u);rows=d.rows;renderTable()
}
function renderTable(){
 $('tablewrap').style.display=$('display').value==='results'?'block':'none';
 if($('display').value!=='results')return;
 let h='<thead><tr>'+cols.map(c=>`<th>${c[1]}</th>`).join('')+'<th class="actions">Actions</th></tr></thead><tbody>';
 h+=rows.map(r=>'<tr>'+cols.map(c=>`<td class="${['seller','warehouse','district','region','buyer'].includes(c[0])?'text':''}">${esc(['n_kgs','price_per_50kg'].includes(c[0])?num(r[c[0]],4):r[c[0]])}</td>`).join('')+
 `<td class="actions"><button class="rowbtn" onclick="editRow(${r.id})">✎</button> <button class="rowbtn danger" onclick="deleteRow(${r.id})">⌫</button></td></tr>`).join('');
 $('table').innerHTML=h+'</tbody>';
}
async function loadReport(){
 const mode=$('display').value;
 $('report').classList.toggle('show',mode==='report');
 $('summaryreport').classList.toggle('show',mode==='summary');
 $('tablewrap').style.display=mode==='results'?'block':'none';

 if(mode==='summary'){
   if(!$('auction').value){$('summaryreport').innerHTML='<div class="muted">Select an auction to view Sale Summary.</div>';return}
   let d=await api('clean_auction.php?action=sale_summary&season='+encodeURIComponent($('season').value)+'&auction_no='+encodeURIComponent($('auction').value));
   let s=d.summary, pv=v=>(v===null||v===undefined)?'-':num(v,2);
   const held=s.held_on?new Date(s.held_on+'T00:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'long',year:'numeric'}):'-';
   let body=s.rows.map(r=>`<tr><td>${esc(r.grade)}</td><td>${num(r.offered)}</td><td>${num(r.sold)}</td>
      <td>${pv(r.low)}</td><td>${pv(r.avg)}</td><td>${pv(r.high)}</td><td>${pv(r.prev_avg)}</td></tr>`).join('');
   $('summaryreport').innerHTML=`<div class="summary-sheet">
    <div class="summary-title">Sale summary for Auction No. ${esc(s.auction_no)}, Held on: ${esc(held)}</div>
    <table class="summary-table"><thead><tr>
      <th>Grade</th><th>Kgs Offered</th><th>Kgs Sold</th><th>Low USD/50kgs</th>
      <th>Average USD/50kg</th><th>High USD/50kg</th><th>Prev. Average Price USD/50kg</th>
    </tr></thead><tbody>${body}
      <tr class="total"><td>TOTAL</td><td>${num(s.total.offered)}</td><td>${num(s.total.sold)}</td>
      <td></td><td>${pv(s.total.avg)}</td><td></td><td>-</td></tr>
    </tbody></table></div>`;
   return;
 }
 if(mode!=='report')return;
 if(!$('auction').value){$('report').innerHTML='<div class="muted">Select an auction to view High & Low.</div>';return}
 let d=await api('clean_auction.php?action=report&season='+encodeURIComponent($('season').value)+'&auction_no='+encodeURIComponent($('auction').value));
 let r=d.report;
 const p=r.prices||{};
 const top=p.top||{low_price:r.top_low,avg_price:r.top_avg,high_price:r.top_high};
 const cg=p.c||{low_price:r.c_low,avg_price:r.c_avg,high_price:r.c_high};
 const low=p.lower||{low_price:r.lower_low,avg_price:r.lower_avg,high_price:r.lower_high};
 const held=r.auction_date ? new Date(r.auction_date+'T00:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'long',year:'numeric'}) : '-';
 $('report').innerHTML=`<div class="highlow">
   <div class="hl-title">TANZANIA COFFEE EXCHANGE</div>
   <div class="hl-title">AUCTION RESULTS SALE NO TCB/M/${esc($('auction').value)}</div>
   <div class="hl-sub">Held On ${esc(held)}</div>
   <table class="hl-table"><colgroup><col><col><col></colgroup>
    <tr class="hl-section"><td colspan="3">Price USD/50KGS</td></tr>
    <tr><th>KGS OFFERED</th><th>KGS SOLD</th><th>TOTAL VALUE (USD)</th></tr>
    <tr class="hl-values"><td>${num(r.offered_kgs)}</td><td>${num(r.sold_kgs)}</td><td class="hl-money">${num(r.total_value)}</td></tr>
    <tr class="hl-section"><td colspan="3">Top Grades (AAA, AA, AB, A, B, PB)</td></tr>
    <tr><th>LOWEST PRICE</th><th>AVERAGE PRICE</th><th>HIGHEST PRICE</th></tr>
    <tr class="hl-values"><td>${num(top.low_price)}</td><td>${num(top.avg_price)}</td><td>${num(top.high_price)}</td></tr>
    <tr class="hl-section"><td colspan="3">C - Grade</td></tr>
    <tr><th>LOWEST PRICE</th><th>AVERAGE PRICE</th><th>HIGHEST PRICE</th></tr>
    <tr class="hl-values"><td>${num(cg.low_price)}</td><td>${num(cg.avg_price)}</td><td>${num(cg.high_price)}</td></tr>
    <tr class="hl-section"><td colspan="3">Lower grades (AF, F, E, TT, UG)</td></tr>
    <tr><th>LOWEST PRICE</th><th>AVERAGE PRICE</th><th>HIGHEST PRICE</th></tr>
    <tr class="hl-values"><td>${num(low.low_price)}</td><td>${num(low.avg_price)}</td><td>${num(low.high_price)}</td></tr>
    <tr class="hl-percent"><td colspan="3">PERCENTAGE SOLD = ${num(r.percentage_sold)}%</td></tr>
   </table></div>`;
}
async function refreshAll(){syncExportVisibility();try{await loadRows();await loadReport()}catch(e){message(e.message,false)}}
async function upload(confirmReplace){
 let f=$('file').files[0];if(!f){message('Select the Clean Auction Results Excel file first.',false);return}
 let fd=new FormData();fd.append('clean_excel',f);if(confirmReplace)fd.append('confirm_replace','1');
 try{
  let r=await fetch('clean_auction.php',{method:'POST',body:fd});let j=await r.json();
  if(!r.ok||j.success===false)throw new Error(j.message||'Upload failed');
  if(j.data?.requires_confirmation){
   if(confirm(`${j.data.existing_count} matching record(s) already exist. Replace them with the uploaded rows?`))return upload(true);
   return;
  }
  message(j.message,true);$('uploadbox').classList.remove('open');await loadFilters();await refreshAll();
 }catch(e){message(e.message,false)}
}
async function deleteRow(id){
 if(!confirm('Delete this row permanently?'))return;
 try{let d=await fetch('clean_auction.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});let j=await d.json();if(!d.ok||!j.success)throw new Error(j.message);message(j.message);await refreshAll()}catch(e){message(e.message,false)}
}
async function deleteAll(){
 $('settingsMenu').classList.remove('open');if(!confirm('Delete ALL Clean Auction records permanently?'))return;
 try{let d=await fetch('clean_auction.php?action=delete_all',{method:'POST'});let j=await d.json();if(!d.ok||!j.success)throw new Error(j.message);message(j.message);await loadFilters();await refreshAll()}catch(e){message(e.message,false)}
}
async function editRow(id){
 let r=rows.find(x=>Number(x.id)===Number(id));if(!r)return;
 let p={id};
 for(const [k,label] of cols){
   let v=prompt(label,r[k]??'');if(v===null)return;p[k]=v;
 }
 try{let q=await fetch('clean_auction.php?action=update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});let j=await q.json();if(!q.ok||!j.success)throw new Error(j.message);message(j.message);await refreshAll()}catch(e){message(e.message,false)}
}
$('season').addEventListener('change',async()=>{await loadAuctions();$('auction').value='';await refreshAll()});
$('auction').addEventListener('change',refreshAll);
$('display').addEventListener('change',refreshAll);
document.addEventListener('click',e=>{
 if(!e.target.closest('.settings'))$('settingsMenu').classList.remove('open');
 if(!e.target.closest('.exportwrap'))$('exportMenu').classList.remove('open');
});
(async()=>{try{await loadFilters();await refreshAll()}catch(e){message(e.message,false)}})();
</script>
</body></html>
