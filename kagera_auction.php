<?php
session_start();

/*
|--------------------------------------------------------------------------
| KAGERA AUCTION - SELF CONTAINED PAGE
|--------------------------------------------------------------------------
| This single file handles:
|   1. Page rendering
|   2. Excel upload
|   3. Fetching saved Kagera Auction results
|
| No kagera_upload.php or kagera_fetch.php is required.
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: login.php");
    exit;
}

/* ---------------------------------------------------------
   DATABASE CONNECTION
   Supports either DATABASE_URL or separate DB_* variables.
--------------------------------------------------------- */
function kagera_db()
{
    static $db = null;

    if (class_exists("mysqli") && $db instanceof mysqli) {
        return $db;
    }

    $databaseUrl = getenv("DATABASE_URL");

    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);

        $host = $parts["host"] ?? "";
        $port = (int)($parts["port"] ?? 3306);
        $user = urldecode($parts["user"] ?? "");
        $pass = urldecode($parts["pass"] ?? "");
        $name = ltrim($parts["path"] ?? "", "/");
    } else {
        $host = getenv("DB_HOST") ?: getenv("MYSQL_HOST") ?: "";
        $port = (int)(getenv("DB_PORT") ?: getenv("MYSQL_PORT") ?: 3306);
        $user = getenv("DB_USER") ?: getenv("MYSQL_USER") ?: "";
        $pass = getenv("DB_PASS") ?: getenv("MYSQL_PASSWORD") ?: "";
        $name = getenv("DB_NAME") ?: getenv("MYSQL_DATABASE") ?: "";
    }

    if (!$host || !$user || !$name) {
        throw new Exception(
            "Database configuration is missing. Set DATABASE_URL or DB_HOST, DB_PORT, DB_USER, DB_PASS and DB_NAME."
        );
    }

    if (!class_exists("mysqli")) {
        throw new Exception("PHP MySQLi extension is not enabled on this server.");
    }

    $db = new mysqli($host, $user, $pass, $name, $port);

    if ($db->connect_errno) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }

    $db->set_charset("utf8mb4");

    return $db;
}

/* ---------------------------------------------------------
   TABLE
   Change KAGERA_TABLE in Render Environment Variables if
   your existing Kagera table has a different name.
--------------------------------------------------------- */
function kagera_table()
{
    $table = getenv("KAGERA_TABLE") ?: "kagera_auction_results";

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new Exception("Invalid KAGERA_TABLE name.");
    }

    return "`" . $table . "`";
}

function ensure_kagera_table()
{
    $db = kagera_db();
    $table = kagera_table();

    /*
     * The Kagera Auction results table is created automatically in Render
     * the first time this page is opened or an upload/fetch is requested.
     * These are the application data columns required by the Kagera table.
     * id and record_key are internal columns and are not displayed.
     */
    $sql = "
        CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_key CHAR(64) NOT NULL,
            lot_no VARCHAR(100) NULL,
            auction_no VARCHAR(50) NULL,
            date_sold VARCHAR(50) NULL,
            sell_mark VARCHAR(255) NULL,
            warehouse VARCHAR(255) NULL,
            warehouse_location VARCHAR(255) NULL,
            net_weight DECIMAL(15,2) NULL,
            grade VARCHAR(100) NULL,
            grade2 VARCHAR(100) NULL,
            price DECIMAL(15,4) NULL,
            buyer_name VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_kagera_record_key (record_key),
            KEY idx_kagera_auction_no (auction_no),
            KEY idx_kagera_lot_no (lot_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$db->query($sql)) {
        throw new Exception("Unable to create Kagera Auction table: " . $db->error);
    }

    /* Add newly required columns to an existing Kagera table automatically. */
    $requiredColumns = [
        "record_key" => "CHAR(64) NULL AFTER id",
        "lot_no" => "VARCHAR(100) NULL AFTER record_key",
        "auction_no" => "VARCHAR(50) NULL",
        "date_sold" => "VARCHAR(50) NULL",
        "sell_mark" => "VARCHAR(255) NULL",
        "warehouse" => "VARCHAR(255) NULL",
        "warehouse_location" => "VARCHAR(255) NULL",
        "net_weight" => "DECIMAL(15,2) NULL",
        "grade" => "VARCHAR(100) NULL",
        "grade2" => "VARCHAR(100) NULL",
        "price" => "DECIMAL(15,4) NULL",
        "buyer_name" => "VARCHAR(255) NULL"
    ];

    foreach ($requiredColumns as $column => $definition) {
        $safeColumn = $db->real_escape_string($column);
        $check = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$safeColumn}'");

        if (!$check) {
            throw new Exception("Unable to inspect Kagera Auction table: " . $db->error);
        }

        if ($check->num_rows === 0) {
            if (!$db->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}")) {
                $check->free();
                throw new Exception("Unable to add Kagera column {$column}: " . $db->error);
            }
        }

        $check->free();
    }

    /* Populate the internal key for existing rows before enforcing uniqueness. */
    $existingKeyCheck = $db->query("SELECT id FROM {$table} WHERE record_key IS NULL OR record_key = '' LIMIT 1000");
    if ($existingKeyCheck) {
        while ($existing = $existingKeyCheck->fetch_assoc()) {
            $id = (int)$existing["id"];
            $key = hash("sha256", "legacy-kagera|" . $id);
            $stmt = $db->prepare("UPDATE {$table} SET record_key = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $key, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        $existingKeyCheck->free();
    }

    /* Ensure the internal unique key exists on older installations. */
    $keyCheck = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'uq_kagera_record_key'");
    if ($keyCheck && $keyCheck->num_rows === 0) {
        if (!$db->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_kagera_record_key (record_key)")) {
            $keyCheck->free();
            throw new Exception("Unable to create Kagera record key: " . $db->error);
        }
    }
    if ($keyCheck instanceof mysqli_result) {
        $keyCheck->free();
    }
}

/* ---------------------------------------------------------
   JSON RESPONSE
--------------------------------------------------------- */
function kagera_json($success, $message = "", $data = [])
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ---------------------------------------------------------
   EXCEL HELPERS
   Native XLSX reader. No separate fetch/upload PHP file.
--------------------------------------------------------- */
function kagera_column_letter_to_number($letters)
{
    $number = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $number = ($number * 26) + (ord(strtoupper($letters[$i])) - 64);
    }

    return $number - 1;
}

function kagera_cell_value($cell, $sharedStrings)
{
    $type = (string)$cell["t"];
    $value = "";

    if ($type === "inlineStr") {
        $nodes = $cell->xpath(".//x:is//x:t | .//is//t");
        if ($nodes) {
            foreach ($nodes as $node) {
                $value .= (string)$node;
            }
        }
        return trim($value);
    }

    $v = $cell->v;
    if ($v !== null) {
        $value = (string)$v;
    }

    if ($type === "s") {
        $index = (int)$value;
        $value = $sharedStrings[$index] ?? "";
    } elseif ($type === "b") {
        $value = ($value === "1") ? "TRUE" : "FALSE";
    }

    return trim($value);
}

function kagera_zip_read_entries($filePath)
{
    /* XLSX is a ZIP container. This reader uses only PHP's file/zlib
       functions, so ZipArchive and SimpleXML are not required. */
    $fp = fopen($filePath, "rb");
    if (!$fp) {
        throw new Exception("Unable to open the Excel file.");
    }

    $fileSize = filesize($filePath);
    $tailSize = min($fileSize, 65557);
    fseek($fp, $fileSize - $tailSize);
    $tail = fread($fp, $tailSize);

    $eocd = strrpos($tail, "PK\x05\x06");
    if ($eocd === false) {
        fclose($fp);
        throw new Exception("Invalid .xlsx file: ZIP directory was not found.");
    }

    $info = unpack(
        "vdisk/vdisk_start/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length",
        substr($tail, $eocd + 4, 18)
    );

    $centralOffset = (int)$info["central_offset"];
    $centralSize = (int)$info["central_size"];

    if ($centralOffset < 0 || $centralSize < 0 || $centralOffset + $centralSize > $fileSize) {
        fclose($fp);
        throw new Exception("Invalid .xlsx file: ZIP directory is outside the file.");
    }

    fseek($fp, $centralOffset);
    $central = fread($fp, $centralSize);
    fclose($fp);

    $entries = [];
    $pos = 0;
    $length = strlen($central);

    while ($pos + 46 <= $length) {
        if (substr($central, $pos, 4) !== "PK\x01\x02") {
            break;
        }

        $h = unpack(
            "vversion_made/vversion_needed/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/veflen/vcommentlen/vdisk/vintattr/Vextattr/Vlhoff",
            substr($central, $pos + 4, 42)
        );

        $nameStart = $pos + 46;
        $name = substr($central, $nameStart, (int)$h["namelen"]);

        $entries[$name] = [
            "method" => (int)$h["method"],
            "flag" => (int)$h["flag"],
            "compressed_size" => (int)$h["csize"],
            "local_offset" => (int)$h["lhoff"]
        ];

        $pos = $nameStart
            + (int)$h["namelen"]
            + (int)$h["eflen"]
            + (int)$h["commentlen"];
    }

    if (!$entries) {
        throw new Exception("Invalid .xlsx file: no ZIP entries were found.");
    }

    return $entries;
}

function kagera_zip_extract($filePath, $entries, $name)
{
    if (!isset($entries[$name])) {
        return false;
    }

    $entry = $entries[$name];

    if (($entry["flag"] & 0x0001) !== 0) {
        throw new Exception("The Excel file is encrypted and cannot be read.");
    }

    $fp = fopen($filePath, "rb");
    if (!$fp) {
        throw new Exception("Unable to open the Excel file.");
    }

    fseek($fp, $entry["local_offset"]);
    $localHeader = fread($fp, 30);

    if (strlen($localHeader) < 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
        fclose($fp);
        throw new Exception("Invalid XLSX local file header.");
    }

    $local = unpack(
        "vversion/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/veflen",
        substr($localHeader, 4, 26)
    );

    $dataOffset = $entry["local_offset"] + 30 + (int)$local["namelen"] + (int)$local["eflen"];
    fseek($fp, $dataOffset);
    $compressed = fread($fp, $entry["compressed_size"]);
    fclose($fp);

    if ($entry["method"] === 0) {
        return $compressed;
    }

    if ($entry["method"] === 8) {
        $data = @gzinflate($compressed);
        if ($data === false) {
            throw new Exception("Unable to decompress XLSX data.");
        }
        return $data;
    }

    throw new Exception("Unsupported XLSX compression method.");
}

function kagera_xml_text($value)
{
    return html_entity_decode(
        strip_tags((string)$value),
        ENT_QUOTES | ENT_XML1,
        "UTF-8"
    );
}

function kagera_parse_shared_strings($xml)
{
    $shared = [];

    if (!$xml) {
        return $shared;
    }

    preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $matches);

    foreach ($matches[1] ?? [] as $item) {
        preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $item, $texts);
        $value = "";

        foreach ($texts[1] ?? [] as $part) {
            $value .= kagera_xml_text($part);
        }

        $shared[] = $value;
    }

    return $shared;
}

function kagera_parse_xlsx($filePath)
{
    $entries = kagera_zip_read_entries($filePath);
    $sharedStrings = kagera_parse_shared_strings(
        kagera_zip_extract($filePath, $entries, "xl/sharedStrings.xml") ?: ""
    );

    $workbookXml = kagera_zip_extract($filePath, $entries, "xl/workbook.xml");
    $relsXml = kagera_zip_extract($filePath, $entries, "xl/_rels/workbook.xml.rels");

    if ($workbookXml === false || $relsXml === false) {
        throw new Exception("Invalid XLSX workbook: required workbook files are missing.");
    }

    /* Find the first worksheet relationship, without SimpleXML. */
    if (!preg_match('/<sheet\b[^>]*r:id=["\']([^"\']+)["\'][^>]*>/i', $workbookXml, $sheetMatch)) {
        if (!preg_match('/<sheet\b[^>]*r:[^=]+=["\']([^"\']+)["\'][^>]*>/i', $workbookXml, $sheetMatch)) {
            throw new Exception("Unable to locate the first worksheet.");
        }
    }

    $relationshipId = $sheetMatch[1];

    $target = "";
    preg_match_all('/<Relationship\b[^>]*>/i', $relsXml, $relMatches);

    foreach ($relMatches[0] ?? [] as $rel) {
        if (
            preg_match('/\bId=["\']([^"\']+)["\']/i', $rel, $idMatch) &&
            $idMatch[1] === $relationshipId &&
            preg_match('/\bTarget=["\']([^"\']+)["\']/i', $rel, $targetMatch)
        ) {
            $target = $targetMatch[1];
            break;
        }
    }

    if (!$target) {
        throw new Exception("Unable to resolve the first worksheet.");
    }

    $target = ltrim(str_replace('\\', '/', $target), '/');
    if (strpos($target, "xl/") !== 0) {
        $target = "xl/" . ltrim($target, "/");
    }

    $sheetXml = kagera_zip_extract($filePath, $entries, $target);

    if ($sheetXml === false) {
        /* Most workbooks use sheet1.xml; use it as a safe fallback. */
        $sheetXml = kagera_zip_extract($filePath, $entries, "xl/worksheets/sheet1.xml");
    }

    if ($sheetXml === false) {
        throw new Exception("Unable to read the first worksheet.");
    }

    $rows = [];
    preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches);

    foreach ($rowMatches[1] ?? [] as $rowXml) {
        $cells = [];
        preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/is', $rowXml, $cellMatches, PREG_SET_ORDER);

        foreach ($cellMatches as $cellMatch) {
            $attributes = $cellMatch[1];
            $content = $cellMatch[2];

            if (!preg_match('/\br=["\']([A-Z]+)\d+["\']/i', $attributes, $refMatch)) {
                continue;
            }

            $column = kagera_column_letter_to_number($refMatch[1]);
            $type = "";
            if (preg_match('/\bt=["\']([^"\']+)["\']/i', $attributes, $typeMatch)) {
                $type = strtolower($typeMatch[1]);
            }

            $value = "";

            if ($type === "inlinestr") {
                preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $content, $textMatches);
                foreach ($textMatches[1] ?? [] as $part) {
                    $value .= kagera_xml_text($part);
                }
            } elseif (preg_match('/<v\b[^>]*>(.*?)<\/v>/is', $content, $vMatch)) {
                $value = kagera_xml_text($vMatch[1]);

                if ($type === "s") {
                    $value = $sharedStrings[(int)$value] ?? "";
                } elseif ($type === "b") {
                    $value = $value === "1" ? "TRUE" : "FALSE";
                }
            }

            $cells[$column] = trim($value);
        }

        if ($cells) {
            $max = max(array_keys($cells));
            $values = array_fill(0, $max + 1, "");

            foreach ($cells as $index => $value) {
                $values[$index] = $value;
            }

            $rows[] = $values;
        }
    }

    return $rows;
}

function kagera_normalize_header($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, "_");
}

function kagera_find_column($headers, $possibleNames)
{
    foreach ($possibleNames as $name) {
        $name = kagera_normalize_header($name);

        foreach ($headers as $index => $header) {
            if ($header === $name) {
                return $index;
            }
        }
    }

    return null;
}

function kagera_parse_number($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $value = str_replace([",", " "], "", $value);

    return is_numeric($value) ? (float)$value : null;
}

/* ---------------------------------------------------------
   UPLOAD HANDLER
--------------------------------------------------------- */
function handle_kagera_upload()
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        kagera_json(false, "Invalid upload request.");
    }

    if (
        !isset($_FILES["kagera_excel"]) ||
        !is_array($_FILES["kagera_excel"])
    ) {
        kagera_json(false, "Please select an Excel file.");
    }

    $file = $_FILES["kagera_excel"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        kagera_json(false, "File upload failed. Please try again.");
    }

    $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    if (!in_array($extension, ["xlsx", "xls"], true)) {
        kagera_json(false, "Only .xlsx and .xls files are accepted.");
    }

    /*
     * XLSX is handled directly in this file.
     * If PhpSpreadsheet is already installed, it is used for XLS/XLSX.
     */
    $rows = [];

    if (class_exists("\\PhpOffice\\PhpSpreadsheet\\IOFactory")) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file["tmp_name"]);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (Throwable $e) {
            kagera_json(false, "Unable to read the Excel file: " . $e->getMessage());
        }
    } elseif ($extension === "xlsx") {
        try {
            $rows = kagera_parse_xlsx($file["tmp_name"]);
        } catch (Throwable $e) {
            kagera_json(false, $e->getMessage());
        }
    } else {
        kagera_json(
            false,
            "Legacy .xls files require PhpSpreadsheet. Please upload the workbook as .xlsx on this server."
        );
    }

    if (count($rows) < 2) {
        kagera_json(false, "The Excel file does not contain enough data.");
    }

    /* Locate the actual header row. Some Kagera workbooks have a title row
       above the column headings, while others start directly with headings. */
    $headerIndex = null;
    $maxHeaderScan = min(count($rows), 6);

    for ($r = 0; $r < $maxHeaderScan; $r++) {
        $candidate = array_map("kagera_normalize_header", $rows[$r]);
        $headerSignals = [
            "lot_no", "lot_number", "auction_no", "auction_number",
            "date_sold", "sell_mark", "warehouse", "net_weight",
            "grade", "price", "buyer_name"
        ];
        if (count(array_intersect($headerSignals, $candidate)) >= 2) {
            $headerIndex = $r;
            break;
        }
    }

    if ($headerIndex === null) {
        throw new Exception("The Excel file must contain a header row with an Invoice column.");
    }

    $headerRow = array_map("kagera_normalize_header", $rows[$headerIndex]);

    $columns = [
        "lot_no" => kagera_find_column($headerRow, [
            "lot_no", "lot_number", "lot"
        ]),
        "auction_no" => kagera_find_column($headerRow, [
            "auction_no", "auction_number", "auction", "auction_no."
        ]),
        "date_sold" => kagera_find_column($headerRow, [
            "date_sold", "date"
        ]),
        "sell_mark" => kagera_find_column($headerRow, [
            "sell_mark", "sellmark", "mark"
        ]),
        "warehouse" => kagera_find_column($headerRow, [
            "warehouse", "warehouse_name"
        ]),
        "warehouse_location" => kagera_find_column($headerRow, [
            "warehouse_location", "warehouse_location_name", "location", "warehouse_loc"
        ]),
        "net_weight" => kagera_find_column($headerRow, [
            "net_weight", "net_weight_kg", "net_kg", "kgs", "kg"
        ]),
        "grade" => kagera_find_column($headerRow, [
            "grade"
        ]),
        "grade2" => kagera_find_column($headerRow, [
            "grade2", "grade_2", "grade_ii"
        ]),
        "price" => kagera_find_column($headerRow, [
            "price", "price_usd", "price_tzs", "unit_price"
        ]),
        "buyer_name" => kagera_find_column($headerRow, [
            "buyer_name", "buyer", "buyer_name_"
        ]),
        /* Optional source fields are accepted for compatibility with existing Excel files. */
        "invoice" => kagera_find_column($headerRow, [
            "invoice", "invoice_no", "invoice_number"
        ])
    ];


    ensure_kagera_table();

    $db = kagera_db();
    $table = kagera_table();

    $sql = "
        INSERT INTO {$table}
        (
            record_key,
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            lot_no = VALUES(lot_no),
            auction_no = VALUES(auction_no),
            date_sold = VALUES(date_sold),
            sell_mark = VALUES(sell_mark),
            warehouse = VALUES(warehouse),
            warehouse_location = VALUES(warehouse_location),
            net_weight = VALUES(net_weight),
            grade = VALUES(grade),
            grade2 = VALUES(grade2),
            price = VALUES(price),
            buyer_name = VALUES(buyer_name)
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        kagera_json(false, "Database statement error: " . $db->error);
    }

    $inserted = 0;

    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        $get = function ($key) use ($row, $columns) {
            $index = $columns[$key];
            return $index !== null ? trim((string)($row[$index] ?? "")) : "";
        };

        $lotNo = $get("lot_no");
        $auctionNo = $get("auction_no");
        $dateSold = $get("date_sold");
        $sellMark = $get("sell_mark");
        $warehouse = $get("warehouse");
        $warehouseLocation = $get("warehouse_location");
        $netWeight = kagera_parse_number($get("net_weight"));
        $grade = $get("grade");
        $grade2 = $get("grade2");
        $price = kagera_parse_number($get("price"));
        $buyerName = $get("buyer_name");

        /* Generate a stable key from the displayed business fields. */
        $recordKey = hash(
            "sha256",
            implode("|", [
                $lotNo,
                $auctionNo,
                $dateSold,
                $sellMark,
                $warehouse,
                $warehouseLocation,
                $netWeight ?? "",
                $grade,
                $grade2,
                $price ?? "",
                $buyerName
            ])
        );

        /* Ignore completely blank Excel rows. */
        if ($lotNo === "" && $auctionNo === "" && $sellMark === "" && $buyerName === "") {
            continue;
        }

        $stmt->bind_param(
            "sssssssdssds",
            $recordKey,
            $lotNo,
            $auctionNo,
            $dateSold,
            $sellMark,
            $warehouse,
            $warehouseLocation,
            $netWeight,
            $grade,
            $grade2,
            $price,
            $buyerName
        );

        if (!$stmt->execute()) {
            $stmt->close();
            kagera_json(false, "Unable to save Kagera Auction record: " . $db->error);
        }

        $inserted++;
    }

    $stmt->close();

    kagera_json(
        true,
        "Kagera Auction results uploaded successfully. {$inserted} record(s) processed."
    );
}

/* ---------------------------------------------------------
   FETCH HANDLER
--------------------------------------------------------- */
function handle_kagera_fetch()
{
    ensure_kagera_table();

    $db = kagera_db();
    $table = kagera_table();

    $sql = "
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
        FROM {$table}
        ORDER BY id DESC
    ";

    $result = $db->query($sql);

    if (!$result) {
        kagera_json(false, "Unable to fetch Kagera Auction results: " . $db->error);
    }

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $result->free();

    kagera_json(
        true,
        "Kagera Auction results loaded.",
        $data
    );
}

/* ---------------------------------------------------------
   ROUTE API REQUESTS BEFORE HTML OUTPUT
--------------------------------------------------------- */
$kageraAction = $_GET["action"] ?? "";

try {
    if ($kageraAction === "fetch") {
        handle_kagera_fetch();
    }

    if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        isset($_FILES["kagera_excel"])
    ) {
        handle_kagera_upload();
    }
} catch (Throwable $e) {
    kagera_json(false, $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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

/*
|--------------------------------------------------------------------------
| KAGERA PAGE
|--------------------------------------------------------------------------
| index.php owns the sidebar. This page contains Kagera information only.
| The left margin keeps the page content correctly positioned beside it.
|--------------------------------------------------------------------------
*/
.kagera-main {
    min-height: 100vh;
    margin-left: 270px;
    padding: 32px;
    transition: margin-left 0.3s ease;
}

.kagera-container {
    width: 100%;
    max-width: 1600px;
    margin: 0 auto;
}

/* Header */
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

/* Cards */
.kagera-card {
    background: #fff;
    border: 1px solid #e7e0dc;
    border-radius: 12px;
    box-shadow: 0 3px 14px rgba(62,39,35,0.055);
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

/* Upload */
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

/* Results */
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

/* If index.php collapses its sidebar, this page can be given
   class="sidebar-collapsed" by the parent/linking logic. */
body.sidebar-collapsed .kagera-main {
    margin-left: 78px;
}

/* Mobile */
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

        <!-- KAGERA AUCTION ONLY -->
        <header class="kagera-page-header">
            <div class="kagera-title-group">
                <div class="kagera-eyebrow">Auction Sales</div>
                <h1>Kagera Auction</h1>
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
                <div class="kagera-card-icon">📊</div>
                <div>
                    <h2>Upload Auction Results</h2>
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
                enctype="multipart/form-data">

                <div class="kagera-upload-row">

                    <div class="kagera-file-area">
                        <input
                            type="file"
                            id="kageraExcelFile"
                            name="kagera_excel"
                            accept=".xlsx,.xls"
                            required>

                        <label
                            for="kageraExcelFile"
                            class="kagera-file-label">

                            <span class="kagera-file-icon">📁</span>

                            <span class="kagera-file-text">
                                <strong>Select Excel File</strong>
                                <small id="kageraFileName">
                                    Supported formats: .xlsx, .xls
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
                        id="kageraUploadButton">

                        <span>↑</span>
                        Upload Results
                    </button>

                </div>
            </form>

            <div
                id="kageraUploadStatus"
                class="kagera-upload-status">
            </div>

        </section>

        <section class="kagera-card kagera-data-card">

            <div class="kagera-data-header">
                <div>
                    <h2>Kagera Auction Results</h2>
                    <p>
                        Results currently stored in the database.
                    </p>
                </div>

                <button
                    type="button"
                    class="kagera-refresh-btn"
                    onclick="loadKageraResults()">

                    <span>↻</span>
                    Refresh
                </button>
            </div>

            <div class="kagera-table-wrap">

                <table
                    id="kageraResultsTable"
                    class="kagera-results-table">

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
                            <td colspan="11" class="kagera-empty-state">
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

if (kageraExcelFile) {
    kageraExcelFile.addEventListener("change", function () {
        kageraFileName.textContent = this.files.length
            ? this.files[0].name
            : "Supported formats: .xlsx, .xls";
    });
}

if (kageraUploadForm) {
    kageraUploadForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        if (!kageraExcelFile.files.length) {
            showKageraStatus(
                "Please select an Excel file first.",
                "error"
            );
            return;
        }

        const formData = new FormData(kageraUploadForm);
        const button =
            document.getElementById("kageraUploadButton");

        button.disabled = true;
        button.innerHTML = "<span>⏳</span> Uploading...";

        try {
            /* Upload is handled by THIS SAME PHP file. */
            const response = await fetch(
                "kagera_auction.php",
                {
                    method: "POST",
                    body: formData,
                    cache: "no-store"
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Upload failed."
                );
            }

            showKageraStatus(
                result.message ||
                "Results uploaded successfully.",
                "success"
            );

            kageraUploadForm.reset();
            kageraFileName.textContent =
                "Supported formats: .xlsx, .xls";

            await loadKageraResults();

        } catch (error) {
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
    });
}

function showKageraStatus(message, type) {
    if (!kageraUploadStatus) return;

    kageraUploadStatus.textContent = message;
    kageraUploadStatus.className =
        "kagera-upload-status " + type;
}

async function loadKageraResults() {
    const body =
        document.getElementById("kageraResultsBody");

    if (!body) return;

    body.innerHTML =
        '<tr><td colspan="11" class="kagera-empty-state">' +
        'Loading results...' +
        '</td></tr>';

    try {
        const response =
            await fetch("kagera_auction.php?action=fetch", { cache: "no-store" });

        const result =
            await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message ||
                "Unable to load results."
            );
        }

        if (!result.data || !result.data.length) {
            body.innerHTML =
                '<tr><td colspan="11" class="kagera-empty-state">' +
                'No Kagera Auction results loaded.' +
                '</td></tr>';
            return;
        }

        body.innerHTML =
            result.data.map(function (row, index) {
                return "<tr>" +
                    "<td>" + escapeKageraHtml(row.lot_no ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.auction_no ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.date_sold ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.sell_mark ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.warehouse ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.warehouse_location ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.net_weight ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.grade ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.grade2 ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.price ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.buyer_name ?? "") + "</td>" +
                    "</tr>";
            }).join("");

    } catch (error) {
        body.innerHTML =
            '<tr><td colspan="11" class="kagera-empty-state">' +
            escapeKageraHtml(
                error.message ||
                "Unable to load results."
            ) +
            "</td></tr>";
    }
}

function escapeKageraHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener(
    "DOMContentLoaded",
    function () {
        loadKageraResults();
    }
);
</script>

</body>
</html>
