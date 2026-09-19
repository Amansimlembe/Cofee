<?php
/*
|--------------------------------------------------------------------------
| KAGERA AUCTION DATABASE LAYER
|--------------------------------------------------------------------------
| PostgreSQL connection, schema alignment, table creation/maintenance,
| cleanup, constraints and indexes.
|
| Save this file in your project as: kagera_database.php
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

function kagera_db()
{
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $database_url = getenv("DATABASE_URL");

    if (!$database_url) {
        throw new Exception(
            "DATABASE_URL is not configured in Render."
        );
    }

    if (!in_array("pgsql", PDO::getAvailableDrivers(), true)) {
        throw new Exception(
            "PHP PDO PostgreSQL driver (pdo_pgsql) is not enabled on Render."
        );
    }

    $parts = parse_url($database_url);

    if (
        !$parts ||
        empty($parts["host"]) ||
        empty($parts["user"]) ||
        empty($parts["path"])
    ) {
        throw new Exception(
            "The Render DATABASE_URL is invalid."
        );
    }

    $host = $parts["host"];
    $port = (int)($parts["port"] ?? 5432);
    $dbname = ltrim($parts["path"], "/");
    $user = urldecode($parts["user"]);
    $password = urldecode($parts["pass"] ?? "");

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

    try {

        $db = new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

    } catch (PDOException $e) {

        error_log(
            "Kagera PostgreSQL connection error: " .
            $e->getMessage()
        );

        throw new Exception(
            "PostgreSQL database connection failed."
        );
    }

    return $db;
}

/*
|--------------------------------------------------------------------------
| DATABASE TABLE
|--------------------------------------------------------------------------
*/

function kagera_table()
{
    return 'public.kagera_auction_results';
}


function kagera_align_existing_database_columns(PDO $db)
{
    $db->exec("
        DO $$
        BEGIN
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='date_sold')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='auction_date')
            THEN ALTER TABLE public.kagera_auction_results RENAME COLUMN date_sold TO auction_date; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='net_weight')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='kgs')
            THEN ALTER TABLE public.kagera_auction_results RENAME COLUMN net_weight TO kgs; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='warehouse_location')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='warehouse_location_district')
            THEN ALTER TABLE public.kagera_auction_results RENAME COLUMN warehouse_location TO warehouse_location_district; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='buyer_name')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_results' AND column_name='buyer')
            THEN ALTER TABLE public.kagera_auction_results RENAME COLUMN buyer_name TO buyer; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='date_sold')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='auction_date')
            THEN ALTER TABLE public.kagera_auction_catalogue RENAME COLUMN date_sold TO auction_date; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='net_weight')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='kgs')
            THEN ALTER TABLE public.kagera_auction_catalogue RENAME COLUMN net_weight TO kgs; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='bags')
            THEN ALTER TABLE public.kagera_auction_catalogue DROP COLUMN bags; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='warehouse')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='warehouse_name_amcos')
            THEN ALTER TABLE public.kagera_auction_catalogue RENAME COLUMN warehouse TO warehouse_name_amcos; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='district')
               AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='warehouse_location_district')
            THEN ALTER TABLE public.kagera_auction_catalogue RENAME COLUMN district TO warehouse_location_district; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='production_group')
            THEN ALTER TABLE public.kagera_auction_catalogue DROP COLUMN production_group; END IF;

            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='kagera_auction_catalogue' AND column_name='amcos')
            THEN ALTER TABLE public.kagera_auction_catalogue DROP COLUMN amcos; END IF;
        END $$;
    ");
}

function ensure_kagera_table()
{
    $db = kagera_db();
    kagera_align_existing_database_columns($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS public.kagera_auction_results (
            id BIGSERIAL PRIMARY KEY,
            lot_no VARCHAR(100),
            auction_no VARCHAR(50),
            auction_date DATE,
            warehouse VARCHAR(255),
            warehouse_location_district VARCHAR(255),
            kgs NUMERIC(15,2),
            grade VARCHAR(100),
            grade2 VARCHAR(100),
            price NUMERIC(15,4),
            buyer VARCHAR(255),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        DELETE FROM public.kagera_auction_results
        WHERE kgs IS NULL
           OR kgs <= 0
    ");

    $db->exec("
        ALTER TABLE public.kagera_auction_results
        ADD COLUMN IF NOT EXISTS value NUMERIC(18,2)
    ");
    $db->exec("
        UPDATE public.kagera_auction_results
        SET value = COALESCE(kgs, 0) * COALESCE(price, 0)
        WHERE value IS NULL
           OR value <> COALESCE(kgs, 0) * COALESCE(price, 0)
    ");



    $columns = [
        "lot_no" => "VARCHAR(100)",
        "auction_no" => "VARCHAR(50)",
        "auction_date" => "DATE",
        "warehouse" => "VARCHAR(255)",
        "warehouse_location_district" => "VARCHAR(255)",
        "kgs" => "NUMERIC(15,2)",
        "grade" => "VARCHAR(100)",
        "grade2" => "VARCHAR(100)",
        "price" => "NUMERIC(15,4)",
        "value" => "NUMERIC(18,2)",
        "buyer" => "VARCHAR(255)"
    ];

    $check = $db->prepare("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'kagera_auction_results'
          AND column_name = :column
    ");

    foreach ($columns as $column => $definition) {
        $check->execute(["column" => $column]);

        if (!$check->fetchColumn()) {
            $db->exec(
                'ALTER TABLE public.kagera_auction_results
                 ADD COLUMN "' . $column . '" ' . $definition
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CONVERT OLD auction_date TEXT/VARCHAR TO DATE
    |--------------------------------------------------------------------------
    */
    $dateTypeStmt = $db->query("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'kagera_auction_results'
          AND column_name = 'auction_date'
    ");

    $dateType = $dateTypeStmt->fetchColumn();

    if ($dateType && strtolower($dateType) !== 'date') {
        $db->exec("
            ALTER TABLE public.kagera_auction_results
            ALTER COLUMN auction_date TYPE DATE
            USING CASE
                WHEN auction_date IS NULL
                  OR BTRIM(auction_date::text) = '' THEN NULL
                WHEN auction_date::text ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                  THEN auction_date::text::date
                WHEN auction_date::text ~ '^[0-9]{2}/[0-9]{2}/[0-9]{4}$'
                  THEN TO_DATE(auction_date::text, 'DD/MM/YYYY')
                WHEN auction_date::text ~ '^[0-9]{2}-[0-9]{2}-[0-9]{4}$'
                  THEN TO_DATE(auction_date::text, 'DD-MM-YYYY')
                WHEN auction_date::text ~ '^[0-9]{1,2} [A-Za-z]+,? [0-9]{4}$'
                  THEN TO_DATE(auction_date::text, 'DD Month YYYY')
                WHEN auction_date::text ~ '^[A-Za-z]+ [0-9]{1,2},? [0-9]{4}$'
                  THEN TO_DATE(auction_date::text, 'Month DD YYYY')
                ELSE NULL
            END
        ");
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE KEY SPACES
    |--------------------------------------------------------------------------
    */
    $db->exec("
        UPDATE public.kagera_auction_results
        SET
            lot_no = NULLIF(BTRIM(lot_no), ''),
            auction_no = NULLIF(BTRIM(auction_no), '')
    ");

    /*
    |--------------------------------------------------------------------------
    | REMOVE INVALID KEY ROWS
    |--------------------------------------------------------------------------
    | Lot No + Auction No + Date Sold are mandatory.
    | Any existing database row with one of these fields blank/null is deleted.
    |--------------------------------------------------------------------------
    */
    $db->exec("
        DELETE FROM public.kagera_auction_results
        WHERE lot_no IS NULL
           OR BTRIM(lot_no) = ''
           OR auction_no IS NULL
           OR BTRIM(auction_no) = ''
           OR auction_date IS NULL
    ");

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC DUPLICATE CLEANUP
    |--------------------------------------------------------------------------
    | The newest row (highest id) is retained.
    |--------------------------------------------------------------------------
    */
    $db->exec("
        DELETE FROM public.kagera_auction_results a
        USING public.kagera_auction_results b
        WHERE a.id < b.id
          AND a.lot_no = b.lot_no
          AND a.auction_no = b.auction_no
          AND a.auction_date = b.auction_date
          AND a.lot_no IS NOT NULL
          AND a.auction_no IS NOT NULL
          AND a.auction_date IS NOT NULL
    ");

    /*
    |--------------------------------------------------------------------------
    | FINAL KEY VALIDATION
    |--------------------------------------------------------------------------
    | Keep the database clean before creating/enforcing the unique index.
    |--------------------------------------------------------------------------
    */
    $db->exec("
        DELETE FROM public.kagera_auction_results
        WHERE lot_no IS NULL
           OR BTRIM(lot_no) = ''
           OR auction_no IS NULL
           OR BTRIM(auction_no) = ''
           OR auction_date IS NULL
    ");

    /*
    |--------------------------------------------------------------------------
    | INDEXES
    |--------------------------------------------------------------------------
    */
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_auction_no
        ON public.kagera_auction_results (auction_no)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_lot_no
        ON public.kagera_auction_results (lot_no)
    ");

    /*
    |--------------------------------------------------------------------------
    | THREE-FIELD UNIQUE CONSTRAINT
    |--------------------------------------------------------------------------
    | Lot No + Auction No + Date Sold must be unique as a combination.
    |--------------------------------------------------------------------------
    */
    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_kagera_lot_auction_date
        ON public.kagera_auction_results
        (lot_no, auction_no, auction_date)
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND auction_date IS NOT NULL
    ");
}


/*
|--------------------------------------------------------------------------
| KAGERA CATALOGUE TABLE
|--------------------------------------------------------------------------
| Catalogue upload uses the Auction Date from Excel as auction_date, while
| retaining the complete catalogue fields.
|--------------------------------------------------------------------------
*/
function ensure_kagera_catalogue_table()
{
    $db = kagera_db();
    kagera_align_existing_database_columns($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS public.kagera_auction_catalogue (
            id BIGSERIAL PRIMARY KEY,
            lot_no VARCHAR(100),
            auction_no VARCHAR(50),
            auction_date DATE,
            union_name VARCHAR(255),
            warehouse_name_amcos VARCHAR(255),
            warehouse_location_district VARCHAR(255),
            kgs NUMERIC(15,2),
            grade VARCHAR(150),
            grade2 VARCHAR(150),
            certification VARCHAR(150),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        DELETE FROM public.kagera_auction_catalogue
        WHERE kgs IS NULL
           OR kgs <= 0
    ");


    $db->exec("
        DELETE FROM public.kagera_auction_catalogue
        WHERE lot_no IS NULL OR BTRIM(lot_no) = ''
           OR auction_no IS NULL OR BTRIM(auction_no) = ''
           OR auction_date IS NULL
    ");

    $db->exec("
        DELETE FROM public.kagera_auction_catalogue a
        USING public.kagera_auction_catalogue b
        WHERE a.id < b.id
          AND a.lot_no = b.lot_no
          AND a.auction_no = b.auction_no
          AND a.auction_date = b.auction_date
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_catalogue_auction_no
        ON public.kagera_auction_catalogue (auction_no)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_catalogue_lot_no
        ON public.kagera_auction_catalogue (lot_no)
    ");

    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_kagera_catalogue_lot_auction_date
        ON public.kagera_auction_catalogue (lot_no, auction_no, auction_date)
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND auction_date IS NOT NULL
    ");
}
