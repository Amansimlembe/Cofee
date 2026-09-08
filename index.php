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

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
Coffee Sales Data Analysis System
</title>


<style>
/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    top: 0;

    left: 0;

    width: 270px;

    --sidebar-width: 270px;

    height: 100vh;

    background: #3e2723;

    color: white;

    z-index: 1000;

    transition: width 0.3s ease;

    box-shadow:
        3px 0 15px rgba(0,0,0,0.12);

    overflow-x: visible;

    overflow-y: auto;
}


/* COLLAPSED */

.sidebar.collapsed {

    width: 78px;

    --sidebar-width: 78px;

}


/* SIDEBAR HEADER */

.sidebar-header {

    height: 90px;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 15px 52px 15px 20px;

    background: #2b1b18;

    border-bottom:
        1px solid rgba(255,255,255,0.1);

    white-space: nowrap;

}


.logo {

    display: flex;

    align-items: center;

    gap: 12px;

}


.logo-icon {

    font-size: 27px;

}


.logo-text {

    font-size: 18px;

    font-weight: bold;

}


.logo-subtitle {

    display: block;

    font-size: 11px;

    color: #d7ccc8;

    margin-top: 3px;

}


.sidebar.collapsed .logo-text,
.sidebar.collapsed .logo-subtitle {

    display: none;

}


/* =========================================================
   MENU
========================================================= */

.menu {

    list-style: none;

    padding: 20px 10px;

    margin: 0;

}


.menu-item {

    margin-bottom: 10px;

}

.menu-item:last-child {

    margin-bottom: 0;

}


.menu-link {

    width: 100%;

    min-height: 48px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 11px 13px;

    border: none;

    border-radius: 7px;

    background: transparent;

    color: white;

    cursor: pointer;

    font-size: 14px;

    transition: all 0.2s ease;

    white-space: nowrap;

}


.menu-link:hover {

    background: #5d4037;

}


.menu-link.active {

    background: #6d4c41;

}


.menu-left {

    display: flex;

    align-items: center;

    gap: 13px;

    min-width: 0;

}


.menu-icon {

    width: 25px;

    min-width: 25px;

    text-align: center;

    font-size: 19px;

}


.menu-text {

    font-size: 14px;

}


.arrow {

    flex: 0 0 auto;

    margin-left: 4px;

    margin-right: 14px;

    width: 14px;

    text-align: center;

    font-size: 10px;

    line-height: 1;

    transition:
        transform 0.25s ease;

}


.menu-item.open >
.menu-link .arrow {

    transform:
        rotate(90deg);

}


/* COLLAPSED MENU */

.sidebar.collapsed .menu-link {

    justify-content: center;

    padding: 11px 8px;

}


.sidebar.collapsed .menu-left {

    justify-content: center;

}


.sidebar.collapsed .menu-text,
.sidebar.collapsed .arrow {

    display: none;

}


/* =========================================================
   SUBMENU
========================================================= */

.submenu {

    list-style: none;

    display: none;

    margin:
        4px 12px 9px 37px;

    border-left:
        1px solid rgba(255,255,255,0.15);

    padding-left: 8px;

}


.menu-item.open .submenu {

    display: block;

}


.submenu a {

    display: block;

    padding: 10px 12px;

    color: #d7ccc8;

    text-decoration: none;

    font-size: 13px;

    border-radius: 5px;

    cursor: pointer;

    transition: all 0.2s ease;

}


.submenu a:hover {

    background: #5d4037;

    color: white;

}


.submenu a.active {

    background: #6d4c41;

    color: white;

}


.sidebar.collapsed .submenu {
    display: none !important;
}


/* =========================================================
   COLLAPSED SIDEBAR TOOLTIP
========================================================= */

.sidebar.collapsed
.menu-item {

    position: relative;

}


/*
When sidebar is collapsed,
hovering an icon displays the
submenu names.
*/

.sidebar.collapsed
.menu-item:hover  .collapsed-tooltip {
    display: none;
    position: fixed;
    left: 78px;
    top: auto;
    background: #2b1b18;
    color: white;
    min-width: 210px;
    padding: 8px;
    border-radius: 0 8px 8px 0;
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    z-index: 3000;
    box-sizing: border-box;
}

/* Invisible hover bridge removes the gap between the icon and tooltip. */
.collapsed-tooltip::before {
    content: "";
    position: absolute;
    left: -12px;
    top: 0;
    width: 12px;
    height: 100%;
}


.collapsed-tooltip {

    display: none;

    position: fixed;

    left: 86px;

    background: #2b1b18;

    color: white;

    min-width: 210px;

    padding: 8px;

    border-radius: 8px;

    box-shadow:
        0 8px 25px rgba(0,0,0,0.25);

    z-index: 2000;

}


.collapsed-tooltip a {

    display: block;

    color: white;

    text-decoration: none;

    padding: 9px 12px;

    border-radius: 5px;

    font-size: 13px;

}


.collapsed-tooltip a:hover {

    background: #6d4c41;

}


/* =========================================================
   SIDEBAR TOGGLE
========================================================= */

 .sidebar-toggle {
    position: absolute;
    top: 29px;
    right: 12px;
    left: auto;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid rgba(255,255,255,0.8);
    border-radius: 50%;
    background: #4e342e;
    color: #ffffff;
    cursor: pointer;
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    box-sizing: border-box;
    box-shadow: 0 3px 10px rgba(0,0,0,0.28);
    transition:
        background 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.sidebar-toggle:hover {
    background: #6d4c41;
    box-shadow: 0 4px 14px rgba(0,0,0,0.34);
    transform: scale(1.04);
}

.sidebar-toggle:focus-visible {
    outline: 2px solid #d7ccc8;
    outline-offset: 2px;
}


.sidebar-toggle:hover {

    background: #8d6e63;

    transform: scale(1.05);

}


/* =========================================================
   MAIN
========================================================= */

.main {

    margin-left: 270px;

    min-height: 100vh;

    transition:
        margin-left 0.3s ease;

}


.sidebar.collapsed ~ .main {

    margin-left: 78px;

}


/* =========================================================
   TOP BAR
========================================================= */

.topbar {

    height: 72px;

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #faf8f7
        );

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 30px;

    border-bottom:
        1px solid #e5e0de;

    box-shadow:
        0 2px 10px rgba(62,39,35,0.06);

}


.topbar-left {

    display: flex;

    align-items: center;

    gap: 12px;

}


.topbar-icon {

    width: 40px;

    height: 40px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 9px;

    background: #3e2723;

    color: white;

    font-size: 20px;

}


.topbar-title {

    font-size: 19px;

    font-weight: bold;

    color: #3e2723;

}


.topbar-subtitle {

    font-size: 11px;

    color: #999;

    margin-top: 3px;

}


.topbar-right {

    display: flex;

    align-items: center;

    gap: 18px;

}


.market-label {

    font-size: 12px;

    color: #777;

}


.user-info {

    display: flex;

    align-items: center;

    gap: 9px;

    padding-left: 15px;

    border-left:
        1px solid #ddd;

}


.user-avatar {

    width: 34px;

    height: 34px;

    border-radius: 50%;

    background: #6d4c41;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 14px;

}


.user-name {

    font-size: 13px;

    font-weight: bold;

    color: #4e342e;

}


.logout {

    text-decoration: none;

    color: #795548;

    font-size: 12px;

}


.logout:hover {

    color: #3e2723;

}


/* =========================================================
   CONTENT
========================================================= */

.content {

    padding: 30px;

}


/* =========================================================
   WELCOME
========================================================= */

.welcome {

    min-height:
        calc(100vh - 130px);

    display: flex;

    align-items: center;

    justify-content: center;

    text-align: center;

}


.welcome-box {

    max-width: 650px;

}


.welcome-icon {

    font-size: 65px;

    margin-bottom: 20px;

}


.welcome-box h1 {

    color: #3e2723;

    font-size: 32px;

    margin-bottom: 12px;

}


.welcome-box p {

    color: #777;

    font-size: 15px;

    line-height: 1.7;

}


.welcome-hint {

    margin-top: 25px;

    padding: 13px 18px;

    display: inline-block;

    background: white;

    border-radius: 7px;

    color: #6d4c41;

    box-shadow:
        0 2px 10px rgba(0,0,0,0.06);

}


/* =========================================================
   SECTIONS
========================================================= */

.section {

    display: none;

}


.section.active {

    display: block;

}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    margin-bottom: 25px;

}


.page-header h1 {

    color: #3e2723;

    font-size: 27px;

    margin-bottom: 8px;

}


.page-header p {

    color: #777;

    font-size: 14px;

}


/* =========================================================
   DASHBOARD CARDS
========================================================= */

.card-container {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 20px;

}


.card {

    background: white;

    padding: 25px;

    border-radius: 10px;

    box-shadow:
        0 2px 12px rgba(0,0,0,0.06);

    border-left:
        4px solid #6d4c41;

}


.card h3 {

    color: #777;

    font-size: 14px;

    font-weight: normal;

    margin-bottom: 15px;

}


.value {

    font-size: 27px;

    font-weight: bold;

    color: #3e2723;

}


/* =========================================================
   SECTION BOX
========================================================= */

.section-box {

    background: white;

    padding: 30px;

    border-radius: 10px;

    box-shadow:
        0 2px 12px rgba(0,0,0,0.06);

}


.section-box h2 {

    color: #3e2723;

    font-size: 23px;

    margin-bottom: 10px;

}


.section-box p {

    color: #777;

    font-size: 14px;

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .card-container {

        grid-template-columns: 1fr;

    }

}


@media (max-width: 700px) {

    .sidebar {

        width: 78px;

        --sidebar-width: 78px;

    }

    .sidebar .logo-text,
    .sidebar .logo-subtitle,
    .sidebar .menu-text,
    .sidebar .arrow {

        display: none;

    }

    .sidebar .menu-link {

        justify-content: center;

    }

    .sidebar .menu-left {

        justify-content: center;

    }

    .main {

        margin-left: 78px;

    }

    .content {

        padding: 18px;

    }

    .topbar {

        padding: 0 18px;

    }

    .topbar-right {

        display: none;

    }

}

</style>

</head>
<body>
<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar" id="sidebar">


    <div class="sidebar-header">

        <div class="logo">

            <div class="logo-icon">
                ☕
            </div>

            <div>

                <div class="logo-text">
                    COFFEE
                </div>

                <span class="logo-subtitle">
                    Sales Analysis System
                </span>

            </div>

        </div>

    </div>


    <button
        class="sidebar-toggle"
        id="sidebarToggle"
        onclick="toggleSidebar()">

        ◀

    </button>


    <ul class="menu">


        <!-- DASHBOARD -->

        <li class="menu-item">

            <div
                class="menu-link"
                onclick="showSection('dashboard', this)">

                <div class="menu-left">

                    <span class="menu-icon">
                        📊
                    </span>

                    <span class="menu-text">
                        Dashboard
                    </span>

                </div>

            </div>

        </li>


        <!-- AUCTION SALES -->

        <li class="menu-item">

            <div
                class="menu-link"
                onclick="toggleSubmenu(this)">

                <div class="menu-left">

                    <span class="menu-icon">
                        🏷️
                    </span>

                    <span class="menu-text">
                        Auction Sales
                    </span>

                </div>

                <span class="arrow">
                    ▶
                </span>

            </div>


            <ul class="submenu">

                <li>
                    <a onclick="showSection('clean-auction', this)">
                        Clean Auction
                    </a>
                </li>

                <li>
                    <a onclick="showSection('kagera-auction', this)">
                        Kagera Auction
                    </a>
                </li>

            </ul>


            <!-- COLLAPSED TOOLTIP -->

            <div class="collapsed-tooltip">

                <a onclick="showSection('clean-auction', this)">
                    Clean Auction
                </a>

                <a onclick="showSection('kagera-auction', this)">
                    Kagera Auction
                </a>

            </div>

        </li>


        <!-- DIRECT SALES -->

        <li class="menu-item">

            <div
                class="menu-link"
                onclick="toggleSubmenu(this)">

                <div class="menu-left">

                    <span class="menu-icon">
                        📈
                    </span>

                    <span class="menu-text">
                        Direct Sales
                    </span>

                </div>

                <span class="arrow">
                    ▶
                </span>

            </div>


            <ul class="submenu">

                <li>
                    <a onclick="showSection('direct-export', this)">
                        Direct Export (DE)
                    </a>
                </li>

                <li>
                    <a onclick="showSection('local-sale', this)">
                        Local Sale (LS)
                    </a>
                </li>

                <li>
                    <a onclick="showSection('local-roast', this)">
                        Local Roast (LR)
                    </a>
                </li>

            </ul>


            <!-- COLLAPSED TOOLTIP -->

            <div class="collapsed-tooltip">

                <a onclick="showSection('direct-export', this)">
                    Direct Export (DE)
                </a>

                <a onclick="showSection('local-sale', this)">
                    Local Sale (LS)
                </a>

                <a onclick="showSection('local-roast', this)">
                    Local Roast (LR)
                </a>

            </div>

        </li>


        <!-- FARM GATE -->

        <li class="menu-item">

            <div
                class="menu-link"
                onclick="showSection('farm-gate', this)">

                <div class="menu-left">

                    <span class="menu-icon">
                        🌱
                    </span>

                    <span class="menu-text">
                        Farm Gate Contract
                    </span>

                </div>

            </div>

        </li>


        <!-- COFFEE LICENSES -->

        <li class="menu-item">

            <div
                class="menu-link"
                onclick="showSection('coffee-licenses', this)">

                <div class="menu-left">

                    <span class="menu-icon">
                        📋
                    </span>

                    <span class="menu-text">
                        Coffee Licenses
                    </span>

                </div>

            </div>

        </li>


    </ul>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <!-- TOP BAR -->

    <div class="topbar">


        <div class="topbar-left">

            <div class="topbar-icon">
                ☕
            </div>


            <div>

                <div
                    class="topbar-title"
                    id="topbarTitle">

                    Coffee Sales Data Analysis

                </div>


                <div class="topbar-subtitle">
                    Tanzania Coffee Market
                </div>

            </div>

        </div>


        <div class="topbar-right">

            <div class="market-label">
                Tanzania Coffee Market
            </div>


            <div class="user-info">

                <div class="user-avatar">

                    <?= strtoupper(
                        substr($_SESSION["username"], 0, 1)
                    ) ?>

                </div>


                <div>

                    <div class="user-name">
                        <?= htmlspecialchars(
                            $_SESSION["username"]
                        ) ?>
                    </div>


                    <a
                        href="login.php?logout=1"
                        class="logout">

                        Logout

                    </a>

                </div>

            </div>

        </div>

    </div>


    <!-- CONTENT -->

    <div class="content">


        <!-- WELCOME -->

        <section
            id="welcome"
            class="welcome">

            <div class="welcome-box">

                <div class="welcome-icon">
                    ☕
                </div>

                <h1>
                    Coffee Sales Data Analysis
                </h1>

                <p>
                    Welcome to the Coffee Sales Data Analysis
                    System. Use the navigation menu on the left
                    to access sales, contracts, licensing and
                    analytical reports.
                </p>


                <div class="welcome-hint">

                    Select
                    <strong>Dashboard</strong>
                    from the sidebar to view the
                    coffee sales overview.

                </div>

            </div>

        </section>


        <!-- DASHBOARD -->

        <section
            id="dashboard"
            class="section">

            <div class="page-header">

                <h1>
                    Coffee Sales Dashboard
                </h1>

                <p>
                    Overview of coffee sales and market performance
                </p>

            </div>


            <div class="card-container">


                <div class="card">

                    <h3>
                        Total Coffee Sold
                    </h3>

                    <div class="value">
                        0 Kg
                    </div>

                </div>


                <div class="card">

                    <h3>
                        Total Sales Value
                    </h3>

                    <div class="value">
                        TZS 0
                    </div>

                </div>


                <div class="card">

                    <h3>
                        Average Price
                    </h3>

                    <div class="value">
                        TZS 0/Kg
                    </div>

                </div>


            </div>

        </section>


        <!-- CLEAN AUCTION -->

        <section
            id="clean-auction"
            class="section">

            <div class="section-box">

                <h2>
                    Clean Auction
                </h2>

                <p>
                    Clean Auction sales data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- KAGERA AUCTION -->

        <section
            id="kagera-auction"
            class="section">

            <div class="section-box">

                <h2>
                    Kagera Auction
                </h2>

                <p>
                    Kagera Auction sales data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- DIRECT EXPORT -->

        <section
            id="direct-export"
            class="section">

            <div class="section-box">

                <h2>
                    Direct Export (DE)
                </h2>

                <p>
                    Direct Export coffee sales data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- LOCAL SALE -->

        <section
            id="local-sale"
            class="section">

            <div class="section-box">

                <h2>
                    Local Sale (LS)
                </h2>

                <p>
                    Local Sale coffee data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- LOCAL ROAST -->

        <section
            id="local-roast"
            class="section">

            <div class="section-box">

                <h2>
                    Local Roast (LR)
                </h2>

                <p>
                    Local Roast coffee data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- FARM GATE -->

        <section
            id="farm-gate"
            class="section">

            <div class="section-box">

                <h2>
                    Farm Gate Contract (Kahawa Ghafi)
                </h2>

                <p>
                    Farm Gate Contract data and analysis
                    will appear here.
                </p>

            </div>

        </section>


        <!-- COFFEE LICENSES -->

        <section
            id="coffee-licenses"
            class="section">

            <div class="section-box">

                <h2>
                    Coffee Licenses
                </h2>

                <p>
                    Coffee license data and analysis
                    will appear here.
                </p>

            </div>

        </section>


    </div>

</main>


<script>

/* =========================================================
   SIDEBAR TOGGLE
========================================================= */

function toggleSidebar() {

    const sidebar =
        document.getElementById("sidebar");

    const toggleButton =
        document.getElementById("sidebarToggle");


    sidebar.classList.toggle("collapsed");

    document.querySelectorAll(".collapsed-tooltip").forEach(function(tooltip) {
        tooltip.style.display = "";
    });

    if (
        sidebar.classList.contains("collapsed")
    ) {

        toggleButton.innerHTML = "▶";

    } else {

        toggleButton.innerHTML = "◀";

    }

}


/* =========================================================
   SUBMENU
========================================================= */

function positionCollapsedTooltip(menuItem) {
    const tooltip = menuItem.querySelector(".collapsed-tooltip");

    if (!tooltip) return;

    const rect = menuItem.getBoundingClientRect();
    const tooltipHeight = tooltip.offsetHeight;
    const viewportPadding = 10;

    let top = rect.top;

    if (top + tooltipHeight > window.innerHeight - viewportPadding) {
        top = window.innerHeight - tooltipHeight - viewportPadding;
    }

    if (top < viewportPadding) {
        top = viewportPadding;
    }

    tooltip.style.top = top + "px";
}

document.querySelectorAll(".sidebar .menu-item").forEach(function(menuItem) {
    const tooltip = menuItem.querySelector(".collapsed-tooltip");

    if (!tooltip) return;

    menuItem.addEventListener("mouseenter", function() {
        if (document.getElementById("sidebar").classList.contains("collapsed")) {
            tooltip.style.display = "block";
            positionCollapsedTooltip(menuItem);
        }
    });

    menuItem.addEventListener("mouseleave", function(event) {
        if (!tooltip.contains(event.relatedTarget)) {
            tooltip.style.display = "";
        }
    });

    tooltip.addEventListener("mouseenter", function() {
        if (document.getElementById("sidebar").classList.contains("collapsed")) {
            tooltip.style.display = "block";
            positionCollapsedTooltip(menuItem);
        }
    });

    tooltip.addEventListener("mouseleave", function() {
        tooltip.style.display = "";
    });
});

window.addEventListener("resize", function() {
    document.querySelectorAll(".sidebar.collapsed .menu-item").forEach(function(menuItem) {
        const tooltip = menuItem.querySelector(".collapsed-tooltip");
        if (tooltip && tooltip.style.display === "block") {
            positionCollapsedTooltip(menuItem);
        }
    });
});

function toggleSubmenu(element) {

    const menuItem =
        element.parentElement;


    const allMenuItems =
        document.querySelectorAll(".menu-item");


    allMenuItems.forEach(function(item) {

        if (item !== menuItem) {

            item.classList.remove("open");

        }

    });


    menuItem.classList.toggle("open");

}


/* =========================================================
   SHOW SECTION
========================================================= */

function showSection(
    sectionId,
    clickedElement
) {


    /*
    Hide welcome page
    */

    const welcome =
        document.getElementById("welcome");

    if (welcome) {

        welcome.style.display = "none";

    }


    /*
    Hide all sections
    */

    const sections =
        document.querySelectorAll(".section");


    sections.forEach(function(section) {

        section.classList.remove("active");

    });


    /*
    Display selected section
    */

    const selectedSection =
        document.getElementById(sectionId);


    if (selectedSection) {

        selectedSection.classList.add("active");

    }


    /*
    Remove active state
    */

    const menuLinks =
        document.querySelectorAll(
            ".menu-link, .submenu a"
        );


    menuLinks.forEach(function(link) {

        link.classList.remove("active");

    });


    /*
    Highlight clicked item
    */

    if (clickedElement) {

        clickedElement.classList.add("active");

    }


    /*
    Update top bar title
    */

    let title = "Coffee Sales Data Analysis";


    if (sectionId === "dashboard") {

        title = "Coffee Sales Dashboard";

    } else if (selectedSection) {

        const heading =
            selectedSection.querySelector(
                "h1, h2"
            );


        if (heading) {

            title =
                heading.textContent.trim();

        }

    }


    document.getElementById(
        "topbarTitle"
    ).textContent = title;

}

</script>
</body>
</html>