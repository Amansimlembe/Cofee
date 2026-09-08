<?php
session_start();

if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: login.php");
    exit;
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
                action="kagera_upload.php"
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
                            <th>#</th>
                            <th>Auction No.</th>
                            <th>Date Sold</th>
                            <th>Lot Number</th>
                            <th>Sell Mark</th>
                            <th>Invoice</th>
                            <th>Packages</th>
                            <th>Net Weight (Kg)</th>
                            <th>Grade</th>
                            <th>Grade2</th>
                            <th>Price</th>
                            <th>Buyer Name</th>
                            <th>Warehouse</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody id="kageraResultsBody">
                        <tr>
                            <td colspan="14" class="kagera-empty-state">
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
            const response = await fetch(
                "kagera_upload.php",
                {
                    method: "POST",
                    body: formData
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

            loadKageraResults();

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
        '<tr><td colspan="14" class="kagera-empty-state">' +
        'Loading results...' +
        '</td></tr>';

    try {
        const response =
            await fetch("kagera_fetch.php");

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
                '<tr><td colspan="14" class="kagera-empty-state">' +
                'No Kagera Auction results loaded.' +
                '</td></tr>';
            return;
        }

        body.innerHTML =
            result.data.map(function (row, index) {
                return "<tr>" +
                    "<td>" + escapeKageraHtml(index + 1) + "</td>" +
                    "<td>" + escapeKageraHtml(row.auction_no ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.date_sold ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.lot_number ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.sell_mark ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.invoice ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.packages ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.net_weight ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.grade ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.grade2 ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.price ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.buyer_name ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.warehouse ?? "") + "</td>" +
                    "<td>" + escapeKageraHtml(row.status ?? "") + "</td>" +
                    "</tr>";
            }).join("");

    } catch (error) {
        body.innerHTML =
            '<tr><td colspan="14" class="kagera-empty-state">' +
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
