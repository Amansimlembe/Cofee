<?php
/* Shared Direct Sales PostgreSQL database layer. */
function direct_db(): PDO {
    static $pdo=null;
    if ($pdo instanceof PDO) return $pdo;
    $url=getenv('DATABASE_URL');
    if (!$url) throw new RuntimeException('DATABASE_URL is not configured.');
    $p=parse_url($url);
    if (!$p || empty($p['host']) || empty($p['path'])) throw new RuntimeException('Invalid DATABASE_URL.');
    $dsn='pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/');
    $pdo=new PDO($dsn,urldecode($p['user']??''),urldecode($p['pass']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    return $pdo;
}
function direct_ensure_table(): void {
    direct_db()->exec("CREATE TABLE IF NOT EXISTS public.direct_sales (
      id BIGSERIAL PRIMARY KEY,
      crop_season VARCHAR(30), sale_category VARCHAR(80) NOT NULL, invoice_number VARCHAR(150), source_invoice_number VARCHAR(150),
      invoice_date DATE, contract_number VARCHAR(150), grade_name VARCHAR(150), coffee_type VARCHAR(100), net_kg NUMERIC(18,3),
      price_usd_50kg NUMERIC(18,6), exchange_rate NUMERIC(18,6), warehouse_name VARCHAR(255), warehouse_location VARCHAR(255),
      region VARCHAR(150), supplier_seller VARCHAR(255), buyer VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    direct_db()->exec("CREATE INDEX IF NOT EXISTS idx_direct_sales_category ON public.direct_sales(sale_category)");
    direct_db()->exec("CREATE INDEX IF NOT EXISTS idx_direct_sales_season ON public.direct_sales(crop_season)");
    direct_db()->exec("CREATE INDEX IF NOT EXISTS idx_direct_sales_date ON public.direct_sales(invoice_date)");
}
function direct_json(bool $ok,string $message='',array $data=[],int $status=200): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>$ok,'message'=>$message]+$data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function direct_category(string $v): string {
    $x=strtolower(trim(preg_replace('/\s+/',' ',$v)));
    $map=['direct export'=>'Direct Export','de'=>'Direct Export','local sale'=>'Local Sale','ls'=>'Local Sale','local roast'=>'Local Roast','lr'=>'Local Roast'];
    return $map[$x]??trim($v);
}
function direct_num($v): ?float { if($v===null||$v==='')return null; $v=str_replace([',',' '],'',(string)$v); return is_numeric($v)?(float)$v:null; }
function direct_date($v): ?string {
    if($v===null||trim((string)$v)==='') return null;
    if(is_numeric($v)){ $ts=((float)$v-25569)*86400; return gmdate('Y-m-d',(int)$ts); }
    $ts=strtotime((string)$v); return $ts?date('Y-m-d',$ts):null;
}
