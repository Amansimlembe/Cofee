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
| DATABASE LAYER
|--------------------------------------------------------------------------
| PostgreSQL connection/schema/table logic is maintained separately.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/kagera_database.php';


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
            kagera_find_col($headers, ["auction_date","date","auction_date"]) !== null
        ) {
            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {
        kagera_json(false, "Catalogue header row could not be identified. Required fields are Lot No., Auction No. and Auction Date.", [], 400);
    }

    $rawHeaders = $rows[$headerIndex];
    kagera_validate_selected_excel_columns($rawHeaders, "catalogue");
    $headers = array_map("kagera_norm", $rawHeaders);

    $col = [
        "lot_no" => kagera_find_col($headers, ["lot_no","lot_number","lot"]),
        "auction_no" => kagera_find_col($headers, ["auction_no","auction_number","auction"]),
        "auction_date" => kagera_find_col($headers, ["auction_date","auction_date","sold_date","date"]),
        "union_name" => kagera_find_col($headers, ["union","union_name"]),
        "warehouse_name_amcos" => kagera_find_col($headers, ["warehouse_name_amcos","warehouse_name","warehouse","amcos"]),
        "warehouse_location_district" => kagera_find_col($headers, ["warehouse_location_district","warehouse_location","district","location"]),
        "kgs" => kagera_find_col($headers, ["kgs","kg","kgs","kgs_kg"]),
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
        $date=kagera_normalize_date($get("auction_date"));

        if ($lot === "" && $auction === "" && $date === null) continue;

        $missing=[];
        if ($lot==="") $missing[]="Lot No";
        if ($auction==="") $missing[]="Auction No";
        if ($date===null) $missing[]="Auction Date";

        // Weight (Kgs) is mandatory.
        $rawWeight = $get("kgs");
        $cleanWeight = str_replace([",", " "], "", trim((string)$rawWeight));

        if (trim((string)$rawWeight) === "") {
            $missing[] = "Weight (Kgs)";
        } elseif (!is_numeric($cleanWeight) || (float)$cleanWeight <= 0) {
            $missing[] = "Valid Weight (Kgs) greater than 0";
        }

        if ($missing) {
            $rowErrors[]=["excel_row"=>$r+1,"missing_fields"=>$missing];
            continue;
        }

        $netWeight = (float)$cleanWeight;

        $key=$lot."\x1F".$auction."\x1F".$date;
        $records[$key]=[
            "lot_no"=>$lot,
            "auction_no"=>$auction,
            "auction_date"=>$date,
            "union_name"=>$get("union_name"),
            "warehouse_name_amcos"=>$get("warehouse_name_amcos"),
            "warehouse_location_district"=>$get("warehouse_location_district"),
            "kgs"=>$netWeight,
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
    $stmt=$db->query("SELECT id,lot_no,auction_no,auction_date FROM public.kagera_auction_catalogue WHERE lot_no IS NOT NULL AND auction_no IS NOT NULL AND auction_date IS NOT NULL");
    while($old=$stmt->fetch(PDO::FETCH_ASSOC)){
        $key=trim((string)$old["lot_no"])."\x1F".trim((string)$old["auction_no"])."\x1F".$old["auction_date"];
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
                return ["lot_no"=>$records[$key]["lot_no"],"auction_no"=>$records[$key]["auction_no"],"auction_date"=>$records[$key]["auction_date"]];
            },$replacementKeys)
        ]);
    }

    $db->beginTransaction();
    try {
        if($replacementKeys){
            $del=$db->prepare("DELETE FROM public.kagera_auction_catalogue WHERE lot_no=:lot_no AND auction_no=:auction_no AND auction_date=:auction_date");
            foreach($replacementKeys as $key){
                $old=$existing[$key];
                $del->execute(["lot_no"=>$old["lot_no"],"auction_no"=>$old["auction_no"],"auction_date"=>$old["auction_date"]]);
            }
        }

        $ins=$db->prepare("
            INSERT INTO public.kagera_auction_catalogue
            (lot_no,auction_no,auction_date,union_name,warehouse_name_amcos,warehouse_location_district,kgs,grade,grade2,certification)
            VALUES
            (:lot_no,:auction_no,:auction_date,:union_name,:warehouse_name_amcos,:warehouse_location_district,:kgs,:grade,:grade2,:certification)
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
        SELECT id,lot_no,auction_no,auction_date,union_name,warehouse_name_amcos,warehouse_location_district,kgs,grade,grade2,certification
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


function kagera_validate_selected_excel_columns(array $rawHeaders, $uploadType)
{
    $isCatalogue = $uploadType === "catalogue";

    $expected = $isCatalogue
        ? ["lot_no","auction_no","auction_date","union","warehouse_name_amcos","warehouse_location_district","kgs","grade","grade2","certification"]
        : ["lot_no","auction_no","auction_date","warehouse_name_amcos","warehouse_location_district","kgs","grade","grade2","price","buyer"];

    $labels = $isCatalogue
        ? ["Lot No.","Auction No.","Auction Date","Union","Warehouse name/Amcos","Warehouse Location/District","Kgs","Grade","Grade2","Certification"]
        : ["Lot No","Auction No.","Auction Date","Warehouse Name/Amcos","Warehouse Location/District","Kgs","Grade","Grade2","Price","Buyer"];

    $aliases = [
        "lot_number"=>"lot_no","lot"=>"lot_no",
        "auction_number"=>"auction_no","auction"=>"auction_no",
        "date_sold"=>"auction_date","sold_date"=>"auction_date","held_on"=>"auction_date","date"=>"auction_date",
        "union_name"=>"union",
        "warehouse"=>"warehouse_name_amcos","warehouse_name"=>"warehouse_name_amcos","warehouse_amcos"=>"warehouse_name_amcos",
        "warehouse_location"=>"warehouse_location_district","district"=>"warehouse_location_district","location"=>"warehouse_location_district","warehouse_loc"=>"warehouse_location_district",
        "net_weight"=>"kgs","net_weight_kg"=>"kgs","weight_kgs"=>"kgs","kg"=>"kgs","kgs_kg"=>"kgs",
        "grade_2"=>"grade2","grade_ii"=>"grade2",
        "buyer_name"=>"buyer","certificate"=>"certification"
    ];

    $actualRaw=array_map(static fn($v)=>trim((string)$v),$rawHeaders);
    while($actualRaw && end($actualRaw)==="") array_pop($actualRaw);

    $actual=[];
    foreach($actualRaw as $v){
        $k=kagera_norm($v);
        $actual[]=$aliases[$k] ?? $k;
    }

    $missing=[];
    foreach($expected as $i=>$k){
        if(!in_array($k,$actual,true)) $missing[]=$labels[$i];
    }

    $extra=[];
    foreach($actual as $i=>$k){
        if($k!=="" && !in_array($k,$expected,true)) $extra[]=$actualRaw[$i] ?: "Column ".($i+1);
    }

    $duplicates=[];
    foreach(array_count_values(array_filter($actual)) as $k=>$count){
        if($count>1 && in_array($k,$expected,true)){
            $idx=array_search($k,$expected,true);
            $duplicates[]=$labels[$idx];
        }
    }

    $actualCount=count(array_filter($actualRaw,static fn($v)=>$v!==""));
    $expectedCount=count($expected);

    if($actualCount!==$expectedCount || $missing || $extra || $duplicates){
        $message="Upload stopped. ".($isCatalogue?"Auction Catalogue":"Auction Results").
            " requires exactly {$expectedCount} columns, but {$actualCount} were found.";
        if($missing) $message.=" Missing column(s): ".implode(", ",array_unique($missing)).".";
        if($extra) $message.=" Unexpected/exceeding column(s): ".implode(", ",array_unique($extra)).".";
        if($duplicates) $message.=" Duplicate column(s): ".implode(", ",array_unique($duplicates)).".";
        $message.=" Expected columns: ".implode(", ",$labels).".";

        kagera_json(false,$message,[
            "expected_count"=>$expectedCount,
            "actual_count"=>$actualCount,
            "missing_columns"=>array_values(array_unique($missing)),
            "extra_columns"=>array_values(array_unique($extra)),
            "duplicate_columns"=>array_values(array_unique($duplicates)),
            "expected_columns"=>$labels
        ],400);
    }

    foreach($expected as $i=>$k){
        if(($actual[$i] ?? "")!==$k){
            kagera_json(false,
                "Upload stopped. Column ".($i+1)." should be \"".$labels[$i].
                "\" but \"".($actualRaw[$i] ?? "")."\" was found. Please upload the correct ".
                ($isCatalogue?"Auction Catalogue":"Auction Results")." file.",
                ["column_number"=>$i+1,"expected_column"=>$labels[$i],"found_column"=>$actualRaw[$i] ?? ""],
                400
            );
        }
    }
}


function kagera_crud_config($type)
{
    if ($type === "results") {
        return [
            "table" => "public.kagera_auction_results",
            "fields" => [
                "lot_no","auction_no","auction_date","warehouse",
                "warehouse_location_district","kgs","grade","grade2",
                "price","buyer"
            ]
        ];
    }

    if ($type === "catalogue") {
        return [
            "table" => "public.kagera_auction_catalogue",
            "fields" => [
                "lot_no","auction_no","auction_date","union_name",
                "warehouse_name_amcos","warehouse_location_district",
                "kgs","grade","grade2","certification"
            ]
        ];
    }

    throw new RuntimeException("Editing is available only for Auction Results or Auction Catalogue.");
}

function kagera_handle_update_row()
{
    $type = strtolower(trim((string)($_POST["kagera_type"] ?? "")));
    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

    if (!$id || $id < 1) {
        kagera_json(false, "Invalid row selected for editing.", [], 422);
    }

    $config = kagera_crud_config($type);
    $payload = json_decode((string)($_POST["row_data"] ?? ""), true);

    if (!is_array($payload)) {
        kagera_json(false, "Invalid row data.", [], 422);
    }

    // Only accept whitelisted database columns for the selected display.
    $data = [];
    foreach ($config["fields"] as $field) {
        if (array_key_exists($field, $payload)) {
            $data[$field] = is_string($payload[$field])
                ? trim($payload[$field])
                : $payload[$field];
        }
    }

    // These fields must always remain populated.
    foreach (["lot_no", "auction_no", "auction_date", "kgs"] as $required) {
        if (
            !array_key_exists($required, $data) ||
            $data[$required] === "" ||
            $data[$required] === null
        ) {
            kagera_json(
                false,
                ucfirst(str_replace("_", " ", $required)) . " cannot be blank.",
                ["field" => $required],
                422
            );
        }
    }

    if (
        $type === "results" &&
        (
            !array_key_exists("price", $data) ||
            $data["price"] === "" ||
            $data["price"] === null
        )
    ) {
        kagera_json(false, "Price cannot be blank for Auction Results.", ["field" => "price"], 422);
    }

    // Normalize edited date back to the database DATE format.
    $data["auction_date"] = kagera_normalize_date($data["auction_date"]);
    if (!$data["auction_date"]) {
        kagera_json(false, "Auction Date is invalid.", ["field" => "auction_date"], 422);
    }

    // Normalize numeric input. Commas entered by the user are accepted.
    foreach (["kgs", "price"] as $numericField) {
        if (!array_key_exists($numericField, $data)) {
            continue;
        }

        $clean = str_replace([",", " "], "", (string)$data[$numericField]);

        if ($clean === "" || !is_numeric($clean)) {
            kagera_json(
                false,
                ucfirst($numericField) . " must be numeric.",
                ["field" => $numericField],
                422
            );
        }

        $data[$numericField] = (float)$clean;
    }

    if ((float)$data["kgs"] <= 0) {
        kagera_json(false, "Kgs must be greater than zero.", ["field" => "kgs"], 422);
    }

    if ($type === "results" && (float)$data["price"] < 0) {
        kagera_json(false, "Price cannot be negative.", ["field" => "price"], 422);
    }

    /*
     * IMPORTANT:
     * Do not reuse :kgs and :price placeholders inside the Value expression.
     * PostgreSQL/PDO can reject repeated named placeholders. Calculate Value
     * in PHP and bind it once as its own parameter.
     */
    if ($type === "results") {
        $data["value"] = round(
            ((float)$data["kgs"]) * ((float)$data["price"]),
            2
        );
    }

    $sets = [];
    $params = [":id" => (int)$id];

    foreach ($data as $field => $value) {
        // Value is intentionally allowed here only because it was generated
        // internally above, never accepted directly from the browser.
        if ($field === "value" && $type !== "results") {
            continue;
        }

        $placeholder = ":upd_" . $field;
        $sets[] = $field . " = " . $placeholder;
        $params[$placeholder] = $value;
    }

    if (!$sets) {
        kagera_json(false, "No editable values were supplied.", [], 422);
    }

    $db = kagera_db();

    // RETURNING id is more reliable than rowCount() for confirming PostgreSQL UPDATE.
    $sql =
        "UPDATE " . $config["table"] .
        " SET " . implode(", ", $sets) .
        " WHERE id = :id RETURNING id";

    $stmt = $db->prepare($sql);

    try {
        $stmt->execute($params);
        $updatedId = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === "23505") {
            kagera_json(
                false,
                "Update stopped because another row already has the same Lot No., Auction No. and Auction Date.",
                [],
                409
            );
        }

        error_log(
            "Kagera row update database error [type={$type}, id={$id}]: " .
            $e->getMessage()
        );

        kagera_json(
            false,
            "The edited row could not be saved. Database error: " . $e->getMessage(),
            [],
            500
        );
    }

    if (!$updatedId) {
        kagera_json(
            false,
            "The selected database row no longer exists. Refresh the table and try again.",
            [],
            404
        );
    }

    kagera_json(
        true,
        "Row updated successfully.",
        [
            "id" => (int)$updatedId,
            "value" => $type === "results" ? $data["value"] : null
        ]
    );
}

function kagera_handle_delete_row()
{
    $type = strtolower(trim((string)($_POST["kagera_type"] ?? "")));
    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

    if (!$id || $id < 1) {
        kagera_json(false, "Invalid row selected for deletion.", [], 422);
    }

    $config = kagera_crud_config($type);
    $stmt = kagera_db()->prepare("DELETE FROM " . $config["table"] . " WHERE id = :id");
    $stmt->execute([":id" => $id]);

    if ($stmt->rowCount() < 1) {
        kagera_json(false, "The selected row was not found.", [], 404);
    }

    kagera_json(true, "Row deleted permanently from the database.");
}

function kagera_handle_delete_all()
{
    $type = strtolower(trim((string)($_POST["kagera_type"] ?? "")));
    $confirmation = trim((string)($_POST["confirmation"] ?? ""));

    if ($confirmation !== "DELETE ALL") {
        kagera_json(false, 'Type "DELETE ALL" to confirm permanent deletion.', [], 422);
    }

    $config = kagera_crud_config($type);
    $count = (int)kagera_db()->exec("DELETE FROM " . $config["table"]);

    kagera_json(true, number_format($count) . " row(s) permanently deleted.", ["deleted" => $count]);
}

function handle_kagera_upload()
{
    $uploadType = "auction_results";

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
            "auction_date", "auction_date", "sold_date",
            "date_of_sale", "auction_date_date", "date",
            "warehouse", "warehouse_name",
            "warehouse_location_district", "location",
            "kgs", "kgs_kg", "kgs", "kg",
            "grade", "grade2", "price", "buyer", "buyer"
        ];

        if (count(array_intersect($signals, $headers)) >= 2) {
            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {
        kagera_json(false, "The Excel file header row could not be identified.", [], 400);
    }

    $rawHeaders = $rows[$headerIndex];
    kagera_validate_selected_excel_columns($rawHeaders, "results");
    $headers = array_map("kagera_norm", $rawHeaders);

    /*
    |--------------------------------------------------------------------------
    | COLUMN MAPPING
    |--------------------------------------------------------------------------
    | Auction Date from Excel is stored in auction_date.
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

        "auction_date" => kagera_find_col(
            $headers,
            [
                "auction_date",
                "auction_date",
                "sold_date",
                "date_of_sale",
                "auction_date_date",
                "date"
            ]
        ),

        "warehouse" => kagera_find_col(
            $headers,
            ["warehouse_name_amcos", "warehouse_amcos", "warehouse_name", "warehouse"]
        ),

        "warehouse_location_district" => kagera_find_col(
            $headers,
            ["warehouse_location_district", "location", "warehouse_loc"]
        ),

        "kgs" => kagera_find_col(
            $headers,
            ["kgs", "kgs_kg", "net_kg", "kgs", "kg"]
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

        "buyer" => kagera_find_col(
            $headers,
            ["buyer", "buyer_name"]
        )
    ];

    if (
        $col["lot_no"] === null ||
        $col["auction_no"] === null ||
        $col["auction_date"] === null
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
        $date = kagera_normalize_date($get("auction_date"));

        $warehouse = $get("warehouse");
        $location = $get("warehouse_location_district");
        $weight = kagera_number($get("kgs"));
        $grade = $get("grade");
        $grade2 = $get("grade2");
        $price = kagera_number($get("price"));

        // Auction Results has 10 Excel columns. Value is NOT an Excel input:
        // it is generated by the system as Kgs × Price.
        $rawPrice = $get("price");
        $cleanPrice = str_replace([",", " "], "", trim((string)$rawPrice));

        if ($rawPrice === "") {
            kagera_json(
                false,
                "Price cannot be blank in Auction Results. Please correct the Excel file and upload again.",
                ["excel_row" => $r + 1, "field" => "Price"],
                400
            );
        }

        if (!is_numeric($cleanPrice) || (float)$cleanPrice < 0) {
            kagera_json(
                false,
                "Invalid Price in Auction Results at Excel row " . ($r + 1) . ". Price must be numeric.",
                ["excel_row" => $r + 1, "field" => "Price"],
                400
            );
        }

        $price = (float)$cleanPrice;
        $buyer = $get("buyer");

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

        // Weight (Kgs) is mandatory for Auction Results.
        $rawWeightForValidation = $get("kgs");
        $cleanWeightForValidation = str_replace(
            [",", " "],
            "",
            trim((string)$rawWeightForValidation)
        );

        if (trim((string)$rawWeightForValidation) === "") {
            $missingFields[] = "Weight (Kgs)";
        } elseif (
            !is_numeric($cleanWeightForValidation) ||
            (float)$cleanWeightForValidation <= 0
        ) {
            $missingFields[] = "Valid Weight (Kgs) greater than 0";
        } else {
            $weight = (float)$cleanWeightForValidation;
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
            "auction_date" => $date,
            "warehouse" => $warehouse,
            "warehouse_location_district" => $location,
            "kgs" => $weight,
            "grade" => $grade,
            "grade2" => $grade2,
            "price" => $price,
            "buyer" => $buyer,
            // Database-only 11th field: automatically generated.
            "value" => round(((float)$weight) * ((float)$price), 2)
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
        SELECT id, lot_no, auction_no, auction_date
        FROM public.kagera_auction_results
        WHERE lot_no IS NOT NULL
          AND auction_no IS NOT NULL
          AND auction_date IS NOT NULL
    ");

    while ($old = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $key =
            trim((string)$old["lot_no"]) . "\x1F" .
            trim((string)$old["auction_no"]) . "\x1F" .
            $old["auction_date"];

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
                "auction_date" => $records[$key]["auction_date"]
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
                  AND auction_date = :auction_date
            ");

            foreach ($replacementKeys as $key) {
                $old = $existing[$key];

                $deleteStmt->execute([
                    "lot_no" => trim((string)$old["lot_no"]),
                    "auction_no" => trim((string)$old["auction_no"]),
                    "auction_date" => $old["auction_date"]
                ]);
            }
        }

        $insertStmt = $db->prepare("
            INSERT INTO public.kagera_auction_results
            (
                lot_no,
                auction_no,
                auction_date,
                warehouse,
                warehouse_location_district,
                kgs,
                grade,
                grade2,
                price,
                buyer, value)
            VALUES
            (
                :lot_no,
                :auction_no,
                :auction_date,
                :warehouse,
                :warehouse_location_district,
                :kgs,
                :grade,
                :grade2,
                :price,
                :buyer,
                :value)
        ");

        foreach ($records as $record) {
            $insertStmt->execute($record);
        }

        // Keep the database-only Value column authoritative for all Results.
        // This also repairs any older rows whose Value was blank or incorrect.
        $db->exec("
            UPDATE public.kagera_auction_results
            SET value = ROUND(
                (COALESCE(kgs, 0) * COALESCE(price, 0))::numeric,
                2
            )
            WHERE value IS NULL
               OR value <> ROUND(
                    (COALESCE(kgs, 0) * COALESCE(price, 0))::numeric,
                    2
               )
        ");

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
               OR auction_date IS NULL
        ");

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
            id,
            lot_no,
            auction_no,
            auction_date,
            warehouse,
            warehouse_location_district,
            kgs,
            grade,
            grade2,
            price,
            COALESCE(value, COALESCE(kgs, 0) * COALESCE(price, 0)) AS value,
            buyer
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

            $catalogueWhere[] = 'auction_date BETWEEN :c_start AND :c_end';
            $catalogueParams['c_start'] = $start;
            $catalogueParams['c_end'] = $end;

            $resultWhere[] = 'auction_date BETWEEN :r_start AND :r_end';
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
        SELECT grade2, COALESCE(SUM(kgs),0) AS kilos_offered
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
            COALESCE(SUM(kgs),0) AS kilos_sold,
            COALESCE(SUM(kgs * COALESCE(price, 0)), 0) AS total_value,
            MIN(CASE WHEN price > 0 AND kgs > 0 THEN price END) AS lowest_price,
            CASE
                WHEN SUM(CASE WHEN price > 0 AND kgs > 0 THEN kgs ELSE 0 END) > 0
                THEN
                    SUM(
                        CASE
                            WHEN price > 0 AND kgs > 0
                            THEN kgs * price
                            ELSE 0
                        END
                    )
                    /
                    SUM(
                        CASE
                            WHEN price > 0 AND kgs > 0
                            THEN kgs
                            ELSE 0
                        END
                    )
                ELSE NULL
            END AS average_price,
            MAX(CASE WHEN price > 0 AND kgs > 0 THEN price END) AS highest_price
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
            COALESCE(SUM(kgs), 0) AS kilos_offered
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
            COALESCE(SUM(kgs), 0) AS kilos_sold,
            COALESCE(SUM(kgs * COALESCE(price, 0)), 0) AS total_value
        FROM public.kagera_auction_results
        WHERE " . ($resultWhere ? '(' . implode(' AND ', $resultWhere) . ') AND ' : '') . "
              TRIM(CAST(grade AS TEXT)) <> ''
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

        // Ignore underscores when identifying the coffee type:
        // spaces and underscores are treated as the same separator.
        $label = preg_replace('/[\\s_]+/', '_', $label);
        $label = trim($label, '_');

        // Normalize the known coffee-type components.
        $parts = array_values(array_filter(explode('_', $label), function($part) {
            return trim($part) !== '';
        }));

        // Some legacy records may contain the split spelling "Robu_Ta".
        // Treat it as the single canonical type "Robusta".
        $normalizedParts = [];
        $collapsedParts = [];
        for ($i = 0; $i < count($parts); $i++) {
            $partLower = strtolower(trim($parts[$i]));
            if ($partLower === 'robu' && isset($parts[$i + 1]) &&
                strtolower(trim($parts[$i + 1])) === 'ta') {
                $collapsedParts[] = 'Robusta';
                $i++;
            } else {
                $collapsedParts[] = $parts[$i];
            }
        }
        $parts = $collapsedParts;
        foreach ($parts as $part) {
            $p = strtolower(trim($part));

            if ($p === 'arabica') {
                $normalizedParts[] = 'Arabica';
            } elseif ($p === 'robusta' || $p === 'robu' || $p === 'robu-ta' || $p === 'robuta') {
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

        // Case-insensitive and underscore-insensitive key prevents
        // duplicate rows such as Arabica Clean vs Arabica_Clean.
        $key = strtolower(str_replace('_', '', $label));

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
     * Always obtain it from Auction Results (auction_date) for the
     * exact selected Auction No. Never use the browser's current date
     * and never fall back to catalogue date.
     */
    $heldOn = null;

    if ($auction !== '') {
        $heldStmt = $db->prepare("
            SELECT auction_date
            FROM public.kagera_auction_results
            WHERE TRIM(CAST(auction_no AS TEXT)) = :auction
              AND auction_date IS NOT NULL
              AND TRIM(CAST(auction_date AS TEXT)) <> ''
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

    /*
    | Resolve the upload destination from the current Display selection.
    | For POST uploads, never use $_REQUEST or kagera_type because those can
    | contain stale values and invert Catalogue/Results routing.
    */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
        isset($_FILES['kagera_excel'])
    ) {
        $kageraType = strtolower(trim((string)(
            $_POST['upload_destination']
            ?? $_POST['upload_type']
            ?? ''
        )));
    } else {
        $kageraType = strtolower(trim((string)(
            $_GET['kagera_type']
            ?? 'results'
        )));
    }

    if (in_array($kageraType, ['results','result','auction_results','auction_result'], true)) {
        $kageraType = 'results';
    } elseif (in_array($kageraType, ['catalogue','catalog','auction_catalogue','auction_catalog'], true)) {
        $kageraType = 'catalogue';
    } else {
        $kageraType = '';
    }

    $postAction = strtolower(trim((string)($_POST["action"] ?? "")));
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && $postAction === "update_row") {
        kagera_handle_update_row();
    }
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && $postAction === "delete_row") {
        kagera_handle_delete_row();
    }
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && $postAction === "delete_all") {
        kagera_handle_delete_all();
    }

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
        if (!in_array($kageraType, ['results', 'catalogue'], true)) {
            kagera_json(false, 'Select Auction Results or Auction Catalogue before uploading.', [], 422);
        }
        if ($kageraType === 'catalogue') {
            handle_kagera_catalogue_upload(); // public.kagera_auction_catalogue
        } elseif ($kageraType === 'results') {
            handle_kagera_upload(); // public.kagera_auction_results
        } else {
            kagera_json(false, 'Invalid upload destination.', [], 422);
        }
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

.kagera-report-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
    flex: 0 0 auto;
}

.kagera-export-wrap {
    position: relative;
    display: none;
    flex: 0 0 auto;
    margin-left: 8px;
    z-index: 2000;
}


.kagera-export-wrap {
    position: relative;
    flex: 0 0 auto;
    align-items: center;
    visibility: visible;
    opacity: 1;
    z-index: 1100;
}
.kagera-export-wrap.kagera-export-hidden {
    display: none !important;
}
.kagera-export-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-width: 38px;
    height: 36px;
    padding: 0 11px;
    border: 1px solid #d8d0cc;
    border-radius: 7px;
    background: #fff;
    color: #3e2723;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}

.kagera-export-btn:hover,
.kagera-export-btn:focus-visible {
    background: #f7f3f1;
    border-color: #b9aaa3;
    box-shadow: 0 2px 7px rgba(62,39,35,.10);
    outline: none;
}

.kagera-export-icon {
    font-size: 17px;
    line-height: 1;
}

.kagera-export-menu {
    pointer-events: auto;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 1000;
    min-width: 150px;
    padding: 5px;
    border: 1px solid #e1d9d5;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(62,39,35,.16);
}

.kagera-export-menu button {
    display: block;
    width: 100%;
    padding: 9px 11px;
    border: 0;
    border-radius: 5px;
    background: transparent;
    color: #3e2723;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.kagera-export-menu button:hover,
.kagera-export-menu button:focus-visible {
    background: #f5f1ef;
    outline: none;
}

@media (max-width: 700px) {
    .kagera-report-header {
        align-items: flex-start;
        gap: 10px;
    }
}

.kagera-report-table-wrap {
    width: 100%;
    overflow-x: hidden;
    overflow-y: visible;
    max-height: none;
}

.kagera-report-table {
    width: 100%;
    min-width: 0;
    table-layout: fixed;
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
    white-space: normal;
    overflow-wrap: anywhere;
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


.kagera-loading-state {
    text-align: center;
    padding: 28px 16px;
    font-weight: 600;
    opacity: .75;
}

.kagera-settings-wrap{position:relative;display:inline-flex;align-items:center}
.kagera-settings-btn{width:38px;height:38px;border:1px solid #d8d1c7;border-radius:9px;background:#fff;color:#49372a;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.kagera-settings-btn:hover{background:#f6f2ed}
.kagera-settings-menu{position:absolute;right:0;top:44px;z-index:80;min-width:210px;padding:7px;background:#fff;border:1px solid #ded7cf;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14)}
.kagera-settings-menu button{display:block;width:100%;border:0;background:transparent;text-align:left;padding:10px 11px;border-radius:7px;cursor:pointer;color:#342820;font-weight:600}
.kagera-settings-menu button:hover{background:#f5f1ec}
.kagera-settings-menu button.danger{color:#9b2c2c}
.kagera-row-actions{white-space:nowrap}
.kagera-row-actions button{border:1px solid #d8d1c7;background:#fff;border-radius:6px;padding:5px 8px;margin:0 2px;cursor:pointer}
.kagera-row-actions .delete{color:#9b2c2c}
.kagera-edit-input{width:100%;min-width:80px;padding:6px 7px;border:1px solid #cfc7bd;border-radius:5px;font:inherit}


.kagera-actions-head,.kagera-row-actions{display:none}
body.kagera-edit-mode .kagera-actions-head,
body.kagera-edit-mode .kagera-row-actions{display:table-cell}
.kagera-row-actions{text-align:center;white-space:nowrap;min-width:88px}
.kagera-action-icon{width:31px;height:29px;display:inline-flex;align-items:center;justify-content:center;padding:0!important;margin:0 2px!important;border:1px solid #d8d1c7!important;border-radius:7px!important;background:#fff!important;cursor:pointer;font-size:15px}
.kagera-action-icon.edit{color:#5b432f}
.kagera-action-icon.edit:hover{background:#f5f0e9!important}
.kagera-action-icon.delete{color:#a12e2e}
.kagera-action-icon.delete:hover{background:#fff0f0!important}
.kagera-action-icon.save{color:#28613e}
.kagera-action-icon.save:hover{background:#eef8f1!important}
.kagera-action-icon:disabled{opacity:.45;cursor:not-allowed}


.kagera-expected-columns{display:none;position:relative;right:auto;top:auto;z-index:1;width:100%;padding:11px 12px;border:1px solid #ded6cd;border-radius:9px;background:#fff;box-shadow:0 12px 30px rgba(62,39,35,.14)}
.kagera-file-area{position:relative}
.kagera-expected-columns.show{display:block}
.kagera-expected-columns-title{display:flex;align-items:flex-start;gap:8px;margin-bottom:8px}
.kagera-schema-icon{width:26px;height:26px;flex:0 0 26px;display:flex;align-items:center;justify-content:center;border-radius:6px;background:#eee7df;color:#5d4037;font-size:14px}
.kagera-expected-columns-title strong{display:block;color:#3e2723;font-size:11px;line-height:1.3}
.kagera-expected-columns-title small{display:block;margin-top:2px;color:#81766f;font-size:9px;line-height:1.4}
.kagera-column-chips{display:flex;flex-wrap:wrap;gap:5px}
.kagera-column-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border:1px solid #e1d9d1;border-radius:999px;background:#fbfaf8;color:#4d4039;font-size:9px;font-weight:600;white-space:nowrap}
.kagera-column-chip .n{display:inline-flex;align-items:center;justify-content:center;min-width:15px;height:15px;padding:0 3px;border-radius:50%;background:#ede5dd;color:#604a3b;font-size:8px}
.kagera-schema-note{margin-top:8px;padding-top:7px;border-top:1px solid #eee8e2;color:#766a63;font-size:9px;line-height:1.45}
.kagera-schema-note strong{color:#4f3b30}


.kagera-settings-upload-panel{
    position:absolute;
    right:0;
    top:calc(100% + 8px);
    z-index:150;
    width:min(610px,92vw);
    padding:13px;
    border:1px solid #ddd4cb;
    border-radius:11px;
    background:#fff;
    box-shadow:0 16px 38px rgba(45,31,24,.16);
}
.kagera-results-actions{position:relative}
.kagera-settings-upload-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;padding-bottom:9px;border-bottom:1px solid #eee8e2}
.kagera-settings-upload-head strong{display:block;color:#3e2723;font-size:12px}
.kagera-settings-upload-head small{display:block;margin-top:3px;color:#81766f;font-size:9px}
.kagera-upload-panel-close{width:28px;height:28px;border:0;border-radius:7px;background:#f5f1ed;color:#66554a;font-size:18px;line-height:1;cursor:pointer}
.kagera-upload-panel-close:hover{background:#ece5de}
.kagera-settings-upload-panel .kagera-header-upload-form{display:flex;align-items:flex-start;gap:8px}
.kagera-settings-upload-panel .kagera-file-area{flex:1 1 auto;min-width:0}
.kagera-settings-upload-panel .kagera-upload-btn{flex:0 0 auto}
@media(max-width:720px){
 .kagera-settings-upload-panel{right:-4px;width:min(94vw,610px)}
 .kagera-settings-upload-panel .kagera-header-upload-form{flex-wrap:wrap}
 .kagera-settings-upload-panel .kagera-file-area{flex:1 1 100%}
 .kagera-settings-upload-panel .kagera-upload-btn{width:100%}
}


.kagera-upload-status{
    margin-top:10px;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid transparent;
    font-size:10px;
    line-height:1.45;
    font-weight:600;
}
.kagera-upload-status.show{display:flex!important;align-items:flex-start;gap:8px}
.kagera-upload-status::before{font-size:13px;line-height:1.1;flex:0 0 auto}
.kagera-upload-status.info{background:#f6f3ef;border-color:#ded5cc;color:#594a40}
.kagera-upload-status.info::before{content:"ℹ"}
.kagera-upload-status.success{background:#eef8f1;border-color:#bfddc8;color:#285c39}
.kagera-upload-status.success::before{content:"✓"}
.kagera-upload-status.error{background:#fff1f0;border-color:#efc5c1;color:#8c2f29}
.kagera-upload-status.error::before{content:"!"}
.kagera-upload-status.warning{background:#fff8e8;border-color:#ead7a4;color:#775a16}
.kagera-upload-status.warning::before{content:"!"}

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

        

        <button
            type="button"
            class="kagera-refresh-btn"
            onclick="loadKageraResults({force:true})"
        >
            <span>↻</span> Refresh
        </button>
<div class="kagera-settings-wrap" id="kageraSettingsWrap">
    <button type="button"
            class="kagera-settings-btn"
            id="kageraSettingsBtn"
            aria-label="Data settings"
            title="Data settings">⚙</button>

    <div class="kagera-settings-menu" id="kageraSettingsMenu" style="display:none;">
        <button type="button" id="kageraSettingsUploadBtn">↑ Upload data</button>
        <button type="button" id="kageraEditModeBtn">✎ Edit selected display</button>
        <button type="button" id="kageraDeleteAllBtn" class="danger">⌫ Delete all data</button>
    </div>
</div>

<div class="kagera-settings-upload-panel" id="kageraSettingsUploadPanel" style="display:none;">
    <div class="kagera-settings-upload-head">
        <div>
            <strong id="kageraSettingsUploadTitle">Upload Auction Results</strong>
            <small id="kageraSettingsUploadHint">Select the required Excel file.</small>
        </div>
        <button type="button" class="kagera-upload-panel-close" id="kageraUploadPanelClose" aria-label="Close upload panel">×</button>
    </div>
<form
                id="kageraUploadForm"
                action="kagera_auction.php"
                method="POST"
                enctype="multipart/form-data"
                class="kagera-header-upload-form"
            >
                <input type="hidden" id="kageraUploadType" name="upload_type" value="results">
                

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

                    <div id="kageraExpectedColumns" class="kagera-expected-columns" aria-live="polite">
                        <div class="kagera-expected-columns-title">
                            <span class="kagera-schema-icon">▦</span>
                            <div>
                                <strong id="kageraExpectedTitle">Expected Excel Columns</strong>
                                <small id="kageraExpectedSubtitle"></small>
                            </div>
                        </div>
                        <div id="kageraExpectedColumnList" class="kagera-column-chips"></div>
                        <div id="kageraExpectedNote" class="kagera-schema-note"></div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="kagera-upload-btn"
                    id="kageraUploadButton"
                >
                    <span>↑</span> Upload
                </button>
            </form>

    <div id="kageraUploadStatus"
         class="kagera-upload-status"
         role="status"
         aria-live="polite"
         aria-atomic="true"
         style="display:none;"></div>
</div>



        
<div class="kagera-export-wrap" id="kageraHighLowExportWrap" style="display:none;">
    <button type="button"
            class="kagera-export-btn"
            id="kageraHighLowExportBtn"
            aria-label="Export High &amp; Low"
            title="Export High &amp; Low">
        <span class="kagera-export-icon" aria-hidden="true">⇩</span>
        <span>Export</span>
    </button>
    <div class="kagera-export-menu" id="kageraHighLowExportMenu" style="display:none;">
        <button type="button" data-kagera-export="pdf">Download PDF</button>
        <button type="button" data-kagera-export="excel">Download Excel</button>
        <button type="button" data-kagera-export="word">Download Word</button>
    </div>
</div>


    </div>

</div>

<div class="kagera-table-wrap">

<table id="kageraResultsTable" class="kagera-results-table">
<thead><tr>
<th>Lot No</th><th>Auction No.</th><th>Auction Date</th><th>Warehouse Name/Amcos</th>
<th>Warehouse Location/District</th><th>Kgs</th><th>Grade</th>
<th>Grade2</th><th>Price</th><th>Value</th><th>Buyer</th><th class="kagera-actions-head">Actions</th>
</tr></thead>
<tbody id="kageraResultsBody">
<tr><td colspan="11" class="kagera-empty-state">No Kagera Auction results loaded.</td></tr>
</tbody>
</table>

<table id="kageraCatalogueTable" class="kagera-results-table" style="display:none;">
<thead><tr>
<th>Lot No.</th><th>Auction No.</th><th>Auction Date</th><th>Union</th>
<th>Warehouse name/Amcos</th><th>Warehouse Location/District</th>
<th>Kgs</th><th>Grade</th><th>Grade2</th><th>Certification</th><th class="kagera-actions-head">Actions</th>
</tr></thead>
<tbody id="kageraCatalogueBody">
<tr><td colspan="10" class="kagera-empty-state">No Kagera Catalogue loaded.</td></tr>
</tbody>
</table>

<div id="kageraReportPanel" class="kagera-report-panel" style="display:none;">

    <div class="kagera-report-header" id="kageraGenericReportHeader">
        <div>
            <h3 id="kageraReportTitle">High &amp; Low</h3>
            <p id="kageraReportSubtitle">Kagera Coffee Exchange auction report.</p>
        </div>

        <div class="kagera-report-header-actions" id="kageraReportHeaderActions">

            <button
            type="button"
            class="kagera-refresh-btn"
            onclick="loadKageraResults({force:true})"
        >
            <span>↻</span> Refresh
        </button>

            
        </div>
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

<!-- Export libraries used only by the High & Low report. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>

function kageraFormatNumber(value, maxDecimals = 4)
{
    if (value === null || value === undefined || value === "") return "";

    const number = Number(String(value).replace(/,/g, ""));
    if (!Number.isFinite(number)) return escapeKageraHtml(value);

    return number.toLocaleString("en-US", {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxDecimals
    });
}



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


function kageraSyncUploadWithDisplay()
{
    const display = document.getElementById("kageraDisplayType");
    const uploadTypeInput = document.getElementById("kageraUploadType");
    const fileName = document.getElementById("kageraFileName");
    const uploadButton = document.getElementById("kageraUploadButton");

    const rawValue = String(display ? display.value : "").trim().toLowerCase();

    // Canonical mapping:
    // Display "results"   -> Results upload/validator/table
    // Display "catalogue" -> Catalogue upload/validator/table
    let uploadType = "";

    if (
        rawValue === "results" ||
        rawValue === "result" ||
        rawValue === "auction_results" ||
        rawValue === "auction_result"
    ) {
        uploadType = "results";
    } else if (
        rawValue === "catalogue" ||
        rawValue === "catalog" ||
        rawValue === "auction_catalogue" ||
        rawValue === "auction_catalog"
    ) {
        uploadType = "catalogue";
    }

    if (uploadTypeInput) {
        uploadTypeInput.value = uploadType;
    }

    if (fileName) {
        fileName.textContent =
            uploadType === "results"
                ? "Select Auction Results file"
                : uploadType === "catalogue"
                    ? "Select Auction Catalogue file"
                    : "Select Auction Results or Catalogue from Display";
    }

    if (uploadButton) {
        uploadButton.disabled = uploadType === "";

        if (uploadType === "results") {
            uploadButton.innerHTML = "<span>↑</span> Upload Auction Results";
            uploadButton.title = "Upload Auction Results";
        } else if (uploadType === "catalogue") {
            uploadButton.innerHTML = "<span>↑</span> Upload Catalogue";
            uploadButton.title = "Upload Auction Catalogue";
        } else {
            uploadButton.innerHTML = "<span>↑</span> Upload";
            uploadButton.title = "Select Auction Results or Auction Catalogue first";
        }
    }

    return uploadType;
}



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

            if (kageraUploadStatus) {
                kageraUploadStatus.style.display = "none";
                kageraUploadStatus.className = "kagera-upload-status";
                kageraUploadStatus.textContent = "";
            }
        }
    );
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

function showKageraStatus(message, type = "info")
{
    if (!kageraUploadStatus) return;

    const allowed = ["info", "success", "error", "warning"];
    const statusType = allowed.includes(type) ? type : "info";

    kageraUploadStatus.textContent = String(message || "");
    kageraUploadStatus.className = "kagera-upload-status show " + statusType;
    kageraUploadStatus.style.display = "flex";

    // Keep upload outcome visible. It is cleared only when a new upload starts,
    // the upload panel is closed, or another file is selected.
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

        let details = "";
        if (result && result.data && Array.isArray(result.data.errors) && result.data.errors.length) {
            details = " " + result.data.errors.slice(0, 5).join(" ");
            if (result.data.errors.length > 5) {
                details += " Additional validation errors were also found.";
            }
        }

        throw new Error(
            (result.message || "Request failed.") + details
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

            // Resolve destination directly from the current Display selection.
            // Never infer it from a previous upload or hidden-field state.
            const selectedUploadType = kageraSyncUploadWithDisplay();

            if (kageraUploadType) {
                kageraUploadType.value = selectedUploadType;
            }

            if (!selectedUploadType) {
                showKageraStatus("Select Auction Results or Auction Catalogue from Display before uploading.", "error");
                return;
            }

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

                formData.delete("kagera_type");
                formData.delete("upload_type");
                formData.set("upload_type", selectedUploadType);
                formData.set("upload_destination", selectedUploadType);

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
                "Checking the selected file and validating its records...",
                "info"
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
                            "Upload stopped. No records were saved or changed.",
                            "warning"
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

                kageraInvalidateDataCache();
                        await loadKageraResults({force:true});

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
                kageraSyncUploadWithDisplay();
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

/*
|--------------------------------------------------------------------------
| FETCH / RENDER PERFORMANCE STATE
|--------------------------------------------------------------------------
| Keep one in-memory copy of each dataset, prevent duplicate requests,
| cancel stale requests, and prevent an older response from rendering over
| a newer selection. This is especially important on large Kagera datasets.
|--------------------------------------------------------------------------
*/
const kageraDataCache = {
    results: { loaded: false, rows: [], promise: null, controller: null },
    catalogue: { loaded: false, rows: [], promise: null, controller: null }
};

let kageraActiveDataRequest = 0;
let kageraActiveReportRequest = 0;
let kageraReportController = null;
const kageraReportCache = new Map();

function kageraNextFrame(callback)
{
    if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(callback);
    } else {
        setTimeout(callback, 0);
    }
}

function kageraSetLoading(body, colspan, message = "Loading records…")
{
    if (!body) return;
    body.innerHTML =
        '<tr><td colspan="' + colspan + '" class="kagera-empty-state kagera-loading-state">' +
        escapeKageraHtml(message) +
        '</td></tr>';
}

function kageraInvalidateDataCache(type = null)
{
    const types = type ? [type] : ["results", "catalogue"];
    types.forEach(function(key) {
        const cache = kageraDataCache[key];
        if (!cache) return;
        if (cache.controller) cache.controller.abort();
        cache.loaded = false;
        cache.rows = [];
        cache.promise = null;
        cache.controller = null;
    });

    if (!type || type === "results") {
        kageraAllResults = [];
        kageraResultsLoaded = false;
    }
    if (!type || type === "catalogue") {
        kageraCatalogueData = [];
    }

    kageraReportCache.clear();
}


function kageraPopulateSeasonFilterFromRows(rows)
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    if (!seasonSelect) return;

    const seasons = new Set();

    (rows || []).forEach(function(row) {
        const season = kageraGetSeason(row.auction_date);
        if (season) seasons.add(String(season));
    });

    const sorted = Array.from(seasons).sort(function(a, b) {
        const yearA = Number(String(a).split("/")[0]);
        const yearB = Number(String(b).split("/")[0]);
        return yearB - yearA;
    });

    const currentValue = seasonSelect.value;
    const optionsHtml = ['<option value="">Select Season</option>']
        .concat(sorted.map(function(season) {
            return '<option value="' + escapeKageraHtml(season) + '">' +
                   escapeKageraHtml(season) + '</option>';
        }))
        .join("");

    if (seasonSelect.innerHTML !== optionsHtml) {
        seasonSelect.innerHTML = optionsHtml;
    }

    if (sorted.indexOf(currentValue) >= 0) {
        seasonSelect.value = currentValue;
    } else if (!currentValue) {
        seasonSelect.value = "";
    }
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

    // Build the auction list from the currently loaded results and, when
    // selected, restrict it to the chosen season. This also guarantees that
    // `sorted` is defined before it is used below.
    const auctions = new Set();

    (rows || []).forEach(function(row) {
        const auction = String(row.auction_no ?? "").trim();
        if (!auction) return;

        if (selectedSeason) {
            const rowSeason = kageraGetSeason(row.auction_date);
            if (rowSeason !== selectedSeason) return;
        }

        auctions.add(auction);
    });

    const sorted = Array.from(auctions).sort(function(a, b) {
        const numberA = kageraAuctionNumber(a);
        const numberB = kageraAuctionNumber(b);

        if (numberA !== null && numberB !== null && numberA !== numberB) {
            return numberB - numberA;
        }

        if (numberA !== null && numberB === null) return -1;
        if (numberA === null && numberB !== null) return 1;

        return String(b).localeCompare(String(a), undefined, {
            numeric: true,
            sensitivity: "base"
        });
    });

    const currentAuction = auctionSelect.value;
    const auctionOptionsHtml = ['<option value="">Select Auction</option>']
        .concat(sorted.map(function(auction) {
            return '<option value="' + escapeKageraHtml(auction) + '">Auction ' +
                   escapeKageraHtml(auction) + '</option>';
        }))
        .join("");

    if (auctionSelect.innerHTML !== auctionOptionsHtml) {
        auctionSelect.innerHTML = auctionOptionsHtml;
    }

    auctionSelect.disabled = sorted.length === 0;
    if (sorted.indexOf(currentAuction) >= 0) {
        auctionSelect.value = currentAuction;
    } else {
        auctionSelect.value = "";
    }
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
            kageraGetSeason(row.auction_date);

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
    const body = document.getElementById("kageraResultsBody");
    if (!body) return;

    const sortedRows = kageraSortResults(rows);

    if (!sortedRows.length) {
        body.innerHTML =
            '<tr><td colspan="12" class="kagera-empty-state">' +
            'No Kagera Auction results found for the selected filters.' +
            '</td></tr>';
        return;
    }

    body.innerHTML = sortedRows.map(function(row) {
        const actions = kageraEditMode
            ? '<td class="kagera-row-actions">' +
              '<button type="button" class="kagera-action-icon edit" data-action="edit" data-row-id="' + escapeKageraHtml(row.id ?? "") + '" title="Edit row" aria-label="Edit row">✎</button>' +
              '<button type="button" class="kagera-action-icon delete" data-action="delete" data-row-id="' + escapeKageraHtml(row.id ?? "") + '" title="Delete row" aria-label="Delete row">⌫</button>' +
              '</td>'
            : '<td class="kagera-row-actions"></td>';

        return '<tr data-row-id="' + escapeKageraHtml(row.id ?? "") + '">' +
            '<td data-field="lot_no" data-raw="' + escapeKageraHtml(row.lot_no ?? "") + '">' + escapeKageraHtml(row.lot_no ?? "") + '</td>' +
            '<td data-field="auction_no" data-raw="' + escapeKageraHtml(row.auction_no ?? "") + '">' + escapeKageraHtml(row.auction_no ?? "") + '</td>' +
            '<td data-field="auction_date" data-raw="' + escapeKageraHtml(row.auction_date ?? "") + '">' + escapeKageraHtml(formatKageraDate(row.auction_date ?? "")) + '</td>' +
            '<td data-field="warehouse" data-raw="' + escapeKageraHtml(row.warehouse ?? "") + '">' + escapeKageraHtml(row.warehouse ?? "") + '</td>' +
            '<td data-field="warehouse_location_district" data-raw="' + escapeKageraHtml(row.warehouse_location_district ?? "") + '">' + escapeKageraHtml(row.warehouse_location_district ?? "") + '</td>' +
            '<td data-field="kgs" data-raw="' + escapeKageraHtml(row.kgs ?? "") + '">' + kageraFormatNumber(row.kgs ?? "",4) + '</td>' +
            '<td data-field="grade" data-raw="' + escapeKageraHtml(row.grade ?? "") + '">' + escapeKageraHtml(row.grade ?? "") + '</td>' +
            '<td data-field="grade2" data-raw="' + escapeKageraHtml(row.grade2 ?? "") + '">' + escapeKageraHtml(row.grade2 ?? "") + '</td>' +
            '<td data-field="price" data-raw="' + escapeKageraHtml(row.price ?? "") + '">' + kageraFormatNumber(row.price ?? "",4) + '</td>' +
            '<td data-field="value" data-raw="' + escapeKageraHtml(row.value ?? "") + '">' + kageraFormatNumber(row.value ?? "",2) + '</td>' +
            '<td data-field="buyer" data-raw="' + escapeKageraHtml(row.buyer ?? "") + '">' + escapeKageraHtml(row.buyer ?? "") + '</td>' +
            actions +
            '</tr>';
    }).join("");
}

function kageraGetFilteredCatalogue()
{
    const seasonSelect = document.getElementById("kageraSeasonFilter");
    const auctionSelect = document.getElementById("kageraAuctionFilter");

    const selectedSeason = seasonSelect ? seasonSelect.value : "";
    const selectedAuction = auctionSelect ? auctionSelect.value : "";

    return kageraCatalogueData.filter(function(row) {
        const rowSeason = kageraGetSeason(row.auction_date);
        const rowAuction = String(row.auction_no ?? "").trim();

        return (!selectedSeason || rowSeason === selectedSeason) &&
               (!selectedAuction || rowAuction === selectedAuction);
    });
}

function kageraRenderCatalogue(rows)
{
    const body = document.getElementById("kageraCatalogueBody");
    if (!body) return;

    const sorted = rows.slice().sort(function(a,b){
        const aa=kageraAuctionNumber(a.auction_no), ab=kageraAuctionNumber(b.auction_no);
        if(aa!==null && ab!==null && aa!==ab) return ab-aa;
        const la=kageraAuctionNumber(a.lot_no), lb=kageraAuctionNumber(b.lot_no);
        if(la!==null && lb!==null && la!==lb) return la-lb;
        return String(a.lot_no??"").localeCompare(String(b.lot_no??""),undefined,{numeric:true,sensitivity:"base"});
    });

    if(!sorted.length){
        body.innerHTML='<tr><td colspan="11" class="kagera-empty-state">No Kagera Catalogue records found for the selected filters.</td></tr>';
        return;
    }

    body.innerHTML=sorted.map(function(row){
        const actions = kageraEditMode
            ? '<td class="kagera-row-actions">' +
              '<button type="button" class="kagera-action-icon edit" data-action="edit" data-row-id="' + escapeKageraHtml(row.id ?? "") + '" title="Edit row" aria-label="Edit row">✎</button>' +
              '<button type="button" class="kagera-action-icon delete" data-action="delete" data-row-id="' + escapeKageraHtml(row.id ?? "") + '" title="Delete row" aria-label="Delete row">⌫</button>' +
              '</td>'
            : '<td class="kagera-row-actions"></td>';

        return '<tr data-row-id="' + escapeKageraHtml(row.id ?? "") + '">' +
            '<td data-field="lot_no" data-raw="' + escapeKageraHtml(row.lot_no??"") + '">' + escapeKageraHtml(row.lot_no??"") + '</td>' +
            '<td data-field="auction_no" data-raw="' + escapeKageraHtml(row.auction_no??"") + '">' + escapeKageraHtml(row.auction_no??"") + '</td>' +
            '<td data-field="auction_date" data-raw="' + escapeKageraHtml(row.auction_date??"") + '">' + escapeKageraHtml(formatKageraDate(row.auction_date??"")) + '</td>' +
            '<td data-field="union_name" data-raw="' + escapeKageraHtml(row.union_name??"") + '">' + escapeKageraHtml(row.union_name??"") + '</td>' +
            '<td data-field="warehouse_name_amcos" data-raw="' + escapeKageraHtml(row.warehouse_name_amcos??"") + '">' + escapeKageraHtml(row.warehouse_name_amcos??"") + '</td>' +
            '<td data-field="warehouse_location_district" data-raw="' + escapeKageraHtml(row.warehouse_location_district??"") + '">' + escapeKageraHtml(row.warehouse_location_district??"") + '</td>' +
            '<td data-field="kgs" data-raw="' + escapeKageraHtml(row.kgs??"") + '">' + kageraFormatNumber(row.kgs??"",4) + '</td>' +
            '<td data-field="grade" data-raw="' + escapeKageraHtml(row.grade??"") + '">' + escapeKageraHtml(row.grade??"") + '</td>' +
            '<td data-field="grade2" data-raw="' + escapeKageraHtml(row.grade2??"") + '">' + escapeKageraHtml(row.grade2??"") + '</td>' +
            '<td data-field="certification" data-raw="' + escapeKageraHtml(row.certification??"") + '">' + escapeKageraHtml(row.certification??"") + '</td>' +
            actions +
            '</tr>';
    }).join("");
}

async function loadKageraData(type, options = {})
{
    const isCatalogue = type === "catalogue";
    const body = document.getElementById(
        isCatalogue ? "kageraCatalogueBody" : "kageraResultsBody"
    );
    if (!body) return [];

    const cache = kageraDataCache[type];
    if (!cache) return [];

    const force = options.force === true;

    /* Reuse already fetched data instead of downloading the same large JSON again. */
    if (!force && cache.loaded) {
        if (isCatalogue) {
            kageraCatalogueData = cache.rows;
            kageraPopulateSeasonFilterFromRows(cache.rows);
            kageraPopulateAuctionFilterFromRows(cache.rows);
            kageraNextFrame(function() {
                kageraRenderCatalogue(kageraGetFilteredCatalogue());
            });
        } else {
            kageraAllResults = cache.rows;
            kageraResultsLoaded = true;
            kageraPopulateSeasonFilterFromRows(cache.rows);
            kageraPopulateAuctionFilterFromRows(cache.rows);
            kageraNextFrame(function() {
                kageraRenderResults(kageraGetFilteredResults());
            });
        }
        return cache.rows;
    }

    /* If the same request is already running, all callers share it. */
    if (!force && cache.promise) {
        return cache.promise;
    }

    if (cache.controller) {
        cache.controller.abort();
    }

    const controller = new AbortController();
    cache.controller = controller;
    const requestId = ++kageraActiveDataRequest;
    const colspan = isCatalogue ? 13 : 10;

    kageraSetLoading(body, colspan, "Loading records…");

    cache.promise = (async function() {
        try {
            const response = await fetch(
                "kagera_auction.php?action=fetch&kagera_type=" + encodeURIComponent(type),
                {
                    cache: "no-store",
                    credentials: "same-origin",
                    signal: controller.signal,
                    headers: { "Accept": "application/json" }
                }
            );

            const result = await readKageraJson(response);
            if (!result || result.success === false) {
                throw new Error(result?.message || "Unable to load records.");
            }

            const rows = Array.isArray(result.data) ? result.data : [];

            /* Never let a stale response repaint the current screen. */
            if (requestId !== kageraActiveDataRequest || controller.signal.aborted) {
                return rows;
            }

            cache.rows = rows;
            cache.loaded = true;

            if (isCatalogue) {
                kageraCatalogueData = rows;
                kageraPopulateSeasonFilterFromRows(rows);
                const auctionSelect = document.getElementById("kageraAuctionFilter");
                if (auctionSelect) auctionSelect.value = "";
                kageraPopulateAuctionFilterFromRows(rows);
                kageraNextFrame(function() {
                    kageraRenderCatalogue(kageraGetFilteredCatalogue());
                });
            } else {
                kageraAllResults = rows;
                kageraResultsLoaded = true;
                kageraPopulateSeasonFilterFromRows(rows);
                const auctionSelect = document.getElementById("kageraAuctionFilter");
                if (auctionSelect) auctionSelect.value = "";
                kageraPopulateAuctionFilterFromRows(rows);
                kageraNextFrame(function() {
                    kageraRenderResults(kageraGetFilteredResults());
                });
            }

            return rows;
        } catch (error) {
            if (error && error.name === "AbortError") {
                return cache.rows;
            }

            if (requestId === kageraActiveDataRequest && body) {
                body.innerHTML =
                    '<tr><td colspan="' + colspan + '" class="kagera-empty-state">' +
                    escapeKageraHtml(error.message || "Unable to load records.") +
                    "</td></tr>";
            }
            throw error;
        } finally {
            if (cache.controller === controller) cache.controller = null;
            if (cache.promise) cache.promise = null;
        }
    })();

    return cache.promise;
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
        const rowSeason = kageraGetSeason(row.auction_date);

        return (!season || rowSeason === season) &&
               (!auction || rowAuction === auction);
    });

    const results = kageraAllResults.filter(function(row) {
        const rowAuction = String(row.auction_no ?? "").trim();
        const rowSeason = kageraGetSeason(row.auction_date);

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

        groups[type].offered += kageraNumber(row.kgs);
    });

    /*
     * Sold Kgs and prices come ONLY from Auction Results.
     * We calculate value as sold kg x price because the Kagera results
     * table stores Net Weight and Price.
     */
    selected.results.forEach(function(row) {
        const type = kageraCoffeeType(row);
        if (!groups[type]) return;

        const kg = kageraNumber(row.kgs);
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
            "<td>" + (g.prices.length ? kageraReportMoney(g.low) : "-") + "</td>" + "<td>" + kageraReportMoney(kageraNumber(row.kgs) * kageraNumber(row.price)) + "</td>" +
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

function kageraCloseHighLowExportMenu()
{
    const menu = document.getElementById("kageraHighLowExportMenu");
    if (menu) menu.style.display = "none";
}

function kageraHighLowFileBase()
{
    const auction = String(
        document.getElementById("kageraAuctionFilter")?.value || ""
    ).trim() || "Selected_Auction";
    const season = String(
        document.getElementById("kageraSeasonFilter")?.value || ""
    ).trim().replace(/[^A-Za-z0-9_-]+/g, "_") || "Season";
    return "Kagera_High_and_Low_Auction_" + auction + "_" + season;
}

function kageraDownloadBlob(blob, filename)
{
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
}


function kageraGetHighLowExportData()
{
    const report = document.getElementById("kageraHighLowReport");

    if (!report) {
        throw new Error("The High & Low report is not available for export.");
    }

    const salesRows = Array.from(
        document.querySelectorAll("#kageraHighLowSalesBody tr")
    ).map(function(tr) {
        return Array.from(tr.cells).map(function(td) {
            return String(td.textContent || "").trim();
        });
    }).filter(function(row) {
        return row.length > 0;
    });

    const priceRows = Array.from(
        document.querySelectorAll("#kageraHighLowPricesBody tr")
    ).map(function(tr) {
        return Array.from(tr.cells).map(function(td) {
            return String(td.textContent || "").trim();
        });
    }).filter(function(row) {
        return row.length > 0;
    });

    if (!salesRows.length && !priceRows.length) {
        throw new Error("The High & Low report has no populated data to export.");
    }

    return {
        title: "KAGERA COFFEE EXCHANGE",
        subtitle: String(
            document.getElementById("kageraHLSubtitle")?.textContent || ""
        ).trim(),
        heldOn: String(
            document.getElementById("kageraHLHeldOn")?.textContent || ""
        ).trim(),
        salesRows: salesRows,
        priceRows: priceRows,
        percentageDry: String(
            document.getElementById("kageraHLPercentageDry")?.textContent || ""
        ).trim(),
        percentageClean: String(
            document.getElementById("kageraHLPercentageClean")?.textContent || ""
        ).trim()
    };
}

function kageraLoadScriptOnce(src)
{
    return new Promise(function(resolve, reject) {
        const existing = document.querySelector(
            'script[data-kagera-export-src="' + src + '"]'
        );

        if (existing) {
            if (existing.dataset.loaded === "1") {
                resolve();
                return;
            }

            existing.addEventListener("load", function() {
                resolve();
            }, {once:true});

            existing.addEventListener("error", function() {
                reject(new Error("Unable to load export library."));
            }, {once:true});

            return;
        }

        const script = document.createElement("script");
        script.src = src;
        script.async = true;
        script.dataset.kageraExportSrc = src;

        script.onload = function() {
            script.dataset.loaded = "1";
            resolve();
        };

        script.onerror = function() {
            reject(new Error("Unable to load the export library. Check your internet connection and try again."));
        };

        document.head.appendChild(script);
    });
}

async function kageraEnsurePdfLibrary()
{
    if (window.jspdf && window.jspdf.jsPDF &&
        window.jspdf.jsPDF.API &&
        typeof window.jspdf.jsPDF.API.autoTable === "function") {
        return true;
    }

    await kageraLoadScriptOnce(
        "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"
    );

    if (!(window.jspdf && window.jspdf.jsPDF)) {
        throw new Error("PDF library could not be loaded.");
    }

    await kageraLoadScriptOnce(
        "https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"
    );

    if (typeof window.jspdf.jsPDF.API.autoTable !== "function") {
        throw new Error("PDF table export library could not be loaded.");
    }

    return true;
}

function kageraExportHighLowExcel()
{
    let data;

    try {
        data = kageraGetHighLowExportData();
    } catch (error) {
        alert(error.message);
        return;
    }

    /*
     * Prefer XLSX for a genuine .xlsx file. If the CDN library is unavailable,
     * use a native Excel-compatible .xls workbook so the download still works.
     */
    if (window.XLSX) {
        try {
            const wb = XLSX.utils.book_new();

            const rows = [
                [data.title],
                [data.subtitle],
                [data.heldOn],
                [],
                ["GRADE", "KILOS OFFERED", "KILOS SOLD", "TOTAL VALUE (TZS)"],
                ...data.salesRows,
                [],
                ["PRICES", "LOWEST PRICE PER KG", "AVERAGE PRICE PER KG", "HIGHEST PRICE PER KG"],
                ...data.priceRows,
                [],
                [data.percentageDry],
                [data.percentageClean]
            ];

            const ws = XLSX.utils.aoa_to_sheet(rows);

            ws["!cols"] = [
                {wch: 30},
                {wch: 20},
                {wch: 20},
                {wch: 25}
            ];

            XLSX.utils.book_append_sheet(wb, ws, "High & Low");
            XLSX.writeFile(
                wb,
                kageraHighLowFileBase() + ".xlsx"
            );
            return;
        } catch (error) {
            console.error("XLSX export failed:", error);
        }
    }

    /*
     * Reliable browser-native fallback. Excel opens this as a workbook even
     * when SheetJS is unavailable.
     */
    const escapeXml = function(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    };

    const allRows = [
        [data.title],
        [data.subtitle],
        [data.heldOn],
        [],
        ["GRADE", "KILOS OFFERED", "KILOS SOLD", "TOTAL VALUE (TZS)"],
        ...data.salesRows,
        [],
        ["PRICES", "LOWEST PRICE PER KG", "AVERAGE PRICE PER KG", "HIGHEST PRICE PER KG"],
        ...data.priceRows,
        [],
        [data.percentageDry],
        [data.percentageClean]
    ];

    const tableRows = allRows.map(function(row) {
        if (!row.length) {
            return "<tr><td>&nbsp;</td></tr>";
        }

        return "<tr>" + row.map(function(cell) {
            return "<td>" + escapeXml(cell) + "</td>";
        }).join("") + "</tr>";
    }).join("");

    const html =
        "<html><head><meta charset='utf-8'>" +
        "<style>" +
        "table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:11pt}" +
        "td{border:1px solid #333;padding:6px 8px}" +
        "tr:nth-child(5) td,tr:nth-child(" + (6 + data.salesRows.length + 1) + ") td{font-weight:bold}" +
        "</style></head><body>" +
        "<table>" + tableRows + "</table>" +
        "</body></html>";

    kageraDownloadBlob(
        new Blob([html], {
            type: "application/vnd.ms-excel;charset=utf-8"
        }),
        kageraHighLowFileBase() + ".xls"
    );
}

function kageraExportHighLowWord()
{
    let data;

    try {
        data = kageraGetHighLowExportData();
    } catch (error) {
        alert(error.message);
        return;
    }

    const makeRow = function(row, header) {
        const tag = header ? "th" : "td";
        return "<tr>" + row.map(function(cell) {
            return "<" + tag + ">" +
                String(cell ?? "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;") +
                "</" + tag + ">";
        }).join("") + "</tr>";
    };

    const salesTable =
        "<table>" +
        makeRow(
            ["GRADE", "KILOS OFFERED", "KILOS SOLD", "TOTAL VALUE (TZS)"],
            true
        ) +
        data.salesRows.map(function(row) {
            return makeRow(row, false);
        }).join("") +
        "</table>";

    const pricesTable =
        "<table>" +
        makeRow(
            ["PRICES", "LOWEST PRICE PER KG", "AVERAGE PRICE PER KG", "HIGHEST PRICE PER KG"],
            true
        ) +
        data.priceRows.map(function(row) {
            return makeRow(row, false);
        }).join("") +
        "</table>";

    const html =
        "<!DOCTYPE html><html><head><meta charset='utf-8'>" +
        "<title>Kagera High &amp; Low Report</title>" +
        "<style>" +
        "body{font-family:Arial,sans-serif;font-size:10pt;color:#222}" +
        "h1{text-align:center;font-size:16pt;margin:0 0 8px}" +
        "h2{text-align:center;font-size:12pt;margin:0 0 5px}" +
        "p{text-align:center;font-size:10pt;margin:0 0 12px}" +
        "table{width:100%;border-collapse:collapse;margin-bottom:14px}" +
        "th,td{border:1px solid #333;padding:6px;text-align:center}" +
        "th{font-weight:bold;background:#eee}" +
        "td:first-child,th:first-child{text-align:left}" +
        "</style></head><body>" +
        "<h1>" + data.title + "</h1>" +
        "<h2>" + data.subtitle + "</h2>" +
        "<p>" + data.heldOn + "</p>" +
        salesTable +
        pricesTable +
        "<p>" + data.percentageDry + "</p>" +
        "<p>" + data.percentageClean + "</p>" +
        "</body></html>";

    kageraDownloadBlob(
        new Blob([html], {
            type: "application/msword;charset=utf-8"
        }),
        kageraHighLowFileBase() + ".doc"
    );
}

async function kageraExportHighLowPdf()
{
    let data;

    try {
        data = kageraGetHighLowExportData();
        await kageraEnsurePdfLibrary();
    } catch (error) {
        console.error("Kagera PDF export:", error);
        alert(error.message);
        return;
    }

    try {
        const JsPDF = window.jspdf.jsPDF;
        const doc = new JsPDF({
            orientation: "landscape",
            unit: "mm",
            format: "a4"
        });

        // Professional coffee-inspired palette: coffee brown, roasted brown,
        // cream paper tones and a subtle coffee-gold accent.
        const coffeeDark = [78, 52, 46];
        const coffeeBrown = [109, 76, 65];
        const coffeeMedium = [141, 110, 99];
        const coffeeCream = [248, 244, 238];
        const coffeeLight = [255, 251, 245];
        const coffeeGold = [184, 134, 75];
        const white = [255, 255, 255];

        // Page background.
        doc.setFillColor(...coffeeLight);
        doc.rect(0, 0, 297, 210, "F");

        // Report title.
        doc.setTextColor(...coffeeDark);
        doc.setFontSize(15);
        doc.setFont(undefined, "bold");
        doc.text(
            data.title,
            148.5,
            13,
            {align:"center"}
        );

        doc.setFontSize(11);
        doc.setTextColor(...coffeeBrown);
        doc.text(
            data.subtitle,
            148.5,
            20,
            {align:"center"}
        );

        doc.setFontSize(9);
        doc.setTextColor(...coffeeMedium);
        doc.setFont(undefined, "normal");
        doc.text(
            data.heldOn,
            148.5,
            26,
            {align:"center"}
        );

        doc.autoTable({
            startY: 31,
            head: [[
                "GRADE",
                "KILOS OFFERED",
                "KILOS SOLD",
                "TOTAL VALUE (TZS)"
            ]],
            body: data.salesRows,
            theme: "grid",
            styles: {
                fontSize: 8,
                cellPadding: 2.2,
                halign: "center",
                textColor: coffeeDark,
                fillColor: coffeeLight,
                lineColor: coffeeMedium,
                lineWidth: 0.25
            },
            alternateRowStyles: {
                fillColor: coffeeCream
            },
            headStyles: {
                fillColor: coffeeDark,
                textColor: white,
                fontStyle: "bold",
                lineColor: coffeeDark,
                lineWidth: 0.35
            },
            didParseCell: function(hook) {
                // Highlight the Total row as the report total.
                if (hook.section === "body" &&
                    hook.row && hook.row.raw &&
                    String(hook.row.raw[0] || "").trim().toLowerCase() === "total") {
                    hook.cell.styles.fillColor = coffeeBrown;
                    hook.cell.styles.textColor = white;
                    hook.cell.styles.fontStyle = "bold";
                }
            },
            columnStyles: {
                0: {halign:"left"}
            }
        });

        doc.autoTable({
            startY: doc.lastAutoTable.finalY + 7,
            head: [[
                "PRICES",
                "LOWEST PRICE PER KG",
                "AVERAGE PRICE PER KG",
                "HIGHEST PRICE PER KG"
            ]],
            body: data.priceRows,
            theme: "grid",
            styles: {
                fontSize: 8,
                cellPadding: 2.2,
                halign: "center",
                textColor: coffeeDark,
                fillColor: coffeeLight,
                lineColor: coffeeMedium,
                lineWidth: 0.25
            },
            alternateRowStyles: {
                fillColor: coffeeCream
            },
            headStyles: {
                fillColor: coffeeDark,
                textColor: white,
                fontStyle: "bold",
                lineColor: coffeeDark,
                lineWidth: 0.35
            },
            didParseCell: function(hook) {
                // Highlight the Total row as the report total.
                if (hook.section === "body" &&
                    hook.row && hook.row.raw &&
                    String(hook.row.raw[0] || "").trim().toLowerCase() === "total") {
                    hook.cell.styles.fillColor = coffeeBrown;
                    hook.cell.styles.textColor = white;
                    hook.cell.styles.fontStyle = "bold";
                }
            },
            columnStyles: {
                0: {halign:"left"}
            }
        });

        // Percentage notes use the coffee-gold accent for visual hierarchy.
        doc.setFontSize(8);
        doc.setFont(undefined, "bold");
        doc.setTextColor(...coffeeBrown);

        doc.text(
            data.percentageDry,
            282,
            doc.lastAutoTable.finalY + 8,
            {align:"right"}
        );

        doc.text(
            data.percentageClean,
            282,
            doc.lastAutoTable.finalY + 13,
            {align:"right"}
        );

        // Subtle coffee-gold footer accent.
        doc.setDrawColor(...coffeeGold);
        doc.setLineWidth(0.6);
        doc.line(15, 198, 282, 198);

        doc.save(
            kageraHighLowFileBase() + ".pdf"
        );

    } catch (error) {
        console.error("Kagera PDF generation failed:", error);
        alert(
            "The High & Low PDF could not be generated. " +
            "Please try again. If the problem continues, refresh the page."
        );
    }
}

async function kageraExportHighLow(format)
{
    try {
        if (format === "pdf") {
            await kageraExportHighLowPdf();
        } else if (format === "excel") {
            kageraExportHighLowExcel();
        } else if (format === "word") {
            kageraExportHighLowWord();
        } else {
            throw new Error("Unsupported export format.");
        }
    } catch (error) {
        console.error("Kagera High & Low export:", error);
        alert(error.message || "Unable to export the High & Low report.");
    } finally {
        kageraCloseHighLowExportMenu();
    }
}

function kageraSetupHighLowExport()
{
    const btn = document.getElementById("kageraHighLowExportBtn");
    const menu = document.getElementById("kageraHighLowExportMenu");

    if (!btn || !menu || btn.dataset.bound === "1") {
        return;
    }

    btn.dataset.bound = "1";

    btn.addEventListener("click", function(event) {
        event.preventDefault();
        event.stopPropagation();

        const visible =
            window.getComputedStyle(menu).display !== "none";

        menu.style.display = visible ? "none" : "block";
    });

    menu.querySelectorAll("button[data-kagera-export]").forEach(function(item) {
        item.addEventListener("click", async function(event) {
            event.preventDefault();
            event.stopPropagation();

            const format = this.getAttribute("data-kagera-export");

            if (!format) {
                return;
            }

            await kageraExportHighLow(format);
        });
    });

    document.addEventListener("click", function(event) {
        if (!event.target.closest("#kageraHighLowExportWrap")) {
            kageraCloseHighLowExportMenu();
        }
    });
}


function kageraIsHighLowReport(value)
{
    const normalized = String(value || "")
        .trim()
        .toLowerCase()
        .replace(/[_-]+/g, " ")
        .replace(/\s+/g, " ");

    return normalized === "high & low" ||
           normalized === "high and low" ||
           normalized === "high low";
}

function kageraSetHighLowExportVisible(visible)
{
    const wrap = document.getElementById("kageraHighLowExportWrap");
    const button = document.getElementById("kageraHighLowExportBtn");
    const menu = document.getElementById("kageraHighLowExportMenu");

    if (!wrap) return;

    if (visible) {
        wrap.style.setProperty("display", "inline-flex", "important");
        wrap.style.setProperty("visibility", "visible", "important");
        wrap.style.setProperty("opacity", "1", "important");

        if (button) {
            button.style.setProperty("display", "inline-flex", "important");
            button.style.setProperty("visibility", "visible", "important");
            button.style.setProperty("opacity", "1", "important");
        }
    } else {
        wrap.style.setProperty("display", "none", "important");
        if (menu) menu.style.setProperty("display", "none", "important");
    }
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

    kageraSetHighLowExportVisible(kageraIsHighLowReport(selectedReport));

    if (selectedReport !== "high_low") {
        kageraCloseHighLowExportMenu();
    }

    if (!isReportView) {
        kageraSetHighLowExportVisible(false);
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

    let result;

    try {
        const reportKey = season + "|" + auction;

        /* Cancel a previous report request when the user changes Season/Auction. */
        if (kageraReportController) {
            kageraReportController.abort();
        }

        if (kageraReportCache.has(reportKey)) {
            result = kageraReportCache.get(reportKey);
        } else {
            const controller = new AbortController();
            kageraReportController = controller;
            const reportRequestId = ++kageraActiveReportRequest;

            const response = await fetch(
                "kagera_auction.php?action=report" +
                "&season=" + encodeURIComponent(season) +
                "&auction_no=" + encodeURIComponent(auction),
                {
                    cache: "no-store",
                    credentials: "same-origin",
                    signal: controller.signal,
                    headers: { "Accept": "application/json" }
                }
            );

            const fetchedResult = await readKageraJson(response);

            if (reportRequestId !== kageraActiveReportRequest || controller.signal.aborted) {
                return;
            }

            if (!fetchedResult || fetchedResult.success === false) {
                throw new Error(fetchedResult?.message || "Unable to generate report.");
            }

            kageraReportCache.set(reportKey, fetchedResult);
            result = fetchedResult;
            kageraReportController = null;
        }

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
                    // Ignore underscores: "_" and whitespace are equivalent.
                    .replace(/[\\s_]+/g, "_")
                    .replace(/^_+|_+$/g, "")
                    .split("_")
                    .filter(Boolean)
                    .reduce(function(parts, part, index, allParts) {
                        const p = part.toLowerCase();
                        if (p === "robu" && allParts[index + 1] &&
                            allParts[index + 1].toLowerCase() === "ta") {
                            parts.push("Robusta");
                            allParts[index + 1] = "";
                        } else if (p !== "") {
                            parts.push(part);
                        }
                        return parts;
                    }, [])
                    .map(function(part) {
                        const p = part.toLowerCase();
                        if (p === "arabica") return "Arabica";
                        if (p === "robusta" || p === "robu" || p === "robusta") return "Robusta";
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
        if (error && error.name === "AbortError") return;

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

async function loadKageraResults(options = {})
{
    const type = kageraDisplayType ? kageraDisplayType.value : "results";
    const force = options.force === true;

    const resultsTable = document.getElementById("kageraResultsTable");
    const catalogueTable = document.getElementById("kageraCatalogueTable");
    const title = document.getElementById("kageraSectionTitle");
    const subtitle = document.getElementById("kageraSectionSubtitle");

    if (resultsTable) resultsTable.style.display = type === "results" ? "table" : "none";
    if (catalogueTable) catalogueTable.style.display = type === "catalogue" ? "table" : "none";

    const reportPanel = document.getElementById("kageraReportPanel");
    const highLowReport = document.getElementById("kageraHighLowReport");
    const salesSummaryReport = document.getElementById("kageraSalesSummaryTable");

    if (type !== "high_low" && type !== "sales_summary") {
        if (reportPanel) reportPanel.style.display = "none";
        if (highLowReport) highLowReport.style.display = "none";
        if (salesSummaryReport) salesSummaryReport.style.display = "none";
    }

    if (title) {
        title.textContent = type === "catalogue" ? "Kagera Auction Catalogue" : "Kagera Auction Results";
    }
    if (subtitle) {
        subtitle.textContent = type === "catalogue"
            ? "Catalogue currently stored in the database."
            : "Results currently stored in the database.";
    }

    if (type === "high_low" || type === "sales_summary") {
        return kageraShowReport();
    }

    return loadKageraData(type, { force: force });
}

let kageraReportRenderTimer = null;
function kageraQueueReportRender()
{
    if (kageraReportRenderTimer) {
        clearTimeout(kageraReportRenderTimer);
    }

    kageraReportRenderTimer = setTimeout(function() {
        kageraReportRenderTimer = null;
        void kageraShowReport();
    }, 80);
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
                kageraQueueReportRender();
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
                kageraQueueReportRender();
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
        kageraSyncUploadWithDisplay();

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
| HIGH & LOW EXPORT
|--------------------------------------------------------------------------
*/
kageraSetupHighLowExport();

/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
*/
kageraSyncUploadWithDisplay();
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


let kageraEditMode = false;

function kageraCurrentEditableType()
{
    const v = String(kageraDisplayType ? kageraDisplayType.value : "").toLowerCase();
    return v === "results" || v === "catalogue" ? v : "";
}

async function kageraPostAction(data)
{
    const body = new FormData();
    Object.entries(data).forEach(([key, value]) => body.set(key, value));

    const response = await fetch("kagera_auction.php", {
        method: "POST",
        body
    });

    const json = await response.json();
    if (!response.ok || !json.success) {
        throw new Error(json.message || "Database operation failed.");
    }
    return json;
}

function kageraToggleSettingsMenu()
{
    const menu = document.getElementById("kageraSettingsMenu");
    if (!menu) return;
    menu.style.display = menu.style.display === "block" ? "none" : "block";
}

document.getElementById("kageraSettingsBtn")?.addEventListener("click", function(event){
    event.stopPropagation();
    kageraToggleSettingsMenu();
});

document.addEventListener("click", function(event){
    const wrap = document.getElementById("kageraSettingsWrap");
    const menu = document.getElementById("kageraSettingsMenu");
    if (wrap && menu && !wrap.contains(event.target)) menu.style.display = "none";
});

document.getElementById("kageraEditModeBtn")?.addEventListener("click", function(){
    const type = kageraCurrentEditableType();
    if (!type) {
        alert("Editing is available only when Auction Results or Auction Catalogue is selected.");
        return;
    }
    kageraEditMode = !kageraEditMode;
    document.body.classList.toggle("kagera-edit-mode", kageraEditMode);
    this.textContent = kageraEditMode ? "✓ Finish editing" : "✎ Edit selected display";
    document.getElementById("kageraSettingsMenu").style.display = "none";
    loadKageraResults({force:true});
});

document.getElementById("kageraDeleteAllBtn")?.addEventListener("click", async function(){
    const type = kageraCurrentEditableType();
    if (!type) {
        alert("Delete All is available only for Auction Results or Auction Catalogue.");
        return;
    }

    const label = type === "results" ? "Auction Results" : "Auction Catalogue";
    const confirmation = prompt(
        "This will permanently delete ALL " + label +
        " records from the database. Type DELETE ALL to continue."
    );

    if (confirmation !== "DELETE ALL") return;

    try {
        const result = await kageraPostAction({
            action: "delete_all",
            kagera_type: type,
            confirmation: confirmation
        });
        alert(result.message);
        loadKageraResults({force:true});
    } catch (error) {
        alert(error.message);
    }
});

async function kageraDeleteRow(id)
{
    const type = kageraCurrentEditableType();
    if (!type || !id) return;

    if (!confirm("Delete this specific row permanently from the database? This cannot be undone.")) return;

    try {
        const result = await kageraPostAction({
            action: "delete_row",
            kagera_type: type,
            id: String(id)
        });
        alert(result.message);
        loadKageraResults({force:true});
    } catch (error) {
        alert(error.message);
    }
}

function kageraBeginRowEdit(button)
{
    const tr = button.closest("tr");
    if (!tr) return;

    const id = tr.dataset.rowId;
    const type = kageraCurrentEditableType();
    if (!id || !type) return;

    const editableFields = type === "results"
        ? ["lot_no","auction_no","auction_date","warehouse","warehouse_location_district","kgs","grade","grade2","price","buyer"]
        : ["lot_no","auction_no","auction_date","union_name","warehouse_name_amcos","warehouse_location_district","kgs","grade","grade2","certification"];

    tr.querySelectorAll("td[data-field]").forEach(td => {
        const field = td.dataset.field;
        if (!editableFields.includes(field)) return;

        const input = document.createElement("input");
        input.className = "kagera-edit-input";
        input.dataset.editField = field;
        input.value = td.dataset.raw ?? "";
        if (field === "kgs" || field === "price") input.inputMode = "decimal";
        td.replaceChildren(input);
    });

    button.classList.remove("edit");
    button.classList.add("save");
    button.innerHTML = "✓";
    button.title = "Save changes";
    button.setAttribute("aria-label","Save changes");
    button.dataset.action = "save";
    button.dataset.rowId = id;

    const del = tr.querySelector(".kagera-action-icon.delete");
    if (del) del.disabled = true;
}
async function kageraSaveRowEdit(tr, id)
{
    const type = kageraCurrentEditableType();
    const rowData = {};

    tr.querySelectorAll("[data-edit-field]").forEach(input => {
        rowData[input.dataset.editField] = input.value;
    });

    try {
        const result = await kageraPostAction({
            action: "update_row",
            kagera_type: type,
            id: String(id),
            row_data: JSON.stringify(rowData)
        });
        alert(result.message);
        loadKageraResults({force:true});
    } catch (error) {
        alert("Save failed: " + error.message);

        const saveButton = tr.querySelector(".kagera-action-icon.save");
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.innerHTML = "✓";
        }

        const deleteButton = tr.querySelector(".kagera-action-icon.delete");
        if (deleteButton) {
            deleteButton.disabled = false;
        }
    }
}



function kageraTagEditableCells()
{
    const type = kageraCurrentEditableType();
    const body = type === "catalogue"
        ? document.getElementById("kageraCatalogueBody")
        : document.getElementById("kageraResultsBody");

    if (!body) return;

    const fields = type === "results"
        ? ["lot_no","auction_no","auction_date","warehouse","warehouse_location_district","kgs","grade","grade2","price","value","buyer"]
        : ["lot_no","auction_no","auction_date","union_name","warehouse_name_amcos","warehouse_location_district","kgs","grade","grade2","certification"];

    body.querySelectorAll("tr[data-row-id]").forEach(tr => {
        const cells = tr.querySelectorAll("td");
        fields.forEach((field, index) => {
            if (!cells[index]) return;
            cells[index].dataset.field = field;
            // Store unformatted value from current dataset where possible.
            const rowId = String(tr.dataset.rowId || "");
            const source = type === "results"
                ? (kageraAllResults || []).find(r => String(r.id) === rowId)
                : (kageraCatalogueData || []).find(r => String(r.id) === rowId);
            if (source) cells[index].dataset.raw = source[field] ?? "";
        });
    });
}


const kageraEditObserver = new MutationObserver(() => kageraTagEditableCells());
["kageraResultsBody","kageraCatalogueBody"].forEach(id => {
    const el = document.getElementById(id);
    if (el) kageraEditObserver.observe(el, {childList:true});
});


if (kageraDisplayType) {
    kageraDisplayType.addEventListener("change", function(){
        kageraEditMode = false;
        document.body.classList.remove("kagera-edit-mode");
        const editButton = document.getElementById("kageraEditModeBtn");
        if (editButton) editButton.textContent = "✎ Edit selected display";
    });
}


function kageraHandleRowActionClick(event)
{
    const button = event.target.closest(".kagera-action-icon");
    if (!button) return;

    const tableBody = button.closest("#kageraResultsBody, #kageraCatalogueBody");
    if (!tableBody) return;

    event.preventDefault();
    event.stopPropagation();

    const action = String(button.dataset.action || "").toLowerCase();
    const tr = button.closest("tr");
    const rowId = String(
        button.dataset.rowId ||
        (tr ? tr.dataset.rowId : "") ||
        ""
    ).trim();

    if (!rowId || rowId === "undefined" || rowId === "null") {
        alert("The database ID for this row was not returned. Refreshing the table now.");
        loadKageraResults({force:true});
        return;
    }

    if (action === "edit") {
        kageraBeginRowEdit(button);
        return;
    }

    if (action === "save") {
        const tr = button.closest("tr");
        if (tr) kageraSaveRowEdit(tr, rowId);
        return;
    }

    if (action === "delete") {
        kageraDeleteRow(rowId);
    }
}

document.getElementById("kageraResultsBody")?.addEventListener("click", kageraHandleRowActionClick);
document.getElementById("kageraCatalogueBody")?.addEventListener("click", kageraHandleRowActionClick);


const kageraExpectedSchemas = {
    results: {
        title: "Auction Results — Expected Excel Columns",
        subtitle: "Upload exactly 10 Excel columns for Auction Results.",
        columns: ["Lot No.","Auction No.","Auction Date","Warehouse Name/Amcos","Warehouse Location/District","Kgs","Grade","Grade2","Price","Buyer"],
        note: "<strong>Value is not an Excel column.</strong> The database creates it automatically as Kgs × Price, giving 11 database fields."
    },
    catalogue: {
        title: "Auction Catalogue — Expected Excel Columns",
        subtitle: "Upload exactly 10 Excel columns for Auction Catalogue.",
        columns: ["Lot No.","Auction No.","Auction Date","Union","Warehouse Name/Amcos","Warehouse Location/District","Kgs","Grade","Grade2","Certification"],
        note: "The file is validated against these Catalogue columns before upload."
    }
};

function kageraShowExpectedColumns()
{
    const panel=document.getElementById("kageraExpectedColumns");
    const title=document.getElementById("kageraExpectedTitle");
    const subtitle=document.getElementById("kageraExpectedSubtitle");
    const list=document.getElementById("kageraExpectedColumnList");
    const note=document.getElementById("kageraExpectedNote");
    if(!panel||!title||!subtitle||!list||!note) return;

    const selected=String(kageraDisplayType?.value||"").toLowerCase();
    const type=["results","auction_results"].includes(selected) ? "results"
        : ["catalogue","catalog","auction_catalogue"].includes(selected) ? "catalogue" : "";
    const schema=kageraExpectedSchemas[type];

    if(!schema){panel.classList.remove("show");return;}

    title.textContent=schema.title;
    subtitle.textContent=schema.subtitle;
    list.innerHTML=schema.columns.map((column,index)=>
        '<span class="kagera-column-chip"><span class="n">'+(index+1)+'</span>'+
        escapeKageraHtml(column)+'</span>'
    ).join("");
    note.innerHTML=schema.note;
    panel.classList.add("show");
}

document.querySelector(".kagera-file-label")?.addEventListener("click", function(){
    kageraShowExpectedColumns();
});
document.getElementById("kageraExcelFile")?.addEventListener("change", function(){
    kageraShowExpectedColumns();
});
document.addEventListener("click", function(event){
    const area=document.querySelector(".kagera-file-area");
    const panel=document.getElementById("kageraExpectedColumns");
    if(area&&panel&&!area.contains(event.target)) panel.classList.remove("show");
});


function kageraSelectedUploadType()
{
    const selected=String(kageraDisplayType?.value||"").toLowerCase();
    if(["results","auction_results"].includes(selected)) return "results";
    if(["catalogue","catalog","auction_catalogue"].includes(selected)) return "catalogue";
    return "";
}

function kageraOpenSettingsUpload()
{
    const type=kageraSelectedUploadType();
    if(!type){
        alert("Select Auction Results or Auction Catalogue from Display before uploading.");
        return;
    }

    const panel=document.getElementById("kageraSettingsUploadPanel");
    const menu=document.getElementById("kageraSettingsMenu");
    const title=document.getElementById("kageraSettingsUploadTitle");
    const hint=document.getElementById("kageraSettingsUploadHint");
    const uploadType=document.getElementById("kageraUploadType");
    const uploadButton=document.getElementById("kageraUploadButton");
    const fileName=document.getElementById("kageraFileName");
    const fileInput=document.getElementById("kageraExcelFile");

    if(uploadType) uploadType.value=type;

    if(type==="results"){
        if(title) title.textContent="Upload Auction Results";
        if(hint) hint.textContent="Select the 10-column Auction Results Excel file.";
        if(uploadButton) uploadButton.innerHTML="<span>↑</span> Upload Results";
        if(fileName) fileName.textContent="Select Auction Results file";
    }else{
        if(title) title.textContent="Upload Auction Catalogue";
        if(hint) hint.textContent="Select the 10-column Auction Catalogue Excel file.";
        if(uploadButton) uploadButton.innerHTML="<span>↑</span> Upload Catalogue";
        if(fileName) fileName.textContent="Select Auction Catalogue file";
    }

    if(fileInput) fileInput.value="";
    if(kageraUploadStatus){
        kageraUploadStatus.style.display="none";
        kageraUploadStatus.className="kagera-upload-status";
        kageraUploadStatus.textContent="";
    }
    if(menu) menu.style.display="none";
    if(panel) panel.style.display="block";
}

document.getElementById("kageraSettingsUploadBtn")?.addEventListener("click", function(event){
    event.preventDefault();
    event.stopPropagation();
    kageraOpenSettingsUpload();
});

document.getElementById("kageraUploadPanelClose")?.addEventListener("click", function(){
    const panel=document.getElementById("kageraSettingsUploadPanel");
    const expected=document.getElementById("kageraExpectedColumns");
    if(panel) panel.style.display="none";
    if(expected) expected.classList.remove("show");
});

// Close upload panel when the selected Display changes so an upload can never
// accidentally continue against the previous destination.
if(kageraDisplayType){
    kageraDisplayType.addEventListener("change",function(){
        const panel=document.getElementById("kageraSettingsUploadPanel");
        const expected=document.getElementById("kageraExpectedColumns");
        if(panel) panel.style.display="none";
        if(expected) expected.classList.remove("show");
    });
}

</script>




</body>
</html>