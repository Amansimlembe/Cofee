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
            date_sold VARCHAR(50),
            sell_mark VARCHAR(255),

            warehouse VARCHAR(255),
            warehouse_location VARCHAR(255),

            net_weight NUMERIC(15,2),

            grade VARCHAR(100),
            grade2 VARCHAR(100),

            price NUMERIC(15,4),

            buyer_name VARCHAR(255),

            created_at TIMESTAMP WITHOUT TIME ZONE
                DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columns = [
        "lot_no" => "VARCHAR(100)",
        "auction_no" => "VARCHAR(50)",
        "date_sold" => "VARCHAR(50)",
        "sell_mark" => "VARCHAR(255)",
        "warehouse" => "VARCHAR(255)",
        "warehouse_location" => "VARCHAR(255)",
        "net_weight" => "NUMERIC(15,2)",
        "grade" => "VARCHAR(100)",
        "grade2" => "VARCHAR(100)",
        "price" => "NUMERIC(15,4)",
        "buyer_name" => "VARCHAR(255)"
    ];

    $check = $db->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'kagera_auction_results'
          AND column_name = :column
    ");

    foreach ($columns as $column => $definition) {

        $check->execute([
            "column" => $column
        ]);

        if (!$check->fetchColumn()) {

            $db->exec(
                'ALTER TABLE public.kagera_auction_results
                 ADD COLUMN "' .
                $column .
                '" ' .
                $definition
            );
        }
    }

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_auction_no
        ON public.kagera_auction_results (auction_no)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_kagera_lot_no
        ON public.kagera_auction_results (lot_no)
    ");
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
    if (
        !isset($_FILES["kagera_excel"]) ||
        !is_array($_FILES["kagera_excel"])
    ) {

        kagera_json(
            false,
            "Please select an Excel file.",
            [],
            400
        );
    }

    $file = $_FILES["kagera_excel"];

    if (
        ($file["error"] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_OK
    ) {

        $errorCode =
            (int)($file["error"] ?? 0);

        $message =
            match ($errorCode) {

                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE =>
                    "The uploaded file is too large.",

                UPLOAD_ERR_PARTIAL =>
                    "The file upload was incomplete.",

                UPLOAD_ERR_NO_FILE =>
                    "Please select an Excel file.",

                default =>
                    "File upload failed. Please try again."
            };

        kagera_json(
            false,
            $message,
            [],
            400
        );
    }

    $ext =
        strtolower(
            pathinfo(
                $file["name"],
                PATHINFO_EXTENSION
            )
        );

    $allowed = [
        "xlsx",
        "xls",
        "xlsm",
        "xltx",
        "xltm",
        "xlsb",
        "ods",
        "csv",
        "tsv",
        "txt",
        "xml",
        "html",
        "htm"
    ];

    if (
        !in_array(
            $ext,
            $allowed,
            true
        )
    ) {

        kagera_json(
            false,
            "Unsupported file format.",
            [],
            400
        );
    }

    if (
        !isset($file["tmp_name"]) ||
        !is_uploaded_file($file["tmp_name"])
    ) {

        kagera_json(
            false,
            "The uploaded file could not be accessed.",
            [],
            400
        );
    }

    $rows =
        kagera_parse_excel(
            $file["tmp_name"],
            $ext
        );

    if (
        !is_array($rows) ||
        count($rows) < 2
    ) {

        kagera_json(
            false,
            "The Excel file does not contain enough data.",
            [],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND HEADER ROW
    |--------------------------------------------------------------------------
    */

    $headerIndex = null;

    for (
        $r = 0;
        $r < min(count($rows), 15);
        $r++
    ) {

        $headers =
            array_map(
                "kagera_norm",
                $rows[$r]
            );

        $signals = [
            "lot_no",
            "lot_number",
            "lot",
            "auction_no",
            "auction_number",
            "date_sold",
            "sell_mark",
            "sellmark",
            "warehouse",
            "warehouse_location",
            "net_weight",
            "net_weight_kg",
            "grade",
            "grade2",
            "price",
            "buyer_name",
            "buyer"
        ];

        $matches =
            count(
                array_intersect(
                    $signals,
                    $headers
                )
            );

        if ($matches >= 2) {

            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {

        kagera_json(
            false,
            "The Excel file header row could not be identified.",
            [],
            400
        );
    }

    $headers =
        array_map(
            "kagera_norm",
            $rows[$headerIndex]
        );

    /*
    |--------------------------------------------------------------------------
    | COLUMN MAPPING
    |--------------------------------------------------------------------------
    */

    $col = [

        "lot_no" =>
            kagera_find_col(
                $headers,
                [
                    "lot_no",
                    "lot_number",
                    "lot"
                ]
            ),

        "auction_no" =>
            kagera_find_col(
                $headers,
                [
                    "auction_no",
                    "auction_number",
                    "auction"
                ]
            ),

        "date_sold" =>
            kagera_find_col(
                $headers,
                [
                    "date_sold",
                    "date"
                ]
            ),

        "sell_mark" =>
            kagera_find_col(
                $headers,
                [
                    "sell_mark",
                    "sellmark",
                    "mark"
                ]
            ),

        "warehouse" =>
            kagera_find_col(
                $headers,
                [
                    "warehouse",
                    "warehouse_name"
                ]
            ),

        "warehouse_location" =>
            kagera_find_col(
                $headers,
                [
                    "warehouse_location",
                    "location",
                    "warehouse_loc"
                ]
            ),

        "net_weight" =>
            kagera_find_col(
                $headers,
                [
                    "net_weight",
                    "net_weight_kg",
                    "net_kg",
                    "kgs",
                    "kg"
                ]
            ),

        "grade" =>
            kagera_find_col(
                $headers,
                [
                    "grade"
                ]
            ),

        "grade2" =>
            kagera_find_col(
                $headers,
                [
                    "grade2",
                    "grade_2",
                    "grade_ii"
                ]
            ),

        "price" =>
            kagera_find_col(
                $headers,
                [
                    "price",
                    "price_usd",
                    "price_tzs",
                    "unit_price"
                ]
            ),

        "buyer_name" =>
            kagera_find_col(
                $headers,
                [
                    "buyer_name",
                    "buyer"
                ]
            )
    ];

    /*
    |--------------------------------------------------------------------------
    | DATABASE
    |--------------------------------------------------------------------------
    */

    ensure_kagera_table();

    $db = kagera_db();

    /*
    |--------------------------------------------------------------------------
    | PREPARED INSERT
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        INSERT INTO public.kagera_auction_results
        (
            lot_no,
            auction_no,
            date_sold,
            sell_mark,
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
            :sell_mark,
            :warehouse,
            :warehouse_location,
            :net_weight,
            :grade,
            :grade2,
            :price,
            :buyer_name
        )
    ");

    $processed = 0;

    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    try {

        foreach ($rows as $r => $row) {

            if ($r <= $headerIndex) {
                continue;
            }

            $get = function ($key) use (
                $row,
                $col
            ) {

                $i = $col[$key];

                if ($i === null) {
                    return "";
                }

                return trim(
                    (string)(
                        $row[$i] ?? ""
                    )
                );
            };

            $lot =
                $get("lot_no");

            $auction =
                $get("auction_no");

            $date =
                $get("date_sold");

            $sell =
                $get("sell_mark");

            $warehouse =
                $get("warehouse");

            $location =
                $get("warehouse_location");

            $weight =
                kagera_number(
                    $get("net_weight")
                );

            $grade =
                $get("grade");

            $grade2 =
                $get("grade2");

            $price =
                kagera_number(
                    $get("price")
                );

            $buyer =
                $get("buyer_name");

            /*
            | Skip completely empty rows
            */
            if (
                $lot === "" &&
                $auction === "" &&
                $date === "" &&
                $sell === "" &&
                $warehouse === "" &&
                $location === "" &&
                $grade === "" &&
                $grade2 === "" &&
                $buyer === ""
            ) {

                continue;
            }

            $stmt->execute([
                "lot_no" =>
                    $lot,

                "auction_no" =>
                    $auction,

                "date_sold" =>
                    $date,

                "sell_mark" =>
                    $sell,

                "warehouse" =>
                    $warehouse,

                "warehouse_location" =>
                    $location,

                "net_weight" =>
                    $weight,

                "grade" =>
                    $grade,

                "grade2" =>
                    $grade2,

                "price" =>
                    $price,

                "buyer_name" =>
                    $buyer
            ]);

            $processed++;
        }

        $db->commit();

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            "Kagera upload database error: " .
            $e->getMessage()
        );

        throw new Exception(
            "The auction records could not be saved to the database."
        );
    }

    kagera_json(
        true,
        "Kagera Auction results uploaded successfully. {$processed} record(s) processed.",
        [],
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
            sell_mark,
            warehouse,
            warehouse_location,
            net_weight,
            grade,
            grade2,
            price,
            buyer_name
        FROM public.kagera_auction_results
        ORDER BY id DESC
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

    if (
        ($_GET['action'] ?? '') === 'fetch'
    ) {

        handle_kagera_fetch();
    }

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST' &&
        isset($_FILES['kagera_excel'])
    ) {

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

.kagera-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 26px;
}

.kagera-title-group {
    min-width: 0;
}

.kagera-eyebrow {
    margin-bottom: 7px;
    color: #8d6e63;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.3px;
    text-transform: uppercase;
}

.kagera-page-header h1 {
    margin: 0 0 7px;
    color: #3e2723;
    font-size: 28px;
    font-weight: 700;
}

.kagera-page-header p {
    margin: 0;
    color: #777;
    font-size: 14px;
    line-height: 1.5;
}

.kagera-status {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 13px;
    border: 1px solid #e1d9d5;
    border-radius: 20px;
    background: #fff;
    color: #5d4037;
    font-size: 12px;
    font-weight: 600;
}

.kagera-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #6d8b6d;
}

.kagera-card {
    background: #fff;
    border: 1px solid #e7e0dc;
    border-radius: 12px;
    box-shadow: 0 3px 14px rgba(62,39,35,.055);
}

.kagera-upload-card {
    margin-bottom: 22px;
    padding: 24px;
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

.kagera-upload-btn,
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
    overflow-x: auto;
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
        align-items: flex-start;
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

</style>

</head>

<body>

<main class="kagera-main">

<div class="kagera-container">

<header class="kagera-page-header">

<div class="kagera-title-group">

<div class="kagera-eyebrow">
Auction Sales
</div>

<h1>
Kagera Auction
</h1>

<p>
Upload Kagera Auction Excel results and manage
the records stored in the database.
</p>

</div>

<div class="kagera-status">

<span class="kagera-status-dot"></span>

Database Ready

</div>

</header>


<section class="kagera-card kagera-upload-card">

<div class="kagera-card-heading">

<div class="kagera-card-icon">
📊
</div>

<div>

<h2>
Upload Auction Results
</h2>

<p>
Choose an Excel results file and upload it securely
into the Kagera Auction database.
</p>

</div>

</div>


<form
    id="kageraUploadForm"
    action="kagera_auction.php"
    method="POST"
    enctype="multipart/form-data"
>

<div class="kagera-upload-row">

<div class="kagera-file-area">

<input
    type="file"
    id="kageraExcelFile"
    name="kagera_excel"
    accept=".xlsx,.xls,.xlsm,.xltx,.xltm,.xlsb,.ods,.csv,.tsv,.txt,.xml,.html,.htm"
    required
>

<label
    for="kageraExcelFile"
    class="kagera-file-label"
>

<span class="kagera-file-icon">
📁
</span>

<span class="kagera-file-text">

<strong>
Select Excel File
</strong>

<small id="kageraFileName">
Supported formats: .xlsx, .xls, .xlsm, .xltx, .xltm, .xlsb, .ods, .csv, .tsv, .txt, .xml, .html
</small>

</span>

<span class="kagera-browse">
Browse
</span>

</label>

</div>


<button
    type="submit"
    class="kagera-upload-btn"
    id="kageraUploadButton"
>

<span>↑</span>

Upload Results

</button>

</div>

</form>


<div
    id="kageraUploadStatus"
    class="kagera-upload-status"
></div>

</section>


<section class="kagera-card kagera-data-card">

<div class="kagera-data-header">

<div>

<h2>
Kagera Auction Results
</h2>

<p>
Results currently stored in the database.
</p>

</div>


<button
    type="button"
    class="kagera-refresh-btn"
    onclick="loadKageraResults()"
>

<span>↻</span>

Refresh

</button>

</div>


<div class="kagera-table-wrap">

<table
    id="kageraResultsTable"
    class="kagera-results-table"
>

<thead>

<tr>

<th>Lot No</th>
<th>Auction No.</th>
<th>Date Sold</th>
<th>Sell Mark</th>
<th>Warehouse</th>
<th>Warehouse Location</th>
<th>Net Weight (Kg)</th>
<th>Grade</th>
<th>Grade2</th>
<th>Price</th>
<th>Buyer Name</th>

</tr>

</thead>


<tbody id="kageraResultsBody">

<tr>

<td
    colspan="11"
    class="kagera-empty-state"
>

No Kagera Auction results loaded.

</td>

</tr>

</tbody>

</table>

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

            if (
                !kageraExcelFile.files.length
            ) {

                showKageraStatus(
                    "Please select an Excel file first.",
                    "error"
                );

                return;
            }

            const button =
                document.getElementById(
                    "kageraUploadButton"
                );

            const formData =
                new FormData(
                    kageraUploadForm
                );

            button.disabled = true;

            button.innerHTML =
                "<span>⏳</span> Uploading...";

            showKageraStatus(
                "Uploading auction results...",
                "success"
            );

            try {

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

                const result =
                    await readKageraJson(
                        response
                    );

                showKageraStatus(
                    result.message ||
                    "Results uploaded successfully.",
                    "success"
                );

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

async function loadKageraResults()
{

    const body =
        document.getElementById(
            "kageraResultsBody"
        );

    if (!body) {
        return;
    }

    body.innerHTML =
        '<tr>' +
        '<td colspan="11" class="kagera-empty-state">' +
        'Loading results...' +
        '</td>' +
        '</tr>';

    try {

        const response =
            await fetch(
                "kagera_auction.php?action=fetch",
                {
                    cache: "no-store",
                    credentials: "same-origin"
                }
            );

        const result =
            await readKageraJson(
                response
            );

        if (
            !result.data ||
            !result.data.length
        ) {

            body.innerHTML =
                '<tr>' +
                '<td colspan="11" class="kagera-empty-state">' +
                'No Kagera Auction results loaded.' +
                '</td>' +
                '</tr>';

            return;
        }


        body.innerHTML =
            result.data
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
                            row.date_sold ?? ""
                        ) +
                        "</td>" +

                        "<td>" +
                        escapeKageraHtml(
                            row.sell_mark ?? ""
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

    } catch (error) {

        console.error(
            "Kagera fetch error:",
            error
        );

        body.innerHTML =
            '<tr>' +
            '<td colspan="11" class="kagera-empty-state">' +
            escapeKageraHtml(
                error.message ||
                "Unable to load results."
            ) +
            '</td>' +
            '</tr>';
    }
}


/*
|--------------------------------------------------------------------------
| HTML ESCAPE
|--------------------------------------------------------------------------
*/

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

</body>
</html>