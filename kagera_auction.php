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
| REQUEST ROUTING
|--------------------------------------------------------------------------
*/

try {

    $kageraType = strtolower(trim((string)($_REQUEST['kagera_type'] ?? 'results')));

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

/* --------------------------------------------------------------------------
   REPORT CONTROL
   -------------------------------------------------------------------------- */
.kagera-report-control {
    display:flex;
    flex-direction:column;
    gap:4px;
    min-width:125px;
}

.kagera-report-control label {
    color:#6b625e;
    font-size:10px;
    font-weight:700;
}

.kagera-report-panel {
    width:100%;
    height:100%;
    overflow:auto;
    background:#fff;
}

.kagera-report-paper {
    width:min(100%, 1100px);
    margin:0 auto;
    padding:24px 28px 30px;
}

.kagera-report-title {
    text-align:center;
    color:#3e2723;
    margin-bottom:18px;
}

.kagera-report-kce {
    font-size:18px;
    font-weight:800;
    letter-spacing:.6px;
    margin-bottom:7px;
}

#kageraReportHeading {
    font-size:14px;
    font-weight:700;
    text-transform:uppercase;
    margin-bottom:6px;
}

#kageraReportHeldOn {
    font-size:11px;
    color:#746b67;
}

.kagera-report-table {
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

.kagera-report-table th,
.kagera-report-table td {
    border:1px solid #d9d0cc;
    padding:10px 9px;
    text-align:right;
}

.kagera-report-table th:first-child,
.kagera-report-table td:first-child {
    text-align:left;
}

.kagera-report-table thead th {
    background:#4e342e;
    color:#fff;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}

.kagera-report-table tbody td {
    background:#fff;
    color:#3e2723;
}

.kagera-report-table tbody tr:hover td {
    background:#faf7f5;
}

.kagera-report-table .report-section td {
    background:#f1ece9;
    color:#4e342e;
    font-weight:700;
}

.kagera-report-table .report-total td {
    background:#f7f3f1;
    font-weight:700;
}

.kagera-report-empty {
    text-align:center !important;
    padding:22px !important;
    color:#888 !important;
}

@media (max-width:1250px) {
    .kagera-report-control {
        min-width:125px;
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
            <form
                id="kageraUploadForm"
                action="kagera_auction.php"
                method="POST"
                enctype="multipart/form-data"
                class="kagera-header-upload-form"
            >
                <input type="hidden" id="kageraUploadType" name="upload_type" value="auction_results">
                

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

        

        <div class="kagera-report-control">
            <label for="kageraReportType">Report</label>
            <select id="kageraReportType" class="kagera-filter-select">
                <option value="">Select Report</option>
                <option value="high_low">High &amp; Low</option>
                <option value="sales_summary">Sales Summary</option>
            </select>
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

    <div class="kagera-report-paper">

        <div class="kagera-report-title">
            <div class="kagera-report-kce">KAGERA COFFEE EXCHANGE</div>
            <div id="kageraReportHeading">SALES SUMMARY FOR THE SELECTED AUCTION</div>
            <div id="kageraReportHeldOn">Held On —</div>
        </div>

        <div id="kageraHighLowReport" style="display:none;">
            <table class="kagera-report-table">
                <thead>
                    <tr>
                        <th>TYPE OF COFFEE</th>
                        <th>KILOS OFFERED</th>
                        <th>KILOS SOLD</th>
                        <th>LOWEST PRICE PER KG</th>
                        <th>AVERAGE PRICE PER KG</th>
                        <th>HIGHEST PRICE PER KG</th>
                    </tr>
                </thead>
                <tbody id="kageraHighLowBody"></tbody>
            </table>
        </div>

        <div id="kageraSalesSummaryReport" style="display:none;">
            <table class="kagera-report-table">
                <thead>
                    <tr>
                        <th>TYPE OF COFFEE</th>
                        <th>KILOS OFFERED</th>
                        <th>KILOS SOLD</th>
                        <th>TOTAL VALUE (TZS)</th>
                        <th>PERCENTAGE SOLD</th>
                    </tr>
                </thead>
                <tbody id="kageraSalesSummaryBody"></tbody>
            </table>
        </div>

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

async function loadKageraResults()
{
    const type=kageraDisplayType ? kageraDisplayType.value : "results";
    const resultsTable=document.getElementById("kageraResultsTable");
    const catalogueTable=document.getElementById("kageraCatalogueTable");
    const title=document.getElementById("kageraSectionTitle");
    const subtitle=document.getElementById("kageraSectionSubtitle");

    if(resultsTable) resultsTable.style.display=type==="results"?"table":"none";
    if(catalogueTable) catalogueTable.style.display=type==="catalogue"?"table":"none";

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
        loadKageraResults();
    });
}

/*
|--------------------------------------------------------------------------
| REPORT + DISPLAY + UPLOAD CONTROL
|--------------------------------------------------------------------------
*/

const kageraReportType =
    document.getElementById("kageraReportType");

const kageraUploadType =
    document.getElementById("kageraUploadType");

const kageraReportPanel =
    document.getElementById("kageraReportPanel");

const kageraHighLowReport =
    document.getElementById("kageraHighLowReport");

const kageraSalesSummaryReport =
    document.getElementById("kageraSalesSummaryReport");

const kageraHighLowBody =
    document.getElementById("kageraHighLowBody");

const kageraSalesSummaryBody =
    document.getElementById("kageraSalesSummaryBody");

const kageraReportHeading =
    document.getElementById("kageraReportHeading");

const kageraReportHeldOn =
    document.getElementById("kageraReportHeldOn");

let kageraReportResults = [];
let kageraReportCatalogue = [];

/*
|--------------------------------------------------------------------------
| DISPLAY -> UPLOAD TYPE
|--------------------------------------------------------------------------
| The Display selection determines what the next uploaded Excel file is.
|--------------------------------------------------------------------------
*/
function kageraSyncUploadType()
{
    if (!kageraDisplayType || !kageraUploadType) return;

    const type =
        String(kageraDisplayType.value || "").toLowerCase();

    kageraUploadType.value =
        type === "catalogue"
            ? "catalogue"
            : "auction_results";

    if (kageraFileName) {
        kageraFileName.textContent =
            type === "catalogue"
                ? "Select Catalogue Excel file"
                : "Select Auction Results Excel file";
    }
}

/*
|--------------------------------------------------------------------------
| REPORT HELPERS
|--------------------------------------------------------------------------
*/
function kageraReportNumber(value)
{
    if (value === null || value === undefined || value === "") {
        return null;
    }

    const number =
        Number(
            String(value)
                .replace(/,/g, "")
                .replace(/\s/g, "")
                .trim()
        );

    return Number.isFinite(number)
        ? number
        : null;
}

function kageraReportTypeName(row)
{
    const value =
        String(
            row.grade2 ??
            row.Grade2 ??
            row.grade ??
            row.Grade ??
            ""
        )
        .trim()
        .toLowerCase();

    if (value.includes("clean")) {
        return "Clean Coffee";
    }

    if (value.includes("dry cherry")) {
        return "Dry Cherry Coffee";
    }

    return null;
}

function kageraFormatReportNumber(value, decimals)
{
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return "-";
    }

    return Number(value).toLocaleString("en-US", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function kageraFormatReportCurrency(value)
{
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return "-";
    }

    return Number(value).toLocaleString("en-US", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
}

function kageraSelectedSeason()
{
    const select =
        document.getElementById("kageraSeasonFilter");

    return select ? String(select.value || "") : "";
}

function kageraSelectedAuction()
{
    const select =
        document.getElementById("kageraAuctionFilter");

    return select ? String(select.value || "") : "";
}

function kageraReportFilteredRows(rows)
{
    const season = kageraSelectedSeason();
    const auction = kageraSelectedAuction();

    return (rows || []).filter(function(row) {

        const rowSeason =
            kageraGetSeason(row.date_sold);

        const rowAuction =
            String(row.auction_no ?? "").trim();

        return (
            (!season || rowSeason === season) &&
            (!auction || rowAuction === auction)
        );
    });
}

function kageraCalculateReport()
{
    const catalogueRows =
        kageraReportFilteredRows(kageraReportCatalogue);

    const resultRows =
        kageraReportFilteredRows(kageraReportResults);

    const types = [
        "Dry Cherry Coffee",
        "Clean Coffee"
    ];

    return types.map(function(type) {

        const offered =
            catalogueRows
                .filter(function(row) {
                    return kageraReportTypeName(row) === type;
                })
                .reduce(function(total, row) {
                    const kg =
                        kageraReportNumber(
                            row.net_weight ??
                            row.kgs ??
                            row.Kgs
                        );

                    return total + (kg !== null ? kg : 0);
                }, 0);

        const sales =
            resultRows
                .filter(function(row) {
                    return kageraReportTypeName(row) === type;
                })
                .map(function(row) {

                    const kg =
                        kageraReportNumber(
                            row.net_weight ??
                            row.kgs ??
                            row.Kgs
                        );

                    const price =
                        kageraReportNumber(
                            row.price ??
                            row.Price
                        );

                    return {
                        kg: kg !== null ? kg : 0,
                        price: price
                    };
                })
                /*
                | A positive price identifies an actual sold result.
                | Rows without a price are not counted as kilos sold or
                | as price observations.
                */
                .filter(function(item) {
                    return item.kg > 0 &&
                           item.price !== null &&
                           item.price > 0;
                });

        const sold =
            sales.reduce(function(total, item) {
                return total + item.kg;
            }, 0);

        const totalValue =
            sales.reduce(function(total, item) {
                return total + (item.kg * item.price);
            }, 0);

        const prices =
            sales
                .map(function(item) {
                    return item.price;
                })
                .filter(function(price) {
                    return Number.isFinite(price);
                });

        const lowest =
            prices.length
                ? Math.min.apply(null, prices)
                : null;

        const highest =
            prices.length
                ? Math.max.apply(null, prices)
                : null;

        /*
        | Weighted average = total sales value / kilos sold.
        | This uses the Auction Results prices and sold kilograms.
        */
        const average =
            sold > 0
                ? totalValue / sold
                : null;

        return {
            type: type,
            offered: offered,
            sold: sold,
            totalValue: totalValue,
            percentage: offered > 0
                ? (sold / offered) * 100
                : 0,
            lowest: lowest,
            average: average,
            highest: highest
        };
    });
}

function kageraGetReportHeldOn()
{
    const rows =
        kageraReportFilteredRows(kageraReportCatalogue);

    if (!rows.length) {
        return "";
    }

    const dates =
        rows
            .map(function(row) {
                return row.date_sold;
            })
            .filter(Boolean)
            .sort();

    if (!dates.length) {
        return "";
    }

    return formatKageraDate(dates[0]);
}

/*
|--------------------------------------------------------------------------
| HIGH & LOW REPORT
|--------------------------------------------------------------------------
*/
function kageraRenderHighLowReport()
{
    const data =
        kageraCalculateReport();

    if (!kageraHighLowBody) return;

    const auction =
        kageraSelectedAuction();

    const titleAuction =
        auction
            ? "AUCTION NO. " + auction
            : "SELECTED AUCTION";

    if (kageraReportHeading) {
        kageraReportHeading.textContent =
            "SALES SUMMARY FOR THE " + titleAuction;
    }

    const heldOn =
        kageraGetReportHeldOn();

    if (kageraReportHeldOn) {
        kageraReportHeldOn.textContent =
            heldOn
                ? "Held On " + heldOn
                : "Held On —";
    }

    kageraHighLowBody.innerHTML =
        data.map(function(item) {

            return (
                "<tr>" +
                    "<td>" + escapeKageraHtml(item.type) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.offered, 0) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.sold, 0) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.lowest, 2) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.average, 2) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.highest, 2) + "</td>" +
                "</tr>"
            );
        })
        .join("");
}

/*
|--------------------------------------------------------------------------
| SALES SUMMARY REPORT
|--------------------------------------------------------------------------
*/
function kageraRenderSalesSummaryReport()
{
    const data =
        kageraCalculateReport();

    if (!kageraSalesSummaryBody) return;

    const auction =
        kageraSelectedAuction();

    const titleAuction =
        auction
            ? "AUCTION NO. " + auction
            : "SELECTED AUCTION";

    if (kageraReportHeading) {
        kageraReportHeading.textContent =
            "SALES SUMMARY FOR THE " + titleAuction;
    }

    const heldOn =
        kageraGetReportHeldOn();

    if (kageraReportHeldOn) {
        kageraReportHeldOn.textContent =
            heldOn
                ? "Held On " + heldOn
                : "Held On —";
    }

    kageraSalesSummaryBody.innerHTML =
        data.map(function(item) {

            return (
                "<tr>" +
                    "<td>" + escapeKageraHtml(item.type) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.offered, 0) + "</td>" +
                    "<td>" + kageraFormatReportNumber(item.sold, 0) + "</td>" +
                    "<td>" + kageraFormatReportCurrency(item.totalValue) + "</td>" +
                    "<td>" + item.percentage.toFixed(2) + "%</td>" +
                "</tr>"
            );
        })
        .join("");
}

async function kageraLoadReportData()
{
    try {

        const requests = [
            fetch(
                "kagera_auction.php?action=fetch&kagera_type=results",
                {
                    cache: "no-store",
                    credentials: "same-origin"
                }
            ),
            fetch(
                "kagera_auction.php?action=fetch&kagera_type=catalogue",
                {
                    cache: "no-store",
                    credentials: "same-origin"
                }
            )
        ];

        const responses =
            await Promise.all(requests);

        const resultsJson =
            await readKageraJson(responses[0]);

        const catalogueJson =
            await readKageraJson(responses[1]);

        kageraReportResults =
            Array.isArray(resultsJson.data)
                ? resultsJson.data
                : [];

        kageraReportCatalogue =
            Array.isArray(catalogueJson.data)
                ? catalogueJson.data
                : [];

        return true;

    } catch (error) {

        if (kageraReportPanel) {
            kageraReportPanel.innerHTML =
                '<div class="kagera-empty-state" style="padding:30px;text-align:center;">' +
                escapeKageraHtml(
                    error.message ||
                    "Unable to load report data."
                ) +
                "</div>";
        }

        return false;
    }
}

async function kageraRenderSelectedReport()
{
    const report =
        kageraReportType
            ? String(kageraReportType.value || "")
            : "";

    const resultsTable =
        document.getElementById("kageraResultsTable");

    const catalogueTable =
        document.getElementById("kageraCatalogueTable");

    if (report) {

        if (resultsTable) {
            resultsTable.style.display = "none";
        }

        if (catalogueTable) {
            catalogueTable.style.display = "none";
        }

        if (kageraReportPanel) {
            kageraReportPanel.style.display = "block";
        }

        const loaded =
            await kageraLoadReportData();

        if (!loaded) return;

        if (report === "high_low") {

            if (kageraHighLowReport) {
                kageraHighLowReport.style.display = "block";
            }

            if (kageraSalesSummaryReport) {
                kageraSalesSummaryReport.style.display = "none";
            }

            kageraRenderHighLowReport();

        } else if (report === "sales_summary") {

            if (kageraHighLowReport) {
                kageraHighLowReport.style.display = "none";
            }

            if (kageraSalesSummaryReport) {
                kageraSalesSummaryReport.style.display = "block";
            }

            kageraRenderSalesSummaryReport();
        }

        return;
    }

    if (kageraReportPanel) {
        kageraReportPanel.style.display = "none";
    }

    if (resultsTable) {
        resultsTable.style.display =
            kageraDisplayType &&
            kageraDisplayType.value === "results"
                ? "table"
                : "none";
    }

    if (catalogueTable) {
        catalogueTable.style.display =
            kageraDisplayType &&
            kageraDisplayType.value === "catalogue"
                ? "table"
                : "none";
    }
}

/*
|--------------------------------------------------------------------------
| DISPLAY TYPE
|--------------------------------------------------------------------------
*/
if (kageraDisplayType) {

    kageraDisplayType.addEventListener(
        "change",
        async function() {

            kageraSyncUploadType();

            if (kageraReportType) {
                kageraReportType.value = "";
            }

            await loadKageraResults();
        }
    );
}

/*
|--------------------------------------------------------------------------
| REPORT TYPE
|--------------------------------------------------------------------------
*/
if (kageraReportType) {

    kageraReportType.addEventListener(
        "change",
        function() {
            kageraRenderSelectedReport();
        }
    );
}

/*
|--------------------------------------------------------------------------
| SEASON FILTER
|--------------------------------------------------------------------------
*/
if (kageraSeasonSelect) {

    kageraSeasonSelect.addEventListener(
        "change",
        async function() {

            const auctionSelect =
                document.getElementById("kageraAuctionFilter");

            if (auctionSelect) {
                auctionSelect.value = "";
            }

            /*
            | The currently displayed data determines the Auction list.
            */
            if (
                kageraDisplayType &&
                kageraDisplayType.value === "catalogue"
            ) {

                kageraPopulateAuctionFilterFromRows(
                    kageraCatalogueData
                );

                kageraRenderCatalogue(
                    kageraGetFilteredCatalogue()
                );

            } else {

                kageraPopulateAuctionFilterFromRows(
                    kageraAllResults
                );

                kageraRenderResults(
                    kageraGetFilteredResults()
                );
            }

            if (kageraReportType && kageraReportType.value) {
                await kageraRenderSelectedReport();
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| AUCTION FILTER
|--------------------------------------------------------------------------
*/
if (kageraAuctionSelect) {

    kageraAuctionSelect.addEventListener(
        "change",
        async function() {

            if (
                kageraDisplayType &&
                kageraDisplayType.value === "catalogue"
            ) {

                kageraRenderCatalogue(
                    kageraGetFilteredCatalogue()
                );

            } else {

                kageraRenderResults(
                    kageraGetFilteredResults()
                );
            }

            if (kageraReportType && kageraReportType.value) {
                await kageraRenderSelectedReport();
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| UPLOAD
|--------------------------------------------------------------------------
*/
if (kageraUploadForm) {

    kageraUploadForm.addEventListener(
        "submit",
        async function(event) {

            event.preventDefault();

            kageraSyncUploadType();

            const button =
                document.getElementById("kageraUploadButton");

            if (button) {
                button.disabled = true;
                button.innerHTML =
                    "<span>⏳</span> Uploading...";
            }

            try {

                const formData =
                    new FormData(kageraUploadForm);

                formData.set(
                    "kagera_type",
                    kageraUploadType
                        ? kageraUploadType.value
                        : "auction_results"
                );

                const response =
                    await fetch(
                        "kagera_auction.php",
                        {
                            method: "POST",
                            body: formData,
                            credentials: "same-origin"
                        }
                    );

                const result =
                    await readKageraJson(response);

                if (
                    result.requires_confirmation ||
                    result.data?.requires_confirmation
                ) {

                    const confirmed =
                        window.confirm(
                            result.message +
                            "\n\nDo you want to replace the matching existing records?"
                        );

                    if (confirmed) {

                        formData.set(
                            "confirm_replace",
                            "1"
                        );

                        const secondResponse =
                            await fetch(
                                "kagera_auction.php",
                                {
                                    method: "POST",
                                    body: formData,
                                    credentials: "same-origin"
                                }
                            );

                        const secondResult =
                            await readKageraJson(
                                secondResponse
                            );

                        showKageraStatus(
                            secondResult.message ||
                            "Upload completed.",
                            secondResult.success
                                ? "success"
                                : "error"
                        );

                        if (secondResult.success) {
                            await loadKageraResults();
                        }

                    } else {

                        showKageraStatus(
                            "Upload cancelled. Existing records were not replaced.",
                            "error"
                        );
                    }

                } else {

                    showKageraStatus(
                        result.message ||
                        "Upload completed.",
                        result.success
                            ? "success"
                            : "error"
                    );

                    if (result.success) {
                        await loadKageraResults();
                    }
                }

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

                if (button) {
                    button.disabled = false;
                    button.innerHTML =
                        "<span>↑</span> Upload";
                }
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
*/
kageraSyncUploadType();

loadKageraResults();

</script>

<script>
/*
|--------------------------------------------------------------------------
| HTML ESCAPE / DATE FORMATTING
|--------------------------------------------------------------------------
*/
function formatKageraDate(value)
{
    if (!value) return "";

    const text = String(value).trim();

    const iso =
        text.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (iso) {
        return iso[3] + "/" + iso[2] + "/" + iso[1];
    }

    return text;
}

function escapeKageraHtml(value)
{
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

</body>
</html>