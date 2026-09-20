<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CLEAN AUCTION DATABASE LAYER
|--------------------------------------------------------------------------
| PostgreSQL database logic for Clean Coffee Auction Results.
| Database columns mirror the uploaded Clean Auction Results workbook:
| Lot No., Auction No., Auction Date, Seller, Crop Season, Grade, Grade2,
| N/Kgs, Warehouse, District, Region, Price (/50Kg), Status and Buyer.
|--------------------------------------------------------------------------
*/

function clean_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $url = getenv('DATABASE_URL');
    if (!$url) throw new RuntimeException('DATABASE_URL is not configured in Render.');
    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL driver (pdo_pgsql) is not enabled.');
    }

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) {
        throw new RuntimeException('DATABASE_URL is invalid.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $p['host'],
        (int)($p['port'] ?? 5432),
        ltrim($p['path'], '/')
    );

    $db = new PDO($dsn, urldecode($p['user']), urldecode($p['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}

function clean_table(): string
{
    return 'public.clean_auction_results';
}

function ensure_clean_table(): void
{
    $db = clean_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS public.clean_auction_results (
            id BIGSERIAL PRIMARY KEY,
            lot_no VARCHAR(100),
            auction_no VARCHAR(100),
            auction_date DATE,
            seller VARCHAR(255),
            crop_season VARCHAR(50),
            grade VARCHAR(100),
            grade2 VARCHAR(100),
            n_kgs NUMERIC(15,2),
            warehouse VARCHAR(255),
            district VARCHAR(255),
            region VARCHAR(255),
            price_per_50kg NUMERIC(15,4),
            status VARCHAR(50),
            buyer VARCHAR(255),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columns = [
        'lot_no' => 'VARCHAR(100)',
        'auction_no' => 'VARCHAR(100)',
        'auction_date' => 'DATE',
        'seller' => 'VARCHAR(255)',
        'crop_season' => 'VARCHAR(50)',
        'grade' => 'VARCHAR(100)',
        'grade2' => 'VARCHAR(100)',
        'n_kgs' => 'NUMERIC(15,2)',
        'warehouse' => 'VARCHAR(255)',
        'district' => 'VARCHAR(255)',
        'region' => 'VARCHAR(255)',
        'price_per_50kg' => 'NUMERIC(15,4)',
        'status' => 'VARCHAR(50)',
        'buyer' => 'VARCHAR(255)',
        'created_at' => 'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP',
    ];

    $check = $db->prepare("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='clean_auction_results'
          AND column_name=:column
    ");
    foreach ($columns as $name => $type) {
        $check->execute(['column'=>$name]);
        if (!$check->fetchColumn()) {
            $db->exec('ALTER TABLE public.clean_auction_results ADD COLUMN "' . $name . '" ' . $type);
        }
    }

    /* Normalize keys and remove unusable legacy rows. */
    $db->exec("
        UPDATE public.clean_auction_results
        SET lot_no=NULLIF(BTRIM(lot_no),''),
            auction_no=NULLIF(BTRIM(auction_no),'')
    ");
    $db->exec("
        DELETE FROM public.clean_auction_results
        WHERE lot_no IS NULL OR auction_no IS NULL OR auction_date IS NULL
           OR n_kgs IS NULL OR n_kgs <= 0
    ");

    /* Keep the newest row if old duplicates already exist. */
    $db->exec("
        DELETE FROM public.clean_auction_results a
        USING public.clean_auction_results b
        WHERE a.id < b.id
          AND a.lot_no=b.lot_no
          AND a.auction_no=b.auction_no
          AND a.auction_date=b.auction_date
    ");

    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_clean_lot_auction_date
        ON public.clean_auction_results(lot_no,auction_no,auction_date)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_clean_auction_date
        ON public.clean_auction_results(auction_date)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_clean_auction_no
        ON public.clean_auction_results(auction_no)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_clean_crop_season
        ON public.clean_auction_results(crop_season)
    ");
}

function clean_delete_all(): int
{
    ensure_clean_table();
    return clean_db()->exec("DELETE FROM public.clean_auction_results");
}
