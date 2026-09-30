<?php
ob_start();
session_start();
if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in']!==true){
    $api=isset($_GET['action']) || ($_SERVER['REQUEST_METHOD']??'')==='POST';
    if($api){while(ob_get_level())ob_end_clean();header('Content-Type: application/json; charset=utf-8');http_response_code(401);echo json_encode(['success'=>false,'message'=>'Your session has expired. Please log in again.']);exit;}
    header('Location: login.php');exit;
}
/* Embedded database layer: keeps this module self-contained on Render. */
/* FARM GATE CONTRACT / MIKATABA YA KAHAWA GHAFI DATABASE LAYER */

function farm_db(): PDO {
    static $db=null;
    if($db instanceof PDO) return $db;
    $url=getenv('DATABASE_URL');
    if(!$url) throw new RuntimeException('DATABASE_URL is not configured in Render.');
    if(!in_array('pgsql',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PDO PostgreSQL driver (pdo_pgsql) is not enabled.');
    $p=parse_url($url);
    if(!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) throw new RuntimeException('DATABASE_URL is invalid.');
    $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',$p['host'],(int)($p['port']??5432),ltrim($p['path'],'/'));
    $db=new PDO($dsn,urldecode($p['user']),urldecode($p['pass']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    return $db;
}
function ensure_farm_gate_table(): void {
    $db=farm_db();
    $db->exec("
      CREATE TABLE IF NOT EXISTS public.farm_gate_contracts(
        id BIGSERIAL PRIMARY KEY,
        contract_date DATE NOT NULL,
        seller_name VARCHAR(300) NOT NULL,
        seller_district VARCHAR(200),
        seller_region VARCHAR(200),
        buyer_name VARCHAR(300) NOT NULL,
        buyer_region VARCHAR(200),
        coffee_type VARCHAR(200),
        processing_method VARCHAR(100),
        kilos_to_be_sold NUMERIC(18,2) NOT NULL,
        price_per_kilo_tzs NUMERIC(18,2) NOT NULL,
        warehouse VARCHAR(300),
        row_hash VARCHAR(64),
        created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $cols=[
      'contract_date'=>'DATE','seller_name'=>'VARCHAR(300)','seller_district'=>'VARCHAR(200)',
      'seller_region'=>'VARCHAR(200)','buyer_name'=>'VARCHAR(300)','buyer_region'=>'VARCHAR(200)',
      'coffee_type'=>'VARCHAR(200)','processing_method'=>'VARCHAR(100)',
      'kilos_to_be_sold'=>'NUMERIC(18,2)','price_per_kilo_tzs'=>'NUMERIC(18,2)',
      'warehouse'=>'VARCHAR(300)','row_hash'=>'VARCHAR(64)',
      'created_at'=>'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP',
      'updated_at'=>'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP'
    ];
    $check=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='farm_gate_contracts' AND column_name=:c");
    foreach($cols as $n=>$type){
        $check->execute(['c'=>$n]);
        if(!$check->fetchColumn()) $db->exec('ALTER TABLE public.farm_gate_contracts ADD COLUMN "'.$n.'" '.$type);
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_farm_gate_row_hash ON public.farm_gate_contracts(row_hash) WHERE row_hash IS NOT NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_farm_gate_contract_date ON public.farm_gate_contracts(contract_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_farm_gate_seller_region ON public.farm_gate_contracts(seller_region)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_farm_gate_buyer ON public.farm_gate_contracts(buyer_name)");
}
function farm_delete_all(): int {
    ensure_farm_gate_table();
    return farm_db()->exec("DELETE FROM public.farm_gate_contracts");
}


function farm_json(bool $ok,string $message='',array $data=[],int $status=200):void{
    while(ob_get_level())ob_end_clean();http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function farm_norm($v):string{$s=strtolower(trim((string)$v));$s=preg_replace('/[^a-z0-9]+/','_',$s);return trim($s,'_');}
function farm_num($v):?float{if($v===null||trim((string)$v)==='')return null;$s=str_replace([',',' '],'',trim((string)$v));return is_numeric($s)?(float)$s:null;}
function farm_date($v):?string{
    if($v===null||trim((string)$v)==='')return null;$s=trim((string)$v);
    if(is_numeric($s)){ $n=(float)$s;if($n>20000&&$n<80000){$d=new DateTime('1899-12-30');$d->modify('+'.(int)floor($n).' days');return $d->format('Y-m-d');}}
    foreach(['Y-m-d','d/m/Y','d-m-Y','d M Y','j F Y','F j Y','F j, Y','j F, Y'] as $f){$d=DateTime::createFromFormat('!'.$f,$s);if($d)return $d->format('Y-m-d');}
    $ts=strtotime($s);return $ts===false?null:date('Y-m-d',$ts);
}
function farm_col_index(string $letters):int{$n=0;foreach(str_split($letters)as$c)$n=$n*26+(ord(strtoupper($c))-64);return$n-1;}
function farm_parse_xlsx(string $file):array{
    if(!class_exists('ZipArchive'))throw new RuntimeException('ZipArchive is required to read .xlsx files.');
    $z=new ZipArchive();if($z->open($file)!==true)throw new RuntimeException('The Excel workbook could not be opened.');
    $shared=[];$ss=$z->getFromName('xl/sharedStrings.xml');
    if($ss!==false){$x=simplexml_load_string($ss);if($x)foreach($x->si as$si){$p=[];if(isset($si->t))$p[]=(string)$si->t;if(isset($si->r))foreach($si->r as$r)$p[]=(string)$r->t;$shared[]=implode('',$p);}}
    $sheet=$z->getFromName('xl/worksheets/sheet1.xml');if($sheet===false){$z->close();throw new RuntimeException('Worksheet sheet1.xml was not found.');}
    $x=simplexml_load_string($sheet);$rows=[];
    foreach($x->sheetData->row as$row){$o=[];foreach($row->c as$c){preg_match('/([A-Z]+)\d+/',(string)$c['r'],$m);$i=farm_col_index($m[1]??'A');$type=(string)$c['t'];$v='';if($type==='s')$v=$shared[(int)$c->v]??'';elseif($type==='inlineStr')$v=(string)$c->is->t;else$v=(string)$c->v;$o[$i]=$v;}if($o){ksort($o);$full=array_fill(0,max(array_keys($o))+1,'');foreach($o as$i=>$v)$full[$i]=$v;$rows[]=$full;}}
    $z->close();return$rows;
}
function farm_parse_csv(string$file):array{$r=[];$h=fopen($file,'rb');if(!$h)return[];while(($x=fgetcsv($h))!==false)$r[]=$x;fclose($h);return$r;}
function farm_parse(string$file,string$ext):array{if(in_array($ext,['xlsx','xlsm','xltx'],true))return farm_parse_xlsx($file);if($ext==='csv')return farm_parse_csv($file);throw new RuntimeException('Please upload an .xlsx, .xlsm, .xltx or .csv file.');}

function farm_headers():array{return[
 'contract_date'=>['contract_date'],'seller_name'=>['seller_name'],'seller_district'=>['seller_district'],
 'seller_region'=>['seller_region'],'buyer_name'=>['buyer_name'],'buyer_region'=>['buyer_region'],
 'coffee_type'=>['coffee_type'],'processing_method'=>['processing_method'],
 'kilos_to_be_sold'=>['kilos_to_be_sold'],'price_per_kilo_tzs'=>['price_per_kilo_tzs','price_per_kilo'],
 'warehouse'=>['warehouse']
];}
function farm_map(array$raw):array{$n=array_map('farm_norm',$raw);$m=[];foreach(farm_headers()as$f=>$aliases){$m[$f]=null;foreach($aliases as$a){$i=array_search($a,$n,true);if($i!==false){$m[$f]=$i;break;}}}return$m;}
function farm_labels():array{return[
 'contract_date'=>'Contract Date','seller_name'=>'Seller Name','seller_district'=>'Seller District','seller_region'=>'Seller Region',
 'buyer_name'=>'Buyer Name','buyer_region'=>'Buyer Region','coffee_type'=>'Coffee Type','processing_method'=>'Processing Method',
 'kilos_to_be_sold'=>'Kilos to be Sold','price_per_kilo_tzs'=>'Price per Kilo (TZS)','warehouse'=>'Warehouse'
];}
function farm_hash(array$r):string{
    $v=[];foreach(['contract_date','seller_name','seller_district','seller_region','buyer_name','buyer_region','coffee_type','processing_method','kilos_to_be_sold','price_per_kilo_tzs','warehouse']as$k)$v[]=strtolower(trim((string)($r[$k]??'')));
    return hash('sha256',implode("\x1f",$v));
}
function farm_upload():void{
    if(!isset($_FILES['farm_excel']))farm_json(false,'Please select the Farm Gate Contract Excel file.',[],400);
    $f=$_FILES['farm_excel'];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)farm_json(false,'The selected file could not be uploaded.',[],400);
    try{$rows=farm_parse($f['tmp_name'],strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)));}catch(Throwable$e){farm_json(false,$e->getMessage(),[],400);}
    if(count($rows)<2)farm_json(false,'The workbook does not contain enough data.',[],400);
    $hi=null;for($i=0;$i<min(15,count($rows));$i++){$m=farm_map($rows[$i]);if($m['contract_date']!==null&&$m['seller_name']!==null&&$m['buyer_name']!==null){$hi=$i;break;}}
    if($hi===null)farm_json(false,'The Farm Gate Contract header row could not be identified.',[],400);
    $map=farm_map($rows[$hi]);$missing=[];$labels=farm_labels();foreach($map as$k=>$v)if($v===null)$missing[]=$labels[$k];
    $non=array_values(array_filter($rows[$hi],fn($x)=>trim((string)$x)!==''));
    if(count($non)!==11||$missing)farm_json(false,'Upload stopped. Farm Gate Contract requires exactly 11 columns.'.($missing?' Missing: '.implode(', ',$missing).'.':'').' Expected: '.implode(', ',array_values($labels)).'.',[],400);
    $records=[];$errors=[];
    foreach($rows as$ri=>$row){if($ri<=$hi)continue;$get=function($k)use($row,$map){return trim((string)($row[$map[$k]]??''));};
      $date=farm_date($get('contract_date'));$seller=$get('seller_name');$buyer=$get('buyer_name');$kg=farm_num($get('kilos_to_be_sold'));$price=farm_num($get('price_per_kilo_tzs'));
      if($date===null&&$seller===''&&$buyer===''&&$kg===null&&$price===null)continue;
      $bad=[];if(!$date)$bad[]='Contract Date';if($seller==='')$bad[]='Seller Name';if($buyer==='')$bad[]='Buyer Name';if($kg===null||$kg<=0)$bad[]='valid Kilos to be Sold';if($price===null||$price<=0)$bad[]='valid Price per Kilo (TZS)';
      if($bad){$errors[]=['excel_row'=>$ri+1,'missing_or_invalid'=>$bad];continue;}
      $r=['contract_date'=>$date,'seller_name'=>$seller,'seller_district'=>$get('seller_district'),'seller_region'=>$get('seller_region'),'buyer_name'=>$buyer,'buyer_region'=>$get('buyer_region'),'coffee_type'=>$get('coffee_type'),'processing_method'=>$get('processing_method'),'kilos_to_be_sold'=>$kg,'price_per_kilo_tzs'=>$price,'warehouse'=>$get('warehouse')];
      $r['row_hash']=farm_hash($r);$records[$r['row_hash']]=$r;
    }
    if($errors)farm_json(false,'Some rows contain blank or invalid mandatory fields. Nothing was uploaded.',['errors'=>$errors],400);
    if(!$records)farm_json(false,'No valid Farm Gate Contract records were found.',[],400);
    ensure_farm_gate_table();$db=farm_db();$existing=[];$q=$db->query("SELECT row_hash FROM public.farm_gate_contracts WHERE row_hash IS NOT NULL");foreach($q as$r)$existing[$r['row_hash']]=true;
    $dupes=array_values(array_intersect(array_keys($records),array_keys($existing)));$confirmed=($_POST['confirm_replace']??'')==='1';
    if($dupes&&!$confirmed)farm_json(true,'Existing matching Farm Gate Contract records were found. Confirm replacement to continue.',['requires_confirmation'=>true,'existing_count'=>count($dupes),'new_record_count'=>count($records)-count($dupes)]);
    $db->beginTransaction();try{
      $del=$db->prepare("DELETE FROM public.farm_gate_contracts WHERE row_hash=:row_hash");
      $ins=$db->prepare("INSERT INTO public.farm_gate_contracts(contract_date,seller_name,seller_district,seller_region,buyer_name,buyer_region,coffee_type,processing_method,kilos_to_be_sold,price_per_kilo_tzs,warehouse,row_hash) VALUES(:contract_date,:seller_name,:seller_district,:seller_region,:buyer_name,:buyer_region,:coffee_type,:processing_method,:kilos_to_be_sold,:price_per_kilo_tzs,:warehouse,:row_hash)");
      foreach($records as$r){if(isset($existing[$r['row_hash']]))$del->execute(['row_hash'=>$r['row_hash']]);$ins->execute($r);}
      $db->commit();
    }catch(Throwable$e){if($db->inTransaction())$db->rollBack();error_log('Farm gate upload: '.$e->getMessage());farm_json(false,'The Farm Gate Contract records could not be saved.',[],500);}
    farm_json(true,'Farm Gate Contract data uploaded successfully.',['requires_confirmation'=>false,'processed_records'=>count($records),'replaced_records'=>count($dupes),'new_records'=>count($records)-count($dupes)]);
}
function farm_range(string$s):array{if(!preg_match('/^(\d{4})\/(\d{4})$/',$s,$m)||(int)$m[2]!=(int)$m[1]+1)throw new InvalidArgumentException('Invalid Sale Season.');return[$m[1].'-07-01',$m[2].'-06-30'];}
function farm_where(string$season,array&$p):string{$w=[];if($season!==''){[$a,$b]=farm_range($season);$w[]='contract_date BETWEEN :sf AND :st';$p['sf']=$a;$p['st']=$b;}return$w?' WHERE '.implode(' AND ',$w):'';}
function farm_filters():void{
    ensure_farm_gate_table();$db=farm_db();$e="CASE WHEN EXTRACT(MONTH FROM contract_date)>=7 THEN EXTRACT(YEAR FROM contract_date)::int ELSE EXTRACT(YEAR FROM contract_date)::int-1 END";
    $seasons=$db->query("SELECT DISTINCT ($e)::text||'/'||(($e)+1)::text s FROM public.farm_gate_contracts WHERE contract_date IS NOT NULL ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);
    farm_json(true,'',['seasons'=>$seasons,'latest'=>$seasons[0]??null]);
}
function farm_fetch():void{
    ensure_farm_gate_table();$db=farm_db();$p=[];$where=farm_where(trim($_GET['season']??''),$p);
    $s=$db->prepare("SELECT id,TO_CHAR(contract_date,'DD/MM/YYYY') contract_date,seller_name,seller_district,seller_region,buyer_name,buyer_region,coffee_type,processing_method,kilos_to_be_sold,price_per_kilo_tzs,warehouse,(kilos_to_be_sold*price_per_kilo_tzs) total_value_tzs FROM public.farm_gate_contracts$where ORDER BY contract_date DESC,id DESC");$s->execute($p);farm_json(true,'',['rows'=>$s->fetchAll()]);
}
function farm_summary():void{
    ensure_farm_gate_table();$db=farm_db();$season=trim($_GET['season']??'');$mode=$_GET['mode']??'coffee_type';$allowed=['coffee_type','seller_region','buyer_name','seller_name'];if(!in_array($mode,$allowed,true))$mode='coffee_type';
    $p=[];$where=farm_where($season,$p);$labels=['coffee_type'=>'Coffee Type','seller_region'=>'Seller Region','buyer_name'=>'Buyer','seller_name'=>'Supplier / Seller'];
    $sql="SELECT COALESCE(NULLIF(BTRIM($mode),''),'Unspecified') label,COUNT(*) contracts,COALESCE(SUM(kilos_to_be_sold),0) kgs,COALESCE(SUM(kilos_to_be_sold*price_per_kilo_tzs),0) value_tzs,CASE WHEN SUM(SUM(kilos_to_be_sold)) OVER()>0 THEN SUM(kilos_to_be_sold)*100.0/SUM(SUM(kilos_to_be_sold)) OVER() ELSE 0 END share FROM public.farm_gate_contracts$where GROUP BY 1 ORDER BY kgs DESC,label";
    $s=$db->prepare($sql);$s->execute($p);$rows=$s->fetchAll();
    $t=['contracts'=>0,'kgs'=>0.0,'value_tzs'=>0.0];foreach($rows as$r){$t['contracts']+=(int)$r['contracts'];$t['kgs']+=(float)$r['kgs'];$t['value_tzs']+=(float)$r['value_tzs'];}
    farm_json(true,'',['title'=>$labels[$mode],'rows'=>$rows,'total'=>$t]);
}
function farm_update():void{
    ensure_farm_gate_table();$db=farm_db();$in=json_decode(file_get_contents('php://input'),true)?:[];$id=(int)($in['id']??0);if($id<=0)farm_json(false,'Invalid row.',[],400);
    $fields=['contract_date','seller_name','seller_district','seller_region','buyer_name','buyer_region','coffee_type','processing_method','kilos_to_be_sold','price_per_kilo_tzs','warehouse'];$set=[];$p=['id'=>$id];
    foreach($fields as$f)if(array_key_exists($f,$in)){$v=$in[$f];if($f==='contract_date')$v=farm_date($v);if(in_array($f,['kilos_to_be_sold','price_per_kilo_tzs'],true))$v=farm_num($v);$set[]="$f=:$f";$p[$f]=$v;}
    if(!$set)farm_json(false,'No changes were supplied.',[],400);
    $s=$db->prepare("UPDATE public.farm_gate_contracts SET ".implode(',',$set).",row_hash=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id");$s->execute($p);farm_json(true,'Row updated successfully.');
}
function farm_delete():void{$in=json_decode(file_get_contents('php://input'),true)?:[];$id=(int)($in['id']??0);if($id<=0)farm_json(false,'Invalid row.',[],400);ensure_farm_gate_table();$s=farm_db()->prepare("DELETE FROM public.farm_gate_contracts WHERE id=:id");$s->execute(['id'=>$id]);farm_json(true,'Row deleted successfully.');}

try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_FILES['farm_excel']))farm_upload();
 $a=$_GET['action']??'';if($a==='filters')farm_filters();if($a==='fetch')farm_fetch();if($a==='summary')farm_summary();
 if($a==='update'&&($_SERVER['REQUEST_METHOD']??'')==='POST')farm_update();if($a==='delete'&&($_SERVER['REQUEST_METHOD']??'')==='POST')farm_delete();
 if($a==='delete_all'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){farm_json(true,'All Farm Gate Contract records were deleted.',['deleted'=>farm_delete_all()]);}
 ensure_farm_gate_table();
}catch(Throwable$e){
    error_log('Farm gate error: '.$e->getMessage());
    if(isset($_GET['action'])||($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        farm_json(false,'Farm Gate Contract operation failed. '.$e->getMessage(),[],500);
    }
    $farm_boot_error=$e->getMessage();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mikataba ya Kahawa Ghafi</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;font-family:Arial,sans-serif;background:#f7f4f2;color:#382b26}body{overflow:hidden}
.app{height:100vh;padding:8px;display:grid;grid-template-rows:auto auto minmax(0,1fr);gap:6px}.toolbar,.filters,.controls{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.toolbar{justify-content:space-between}.title{font-size:15px;font-weight:800;color:#4b342c}.muted{font-size:9px;color:#8b7d77}
select,button,input{height:30px;border:1px solid #d9cfca;border-radius:6px;background:#fff;padding:0 9px;font-size:11px;color:#4b342c}button{cursor:pointer;font-weight:700}.primary{background:#5d4037;color:#fff;border-color:#5d4037}.danger{color:#9a2f28}
.settings,.exportwrap{position:relative}.menu,.exportmenu{display:none;position:absolute;right:0;top:34px;background:#fff;border:1px solid #ddd2cd;border-radius:7px;padding:5px;min-width:145px;z-index:40;box-shadow:0 8px 22px #0002}.menu.open,.exportmenu.open{display:block}.menu button,.exportmenu button{display:block;width:100%;text-align:left;border:0;background:#fff}.menu button:hover,.exportmenu button:hover{background:#f5f1ef}
.uploadbox{display:none;align-items:center;gap:5px}.uploadbox.open{display:flex}.msg{font-size:10px;padding:5px 8px;border-radius:5px;display:none}.msg.ok{display:block;background:#eaf5ec;color:#276536}.msg.err{display:block;background:#faecea;color:#8a3029}
.card{min-height:0;background:#fff;border:1px solid #e6ddd9;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}.tablewrap,.summarywrap{min-height:0;overflow:auto;flex:1}.summarywrap{display:none;padding:8px}.summarywrap.show{display:block}.tablewrap.hide{display:none}
table{border-collapse:collapse;width:100%;min-width:1080px;font-size:9px}th,td{padding:4px 5px;border-bottom:1px solid #eee7e4;white-space:nowrap;text-align:right}th:first-child,td:first-child{text-align:left}thead th{position:sticky;top:0;background:#4b342c;color:#fff;z-index:3;font-size:8px}.text{text-align:left}.actions{display:none}.editmode .actions{display:table-cell}.rowbtn{height:23px;padding:0 5px;font-size:9px}
.summary-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:7px}.summary-title{font-size:13px;font-weight:800}.summary-table{min-width:650px;border:3px solid #000;table-layout:auto}.summary-table th,.summary-table td{border:1px solid #000;background:#fff!important;color:#000!important;padding:5px}.summary-table th{position:sticky;top:0;text-align:center}.summary-table tr:first-child>*{border-top-width:3px}.summary-table tr:last-child>*{border-bottom-width:3px}.summary-table tr>*:first-child{border-left-width:3px}.summary-table tr>*:last-child{border-right-width:3px}.summary-table .total td{font-weight:800;border-top:2px solid #000}
@media(max-width:900px){body{overflow:auto}.app{height:auto;min-height:100vh}.controls,.filters{width:100%}.card{min-height:70vh}}@media(max-width:600px){.app{padding:5px}.title{font-size:13px}select,button,input{height:28px;font-size:10px}.controls>*{flex:1 1 auto}.uploadbox.open{width:100%;flex-wrap:wrap}}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head><body><div class="app">
<?php if(!empty($farm_boot_error)): ?>
<div style="margin:4px 0;padding:8px 10px;border:1px solid #d8a29d;background:#fff0ee;color:#8a3029;border-radius:6px;font-size:11px">
<strong>Farm Gate module initialization error:</strong> <?= htmlspecialchars($farm_boot_error,ENT_QUOTES,'UTF-8') ?>
</div>
<?php endif; ?>
<div class="toolbar"><div><span class="title">Mikataba ya Kahawa Ghafi</span> <span class="muted">Farm Gate Contracts · PostgreSQL</span></div><div class="controls">
<select id="display"><option value="all">All Contracts</option><option value="summary">Sale Summary</option></select>
<div class="exportwrap"><button id="exportButton" onclick="toggleExport(event)">⇩ Export</button><div class="exportmenu" id="exportMenu"><button onclick="doExport('pdf')">PDF</button><button onclick="doExport('excel')">Excel</button><button onclick="doExport('word')">Word</button></div></div>
<div class="settings"><button onclick="toggleSettings()">⚙ Settings</button><div class="menu" id="settingsMenu"><button onclick="showUpload()">↑ Upload Contracts</button><button onclick="toggleEdit()">✎ Edit data</button><button class="danger" onclick="deleteAll()">⌫ Delete all data</button></div></div>
</div></div>
<div><div class="filters"><select id="season" title="Sale Season: 1 July to 30 June"><option value="">All Sale Seasons</option></select>
<select id="summaryMode" style="display:none"><option value="coffee_type">By Coffee Type</option><option value="seller_region">By Seller Region</option><option value="buyer_name">By Buyer</option><option value="seller_name">By Supplier / Seller</option></select>
<button onclick="refreshAll()">↻ Refresh</button><div class="uploadbox" id="uploadbox"><input type="file" id="file" accept=".xlsx,.xlsm,.xltx,.csv"><button class="primary" onclick="upload(false)">Upload Farm Gate Contracts</button></div></div><div class="msg" id="msg"></div></div>
<div class="card" id="card"><div class="tablewrap" id="tablewrap"><table id="table"></table></div><div class="summarywrap" id="summarywrap"><div class="summary-head"><div><div class="summary-title" id="summaryTitle">Sale Summary</div><div class="muted">Value = Kilos × Price per Kilo (TZS)</div></div></div><table class="summary-table" id="summaryTable"></table></div></div>
</div>
<script>
const $=id=>document.getElementById(id);let rows=[],editMode=false;
const cols=[['contract_date','Contract Date'],['seller_name','Seller Name'],['seller_district','Seller District'],['seller_region','Seller Region'],['buyer_name','Buyer Name'],['buyer_region','Buyer Region'],['coffee_type','Coffee Type'],['processing_method','Processing Method'],['kilos_to_be_sold','Kilos to be Sold'],['price_per_kilo_tzs','Price/Kg (TZS)'],['total_value_tzs','Value (TZS)'],['warehouse','Warehouse']];
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}function num(v,d=2){let n=Number(v);return Number.isFinite(n)?n.toLocaleString(undefined,{maximumFractionDigits:d}):'-'}
function message(t,ok=true){let m=$('msg');m.className='msg '+(ok?'ok':'err');m.textContent=t;setTimeout(()=>m.className='msg',6500)}async function api(u,o){let r=await fetch(u,o),j=await r.json();if(!r.ok||j.success===false)throw new Error(j.message||'Request failed');return j.data}
function toggleSettings(){$('settingsMenu').classList.toggle('open')}function toggleExport(e){e.stopPropagation();$('exportMenu').classList.toggle('open')}function showUpload(){$('uploadbox').classList.toggle('open');$('settingsMenu').classList.remove('open')}function toggleEdit(){editMode=!editMode;$('card').classList.toggle('editmode',editMode);renderRows();$('settingsMenu').classList.remove('open')}
async function loadFilters(){let d=await api('farm_gate_contract.php?action=filters'),s=$('season'),cur=s.value;s.innerHTML='<option value="">All Sale Seasons</option>'+d.seasons.map(x=>`<option>${esc(x)}</option>`).join('');s.value=(cur&&d.seasons.includes(cur))?cur:(d.latest||'')}
async function loadRows(){let d=await api('farm_gate_contract.php?action=fetch&season='+encodeURIComponent($('season').value));rows=d.rows;renderRows()}
function renderRows(){let h='<thead><tr>'+cols.map(c=>`<th>${esc(c[1])}</th>`).join('')+'<th class="actions">Actions</th></tr></thead><tbody>';h+=rows.map(r=>'<tr>'+cols.map(c=>`<td class="${['seller_name','seller_district','seller_region','buyer_name','buyer_region','coffee_type','processing_method','warehouse'].includes(c[0])?'text':''}">${['kilos_to_be_sold','price_per_kilo_tzs','total_value_tzs'].includes(c[0])?num(r[c[0]]):esc(r[c[0]])}</td>`).join('')+`<td class="actions"><button class="rowbtn" onclick="editRow(${r.id})">✎</button> <button class="rowbtn danger" onclick="deleteRow(${r.id})">⌫</button></td></tr>`).join('');if(!rows.length)h+=`<tr><td colspan="${cols.length+1}" class="text">No Farm Gate Contract data for this season.</td></tr>`;$('table').innerHTML=h+'</tbody>'}
async function loadSummary(){let d=await api('farm_gate_contract.php?action=summary&season='+encodeURIComponent($('season').value)+'&mode='+encodeURIComponent($('summaryMode').value));$('summaryTitle').textContent='Sale Summary by '+d.title;let h='<thead><tr><th>'+esc(d.title)+'</th><th>Contracts</th><th>Kilos (kg)</th><th>Value (TZS)</th><th>% Share</th></tr></thead><tbody>';h+=d.rows.map(r=>`<tr><td class="text">${esc(r.label)}</td><td>${num(r.contracts,0)}</td><td>${num(r.kgs)}</td><td>${num(r.value_tzs)}</td><td>${num(r.share)}%</td></tr>`).join('');h+=`<tr class="total"><td>Grand Total</td><td>${num(d.total.contracts,0)}</td><td>${num(d.total.kgs)}</td><td>${num(d.total.value_tzs)}</td><td>100%</td></tr></tbody>`;$('summaryTable').innerHTML=h}
async function refreshAll(){try{let summary=$('display').value==='summary';$('tablewrap').classList.toggle('hide',summary);$('summarywrap').classList.toggle('show',summary);$('summaryMode').style.display=summary?'inline-block':'none';if(summary)await loadSummary();else await loadRows()}catch(e){message(e.message,false)}}
async function upload(confirmReplace){let f=$('file').files[0];if(!f){message('Select the Farm Gate Contract Excel file first.',false);return}let fd=new FormData();fd.append('farm_excel',f);if(confirmReplace)fd.append('confirm_replace','1');try{let r=await fetch('farm_gate_contract.php',{method:'POST',body:fd}),j=await r.json();if(!r.ok||j.success===false)throw new Error(j.message);if(j.data?.requires_confirmation){if(confirm(`Found ${j.data.existing_count} existing matching record(s). Replace them?`))return upload(true);return}message(j.message);await loadFilters();await refreshAll()}catch(e){message(e.message,false)}}
async function deleteRow(id){if(!confirm('Delete this contract record?'))return;try{let r=await fetch('farm_gate_contract.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}),j=await r.json();if(!r.ok||!j.success)throw new Error(j.message);message(j.message);await refreshAll()}catch(e){message(e.message,false)}}
async function deleteAll(){if(!confirm('Delete ALL Farm Gate Contract records?'))return;try{let r=await fetch('farm_gate_contract.php?action=delete_all',{method:'POST'}),j=await r.json();if(!r.ok||!j.success)throw new Error(j.message);message(j.message);await loadFilters();await refreshAll()}catch(e){message(e.message,false)}}
async function editRow(id){let r=rows.find(x=>Number(x.id)===Number(id));if(!r)return;let p={id};for(let [k,label] of cols){if(k==='total_value_tzs')continue;let v=prompt(label,r[k]??'');if(v===null)return;p[k]=v}try{let q=await fetch('farm_gate_contract.php?action=update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)}),j=await q.json();if(!q.ok||!j.success)throw new Error(j.message);message(j.message);await refreshAll()}catch(e){message(e.message,false)}}
function exportHtml(){let el=$('display').value==='summary'?$('summarywrap'):$('table');return `<h2>Mikataba ya Kahawa Ghafi</h2><p>Sale Season: ${esc($('season').value||'All')}</p>${el.outerHTML}`}
function doExport(type){$('exportMenu').classList.remove('open');let name='Farm_Gate_Contracts_'+($('season').value||'All').replace('/','-');if(type==='excel'){let blob=new Blob(['<html><head><meta charset="utf-8"></head><body>'+exportHtml()+'</body></html>'],{type:'application/vnd.ms-excel'});download(blob,name+'.xls');return}if(type==='word'){let blob=new Blob(['<html><head><meta charset="utf-8"></head><body>'+exportHtml()+'</body></html>'],{type:'application/msword'});download(blob,name+'.doc');return}exportPdf(name)}
function download(blob,name){let a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=name;document.body.appendChild(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},1000)}
async function exportPdf(name){if(!window.html2canvas||!window.jspdf){message('PDF exporter did not load. Refresh and try again.',false);return}let el=$('display').value==='summary'?$('summarywrap'):$('tablewrap');let c=await html2canvas(el,{scale:1.5,backgroundColor:'#fff'}),img=c.toDataURL('image/png'),jsPDF=window.jspdf.jsPDF,pdf=new jsPDF({orientation:c.width>c.height?'landscape':'portrait',unit:'mm',format:'a4'}),pw=pdf.internal.pageSize.getWidth()-12,ph=c.height*pw/c.width;pdf.addImage(img,'PNG',6,6,pw,ph);pdf.save(name+'.pdf')}
$('display').addEventListener('change',refreshAll);$('season').addEventListener('change',refreshAll);$('summaryMode').addEventListener('change',refreshAll);document.addEventListener('click',e=>{if(!e.target.closest('.settings'))$('settingsMenu').classList.remove('open');if(!e.target.closest('.exportwrap'))$('exportMenu').classList.remove('open')});
(async()=>{try{await loadFilters();await refreshAll()}catch(e){message(e.message,false)}})();
</script></body></html>
