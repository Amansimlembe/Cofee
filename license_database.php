<?php
declare(strict_types=1);
function license_db(): PDO {
    static $db=null; if($db instanceof PDO)return $db;
    $url=getenv('DATABASE_URL'); if(!$url)throw new RuntimeException('DATABASE_URL is not configured in Render.');
    if(!in_array('pgsql',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO PostgreSQL driver (pdo_pgsql) is not enabled.');
    $p=parse_url($url); if(!$p||empty($p['host'])||empty($p['user'])||empty($p['path']))throw new RuntimeException('DATABASE_URL is invalid.');
    $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',$p['host'],(int)($p['port']??5432),ltrim($p['path'],'/'));
    $db=new PDO($dsn,urldecode($p['user']),urldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $db;
}
function ensure_license_table(): void {
 $db=license_db();
 $db->exec("CREATE TABLE IF NOT EXISTS public.coffee_licenses (
 id BIGSERIAL PRIMARY KEY, license_sale_season VARCHAR(20) NOT NULL, license_category VARCHAR(255) NOT NULL,
 company_name VARCHAR(300) NOT NULL, postal_address TEXT, region VARCHAR(120), email_address VARCHAR(255),
 tin_no VARCHAR(80), tel_no VARCHAR(80), license_no VARCHAR(120), issued_date DATE, expire_date DATE,
 created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP)");
 $cols=['license_sale_season'=>'VARCHAR(20)','license_category'=>'VARCHAR(255)','company_name'=>'VARCHAR(300)','postal_address'=>'TEXT','region'=>'VARCHAR(120)','email_address'=>'VARCHAR(255)','tin_no'=>'VARCHAR(80)','tel_no'=>'VARCHAR(80)','license_no'=>'VARCHAR(120)','issued_date'=>'DATE','expire_date'=>'DATE','created_at'=>'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP'];
 $q=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='coffee_licenses' AND column_name=:c");
 foreach($cols as $c=>$t){$q->execute(['c'=>$c]);if(!$q->fetchColumn())$db->exec('ALTER TABLE public.coffee_licenses ADD COLUMN "'.$c.'" '.$t);}
 $db->exec("CREATE INDEX IF NOT EXISTS idx_license_season ON public.coffee_licenses(license_sale_season)");
 $db->exec("CREATE INDEX IF NOT EXISTS idx_license_category ON public.coffee_licenses(license_category)");
 $db->exec("CREATE INDEX IF NOT EXISTS idx_license_region ON public.coffee_licenses(region)");
 $db->exec("CREATE INDEX IF NOT EXISTS idx_license_company ON public.coffee_licenses(company_name)");
}
function license_delete_all(): int {ensure_license_table();return license_db()->exec('DELETE FROM public.coffee_licenses');}
