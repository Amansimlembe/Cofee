<?php

/* =========================================================
   KAGERA AUCTION - SELF CONTAINED
   Database: Render PostgreSQL through DATABASE_URL
   ========================================================= */

ob_start();

session_start();

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {

    $isApiRequest =
        isset($_GET['action']) ||
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    if ($isApiRequest) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Your session has expired. Please log in again.',
            'data' => []
        ]);

        exit;
    }

    header("Location: login.php");
    exit;
}

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

function ensure_kagera_table()
{
    $db = kagera_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS public.kagera_auction_results (
            id BIGSERIAL PRIMARY KEY,
            lot_no VARCHAR(100),
            auction_no VARCHAR(50),
            date_sold DATE,
            warehouse VARCHAR(255),
            warehouse_location VARCHAR(255),
            net_weight NUMERIC(15,2),
            grade VARCHAR(100),
            grade2 VARCHAR(100),
            price NUMERIC(15,4),
            buyer_name VARCHAR(255),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columns = [
        "lot_no" => "VARCHAR(100)",
        "auction_no" => "VARCHAR(50)",
        "date_sold" => "DATE",
        "warehouse" => "VARCHAR(255)",
        "warehouse_location" => "VARCHAR(255)",
        "net_weight" => "NUMERIC(15,2)",
        "grade" => "VARCHAR(100)",
        "grade2" => "VARCHAR(100)",
        "price" => "NUMERIC(15,4)",
        "buyer_name" => "VARCHAR(255)"
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
    | CONVERT OLD date_sold TEXT/VARCHAR TO DATE
    |--------------------------------------------------------------------------
    */
    $dateTypeStmt = $db->query("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'kagera_auction_results'
          AND column_name = 'date_sold'
    ");

    $dateType = $dateTypeStmt->fetchColumn();

    if ($dateType && strtolower($dateType) !== 'date') {
        $db->exec("
            ALTER TABLE public.kagera_auction_results
            ALTER COLUMN date_sold TYPE DATE
            USING CASE
                WHEN date_sold IS NULL
                  OR BTRIM(date_sold::text) = '' THEN NULL
                WHEN date_sold::text ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                  THEN date_sold::text::date
                WHEN date_sold::text ~ '^[0-9]{2}/[0-9]{2}/[0-9]{4}$'
                  THEN TO_DATE(date_sold::text, 'DD/MM/YYYY')
                WHEN date_sold::text ~ '^[0-9]{2}-[0-9]{2}-[0-9]{4}$'
                  THEN TO_DATE(date_sold::text, 'DD-MM-YYYY')
                WHEN date_sold::text ~ '^[0-9]{1,2} [A-Za-z]+,? [0-9]{4}$'
                  THEN TO_DATE(date_sold::text, 'DD Month YYYY')
                WHEN date_sold::text ~ '^[A-Za-z]+ [0-9]{1,2},? [0-9]{4}$'
                  THEN TO_DATE(date_sold::text, 'Month DD YYYY')
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
           OR date_sold IS NULL
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
          AND a.date_sold = b.date_sold
          AND a.lot_no IS NOT NULL
          AND a.auction_no IS NOT NULL
          AND a.date_sold IS NOT NULL
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
           OR date_sold IS NULL
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
        (lot_no, auction_no, date_sold)
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND date_sold IS NOT NULL
    ");
}


/*
|--------------------------------------------------------------------------
| KAGERA CATALOGUE TABLE
|--------------------------------------------------------------------------
| Catalogue upload uses the Auction Date from Excel as date_sold, while
| retaining the complete catalogue fields.
|--------------------------------------------------------------------------
*/
function ensure_kagera_catalogue_table()
{
    $db = kagera_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS public.kagera_auction_catalogue (
            id BIGSERIAL PRIMARY KEY,
            lot_no VARCHAR(100),
            auction_no VARCHAR(50),
            date_sold DATE,
            union_name VARCHAR(255),
            production_group VARCHAR(255),
            amcos VARCHAR(255),
            district VARCHAR(255),
            warehouse VARCHAR(255),
            bags NUMERIC(15,2),
            net_weight NUMERIC(15,2),
            grade VARCHAR(150),
            grade2 VARCHAR(150),
            certification VARCHAR(150),
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        DELETE FROM public.kagera_auction_catalogue
        WHERE lot_no IS NULL OR BTRIM(lot_no) = ''
           OR auction_no IS NULL OR BTRIM(auction_no) = ''
           OR date_sold IS NULL
    ");

    $db->exec("
        DELETE FROM public.kagera_auction_catalogue a
        USING public.kagera_auction_catalogue b
        WHERE a.id < b.id
          AND a.lot_no = b.lot_no
          AND a.auction_no = b.auction_no
          AND a.date_sold = b.date_sold
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
        ON public.kagera_auction_catalogue (lot_no, auction_no, date_sold)
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND date_sold IS NOT NULL
    ");
}

/*
|--------------------------------------------------------------------------
| CATALOGUE UPLOAD
|--------------------------------------------------------------------------
*/
function handle_kagera_catalogue_upload()
{
    if (!isset($_FILES["kagera_excel"])) {
        kagera_json(false, "Please select a Catalogue Excel file.", [], 400);
    }

    $file = $_FILES["kagera_excel"];

    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        kagera_json(false, "The Catalogue file could not be uploaded.", [], 400);
    }

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed = ["xlsx","xls","xlsm","xltx","xltm","xlsb","ods","csv","tsv","txt","xml","html","htm"];

    if (!in_array($ext, $allowed, true)) {
        kagera_json(false, "Unsupported Catalogue file format.", [], 400);
    }

    $rows = kagera_parse_excel($file["tmp_name"], $ext);

    if (!is_array($rows) || count($rows) < 2) {
        kagera_json(false, "The Catalogue Excel file does not contain enough data.", [], 400);
    }

    $headerIndex = null;
    for ($r = 0; $r < min(count($rows), 15); $r++) {
        $headers = array_map("kagera_norm", $rows[$r]);
        if (
            kagera_find_col($headers, ["lot_no","lot_number","lot"]) !== null &&
            kagera_find_col($headers, ["auction_no","auction_number","auction"]) !== null &&
            kagera_find_col($headers, ["auction_date","date","date_sold"]) !== null
        ) {
            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {
        kagera_json(false, "Catalogue header row could not be identified. Required fields are Lot No., Auction No. and Auction Date.", [], 400);
    }

    $headers = array_map("kagera_norm", $rows[$headerIndex]);

    $col = [
        "lot_no" => kagera_find_col($headers, ["lot_no","lot_number","lot"]),
        "auction_no" => kagera_find_col($headers, ["auction_no","auction_number","auction"]),
        "date_sold" => kagera_find_col($headers, ["auction_date","date_sold","sold_date","date"]),
        "union_name" => kagera_find_col($headers, ["union","union_name"]),
        "production_group" => kagera_find_col($headers, ["production_group","production"]),
        "amcos" => kagera_find_col($headers, ["amcos","amcos_name"]),
        "district" => kagera_find_col($headers, ["district"]),
        "warehouse" => kagera_find_col($headers, ["warehouse","warehouse_name"]),
        "bags" => kagera_find_col($headers, ["bags","bag","no_of_bags","number_of_bags"]),
        "net_weight" => kagera_find_col($headers, ["kgs","kg","net_weight","net_weight_kg"]),
        "grade" => kagera_find_col($headers, ["grade"]),
        "grade2" => kagera_find_col($headers, ["grade2","grade_2","grade_ii"]),
        "certification" => kagera_find_col($headers, ["certification","certificate"])
    ];

    $records = [];
    $rowErrors = [];

    foreach ($rows as $r => $row) {
        if ($r <= $headerIndex) continue;

        $get = function($key) use ($row,$col) {
            $i=$col[$key];
            return $i === null ? "" : trim((string)($row[$i] ?? ""));
        };

        $lot=$get("lot_no");
        $auction=$get("auction_no");
        $date=kagera_normalize_date($get("date_sold"));

        if ($lot === "" && $auction === "" && $date === null) continue;

        $missing=[];
        if ($lot==="") $missing[]="Lot No";
        if ($auction==="") $missing[]="Auction No";
        if ($date===null) $missing[]="Auction Date";

        if ($missing) {
            $rowErrors[]=["excel_row"=>$r+1,"missing_fields"=>$missing];
            continue;
        }

        $key=$lot."\x1F".$auction."\x1F".$date;
        $records[$key]=[
            "lot_no"=>$lot,
            "auction_no"=>$auction,
            "date_sold"=>$date,
            "union_name"=>$get("union_name"),
            "production_group"=>$get("production_group"),
            "amcos"=>$get("amcos"),
            "district"=>$get("district"),
            "warehouse"=>$get("warehouse"),
            "bags"=>kagera_number($get("bags")),
            "net_weight"=>kagera_number($get("net_weight")),
            "grade"=>$get("grade"),
            "grade2"=>$get("grade2"),
            "certification"=>$get("certification")
        ];
    }

    if ($rowErrors) {
        kagera_json(false, "Some Catalogue rows have blank or invalid required fields. No data was uploaded.", ["errors"=>$rowErrors], 400);
    }

    if (!$records) {
        kagera_json(false, "No valid Catalogue records were found in the Excel file.", [], 400);
    }

    ensure_kagera_catalogue_table();
    $db=kagera_db();

    $existing=[];
    $stmt=$db->query("SELECT id,lot_no,auction_no,date_sold FROM public.kagera_auction_catalogue WHERE lot_no IS NOT NULL AND auction_no IS NOT NULL AND date_sold IS NOT NULL");
    while($old=$stmt->fetch(PDO::FETCH_ASSOC)){
        $key=trim((string)$old["lot_no"])."\x1F".trim((string)$old["auction_no"])."\x1F".$old["date_sold"];
        $existing[$key]=$old;
    }

    $replacementKeys=array_values(array_intersect(array_keys($records),array_keys($existing)));
    $confirmed=isset($_POST["confirm_replace"]) && (string)$_POST["confirm_replace"]==="1";

    if ($replacementKeys && !$confirmed) {
        kagera_json(true,"Existing matching Catalogue records were found. Confirmation is required before replacement.",[
            "requires_confirmation"=>true,
            "existing_count"=>count($replacementKeys),
            "new_record_count"=>count($records)-count($replacementKeys),
            "replacement_records"=>array_map(function($key) use($records){
                return ["lot_no"=>$records[$key]["lot_no"],"auction_no"=>$records[$key]["auction_no"],"date_sold"=>$records[$key]["date_sold"]];
            },$replacementKeys)
        ]);
    }

    $db->beginTransaction();
    try {
        if($replacementKeys){
            $del=$db->prepare("DELETE FROM public.kagera_auction_catalogue WHERE lot_no=:lot_no AND auction_no=:auction_no AND date_sold=:date_sold");
            foreach($replacementKeys as $key){
                $old=$existing[$key];
                $del->execute(["lot_no"=>$old["lot_no"],"auction_no"=>$old["auction_no"],"date_sold"=>$old["date_sold"]]);
            }
        }

        $ins=$db->prepare("
            INSERT INTO public.kagera_auction_catalogue
            (lot_no,auction_no,date_sold,union_name,production_group,amcos,district,warehouse,bags,net_weight,grade,grade2,certification)
            VALUES
            (:lot_no,:auction_no,:date_sold,:union_name,:production_group,:amcos,:district,:warehouse,:bags,:net_weight,:grade,:grade2,:certification)
        ");
        foreach($records as $record) $ins->execute($record);

        $db->commit();
    } catch(Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        error_log("Kagera Catalogue upload database error: ".$e->getMessage());
        throw new Exception("The Catalogue records could not be saved to the database.");
    }

    $replaced=count($replacementKeys);
    $added=count($records)-$replaced;

    kagera_json(true,"Kagera Catalogue upload completed. {$added} new record(s) added and {$replaced} existing record(s) replaced.",[
        "requires_confirmation"=>false,
        "new_records"=>$added,
        "replaced_records"=>$replaced,
        "processed_records"=>count($records)
    ]);
}

/*
|--------------------------------------------------------------------------
| CATALOGUE FETCH
|--------------------------------------------------------------------------
*/
function handle_kagera_catalogue_fetch()
{
    ensure_kagera_catalogue_table();
    $db=kagera_db();

    $stmt=$db->query("
        SELECT lot_no,auction_no,date_sold,union_name,production_group,amcos,district,warehouse,bags,net_weight,grade,grade2,certification
        FROM public.kagera_auction_catalogue
        ORDER BY
            CASE WHEN auction_no ~ '^[0-9]+([.][0-9]+)?$' THEN auction_no::NUMERIC ELSE NULL END DESC NULLS LAST,
            CASE WHEN lot_no ~ '^[0-9]+([.][0-9]+)?$' THEN lot_no::NUMERIC ELSE NULL END ASC NULLS LAST,
            lot_no ASC
    ");
    kagera_json(true,"Kagera Catalogue loaded.",$stmt->fetchAll(),200);
}

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function kagera_json(
    $success,
    $message = '',
    $data = [],
    $statusCode = 200
) {

    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code($statusCode);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    $response = [
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => $data
    ];

    $json = json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {

        $json = json_encode([
            'success' => false,
            'message' => 'Unable to create server response.',
            'data' => []
        ]);
    }

    echo $json;

    exit;
}

/*
|--------------------------------------------------------------------------
| CLEAN CELL
|--------------------------------------------------------------------------
*/

function kagera_clean_cell($value)
{
    if ($value === null) {
        return '';
    }

    $value = (string)$value;

    $value = preg_replace(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
        '',
        $value
    );

    return trim($value);
}

/*
|--------------------------------------------------------------------------
| NUMBER
|--------------------------------------------------------------------------
*/

function kagera_number($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $value = str_replace(
        [',', ' '],
        '',
        $value
    );

    return is_numeric($value)
        ? (float)$value
        : null;
}

/*
|--------------------------------------------------------------------------
| HEADER NORMALIZATION
|--------------------------------------------------------------------------
*/

function kagera_norm($value)
{
    $value = strtolower(trim((string)$value));

    $value = preg_replace(
        '/[\r\n\t]+/',
        ' ',
        $value
    );

    $value = preg_replace(
        '/[^a-z0-9]+/',
        '_',
        $value
    );

    return trim($value, '_');
}

function kagera_find_col($headers, $names)
{
    foreach ($names as $name) {

        $wanted = kagera_norm($name);

        foreach ($headers as $i => $header) {

            if ($header === $wanted) {
                return $i;
            }
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| KAGERA DATE NORMALIZATION
|--------------------------------------------------------------------------
| Excel may store dates as serial numbers, formatted strings, or formula
| results. Convert all supported forms to a consistent YYYY-MM-DD value.
|--------------------------------------------------------------------------
*/
function kagera_normalize_date($value)
{
    if ($value === null) {
        return null;
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    // Excel serial date.
    if (is_numeric($value)) {
        $serial = (float)$value;

        if ($serial >= 1 && $serial <= 100000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date
                    ::excelToDateTimeObject($serial)
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }
    }

    // Normalize spaces and remove time.
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = preg_replace('/^(.+?)\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?$/i', '$1', $value);
    $value = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $value);
    $value = trim($value);

    // All common numeric and text-month formats.
    $formats = [
        'Y-m-d', 'Y/m/d', 'Y.m.d',
        'd/m/Y', 'd-m-Y', 'd.m.Y',
        'm/d/Y', 'm-d-Y', 'm.d.Y',
        'd/m/y', 'd-m-y', 'd.m.y',
        'm/d/y', 'm-d-y', 'm.d.y',
        'd F Y', 'd F, Y', 'd M Y', 'd M, Y',
        'F d Y', 'F d, Y', 'M d Y', 'M d, Y',
        'Y F d', 'Y F, d', 'Y M d', 'Y M, d'
    ];

    foreach ($formats as $format) {
        $date = \DateTime::createFromFormat('!' . $format, $value);
        $errors = \DateTime::getLastErrors();

        if ($date !== false &&
            ($errors === false ||
             ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }

    // General fallback.
    try {
        return (new \DateTime($value))->format('Y-m-d');
    } catch (\Throwable $e) {
        return null;
    }
}

/*
|--------------------------------------------------------------------------
| PHPSPREADSHEET XLSX READER
|--------------------------------------------------------------------------
*/


function kagera_parse_with_phpspreadsheet($file) {
    $autoloaders = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php'
    ];

    $loaded = false;

    foreach ($autoloaders as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                $loaded = true;
                break;
            }
        }
    }

    if (!$loaded) {
        return false;
    }

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($file);

        $sheetCount = $spreadsheet->getSheetCount();

        if ($sheetCount < 1) {
            return false;
        }

        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        if ($highestRow < 1 || $highestColumn === '') {
            return false;
        }

        /*
         * IMPORTANT:
         * PhpSpreadsheet 2.x does not provide
         * getCellByColumnAndRow().
         *
         * Use Coordinate::stringFromColumnIndex()
         * together with Worksheet::getCell().
         */

        $highestColumnIndex =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $highestColumn
            );

        $rows = [];

        for ($r = 1; $r <= $highestRow; $r++) {

            $row = [];

            for ($c = 1; $c <= $highestColumnIndex; $c++) {

                $columnLetter =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);

                $cellReference = $columnLetter . $r;

                $cell = $sheet->getCell($cellReference);

                $value = $cell->getValue();

                /*
                 * Handle Excel date/time cells.
                 */
                if (
                    $value !== null &&
                    $value !== '' &&
                    \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)
                ) {
                    $value = $cell->getFormattedValue();
                }

                /*
                 * Handle rich text cells.
                 */
                elseif (
                    $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText
                ) {
                    $value = $value->getPlainText();
                }

                /*
                 * Formula cells:
                 * If a formula is present, get the calculated value.
                 */
                elseif (
                    is_string($value) &&
                    strlen($value) > 0 &&
                    $value[0] === '='
                ) {
                    $calculated = $cell->getCalculatedValue();

                    if (
                        $calculated instanceof
                        \PhpOffice\PhpSpreadsheet\RichText\RichText
                    ) {
                        $calculated = $calculated->getPlainText();
                    }

                    $value = $calculated;
                }

                $row[] = kagera_clean_cell($value);
            }

            /*
             * Ignore completely empty rows.
             */
            if (
                count(
                    array_filter(
                        $row,
                        fn($v) => $v !== ''
                    )
                ) > 0
            ) {
                $rows[] = $row;
            }
        }

        return !empty($rows) ? $rows : false;

    } catch (Throwable $e) {

        /*
         * Log the real PhpSpreadsheet error so Render
         * logs remain useful for future troubleshooting.
         */
        error_log(
            'Kagera PhpSpreadsheet error: ' . $e->getMessage()
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| CSV / TSV / TXT
|--------------------------------------------------------------------------
*/

function kagera_parse_csv($file)
{
    $handle = fopen($file, 'rb');

    if (!$handle) {
        throw new Exception(
            'Unable to open the CSV/text file.'
        );
    }

    $first = fgets($handle);

    if ($first === false) {

        fclose($handle);

        throw new Exception(
            'The spreadsheet file is empty.'
        );
    }

    $first =
        preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $first
        );

    $delimiters = [
        ',',
        ';',
        "\t",
        '|'
    ];

    $bestDelimiter = ',';
    $bestCount = -1;

    foreach ($delimiters as $delimiter) {

        $count =
            substr_count(
                $first,
                $delimiter
            );

        if ($count > $bestCount) {

            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }

    rewind($handle);

    $rows = [];

    while (
        ($row = fgetcsv(
            $handle,
            0,
            $bestDelimiter
        )) !== false
    ) {

        $row =
            array_map(
                'kagera_clean_cell',
                $row
            );

        if (
            count(
                array_filter(
                    $row,
                    fn($v) => $v !== ''
                )
            ) > 0
        ) {

            $rows[] = $row;
        }
    }

    fclose($handle);

    return $rows;
}

/*
|--------------------------------------------------------------------------
| EXCEL PARSER
|--------------------------------------------------------------------------
*/

function kagera_parse_excel(
    $file,
    $extension
) {

    $extension =
        strtolower(
            ltrim(
                $extension,
                '.'
            )
        );

    if (
        in_array(
            $extension,
            [
                'csv',
                'tsv',
                'txt'
            ],
            true
        )
    ) {

        return kagera_parse_csv($file);
    }

    $rows =
        kagera_parse_with_phpspreadsheet(
            $file
        );

    if (
        $rows !== false &&
        !empty($rows)
    ) {

        return $rows;
    }

    throw new Exception(
        'The uploaded Excel file could not be read. ' .
        'Please save the file as .xlsx and upload it again.'
    );
}

/*
|--------------------------------------------------------------------------
| UPLOAD HANDLER
|--------------------------------------------------------------------------
*/

function handle_kagera_upload()
{
    if (!isset($_FILES["kagera_excel"]) || !is_array($_FILES["kagera_excel"])) {
        kagera_json(false, "Please select an Excel file.", [], 400);
    }

    $file = $_FILES["kagera_excel"];

    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errorCode = (int)($file["error"] ?? 0);

        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => "The uploaded file is too large.",
            UPLOAD_ERR_PARTIAL => "The file upload was incomplete.",
            UPLOAD_ERR_NO_FILE => "Please select an Excel file.",
            default => "File upload failed. Please try again."
        };

        kagera_json(false, $message, [], 400);
    }

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    $allowed = [
        "xlsx", "xls", "xlsm", "xltx", "xltm", "xlsb",
        "ods", "csv", "tsv", "txt", "xml", "html", "htm"
    ];

    if (!in_array($ext, $allowed, true)) {
        kagera_json(false, "Unsupported file format.", [], 400);
    }

    if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) {
        kagera_json(false, "The uploaded file could not be accessed.", [], 400);
    }

    $rows = kagera_parse_excel($file["tmp_name"], $ext);

    if (!is_array($rows) || count($rows) < 2) {
        kagera_json(false, "The Excel file does not contain enough data.", [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND HEADER ROW
    |--------------------------------------------------------------------------
    */
    $headerIndex = null;

    for ($r = 0; $r < min(count($rows), 15); $r++) {
        $headers = array_map("kagera_norm", $rows[$r]);

        $signals = [
            "lot_no", "lot_number", "lot",
            "auction_no", "auction_number", "auction",
            "auction_date", "date_sold", "sold_date",
            "date_of_sale", "date_sold_date", "date",
            "warehouse", "warehouse_name",
            "warehouse_location", "location",
            "net_weight", "net_weight_kg", "kgs", "kg",
            "grade", "grade2", "price", "buyer_name", "buyer"
        ];

        if (count(array_intersect($signals, $headers)) >= 2) {
            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {
        kagera_json(false, "The Excel file header row could not be identified.", [], 400);
    }

    $headers = array_map("kagera_norm", $rows[$headerIndex]);

    /*
    |--------------------------------------------------------------------------
    | COLUMN MAPPING
    |--------------------------------------------------------------------------
    | Auction Date from Excel is stored in date_sold.
    |--------------------------------------------------------------------------
    */
    $col = [
        "lot_no" => kagera_find_col(
            $headers,
            ["lot_no", "lot_number", "lot"]
        ),

        "auction_no" => kagera_find_col(
            $headers,
            ["auction_no", "auction_number", "auction"]
        ),

        "date_sold" => kagera_find_col(
            $headers,
            [
                "auction_date",
                "date_sold",
                "sold_date",
                "date_of_sale",
                "date_sold_date",
                "date"
            ]
        ),

        "warehouse" => kagera_find_col(
            $headers,
            ["warehouse", "warehouse_name"]
        ),

        "warehouse_location" => kagera_find_col(
            $headers,
            ["warehouse_location", "location", "warehouse_loc"]
        ),

        "net_weight" => kagera_find_col(
            $headers,
            ["net_weight", "net_weight_kg", "net_kg", "kgs", "kg"]
        ),

        "grade" => kagera_find_col(
            $headers,
            ["grade"]
        ),

        "grade2" => kagera_find_col(
            $headers,
            ["grade2", "grade_2", "grade_ii"]
        ),

        "price" => kagera_find_col(
            $headers,
            ["price", "price_usd", "price_tzs", "unit_price"]
        ),

        "buyer_name" => kagera_find_col(
            $headers,
            ["buyer_name", "buyer"]
        )
    ];

    if (
        $col["lot_no"] === null ||
        $col["auction_no"] === null ||
        $col["date_sold"] === null
    ) {
        kagera_json(
            false,
            "The Excel file must contain Lot No, Auction No. and Auction Date columns.",
            [],
            400
        );
    }

    ensure_kagera_table();
    $db = kagera_db();

    /*
    |--------------------------------------------------------------------------
    | READ AND DEDUPLICATE UPLOAD
    |--------------------------------------------------------------------------
    */
    $records = [];

    foreach ($rows as $r => $row) {
        if ($r <= $headerIndex) {
            continue;
        }

        $get = function ($key) use ($row, $col) {
            $i = $col[$key];

            if ($i === null) {
                return "";
            }

            return trim((string)($row[$i] ?? ""));
        };

        $lot = $get("lot_no");
        $auction = $get("auction_no");
        $date = kagera_normalize_date($get("date_sold"));

        $warehouse = $get("warehouse");
        $location = $get("warehouse_location");
        $weight = kagera_number($get("net_weight"));
        $grade = $get("grade");
        $grade2 = $get("grade2");
        $price = kagera_number($get("price"));
        $buyer = $get("buyer_name");

        if (
            $lot === "" &&
            $auction === "" &&
            $date === null &&
            $warehouse === "" &&
            $location === "" &&
            $grade === "" &&
            $grade2 === "" &&
            $buyer === ""
        ) {
            continue;
        }

        $missingFields = [];

        if ($lot === "") {
            $missingFields[] = "Lot No";
        }

        if ($auction === "") {
            $missingFields[] = "Auction No";
        }

        if ($date === null) {
            $missingFields[] = "Date Sold / Auction Date";
        }

        if (!empty($missingFields)) {
            kagera_json(
                false,
                "The following required field(s) are blank or invalid: " .
                implode(", ", $missingFields) .
                ". Please correct the Excel file and upload it again.",
                [
                    "excel_row" => $r + 1,
                    "missing_fields" => $missingFields
                ],
                400
            );
        }

        $key = $lot . "\x1F" . $auction . "\x1F" . $date;

        /*
        | If the same three-field key occurs more than once in the
        | uploaded Excel, keep the last occurrence.
        */
        $records[$key] = [
            "lot_no" => $lot,
            "auction_no" => $auction,
            "date_sold" => $date,
            "warehouse" => $warehouse,
            "warehouse_location" => $location,
            "net_weight" => $weight,
            "grade" => $grade,
            "grade2" => $grade2,
            "price" => $price,
            "buyer_name" => $buyer
        ];
    }

    if (empty($records)) {
        kagera_json(false, "No valid auction records were found in the Excel file.", [], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND EXISTING EXACT MATCHES
    |--------------------------------------------------------------------------
    */
    $existing = [];

    $existingStmt = $db->query("
        SELECT id, lot_no, auction_no, date_sold
        FROM public.kagera_auction_results
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND date_sold IS NOT NULL
    ");

    while ($old = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $key =
            trim((string)$old["lot_no"]) . "\x1F" .
            trim((string)$old["auction_no"]) . "\x1F" .
            $old["date_sold"];

        $existing[$key] = $old;
    }

    $replacementKeys = array_values(
        array_intersect(array_keys($records), array_keys($existing))
    );

    $confirmed =
        isset($_POST["confirm_replace"]) &&
        (string)$_POST["confirm_replace"] === "1";

    /*
    |--------------------------------------------------------------------------
    | PREVIEW: DO NOT CHANGE DATABASE UNTIL USER CONFIRMS
    |--------------------------------------------------------------------------
    */
    if (!empty($replacementKeys) && !$confirmed) {
        $preview = [];

        foreach ($replacementKeys as $key) {
            $preview[] = [
                "lot_no" => $records[$key]["lot_no"],
                "auction_no" => $records[$key]["auction_no"],
                "date_sold" => $records[$key]["date_sold"]
            ];
        }

        kagera_json(
            true,
            "Existing matching records were found. Confirmation is required before replacement.",
            [
                "requires_confirmation" => true,
                "existing_count" => count($replacementKeys),
                "new_record_count" => count($records) - count($replacementKeys),
                "replacement_records" => $preview
            ],
            200
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIRMED REPLACEMENT / INSERT
    |--------------------------------------------------------------------------
    */
    $db->beginTransaction();

    try {
        if (!empty($replacementKeys)) {
            $deleteStmt = $db->prepare("
                DELETE FROM public.kagera_auction_results
                WHERE lot_no = :lot_no
                  AND auction_no = :auction_no
                  AND date_sold = :date_sold
            ");

            foreach ($replacementKeys as $key) {
                $old = $existing[$key];

                $deleteStmt->execute([
                    "lot_no" => trim((string)$old["lot_no"]),
                    "auction_no" => trim((string)$old["auction_no"]),
                    "date_sold" => $old["date_sold"]
                ]);
            }
        }

        $insertStmt = $db->prepare("
            INSERT INTO public.kagera_auction_results
            (
                lot_no,
                auction_no,
                date_sold,
                warehouse,
                warehouse_location,
                net_weight,
                grade,
                grade2,
                price,
                buyer_name
            )
            VALUES
            (
                :lot_no,
                :auction_no,
                :date_sold,
                :warehouse,
                :warehouse_location,
                :net_weight,
                :grade,
                :grade2,
                :price,
                :buyer_name
            )
        ");

        foreach ($records as $record) {
            $insertStmt->execute($record);
        }

        /*
        | Final safety cleanup. Invalid key rows are never retained,
        | and duplicate complete keys are reduced to one row.
        */
        $db->exec("
            DELETE FROM public.kagera_auction_results
            WHERE lot_no IS NULL
               OR BTRIM(lot_no) = ''
               OR auction_no IS NULL
               OR BTRIM(auction_no) = ''
               OR date_sold IS NULL
        ");

        $db->exec("
            DELETE FROM public.kagera_auction_results a
            USING public.kagera_auction_results b
            WHERE a.id < b.id
              AND a.lot_no = b.lot_no
              AND a.auction_no = b.auction_no
              AND a.date_sold = b.date_sold
              AND a.lot_no IS NOT NULL
              AND a.auction_no IS NOT NULL
              AND a.date_sold IS NOT NULL
        ");

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log("Kagera upload database error: " . $e->getMessage());

        throw new Exception(
            "The auction records could not be saved to the database."
        );
    }

    $replaced = count($replacementKeys);
    $added = count($records) - $replaced;

    kagera_json(
        true,
        "Kagera Auction upload completed. {$added} new record(s) added and {$replaced} existing record(s) replaced.",
        [
            "requires_confirmation" => false,
            "new_records" => $added,
            "replaced_records" => $replaced,
            "processed_records" => count($records)
        ],
        200
    );
}

/*
|--------------------------------------------------------------------------
| FETCH HANDLER
|--------------------------------------------------------------------------
*/

function handle_kagera_fetch()
{
    ensure_kagera_table();

    $db = kagera_db();

    $stmt = $db->query("
        SELECT
            lot_no,
            auction_no,
            date_sold,
            warehouse,
            warehouse_location,
            net_weight,
            grade,
            grade2,
            price,
            buyer_name
        FROM public.kagera_auction_results
        ORDER BY
                CASE
                    WHEN auction_no ~ '^[0-9]+([.][0-9]+)?$'
                    THEN auction_no::NUMERIC
                    ELSE NULL
                END DESC NULLS LAST,
                CASE
                    WHEN lot_no ~ '^[0-9]+([.][0-9]+)?$'
                    THEN lot_no::NUMERIC
                    ELSE NULL
                END ASC NULLS LAST,
                lot_no ASC,
                id DESC
    ");

    $data = $stmt->fetchAll();

    kagera_json(
        true,
        "Kagera Auction results loaded.",
        $data,
        200
    );
}


/*
|--------------------------------------------------------------------------
| HIGH & LOW / SALES SUMMARY REPORT
|--------------------------------------------------------------------------
| Catalogue supplies Kgs Offered.
| Auction Results supplies Kgs Sold and Price.
|--------------------------------------------------------------------------
*/
function handle_kagera_report()
{
    ensure_kagera_table();
    ensure_kagera_catalogue_table();

    $db = kagera_db();

    $season = trim((string)($_GET['season'] ?? ''));
    $auction = trim((string)($_GET['auction_no'] ?? ''));

    $catalogueWhere = [];
    $resultWhere = [];
    $catalogueParams = [];
    $resultParams = [];

    if ($season !== '') {
        /*
         * Season rule: 01 June YYYY through 30 May YYYY+1.
         */
        if (preg_match('/^(\d{4})\/(\d{4})$/', $season, $m)) {
            $start = $m[1] . '-06-01';
            $end = $m[2] . '-05-30';

            $catalogueWhere[] = 'date_sold BETWEEN :c_start AND :c_end';
            $catalogueParams['c_start'] = $start;
            $catalogueParams['c_end'] = $end;

            $resultWhere[] = 'date_sold BETWEEN :r_start AND :r_end';
            $resultParams['r_start'] = $start;
            $resultParams['r_end'] = $end;
        }
    }

    if ($auction !== '') {
        $catalogueWhere[] = 'TRIM(auction_no) = :c_auction';
        $catalogueParams['c_auction'] = $auction;

        $resultWhere[] = 'TRIM(auction_no) = :r_auction';
        $resultParams['r_auction'] = $auction;
    }

    $catalogueSql = "
        SELECT grade2, COALESCE(SUM(net_weight),0) AS kilos_offered
        FROM public.kagera_auction_catalogue
        " . ($catalogueWhere ? 'WHERE ' . implode(' AND ', $catalogueWhere) : '') . "
        GROUP BY grade2
    ";

    $stmt = $db->prepare($catalogueSql);
    $stmt->execute($catalogueParams);
    $catalogueRows = $stmt->fetchAll();

    $resultSql = "
        SELECT
            grade2,
            COALESCE(SUM(net_weight),0) AS kilos_sold,
            COALESCE(SUM(net_weight * COALESCE(price,0)),0) AS total_value,
            MIN(CASE WHEN price > 0 AND net_weight > 0 THEN price END) AS lowest_price,
            CASE
                WHEN SUM(CASE WHEN price > 0 AND net_weight > 0 THEN net_weight ELSE 0 END) > 0
                THEN
                    SUM(
                        CASE
                            WHEN price > 0 AND net_weight > 0
                            THEN net_weight * price
                            ELSE 0
                        END
                    )
                    /
                    SUM(
                        CASE
                            WHEN price > 0 AND net_weight > 0
                            THEN net_weight
                            ELSE 0
                        END
                    )
                ELSE NULL
            END AS average_price,
            MAX(CASE WHEN price > 0 AND net_weight > 0 THEN price END) AS highest_price
        FROM public.kagera_auction_results
        " . ($resultWhere ? 'WHERE ' . implode(' AND ', $resultWhere) : '') . "
        GROUP BY grade2
    ";

    $stmt = $db->prepare($resultSql);
    $stmt->execute($resultParams);
    $resultRows = $stmt->fetchAll();

    /*
     * SALES SUMMARY:
     * Group by the actual Grade column. Catalogue provides Kilos Offered;
     * Auction Results provide Kilos Sold and Total Value.
     */
    $gradeCatalogueSql = "
        SELECT
            TRIM(CAST(grade AS TEXT)) AS grade,
            COALESCE(SUM(net_weight), 0) AS kilos_offered
        FROM public.kagera_auction_catalogue
        " . ($catalogueWhere ? 'WHERE ' . implode(' AND ', $catalogueWhere) : '') . "
        AND TRIM(CAST(grade AS TEXT)) <> ''
        GROUP BY TRIM(CAST(grade AS TEXT))
    ";

    $stmt = $db->prepare($gradeCatalogueSql);
    $stmt->execute($catalogueParams);
    $gradeCatalogueRows = $stmt->fetchAll();

    $gradeResultSql = "
        SELECT
            TRIM(CAST(grade AS TEXT)) AS grade,
            COALESCE(SUM(net_weight), 0) AS kilos_sold,
            COALESCE(SUM(net_weight * COALESCE(price, 0)), 0) AS total_value
        FROM public.kagera_auction_results
        " . ($resultWhere ? 'WHERE ' . implode(' AND ', $resultWhere) : '') . "
        AND TRIM(CAST(grade AS TEXT)) <> ''
        GROUP BY TRIM(CAST(grade AS TEXT))
    ";

    $stmt = $db->prepare($gradeResultSql);
    $stmt->execute($resultParams);
    $gradeResultRows = $stmt->fetchAll();

    $gradeGroups = [];
    $gradeKeys = [];

    /*
     * Normalize Type of Coffee / Grade labels to one canonical form.
     * Examples:
     *   Arabica Certified        -> Arabica_Certified
     *   Arabica Clean            -> Arabica_Clean
     *   Arabica Clean Certified  -> Arabica_Clean_Certified
     *   Robusta Certified        -> Robusta_Certified
     *   Robusta Clean            -> Robusta_Clean
     *   Robusta Clean Certified  -> Robusta_Clean_Certified
     *
     * Existing underscore values are preserved in the same canonical form.
     */
    $normalizeCoffeeType = function($value) {
        $label = trim((string)$value);
        if ($label === '') {
            return '';
        }

        // Convert any run of whitespace and underscores to one separator.
        $label = preg_replace('/[\\s_]+/', '_', $label);
        $label = trim($label, '_');

        // Normalize the known coffee-type components.
        $parts = array_filter(explode('_', $label), function($part) {
            return trim($part) !== '';
        });

        $normalizedParts = [];
        foreach ($parts as $part) {
            $p = strtolower(trim($part));

            if ($p === 'arabica') {
                $normalizedParts[] = 'Arabica';
            } elseif ($p === 'robusta') {
                $normalizedParts[] = 'Robusta';
            } elseif ($p === 'clean') {
                $normalizedParts[] = 'Clean';
            } elseif ($p === 'certified') {
                $normalizedParts[] = 'Certified';
            } else {
                // Preserve other Grade/Type values while cleaning spacing.
                $normalizedParts[] = ucfirst($p);
            }
        }

        return implode('_', $normalizedParts);
    };

    $ensureGrade = function($value) use (
        &$gradeGroups,
        &$gradeKeys,
        $normalizeCoffeeType
    ) {
        $label = $normalizeCoffeeType($value);
        if ($label === '') {
            return null;
        }

        // Case-insensitive canonical key prevents duplicate rows.
        $key = strtolower($label);

        if (!isset($gradeKeys[$key])) {
            $gradeKeys[$key] = $label;
            $gradeGroups[$label] = [
                'kilos_offered' => 0,
                'kilos_sold' => 0,
                'total_value' => 0,
                'percentage_sold' => 0
            ];
        }

        return $gradeKeys[$key];
    };
    foreach ($gradeCatalogueRows as $row) {
        $gradeLabel = $ensureGrade($row['grade'] ?? '');
        if ($gradeLabel !== null) {
            $gradeGroups[$gradeLabel]['kilos_offered'] +=
                (float)($row['kilos_offered'] ?? 0);
        }
    }

    foreach ($gradeResultRows as $row) {
        $gradeLabel = $ensureGrade($row['grade'] ?? '');
        if ($gradeLabel !== null) {
            $gradeGroups[$gradeLabel]['kilos_sold'] +=
                (float)($row['kilos_sold'] ?? 0);
            $gradeGroups[$gradeLabel]['total_value'] +=
                (float)($row['total_value'] ?? 0);
        }
    }

    foreach ($gradeGroups as &$gradeGroup) {
        $gradeGroup['percentage_sold'] =
            $gradeGroup['kilos_offered'] > 0
                ? ($gradeGroup['kilos_sold'] / $gradeGroup['kilos_offered']) * 100
                : 0;
    }
    unset($gradeGroup);

    /*
     * Auction date for Held On:
     * Always obtain it from Auction Results (date_sold) for the
     * exact selected Auction No. Never use the browser's current date
     * and never fall back to catalogue date.
     */
    $heldOn = null;

    if ($auction !== '') {
        $heldStmt = $db->prepare("
            SELECT date_sold
            FROM public.kagera_auction_results
            WHERE TRIM(CAST(auction_no AS TEXT)) = :auction
              AND date_sold IS NOT NULL
              AND TRIM(CAST(date_sold AS TEXT)) <> ''
            ORDER BY id ASC
            LIMIT 1
        ");

        $heldStmt->execute([
            'auction' => $auction
        ]);

        $heldOn = $heldStmt->fetchColumn() ?: null;
    }

    $groups = [
        'Dry Cherry Coffee' => [
            'kilos_offered' => 0,
            'kilos_sold' => 0,
            'total_value' => 0,
            'lowest_price' => null,
            'average_price' => null,
            'highest_price' => null
        ],
        'Clean Coffee' => [
            'kilos_offered' => 0,
            'kilos_sold' => 0,
            'total_value' => 0,
            'lowest_price' => null,
            'average_price' => null,
            'highest_price' => null
        ]
    ];

    $normaliseType = function($value) {
        $value = strtolower(trim((string)$value));

        if (strpos($value, 'dry cherry') !== false) {
            return 'Dry Cherry Coffee';
        }

        if (strpos($value, 'clean') !== false) {
            return 'Clean Coffee';
        }

        return null;
    };

    foreach ($catalogueRows as $row) {
        $type = $normaliseType($row['grade2'] ?? '');
        if ($type !== null) {
            $groups[$type]['kilos_offered'] += (float)($row['kilos_offered'] ?? 0);
        }
    }

    foreach ($resultRows as $row) {
        $type = $normaliseType($row['grade2'] ?? '');
        if ($type !== null) {
            $groups[$type]['kilos_sold'] = (float)($row['kilos_sold'] ?? 0);
            $groups[$type]['total_value'] = (float)($row['total_value'] ?? 0);
            $groups[$type]['lowest_price'] = $row['lowest_price'] !== null ? (float)$row['lowest_price'] : null;
            $groups[$type]['average_price'] = $row['average_price'] !== null ? (float)$row['average_price'] : null;
            $groups[$type]['highest_price'] = $row['highest_price'] !== null ? (float)$row['highest_price'] : null;
        }
    }

    foreach ($groups as $type => &$g) {
        $g['percentage_sold'] =
            $g['kilos_offered'] > 0
                ? ($g['kilos_sold'] / $g['kilos_offered']) * 100
                : 0;
    }
    unset($g);

    kagera_json(true, 'Kagera report generated.', [
        'season' => $season,
        'auction_no' => $auction,
        'held_on' => $heldOn,
        'groups' => $groups,
        'grade_groups' => $gradeGroups
    ], 200);
}

/*
|--------------------------------------------------------------------------
| REQUEST ROUTING
|--------------------------------------------------------------------------
*/

try {

    $kageraType = strtolower(trim((string)($_REQUEST['kagera_type'] ?? 'results')));

    if (
        ($_GET['action'] ?? '') === 'report'
    ) {
        handle_kagera_report();
    }

    if (
        ($_GET['action'] ?? '') === 'fetch'
    ) {
        if ($kageraType === 'catalogue') {
            handle_kagera_catalogue_fetch();
        }
        handle_kagera_fetch();
    }

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
        isset($_FILES['kagera_excel'])
    ) {
        if ($kageraType === 'catalogue') {
            handle_kagera_catalogue_upload();
        }
        handle_kagera_upload();
    }

} catch (Throwable $e) {

    error_log(
        "Kagera Auction error: " .
        $e->getMessage()
    );

    kagera_json(
        false,
        $e->getMessage(),
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| HTML PAGE
|--------------------------------------------------------------------------
*/

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Kagera Auction</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    font-family: Arial, Helvetica, sans-serif;
    background: #f6f4f2;
    color: #3e2723;
}

.kagera-main {
    min-height: 100vh;
    margin-left: 270px;
    padding: 32px;
    transition: margin-left .3s ease;
}

.kagera-container {
    width: 100%;
    max-width: 1600px;
    margin: 0 auto;
}

.kagera-header-upload-form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.kagera-header-upload-form .kagera-file-area {
    flex: 1 1 auto;
}

.kagera-header-upload-form .kagera-file-label {
    min-height: 38px;
    padding: 5px 7px 5px 9px;
    gap: 7px;
    border-radius: 6px;
}

.kagera-header-upload-form .kagera-file-icon {
    width: 25px;
    height: 25px;
    flex: 0 0 25px;
    font-size: 13px;
}

.kagera-header-upload-form .kagera-file-text strong {
    font-size: 11px;
}

.kagera-header-upload-form .kagera-file-text small {
    margin-top: 1px;
    font-size: 9px;
}

.kagera-header-upload-form .kagera-browse {
    padding: 5px 8px;
    font-size: 9px;
}

.kagera-header-upload-form .kagera-upload-btn {
    min-width: 82px;
    height: 38px;
    padding: 0 11px;
    border: 0;
    border-radius: 6px;
    font-size: 11px;
}

.kagera-header-upload .kagera-upload-status {
    margin-top: 7px;
    padding: 6px 8px;
    font-size: 10px;
}


.kagera-card {
    background: #fff;
    border: 1px solid #e7e0dc;
    border-radius: 12px;
    box-shadow: 0 3px 14px rgba(62,39,35,.055);
}

.kagera-upload-card {
    margin-bottom: 16px;
    padding: 16px;
}

.kagera-card-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
}

.kagera-card-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #f1ece9;
    font-size: 20px;
}

.kagera-card-heading h2,
.kagera-data-header h2 {
    margin: 0 0 5px;
    color: #3e2723;
    font-size: 17px;
}

.kagera-card-heading p,
.kagera-data-header p {
    margin: 0;
    color: #888;
    font-size: 12px;
}

.kagera-upload-row {
    display: flex;
    align-items: stretch;
    gap: 14px;
}

.kagera-file-area {
    flex: 1;
    min-width: 0;
}

.kagera-file-area input[type="file"] {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.kagera-file-label {
    min-height: 58px;
    width: 100%;
    padding: 8px 10px 8px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1px dashed #bcaea8;
    border-radius: 8px;
    background: #fbfaf9;
    cursor: pointer;
    transition: .2s ease;
}

.kagera-file-label:hover {
    border-color: #6d4c41;
    background: #f7f3f1;
}

.kagera-file-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    background: #eee8e5;
    font-size: 17px;
}

.kagera-file-text {
    min-width: 0;
    flex: 1;
}

.kagera-file-text strong,
.kagera-file-text small {
    display: block;
}

.kagera-file-text strong {
    color: #4e342e;
    font-size: 13px;
}

.kagera-file-text small {
    overflow: hidden;
    margin-top: 3px;
    color: #8d817b;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kagera-browse {
    flex: 0 0 auto;
    padding: 7px 11px;
    border: 1px solid #ddd4d0;
    border-radius: 6px;
    color: #5d4037;
    background: #fff;
    font-size: 11px;
    font-weight: 600;
}

.kagera-upload-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 145px;
}

.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: #6b625e;
}

.kagera-filter-select {
    width: 145px;
    height: 36px;
    padding: 0 30px 0 10px;
    border: 1px solid #ddd4d0;
    border-radius: 7px;
    background: #fff;
    color: #4e342e;
    font-size: 12px;
    font-weight: 600;
    outline: none;
    cursor: pointer;
}

.kagera-filter-select:focus {
    border-color: #8d6e63;
    box-shadow: 0 0 0 2px rgba(141, 110, 99, .12);
}

.kagera-filter-select:disabled {
    background: #f5f2f0;
    color: #aaa09b;
    cursor: not-allowed;
}

@media (max-width: 900px) {
    .kagera-data-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .kagera-filters {
        width: 100%;
        margin-left: 0;
        margin-right: 0;
    }
}

.kagera-refresh-btn {
    border: 0;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: .2s ease;
}

.kagera-upload-btn {
    min-width: 165px;
    padding: 0 20px;
    background: #4e342e;
    color: #fff;
}

.kagera-upload-btn:hover {
    background: #6d4c41;
    transform: translateY(-1px);
}

.kagera-upload-btn:disabled {
    opacity: .65;
    cursor: wait;
    transform: none;
}

.kagera-upload-btn span,
.kagera-refresh-btn span {
    margin-right: 5px;
}

.kagera-upload-status {
    display: none;
    margin-top: 13px;
    padding: 10px 13px;
    border-radius: 7px;
    font-size: 12px;
}

.kagera-upload-status.success {
    display: block;
    background: #eef6ef;
    color: #35613b;
}

.kagera-upload-status.error {
    display: block;
    background: #fbefef;
    color: #8a3f3f;
}

.kagera-data-card {
    overflow: hidden;
}

.kagera-data-header {
    min-height: 74px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    border-bottom: 1px solid #eee8e5;
}

.kagera-refresh-btn {
    padding: 9px 13px;
    background: #f1ece9;
    color: #4e342e;
}

.kagera-refresh-btn:hover {
    background: #e7dfdb;
}

.kagera-table-wrap {
    width: 100%;
    height: calc(100vh - 205px);
    min-height: 360px;
    max-height: calc(100vh - 205px);
    overflow: auto;
    position: relative;
    border-top: 1px solid #eee8e5;
}

/* Compact Kagera results toolbar */
.kagera-results-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 14px;
}

.kagera-results-heading {
    flex: 0 0 auto;
    min-width: 190px;
}

.kagera-results-heading h2 {
    margin: 0 0 4px;
    color: #3e2723;
    font-size: 17px;
    font-weight: 700;
}

.kagera-results-heading p {
    margin: 0;
    color: #888;
    font-size: 12px;
}

.kagera-results-actions {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    gap: 9px;
    min-width: 0;
    margin-left: auto;
}

.kagera-filters {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 125px;
}

.filter-group label {
    color: #6b625e;
    font-size: 10px;
    font-weight: 700;
}

.kagera-filter-select {
    width: 125px;
    height: 34px;
    padding: 0 28px 0 9px;
    border: 1px solid #ddd4d0;
    border-radius: 7px;
    background: #fff;
    color: #4e342e;
    font-size: 11px;
    font-weight: 600;
    outline: none;
    cursor: pointer;
}

.kagera-filter-select:focus {
    border-color: #8d6e63;
    box-shadow: 0 0 0 2px rgba(141, 110, 99, .12);
}

.kagera-filter-select:disabled {
    background: #f5f2f0;
    color: #aaa09b;
    cursor: not-allowed;
}

.kagera-upload-compact {
    position: relative;
    flex: 0 0 auto;
}

.kagera-header-upload-form {
    display: flex;
    align-items: center;
    gap: 6px;
}

.kagera-header-upload-form .kagera-file-area {
    width: 220px;
    flex: 0 0 220px;
}

.kagera-header-upload-form .kagera-file-label {
    min-height: 34px;
    height: 34px;
    padding: 4px 6px 4px 7px;
    gap: 5px;
    border-radius: 7px;
}

.kagera-header-upload-form .kagera-file-icon {
    width: 22px;
    height: 22px;
    flex: 0 0 22px;
    font-size: 12px;
}

.kagera-header-upload-form .kagera-file-text strong {
    font-size: 10px;
}

.kagera-header-upload-form .kagera-file-text small {
    font-size: 8px;
}

.kagera-header-upload-form .kagera-browse {
    padding: 4px 6px;
    font-size: 8px;
}

.kagera-header-upload-form .kagera-upload-btn,
.kagera-refresh-btn {
    height: 34px;
    min-width: 72px;
    padding: 0 9px;
    border-radius: 7px;
    white-space: nowrap;
    font-size: 10px;
}

.kagera-upload-compact .kagera-upload-status {
    position: absolute;
    right: 0;
    top: 40px;
    z-index: 20;
    min-width: 230px;
    max-width: 360px;
    margin: 0;
    padding: 7px 9px;
    font-size: 10px;
}

/* Excel-style fixed table header */
.kagera-results-table {
    width: 100%;
    min-width: 1300px;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12px;
}

.kagera-results-table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #4e342e;
    color: #fff;
    box-shadow: 0 1px 0 rgba(0,0,0,.15);
}

.kagera-results-table {
    width: 100%;
    min-width: 1300px;
    border-collapse: collapse;
    font-size: 12px;
}

.kagera-results-table th {
    padding: 12px 11px;
    background: #4e342e;
    color: #fff;
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.kagera-results-table td {
    padding: 11px;
    border-bottom: 1px solid #eee8e5;
    color: #4e342e;
    white-space: nowrap;
}

.kagera-results-table tbody tr:hover {
    background: #faf7f5;
}

.kagera-empty-state {
    padding: 34px !important;
    text-align: center;
    color: #91857f !important;
}

body.sidebar-collapsed .kagera-main {
    margin-left: 78px;
}

@media (max-width: 900px) {

    .kagera-main {
        padding: 24px;
    }

    .kagera-page-header {
        align-items: stretch;
        flex-direction: column;
    }

    .kagera-upload-row {
        flex-direction: column;
    }

    .kagera-upload-btn {
        min-height: 46px;
    }
}

@media (max-width: 700px) {

    .kagera-main,
    body.sidebar-collapsed .kagera-main {
        margin-left: 78px;
        padding: 18px;
    }

    .kagera-upload-card {
        padding: 18px;
    }

    .kagera-data-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .kagera-page-header h1 {
        font-size: 24px;
    }

    .kagera-status {
        align-self: flex-start;
    }
}

@media (max-width: 1250px) {
    .kagera-results-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .kagera-results-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
        margin-left: 0;
    }
}

@media (max-width: 700px) {
    .kagera-results-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .kagera-filters {
        flex-wrap: wrap;
    }

    .kagera-header-upload-form {
        flex-wrap: wrap;
    }

    .kagera-header-upload-form .kagera-file-area {
        width: min(100%, 300px);
        flex-basis: 300px;
    }

    .kagera-table-wrap {
        height: calc(100vh - 260px);
        max-height: calc(100vh - 260px);
        min-height: 300px;
    }
}



.kagera-display-type,
.kagera-upload-type {
    display:flex;
    flex-direction:column;
    gap:4px;
    flex:0 0 auto;
}
.kagera-display-type label,
.kagera-upload-type label {
    color:#6b625e;
    font-size:10px;
    font-weight:700;
}
.kagera-upload-type .kagera-filter-select {
    width:130px;
}
#kageraCatalogueTable {
    display:none;
}

/* Report tables */

/* High & Low report — workbook-matching structure */
.kagera-high-low-report {
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    background: #fff;
    padding: 12px 14px 16px;
    box-sizing: border-box;
}

.kagera-hl-title,
.kagera-hl-subtitle,
.kagera-hl-held {
    text-align: center;
    font-weight: 800;
    letter-spacing: .3px;
}

.kagera-hl-title {
    font-size: 17px;
    margin-bottom: 5px;
}

.kagera-hl-subtitle {
    font-size: 13px;
    margin-bottom: 5px;
}

.kagera-hl-held {
    font-size: 12px;
    margin-bottom: 12px;
}

.kagera-high-low-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}

.kagera-high-low-table th,
.kagera-high-low-table td {
    border: 1px solid #333;
    padding: 8px 10px;
    font-size: 11px;
    text-align: center;
    white-space: nowrap;
}

.kagera-high-low-table th {
    font-weight: 800;
}

.kagera-high-low-table td:first-child,
.kagera-high-low-table th:first-child {
    text-align: left;
}

.kagera-high-low-total-row td {
    font-weight: 800;
    border-top: 2px solid #333;
}

.kagera-prices-block {
    margin-top: 12px;
}

.kagera-hl-percentage {
    text-align: right;
    font-size: 11px;
    font-weight: 700;
    margin-top: 7px;
}

@media (max-width: 800px) {
    .kagera-high-low-report {
        min-width: 700px;
    }
}

.kagera-report-panel {
    margin-top: 10px;
    border: 1px solid #e7e0dc;
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}

.kagera-report-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    border-bottom: 1px solid #eee8e5;
}

.kagera-report-header h3 {
    margin: 0 0 3px;
    color: #3e2723;
    font-size: 15px;
}

.kagera-report-header p {
    margin: 0;
    color: #888;
    font-size: 11px;
}

.kagera-report-table-wrap {
    width: 100%;
    overflow: auto;
    max-height: 430px;
}

.kagera-report-table {
    width: 100%;
    min-width: 850px;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 11px;
}

.kagera-report-table th {
    position: sticky;
    top: 0;
    z-index: 5;
    padding: 9px 10px;
    background: #4e342e;
    color: #fff;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
    box-shadow: 0 1px 0 rgba(0,0,0,.15);
}

.kagera-report-table td {
    padding: 9px 10px;
    border-bottom: 1px solid #eee8e5;
    color: #4e342e;
    white-space: nowrap;
    background: #fff;
}

.kagera-report-table tbody tr:hover td {
    background: #faf7f5;
}

.kagera-report-table td:first-child {
    font-weight: 700;
}

@media (max-width: 1250px) {
    .kagera-report-type {
        order: 4;
    }
}

</style>

</head>

<body>

<main class="kagera-main">

<div class="kagera-container">







<section class="kagera-card kagera-data-card">

<div class="kagera-data-header kagera-results-header">

    <div class="kagera-results-heading">
        <h2 id="kageraSectionTitle">Kagera Auction Results</h2>
        <p id="kageraSectionSubtitle">Results currently stored in the database.</p>
    </div>

    <div class="kagera-display-type">
        <label for="kageraDisplayType">Display</label>
        <select id="kageraDisplayType" class="kagera-filter-select">
            <option value="results">Auction Results</option>
            <option value="catalogue">Auction Catalogue</option>
                <option value="high_low">High &amp; Low</option>
                <option value="sales_summary">Sales Summary</option>
            </select>
    </div>

    <div class="kagera-results-actions">

        <div class="kagera-filters">

            <div class="filter-group">
                <label for="kageraSeasonFilter">Season</label>
                <select id="kageraSeasonFilter" class="kagera-filter-select">
                    <option value="">Select Season</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="kageraAuctionFilter">Auction No.</label>
                <select id="kageraAuctionFilter" class="kagera-filter-select" disabled>
                    <option value="">Select Auction</option>
                </select>
            </div>

        </div>

        <div class="kagera-upload-compact">
            <input type="hidden" id="kageraUploadType" name="upload_type" value="auction_results">

                <form
                id="kageraUploadForm"
                action="kagera_auction.php"
                method="POST"
                enctype="multipart/form-data"
                class="kagera-header-upload-form"
            >
                

                <div class="kagera-file-area">
                    <input
                        type="file"
                        id="kageraExcelFile"
                        name="kagera_excel"
                        accept=".xlsx,.xls,.xlsm,.xltx,.xltm,.xlsb,.ods,.csv,.tsv,.txt,.xml,.html,.htm"
                        required
                    >

                    <label for="kageraExcelFile" class="kagera-file-label">
                        <span class="kagera-file-icon">📁</span>
                        <span class="kagera-file-text">
                            <strong>Select Excel File</strong>
                            <small id="kageraFileName">Choose file</small>
                        </span>
                        <span class="kagera-browse">Browse</span>
                    </label>
                </div>

                <button
                    type="submit"
                    class="kagera-upload-btn"
                    id="kageraUploadButton"
                >
                    <span>↑</span> Upload
                </button>
            </form>

            <div id="kageraUploadStatus" class="kagera-upload-status"></div>
        </div>

        <button
            type="button"
            class="kagera-refresh-btn"
            onclick="loadKageraResults()"
        >
            <span>↻</span> Refresh
        </button>

    </div>

</div>

<div class="kagera-table-wrap">

<table id="kageraResultsTable" class="kagera-results-table">
<thead><tr>
<th>Lot No.</th><th>Auction No.</th><th>Date Sold</th><th>Warehouse</th>
<th>Warehouse Location</th><th>Net Weight (Kg)</th><th>Grade</th>
<th>Grade2</th><th>Price</th><th>Buyer Name</th>
</tr></thead>
<tbody id="kageraResultsBody">
<tr><td colspan="10" class="kagera-empty-state">No Kagera Auction results loaded.</td></tr>
</tbody>
</table>

<table id="kageraCatalogueTable" class="kagera-results-table" style="display:none;">
<thead><tr>
<th>Lot No.</th><th>Auction No.</th><th>Auction Date</th><th>Union</th>
<th>Production Group</th><th>AMCOS</th><th>District</th><th>Warehouse</th>
<th>Bags</th><th>Kgs</th><th>Grade</th><th>Grade2</th><th>Certification</th>
</tr></thead>
<tbody id="kageraCatalogueBody">
<tr><td colspan="13" class="kagera-empty-state">No Kagera Catalogue loaded.</td></tr>
</tbody>
</table>

<div id="kageraReportPanel" class="kagera-report-panel" style="display:none;">

    <div class="kagera-report-header" id="kageraGenericReportHeader">
        <div>
            <h3 id="kageraReportTitle">High &amp; Low</h3>
            <p id="kageraReportSubtitle">Kagera Coffee Exchange auction report.</p>
        </div>
    </div>

    <div class="kagera-report-table-wrap">

        <div id="kageraHighLowReport" class="kagera-high-low-report">

    <div class="kagera-hl-title">KAGERA COFFEE EXCHANGE</div>
    <div class="kagera-hl-subtitle" id="kageraHLSubtitle">SALES SUMMARY FOR THE AUCTION NO. —</div>
    <div class="kagera-hl-held" id="kageraHLHeldOn">Held On —</div>

    <table class="kagera-high-low-table kagera-summary-block">
        <thead>
            <tr>
                <th>GRADE</th>
                <th>KILOS OFFERED</th>
                <th>KILOS SOLD</th>
                <th>TOTAL VALUE (TZS)</th>
            </tr>
        </thead>
        <tbody id="kageraHighLowSalesBody">
            <tr>
                <td>Dry Cherry Coffee</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
            </tr>
            <tr>
                <td>Clean Coffee</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
            </tr>
            <tr class="kagera-high-low-total-row">
                <td>Total</td>
                <td id="kageraHLTotalOffered">0</td>
                <td id="kageraHLTotalSold">0</td>
                <td id="kageraHLTotalValue">0.00</td>
            </tr>
        </tbody>
    </table>

    <table class="kagera-high-low-table kagera-prices-block">
        <thead>
            <tr>
                <th>PRICES</th>
                <th>LOWEST PRICE PER KG</th>
                <th>AVERAGE PRICE PER KG</th>
                <th>HIGHEST PRICE PER KG</th>
            </tr>
        </thead>
        <tbody id="kageraHighLowPricesBody">
            <tr>
                <td>Dry Cherry Coffee</td>
                <td>0</td>
                <td>-</td>
                <td>0</td>
            </tr>
            <tr>
                <td>Clean Coffee</td>
                <td>0</td>
                <td>-</td>
                <td>0</td>
            </tr>
        </tbody>
    </table>

    <div class="kagera-hl-percentage" id="kageraHLPercentageDry">
        PERCENTAGE SOLD (Dry)= 0.00%
    </div>
    <div class="kagera-hl-percentage" id="kageraHLPercentageClean">
        PERCENTAGE SOLD (Clean)= 0.00%
    </div>

</div>

        <table id="kageraSalesSummaryTable" class="kagera-report-table" style="display:none;">
            <thead>
                <tr>
                    <th>TYPE OF COFFEE</th>
                    <th>KILOS OFFERED</th>
                    <th>KILOS SOLD</th>
                    <th>TOTAL VALUE (TZS)</th>
                    <th>PERCENTAGE SOLD</th>
                </tr>
            </thead>
            <tbody id="kageraSalesSummaryBody">
                <tr>
                    <td colspan="5" class="kagera-empty-state">
                        Select an Auction and a Report.
                    </td>
                </tr>
            </tbody>
        </table>

    </div>
</div>


</div>

</section>

</div>

</main>


<script>

const kageraExcelFile =
    document.getElementById("kageraExcelFile");

const kageraFileName =
    document.getElementById("kageraFileName");

const kageraUploadForm =
    document.getElementById("kageraUploadForm");

const kageraUploadStatus =
    document.getElementById("kageraUploadStatus");
const kageraDisplayType =
    document.getElementById("kageraDisplayType");

const kageraUploadType =
    document.getElementById("kageraUploadType");

let kageraCatalogueData = [];



/*
|--------------------------------------------------------------------------
| FILE NAME
|--------------------------------------------------------------------------
*/

if (kageraExcelFile) {

    kageraExcelFile.addEventListener(
        "change",
        function () {

            kageraFileName.textContent =
                this.files.length
                    ? this.files[0].name
                    : "Supported formats: .xlsx, .xls, .xlsm, .xltx, .xltm, .xlsb, .ods, .csv, .tsv, .txt, .xml, .html";

        }
    );
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

function showKageraStatus(
    message,
    type
) {

    if (!kageraUploadStatus) {
        return;
    }

    kageraUploadStatus.textContent =
        message;

    kageraUploadStatus.className =
        "kagera-upload-status " + type;
}


/*
|--------------------------------------------------------------------------
| JSON RESPONSE READER
|--------------------------------------------------------------------------
*/

async function readKageraJson(response)
{
    const text =
        await response.text();

    let result;

    try {

        result =
            JSON.parse(text);

    } catch (e) {

        console.error(
            "Kagera server response:",
            text
        );

        throw new Error(
            "The server did not return a valid JSON response. Check the Render logs for the exact PHP error."
        );
    }

    if (
        !response.ok ||
        !result.success
    ) {

        throw new Error(
            result.message ||
            "Request failed."
        );
    }

    return result;
}


/*
|--------------------------------------------------------------------------
| UPLOAD
|--------------------------------------------------------------------------
*/

if (kageraUploadForm) {
    kageraUploadForm.addEventListener(
        "submit",
        async function (event) {
            event.preventDefault();

            if (!kageraExcelFile.files.length) {
                showKageraStatus(
                    "Please select an Excel file first.",
                    "error"
                );
                return;
            }

            const button =
                document.getElementById("kageraUploadButton");

            async function submitUpload(confirmReplace) {
                const formData =
                    new FormData(kageraUploadForm);

                formData.set(
                    "kagera_type",
                    kageraUploadType
                        ? kageraUploadType.value
                        : "results"
                );

                if (confirmReplace) {
                    formData.set("confirm_replace", "1");
                } else {
                    formData.delete("confirm_replace");
                }

                const response =
                    await fetch(
                        "kagera_auction.php",
                        {
                            method: "POST",
                            body: formData,
                            cache: "no-store",
                            credentials: "same-origin"
                        }
                    );

                return await readKageraJson(response);
            }

            button.disabled = true;
            button.innerHTML =
                "<span>⏳</span> Checking...";

            showKageraStatus(
                "Checking Lot No, Auction No. and Auction Date...",
                "success"
            );

            try {
                const firstResult =
                    await submitUpload(false);

                const info =
                    firstResult.data || {};

                if (
                    info.requires_confirmation === true
                ) {
                    const count =
                        Number(info.existing_count || 0);

                    const confirmed =
                        window.confirm(
                            count +
                            " matching record(s) already exist in the database.\n\n" +
                            "The match is based on:\n" +
                            "Lot No + Auction No. + Date Sold.\n\n" +
                            "Do you want to REPLACE the existing record(s) with the new Excel data?\n\n" +
                            "OK = Replace existing records\n" +
                            "Cancel = Keep the existing records"
                        );

                    if (!confirmed) {
                        showKageraStatus(
                            "Upload cancelled. No existing records were changed.",
                            "success"
                        );
                        return;
                    }

                    button.innerHTML =
                        "<span>⏳</span> Replacing...";

                    const finalResult =
                        await submitUpload(true);

                    showKageraStatus(
                        finalResult.message ||
                        "Kagera Auction results uploaded successfully.",
                        "success"
                    );

                } else {
                    showKageraStatus(
                        firstResult.message ||
                        "Kagera Auction results uploaded successfully.",
                        "success"
                    );
                }

                kageraUploadForm.reset();

                kageraFileName.textContent =
                    "Supported formats: .xlsx, .xls, .xlsm, .xltx, .xltm, .xlsb, .ods, .csv, .tsv, .txt, .xml, .html";

                await loadKageraResults();

            } catch (error) {
                console.error(
                    "Kagera upload error:",
                    error
                );

                showKageraStatus(
                    error.message ||
                    "Unable to upload the Excel file.",
                    "error"
                );

            } finally {
                button.disabled = false;
                button.innerHTML =
                    "<span>↑</span> Upload Results";
            }
        }
    );
}


/*
|--------------------------------------------------------------------------
| FETCH RESULTS
|--------------------------------------------------------------------------
*/

function kageraGetSeason(dateValue)
{
    if (!dateValue) {
        return null;
    }

    const value = String(dateValue).trim();
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);

    /*
    |--------------------------------------------------------------------------
    | KAGERA SEASON
    |--------------------------------------------------------------------------
    | 01 June YYYY to 30 May YYYY+1.
    | Example: 2026/2027 = 01 June 2026 to 30 May 2027.
    |--------------------------------------------------------------------------
    */
    return month >= 6
        ? year + "/" + (year + 1)
        : (year - 1) + "/" + year;
}

function kageraAuctionNumber(value)
{
    const number = Number(
        String(value ?? "")
            .replace(/,/g, "")
            .trim()
    );

    return Number.isFinite(number)
        ? number
        : null;
}

function kageraSortResults(rows)
{
    return rows.slice().sort(function (a, b) {

        const auctionA =
            kageraAuctionNumber(a.auction_no);

        const auctionB =
            kageraAuctionNumber(b.auction_no);

        /*
        | Auction No: largest -> smallest
        */
        if (
            auctionA !== null &&
            auctionB !== null &&
            auctionA !== auctionB
        ) {
            return auctionB - auctionA;
        }

        if (auctionA !== null && auctionB === null) {
            return -1;
        }

        if (auctionA === null && auctionB !== null) {
            return 1;
        }

        /*
        | Same Auction: Lot No smallest -> largest
        */
        const lotA =
            kageraAuctionNumber(a.lot_no);

        const lotB =
            kageraAuctionNumber(b.lot_no);

        if (
            lotA !== null &&
            lotB !== null &&
            lotA !== lotB
        ) {
            return lotA - lotB;
        }

        if (lotA !== null && lotB === null) {
            return -1;
        }

        if (lotA === null && lotB !== null) {
            return 1;
        }

        return String(a.lot_no ?? "").localeCompare(
            String(b.lot_no ?? ""),
            undefined,
            {
                numeric: true,
                sensitivity: "base"
            }
        );
    });
}

let kageraAllResults = [];
let kageraResultsLoaded = false;

function kageraPopulateSeasonFilterFromRows(rows)
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    if (!seasonSelect) return;

    const seasons = new Set();

    (rows || []).forEach(function(row) {
        const season = kageraGetSeason(row.date_sold);
        if (season) seasons.add(String(season));
    });

    const sorted = Array.from(seasons).sort(function(a, b) {
        const yearA = Number(String(a).split("/")[0]);
        const yearB = Number(String(b).split("/")[0]);
        return yearB - yearA;
    });

    seasonSelect.innerHTML = '<option value="">Select Season</option>';

    sorted.forEach(function(season) {
        const option = document.createElement("option");
        option.value = season;
        option.textContent = season;
        seasonSelect.appendChild(option);
    });
}

function kageraPopulateSeasonFilter()
{
    kageraPopulateSeasonFilterFromRows(kageraAllResults);
}

function kageraPopulateAuctionFilterFromRows(rows)
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    const auctionSelect = document.getElementById("kageraAuctionFilter");

    if (!seasonSelect || !auctionSelect) return;

    const selectedSeason = seasonSelect.value;

    auctionSelect.innerHTML =
        '<option value="">Select Auction</option>';

    if (!selectedSeason) {
        auctionSelect.disabled = true;
        return;
    }

    const auctions = new Set();

    (rows || []).forEach(function(row) {
        if (kageraGetSeason(row.date_sold) !== selectedSeason) return;

        const auction = String(row.auction_no ?? "").trim();
        if (auction) auctions.add(auction);
    });

    const sorted = Array.from(auctions).sort(function(a, b) {
        const numberA = kageraAuctionNumber(a);
        const numberB = kageraAuctionNumber(b);

        if (numberA !== null && numberB !== null) {
            return numberB - numberA;
        }

        if (numberA !== null) return -1;
        if (numberB !== null) return 1;

        return String(b).localeCompare(String(a), undefined, {
            numeric: true,
            sensitivity: "base"
        });
    });

    sorted.forEach(function(auction) {
        const option = document.createElement("option");
        option.value = auction;
        option.textContent = "Auction " + auction;
        auctionSelect.appendChild(option);
    });

    auctionSelect.disabled = sorted.length === 0;
}

function kageraPopulateAuctionFilter()
{
    kageraPopulateAuctionFilterFromRows(kageraAllResults);
}

function kageraGetFilteredResults()
{
    const seasonSelect =
        document.getElementById("kageraSeasonFilter");

    const auctionSelect =
        document.getElementById("kageraAuctionFilter");

    const selectedSeason =
        seasonSelect
            ? seasonSelect.value
            : "";

    const selectedAuction =
        auctionSelect
            ? auctionSelect.value
            : "";

    return kageraAllResults.filter(function (row) {

        const rowSeason =
            kageraGetSeason(row.date_sold);

        const rowAuction =
            String(row.auction_no ?? "").trim();

        const seasonMatches =
            !selectedSeason ||
            rowSeason === selectedSeason;

        const auctionMatches =
            !selectedAuction ||
            rowAuction === selectedAuction;

        return seasonMatches && auctionMatches;
    });
}

function kageraRenderResults(rows)
{
    const body =
        document.getElementById(
            "kageraResultsBody"
        );

    if (!body) {
        return;
    }

    const sortedRows =
        kageraSortResults(rows);

    if (!sortedRows.length) {

        body.innerHTML =
            '<tr>' +
            '<td colspan="10" class="kagera-empty-state">' +
            'No Kagera Auction results found for the selected filters.' +
            '</td>' +
            '</tr>';

        return;
    }

    body.innerHTML =
        sortedRows
            .map(function (row) {

                return (
                    "<tr>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.lot_no ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.auction_no ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        formatKageraDate(
                            row.date_sold ?? ""
                        )
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.warehouse ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.warehouse_location ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.net_weight ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.grade ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.grade2 ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.price ?? ""
                    ) +
                    "</td>" +

                    "<td>" +
                    escapeKageraHtml(
                        row.buyer_name ?? ""
                    ) +
                    "</td>" +

                    "</tr>"
                );
            })
            .join("");
}


function kageraGetFilteredCatalogue()
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    const auctionSelect = document.getElementById("kageraAuctionFilter");

    const selectedSeason = seasonSelect ? seasonSelect.value : "";
    const selectedAuction = auctionSelect ? auctionSelect.value : "";

    return kageraCatalogueData.filter(function(row) {
        const rowSeason = kageraGetSeason(row.date_sold);
        const rowAuction = String(row.auction_no ?? "").trim();

        return (!selectedSeason || rowSeason === selectedSeason) &&
               (!selectedAuction || rowAuction === selectedAuction);
    });
}

function kageraRenderCatalogue(rows)
{
    const body=document.getElementById("kageraCatalogueBody");
    if(!body) return;

    const sorted=rows.slice().sort(function(a,b){
        const aa=kageraAuctionNumber(a.auction_no), ab=kageraAuctionNumber(b.auction_no);
        if(aa!==null && ab!==null && aa!==ab) return ab-aa;
        const la=kageraAuctionNumber(a.lot_no), lb=kageraAuctionNumber(b.lot_no);
        if(la!==null && lb!==null && la!==lb) return la-lb;
        return String(a.lot_no??"").localeCompare(String(b.lot_no??""),undefined,{numeric:true,sensitivity:"base"});
    });

    if(!sorted.length){
        body.innerHTML='<tr><td colspan="13" class="kagera-empty-state">No Kagera Catalogue records found for the selected filters.</td></tr>';
        return;
    }

    body.innerHTML=sorted.map(function(row){
        return "<tr>"+
            "<td>"+escapeKageraHtml(row.lot_no??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.auction_no??"")+"</td>"+
            "<td>"+escapeKageraHtml(formatKageraDate(row.date_sold??""))+"</td>"+
            "<td>"+escapeKageraHtml(row.union_name??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.production_group??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.amcos??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.district??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.warehouse??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.bags??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.net_weight??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.grade??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.grade2??"")+"</td>"+
            "<td>"+escapeKageraHtml(row.certification??"")+"</td>"+
            "</tr>";
    }).join("");
}

async function loadKageraData(type)
{
    const isCatalogue=type==="catalogue";
    const body=document.getElementById(isCatalogue ? "kageraCatalogueBody" : "kageraResultsBody");
    if(!body) return;

    try{
        const response=await fetch(
            "kagera_auction.php?action=fetch&kagera_type="+encodeURIComponent(type),
            {cache:"no-store",credentials:"same-origin"}
        );
        const result=await readKageraJson(response);

        if(isCatalogue){
            kageraCatalogueData=Array.isArray(result.data)?result.data:[];
            kageraPopulateSeasonFilterFromRows(kageraCatalogueData);
            const auctionSelect=document.getElementById("kageraAuctionFilter");
            if(auctionSelect) auctionSelect.value="";
            kageraPopulateAuctionFilterFromRows(kageraCatalogueData);
            kageraRenderCatalogue(kageraGetFilteredCatalogue());
        }else{
            kageraAllResults=Array.isArray(result.data)?result.data:[];
            kageraResultsLoaded=true;
            kageraPopulateSeasonFilterFromRows(kageraAllResults);
            const auctionSelect=document.getElementById("kageraAuctionFilter");
            if(auctionSelect) auctionSelect.value="";
            kageraPopulateAuctionFilterFromRows(kageraAllResults);
            kageraRenderResults(kageraGetFilteredResults());
        }
    }catch(error){
        body.innerHTML='<tr><td colspan="'+(isCatalogue?13:10)+'" class="kagera-empty-state">'+
            escapeKageraHtml(error.message||"Unable to load records.")+"</td></tr>";
    }
}


/*
|--------------------------------------------------------------------------
| KAGERA REPORTS
|--------------------------------------------------------------------------
| High & Low:
|   - Offered kilos come from Catalogue Kgs
|   - Kilos Sold = Auction Results Net Weight (Kg)
|   - Prices = Auction Results Price
|   - Total Value = Kilos Sold x Price
|   - Average Price = weighted average: SUM(Kg x Price) / SUM(Kg)
|
| The report always combines the selected Auction + Season from the
| Catalogue and Auction Results tables. It does NOT use Catalogue prices.
|--------------------------------------------------------------------------
*/

const kageraReportType =
    document.getElementById("kageraDisplayType");

function kageraNumber(value)
{
    if (value === null || value === undefined || value === "") {
        return 0;
    }

    let text = String(value).trim();

    // Remove common currency text and thousands separators while preserving
    // the decimal point and negative sign.
    text = text
        .replace(/TZS/gi, "")
        .replace(/USD/gi, "")
        .replace(/\s+/g, "")
        .replace(/,/g, "");

    const match = text.match(/-?\d+(?:\.\d+)?/);
    if (!match) {
        return 0;
    }

    const n = Number(match[0]);
    return Number.isFinite(n) ? n : 0;
}

function kageraReportMoney(value)
{
    const n = kageraNumber(value);
    return n.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function kageraReportKg(value)
{
    const n = kageraNumber(value);
    return n.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function kageraCoffeeType(row)
{
    const value = String(row?.grade2 ?? "").trim().toLowerCase();

    if (value.includes("dry cherry")) {
        return "Dry Cherry Coffee";
    }

    if (value.includes("clean")) {
        return "Clean Coffee";
    }

    return "";
}

function kageraGetSelectedReportRows()
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    const auctionSelect = document.getElementById("kageraAuctionFilter");

    const season = seasonSelect ? String(seasonSelect.value || "").trim() : "";
    const auction = auctionSelect ? String(auctionSelect.value || "").trim() : "";

    const catalogue = kageraCatalogueData.filter(function(row) {
        const rowAuction = String(row.auction_no ?? "").trim();
        const rowSeason = kageraGetSeason(row.date_sold);

        return (!season || rowSeason === season) &&
               (!auction || rowAuction === auction);
    });

    const results = kageraAllResults.filter(function(row) {
        const rowAuction = String(row.auction_no ?? "").trim();
        const rowSeason = kageraGetSeason(row.date_sold);

        return (!season || rowSeason === season) &&
               (!auction || rowAuction === auction);
    });

    return { catalogue, results, season, auction };
}

function kageraBuildReport()
{
    const selected = kageraGetSelectedReportRows();

    const groups = {
        "Dry Cherry Coffee": {
            offered: 0,
            sold: 0,
            value: 0,
            prices: []
        },
        "Clean Coffee": {
            offered: 0,
            sold: 0,
            value: 0,
            prices: []
        }
    };

    /*
     * IMPORTANT:
     * Offered Kgs come ONLY from the Catalogue.
     */
    selected.catalogue.forEach(function(row) {
        const type = kageraCoffeeType(row);
        if (!groups[type]) return;

        groups[type].offered += kageraNumber(row.net_weight);
    });

    /*
     * Sold Kgs and prices come ONLY from Auction Results.
     * We calculate value as sold kg x price because the Kagera results
     * table stores Net Weight and Price.
     */
    selected.results.forEach(function(row) {
        const type = kageraCoffeeType(row);
        if (!groups[type]) return;

        const kg = kageraNumber(row.net_weight);
        const price = kageraNumber(row.price);

        if (kg > 0) {
            groups[type].sold += kg;
            groups[type].value += kg * price;

            if (price > 0) {
                groups[type].prices.push({
                    kg: kg,
                    price: price
                });
            }
        }
    });

    Object.keys(groups).forEach(function(type) {
        const g = groups[type];

        if (g.prices.length) {
            g.low = Math.min.apply(null, g.prices.map(x => x.price));
            g.high = Math.max.apply(null, g.prices.map(x => x.price));

            const totalKgForPrice =
                g.prices.reduce((sum, x) => sum + x.kg, 0);

            const weightedValue =
                g.prices.reduce(
                    (sum, x) => sum + (x.kg * x.price),
                    0
                );

            g.average =
                totalKgForPrice > 0
                    ? weightedValue / totalKgForPrice
                    : 0;
        } else {
            g.low = 0;
            g.high = 0;
            g.average = 0;
        }

        g.percentage =
            g.offered > 0
                ? (g.sold / g.offered) * 100
                : 0;
    });

    return {
        selected: selected,
        groups: groups
    };
}

function kageraRenderHighLowReport(report)
{
    const body = document.getElementById("kageraHighLowBody");
    if (!body) return;

    const rows = [
        ["Dry Cherry Coffee", report.groups["Dry Cherry Coffee"]],
        ["Clean Coffee", report.groups["Clean Coffee"]]
    ];

    body.innerHTML = rows.map(function(item) {
        const type = item[0];
        const g = item[1];

        return "<tr>" +
            "<td>" + escapeKageraHtml(type) + "</td>" +
            "<td>" + kageraReportKg(g.offered) + "</td>" +
            "<td>" + kageraReportKg(g.sold) + "</td>" +
            "<td>" + (g.prices.length ? kageraReportMoney(g.low) : "-") + "</td>" +
            "<td>" + (g.prices.length ? kageraReportMoney(g.average) : "-") + "</td>" +
            "<td>" + (g.prices.length ? kageraReportMoney(g.high) : "-") + "</td>" +
            "<td>" + kageraReportMoney(g.value) + "</td>" +
            "<td>" + g.percentage.toFixed(2) + "%</td>" +
            "</tr>";
    }).join("");
}

function kageraRenderSalesSummaryReport(report)
{
    const body = document.getElementById("kageraSalesSummaryBody");
    if (!body) return;

    const rows = [
        ["Dry Cherry Coffee", report.groups["Dry Cherry Coffee"]],
        ["Clean Coffee", report.groups["Clean Coffee"]]
    ];

    body.innerHTML = rows.map(function(item) {
        const type = item[0];
        const g = item[1];

        return "<tr>" +
            "<td>" + escapeKageraHtml(type) + "</td>" +
            "<td>" + kageraReportKg(g.offered) + "</td>" +
            "<td>" + kageraReportKg(g.sold) + "</td>" +
            "<td>" + kageraReportMoney(g.value) + "</td>" +
            "<td>" + g.percentage.toFixed(2) + "%</td>" +
            "</tr>";
    }).join("");
}

async function kageraShowReport()
{
    const panel = document.getElementById("kageraReportPanel");
    const highLow = document.getElementById("kageraHighLowReport");
    const salesSummary = document.getElementById("kageraSalesSummaryTable");
    const title = document.getElementById("kageraReportTitle");
    const subtitle = document.getElementById("kageraReportSubtitle");

    const selectedReport =
        kageraReportType
            ? String(kageraReportType.value || "").trim()
            : "";

    // Only these two Display values are report views.
    // Auction Results and Auction Catalogue must never show the report panel.
    const isReportView =
        selectedReport === "high_low" ||
        selectedReport === "sales_summary";

    if (!isReportView) {
        if (panel) panel.style.display = "none";
        if (highLow) highLow.style.display = "none";
        if (salesSummary) salesSummary.style.display = "none";

        const genericHeader =
            document.getElementById("kageraGenericReportHeader");
        if (genericHeader) genericHeader.style.display = "none";

        return;
    }

    if (panel) panel.style.display = "block";

    const genericHeader = document.getElementById("kageraGenericReportHeader");
    if (genericHeader) genericHeader.style.display = selectedReport === "high_low" ? "none" : "flex";

    const seasonSelect = document.getElementById("kageraSeasonFilter");
    const auctionSelect = document.getElementById("kageraAuctionFilter");

    const season = seasonSelect ? String(seasonSelect.value || "") : "";
    const auction = auctionSelect ? String(auctionSelect.value || "") : "";

    try {
        const response = await fetch(
            "kagera_auction.php?action=report" +
            "&season=" + encodeURIComponent(season) +
            "&auction_no=" + encodeURIComponent(auction),
            {
                cache: "no-store",
                credentials: "same-origin"
            }
        );

        const result = await readKageraJson(response);

        if (!result.success) {
            throw new Error(result.message || "Unable to generate report.");
        }

        const groups = result.data?.groups || {};

        if (selectedReport === "high_low") {
            if (highLow) highLow.style.display = "block";
            if (salesSummary) salesSummary.style.display = "none";

            if (title) title.textContent = "";
            if (subtitle) subtitle.textContent = "";

            const types = ["Dry Cherry Coffee", "Clean Coffee"];

            const totalOffered = types.reduce(function(sum, type) {
                return sum + kageraNumber((groups[type] || {}).kilos_offered);
            }, 0);

            const totalSold = types.reduce(function(sum, type) {
                return sum + kageraNumber((groups[type] || {}).kilos_sold);
            }, 0);

            const totalValue = types.reduce(function(sum, type) {
                return sum + kageraNumber((groups[type] || {}).total_value);
            }, 0);

            const salesBody =
                document.getElementById("kageraHighLowSalesBody");
            const pricesBody =
                document.getElementById("kageraHighLowPricesBody");

            const auctionText =
                auction
                    ? "SALES SUMMARY FOR THE AUCTION NO. " + auction
                    : "SALES SUMMARY FOR THE AUCTION NO. —";

            const subtitleEl =
                document.getElementById("kageraHLSubtitle");

            const heldEl =
                document.getElementById("kageraHLHeldOn");

            if (subtitleEl) {
                subtitleEl.textContent = auctionText;
            }

            /*
             * Held On must be the Auction Date / Date Sold returned by
             * PostgreSQL for the exact selected Auction No.
             */
            const heldOn =
                result.data && result.data.held_on
                    ? formatKageraDate(result.data.held_on)
                    : "";

            if (heldEl) {
                heldEl.textContent =
                    heldOn ? "Held On  " + heldOn : "Held On  —";
            }

            if (salesBody) {
                salesBody.innerHTML = types.map(function(type) {
                    const g = groups[type] || {};

                    return "<tr>" +
                        "<td>" + escapeKageraHtml(type) + "</td>" +
                        "<td>" + kageraReportKg(g.kilos_offered) + "</td>" +
                        "<td>" + kageraReportKg(g.kilos_sold) + "</td>" +
                        "<td>" + kageraReportMoney(g.total_value) + "</td>" +
                        "</tr>";
                }).join("") +
                "<tr class=\"kagera-high-low-total-row\">" +
                    "<td>Total</td>" +
                    "<td>" + kageraReportKg(totalOffered) + "</td>" +
                    "<td>" + kageraReportKg(totalSold) + "</td>" +
                    "<td>" + kageraReportMoney(totalValue) + "</td>" +
                "</tr>";
            }

            const totalOfferedEl =
                document.getElementById("kageraHLTotalOffered");
            const totalSoldEl =
                document.getElementById("kageraHLTotalSold");
            const totalValueEl =
                document.getElementById("kageraHLTotalValue");

            if (totalOfferedEl) {
                totalOfferedEl.textContent = kageraReportKg(totalOffered);
            }
            if (totalSoldEl) {
                totalSoldEl.textContent = kageraReportKg(totalSold);
            }
            if (totalValueEl) {
                totalValueEl.textContent = kageraReportMoney(totalValue);
            }

            /*
             * PRICE TABLE
             * Prices must come only from Auction Results:
             *   Lowest  = minimum positive price
             *   Average = weighted average price per Kg
             *   Highest = maximum positive price
             */
            if (pricesBody) {
                pricesBody.innerHTML = types.map(function(type) {
                    const g = groups[type] || {};

                    const lowest =
                        g.lowest_price !== null &&
                        g.lowest_price !== undefined &&
                        kageraNumber(g.lowest_price) > 0
                            ? kageraReportMoney(g.lowest_price)
                            : "-";

                    const average =
                        g.average_price !== null &&
                        g.average_price !== undefined &&
                        kageraNumber(g.average_price) > 0
                            ? kageraReportMoney(g.average_price)
                            : "-";

                    const highest =
                        g.highest_price !== null &&
                        g.highest_price !== undefined &&
                        kageraNumber(g.highest_price) > 0
                            ? kageraReportMoney(g.highest_price)
                            : "-";

                    return "<tr>" +
                        "<td>" + escapeKageraHtml(type) + "</td>" +
                        "<td>" + lowest + "</td>" +
                        "<td>" + average + "</td>" +
                        "<td>" + highest + "</td>" +
                        "</tr>";
                }).join("");
            }

            const dry =
                document.getElementById("kageraHLPercentageDry");
            const clean =
                document.getElementById("kageraHLPercentageClean");

            if (dry) {
                dry.textContent =
                    "PERCENTAGE SOLD (Dry)= " +
                    kageraNumber(groups["Dry Cherry Coffee"]?.percentage_sold).toFixed(2) +
                    "%";
            }

            if (clean) {
                clean.textContent =
                    "PERCENTAGE SOLD (Clean)= " +
                    kageraNumber(groups["Clean Coffee"]?.percentage_sold).toFixed(2) +
                    "%";
            }

        } else if (selectedReport === "sales_summary") {
            if (highLow) highLow.style.display = "none";
            if (salesSummary) salesSummary.style.display = "table";

            if (title) title.textContent = "Sales Summary";
            if (subtitle) {
                subtitle.textContent =
                    "Kilos offered from Auction Catalogue; kilos sold and total value from Auction Results.";
            }

            const body =
                document.getElementById("kageraSalesSummaryBody");

            // Use the actual Grade values: Arabica, Robusta, Arabica Certified,
            // and any other Grade present in the selected auction.
            const rawGradeGroups = result.data?.grade_groups || {};
            const gradeGroups = {};

            Object.keys(rawGradeGroups).forEach(function(rawGrade) {
                const normalizedGrade = String(rawGrade || "")
                    .trim()
                    .replace(/[\\s_]+/g, "_")
                    .replace(/^_+|_+$/g, "")
                    .split("_")
                    .filter(Boolean)
                    .map(function(part) {
                        const p = part.toLowerCase();
                        if (p === "arabica") return "Arabica";
                        if (p === "robusta") return "Robusta";
                        if (p === "clean") return "Clean";
                        if (p === "certified") return "Certified";
                        return p.charAt(0).toUpperCase() + p.slice(1);
                    })
                    .join("_");

                if (!normalizedGrade) return;

                if (!gradeGroups[normalizedGrade]) {
                    gradeGroups[normalizedGrade] = {
                        kilos_offered: 0,
                        kilos_sold: 0,
                        total_value: 0,
                        percentage_sold: 0
                    };
                }

                const source = rawGradeGroups[rawGrade] || {};
                gradeGroups[normalizedGrade].kilos_offered +=
                    kageraNumber(source.kilos_offered);
                gradeGroups[normalizedGrade].kilos_sold +=
                    kageraNumber(source.kilos_sold);
                gradeGroups[normalizedGrade].total_value +=
                    kageraNumber(source.total_value);
            });

            Object.keys(gradeGroups).forEach(function(grade) {
                const g = gradeGroups[grade];
                g.percentage_sold = g.kilos_offered > 0
                    ? (g.kilos_sold / g.kilos_offered) * 100
                    : 0;
            });

            const gradeRows = Object.keys(gradeGroups);

            let totalOffered = 0;
            let totalSold = 0;
            let totalValue = 0;

            gradeRows.forEach(function(grade) {
                const g = gradeGroups[grade] || {};
                totalOffered += kageraNumber(g.kilos_offered);
                totalSold += kageraNumber(g.kilos_sold);
                totalValue += kageraNumber(g.total_value);
            });

            if (body) {
                if (!gradeRows.length) {
                    body.innerHTML =
                        '<tr><td colspan="5" class="kagera-empty-state">' +
                        'No Sales Summary data available for the selected Auction.' +
                        '</td></tr>';
                } else {
                    body.innerHTML = gradeRows.map(function(grade) {
                        const g = gradeGroups[grade] || {};

                        return "<tr>" +
                            "<td>" + escapeKageraHtml(grade) + "</td>" +
                            "<td>" + kageraReportKg(g.kilos_offered) + "</td>" +
                            "<td>" + kageraReportKg(g.kilos_sold) + "</td>" +
                            "<td>" + kageraReportMoney(g.total_value) + "</td>" +
                            "<td>" +
                                kageraNumber(g.percentage_sold).toFixed(2) +
                                "%" +
                            "</td>" +
                            "</tr>";
                    }).join("") +
                    "<tr class=\"kagera-high-low-total-row\">" +
                        "<td>Total</td>" +
                        "<td>" + kageraReportKg(totalOffered) + "</td>" +
                        "<td>" + kageraReportKg(totalSold) + "</td>" +
                        "<td>" + kageraReportMoney(totalValue) + "</td>" +
                        "<td>" +
                            (totalOffered > 0
                                ? ((totalSold / totalOffered) * 100).toFixed(2)
                                : "0.00") +
                            "%" +
                        "</td>" +
                    "</tr>";
                }
            }
        }

    } catch (error) {
        const body =
            selectedReport === "high_low"
                ? document.getElementById("kageraHighLowBody")
                : document.getElementById("kageraSalesSummaryBody");

        if (body) {
            body.innerHTML =
                '<tr><td colspan="' +
                (selectedReport === "high_low" ? "8" : "5") +
                '" class="kagera-empty-state">' +
                escapeKageraHtml(error.message || "Unable to generate report.") +
                "</td></tr>";
        }
    }
}

async function loadKageraResults()
{
    const type=kageraDisplayType ? kageraDisplayType.value : "results";
    const resultsTable=document.getElementById("kageraResultsTable");
    const catalogueTable=document.getElementById("kageraCatalogueTable");
    const title=document.getElementById("kageraSectionTitle");
    const subtitle=document.getElementById("kageraSectionSubtitle");

    if(resultsTable) resultsTable.style.display=type==="results"?"table":"none";
    if(catalogueTable) catalogueTable.style.display=type==="catalogue"?"table":"none";

    const reportPanel = document.getElementById("kageraReportPanel");
    const highLowReport = document.getElementById("kageraHighLowReport");
    const salesSummaryReport = document.getElementById("kageraSalesSummaryTable");

    if (type !== "high_low" && type !== "sales_summary") {
        if (reportPanel) reportPanel.style.display = "none";
        if (highLowReport) highLowReport.style.display = "none";
        if (salesSummaryReport) salesSummaryReport.style.display = "none";
    }

    if(title) title.textContent=type==="catalogue"?"Kagera Auction Catalogue":"Kagera Auction Results";
    if(subtitle) subtitle.textContent=type==="catalogue"?"Catalogue currently stored in the database.":"Results currently stored in the database.";

    await loadKageraData(type);
}

/*
|--------------------------------------------------------------------------
| SEASON FILTER
|--------------------------------------------------------------------------
*/
const kageraSeasonSelect =
    document.getElementById(
        "kageraSeasonFilter"
    );

if (kageraSeasonSelect) {

    kageraSeasonSelect.addEventListener(
        "change",
        function () {

            const auctionSelect =
                document.getElementById(
                    "kageraAuctionFilter"
                );

            if (auctionSelect) {
                auctionSelect.value = "";
            }

            /*
            | Selecting Season populates only auctions
            | belonging to that season.
            */
            kageraPopulateAuctionFilter();

            /*
            | Display all records in selected season
            | until an Auction is selected.
            */
            if (kageraDisplayType && kageraDisplayType.value === "catalogue") {
                kageraPopulateAuctionFilterFromRows(kageraCatalogueData);
                kageraRenderCatalogue(kageraGetFilteredCatalogue());
            } else if (kageraDisplayType &&
                       (kageraDisplayType.value === "high_low" ||
                        kageraDisplayType.value === "sales_summary")) {
                void kageraShowReport();
            } else {
                kageraRenderResults(kageraGetFilteredResults());
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| AUCTION FILTER
|--------------------------------------------------------------------------
*/
const kageraAuctionSelect =
    document.getElementById(
        "kageraAuctionFilter"
    );

if (kageraAuctionSelect) {

    kageraAuctionSelect.addEventListener(
        "change",
        function () {

            /*
            | Selecting an Auction immediately filters
            | the table to that Auction within the selected Season.
            */
            if (kageraDisplayType && kageraDisplayType.value === "catalogue") {
                kageraRenderCatalogue(kageraGetFilteredCatalogue());
            } else if (kageraDisplayType &&
                       (kageraDisplayType.value === "high_low" ||
                        kageraDisplayType.value === "sales_summary")) {
                void kageraShowReport();
            } else {
                kageraRenderResults(kageraGetFilteredResults());
            }
        }
    );
}


/*
|--------------------------------------------------------------------------
| DISPLAY TYPE
|--------------------------------------------------------------------------
*/
if(kageraDisplayType){
    kageraDisplayType.addEventListener("change", function(){
        const value = String(this.value || "");

        if (value === "high_low" || value === "sales_summary") {
            // Report selection: hide both data tables and show only the
            // selected report.
            const resultsTable = document.getElementById("kageraResultsTable");
            const catalogueTable = document.getElementById("kageraCatalogueTable");

            if (resultsTable) resultsTable.style.display = "none";
            if (catalogueTable) catalogueTable.style.display = "none";

            void kageraShowReport();
            return;
        }

        // Normal data display: reports must be completely hidden.
        const reportPanel = document.getElementById("kageraReportPanel");
        if (reportPanel) reportPanel.style.display = "none";

        loadKageraResults();
    });
}

/*
|--------------------------------------------------------------------------
| UPLOAD TYPE
|--------------------------------------------------------------------------
*/
if(kageraUploadType){
    kageraUploadType.addEventListener("change", function(){
        const type=this.value;
        if(kageraFileName){
            kageraFileName.textContent =
                type==="catalogue"
                ? "Select Catalogue Excel file"
                : "Select Auction Results Excel file";
        }
    });
}

/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
*/
loadKageraResults();



/*
|--------------------------------------------------------------------------
| HTML ESCAPE
|--------------------------------------------------------------------------
*/

function formatKageraDate(value)
{
    if (!value) {
        return "";
    }

    const text = String(value).trim();

    // PostgreSQL DATE: YYYY-MM-DD
    let m = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m) {
        return m[3] + "/" + m[2] + "/" + m[1];
    }

    // PostgreSQL TIMESTAMP: YYYY-MM-DD HH:MM:SS or ISO timestamp.
    m = text.match(/^(\d{4})-(\d{2})-(\d{2})[T\s]/);
    if (m) {
        return m[3] + "/" + m[2] + "/" + m[1];
    }

    // Excel/text date: 10 September 2026.
    m = text.match(/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/);
    if (m) {
        const months = {
            january: "01", february: "02", march: "03",
            april: "04", may: "05", june: "06",
            july: "07", august: "08", september: "09",
            october: "10", november: "11", december: "12"
        };

        const month = months[m[2].toLowerCase()];
        if (month) {
            return String(m[1]).padStart(2, "0") + "/" + month + "/" + m[3];
        }
    }

    return text;
}

function escapeKageraHtml(value)
{

    return String(value)

        .replace(
            /&/g,
            "&amp;"
        )

        .replace(
            /</g,
            "&lt;"
        )

        .replace(
            />/g,
            "&gt;"
        )

        .replace(
            /"/g,
            "&quot;"
        )

        .replace(
            /'/g,
            "&#039;"
        );
}


/*
|--------------------------------------------------------------------------
| LOAD RESULTS
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    loadKageraResults
);

</script>


<script>
(function () {
    const displaySelect = document.getElementById( + display_id + r);
    const uploadType = document.getElementById('kageraUploadType');
    const uploadForm = document.getElementById('kageraUploadForm');

    function syncUploadTypeWithDisplay() {
        if (!displaySelect || !uploadType) return;

        const value = String(displaySelect.value || '').toLowerCase();
        const text = String(
            displaySelect.options[displaySelect.selectedIndex]?.text || ''
        ).toLowerCase();

        const isCatalogue =
            value.includes('catalogue') ||
            value.includes('catalog') ||
            text.includes('catalogue') ||
            text.includes('catalog');

        uploadType.value = isCatalogue ? 'catalogue' : 'auction_results';

        // Keep the backend informed without adding another user-facing dropdown.
        if (uploadForm) {
            uploadForm.dataset.uploadType = uploadType.value;
        }
    }

    if (displaySelect) {
        displaySelect.addEventListener('change', syncUploadTypeWithDisplay);
        syncUploadTypeWithDisplay();
    }
})();
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('kageraUploadForm');
    const displaySelect = document.getElementById( + display_id + r);
    const uploadType = document.getElementById('kageraUploadType');

    if (form && displaySelect && uploadType) {
        form.addEventListener('submit', function () {
            const selectedText = String(
                displaySelect.options[displaySelect.selectedIndex]?.text || ''
            ).toLowerCase();
            const selectedValue = String(displaySelect.value || '').toLowerCase();

            const catalogue =
                selectedText.includes('catalogue') ||
                selectedText.includes('catalog') ||
                selectedValue.includes('catalogue') ||
                selectedValue.includes('catalog');

            uploadType.value = catalogue ? 'catalogue' : 'auction_results';
        });
    }
});


</script>

</body>
</html>