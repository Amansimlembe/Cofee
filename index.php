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
    box-shadow: 3px 0 15px rgba(0,0,0,0.12);
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
    padding: 15px 50px 15px 22px;
    background: #2b1b18;
    border-bottom: 1px solid rgba(255,255,255,0.1);
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

.sidebar.collapsed .sidebar-header {
    padding: 15px 8px;
}



/* =========================================================
   MENU
========================================================= */

.menu {
    list-style: none;
    padding: 18px 14px 24px;
    margin: 0;
}


.menu-item {
    margin-bottom: 6px;
}

.menu-item:last-child {

    margin-bottom: 0;

}


.menu-link {
    width: 100%;
    min-height: 46px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
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
    gap: 12px;
    min-width: 0;
}


.menu-icon {
    width: 24px;
    min-width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: 18px;
}


.menu-text {

    font-size: 14px;

}


.arrow {
    flex: 0 0 14px;
    margin-left: 10px;
    width: 14px;
    text-align: center;
    font-size: 10px;
    line-height: 1;
    transition: transform 0.25s ease;
}


.menu-item.open >
.menu-link .arrow {

    transform:
        rotate(90deg);

}


/* COLLAPSED MENU */

.sidebar.collapsed .menu-link {
    justify-content: center;
    padding: 10px 8px;
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
    margin: 5px 10px 8px 36px;
    border-left: 1px solid rgba(255,255,255,0.15);
    padding: 3px 0 3px 9px;
}


.menu-item.open .submenu {

    display: block;

}


.submenu a {
    display: block;
    padding: 9px 11px;
    color: #d7ccc8;
    text-decoration: none;
    font-size: 13px;
    line-height: 1.35;
    border-radius: 6px;
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
   KAGERA AUCTION PAGE
========================================================= */

.kagera-page-section, .clean-page-section {
    width: 100%;
    min-height: calc(100vh - 130px);
    padding: 0;
    overflow: hidden;
}

.kagera-auction-frame, .clean-auction-frame {
    display: block;
    width: 100%;
    min-height: calc(100vh - 130px);
    height: calc(100vh - 130px);
    border: 0;
    margin: 0;
    padding: 0;
    background: transparent;
}

@media (max-width: 700px) {


    .kagera-page-section, .clean-page-section {
        min-height: calc(100vh - 110px);
    }

    .kagera-auction-frame, .clean-auction-frame {
        min-height: calc(100vh - 110px);
        height: calc(100vh - 110px);
    }
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
        padding: 10px 8px;
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


/* =========================================================
   COMPLETE RESPONSIVE OVERRIDES
   Keeps the sidebar, main content and data areas usable
   across desktop, tablet and mobile screens.
========================================================= */

/* Large tablets / small laptops */
@media (max-width: 1100px) {
    .sidebar {
        width: 240px;
        --sidebar-width: 240px;
    }

    .main {
        margin-left: 240px;
    }

    .sidebar-header {
        padding-left: 18px;
        padding-right: 46px;
    }

    .menu {
        padding-left: 12px;
        padding-right: 12px;
    }

    .menu-link {
        padding-left: 12px;
        padding-right: 12px;
    }

    .content {
        padding: 24px;
    }

    .topbar {
        padding-left: 24px;
        padding-right: 24px;
    }
}

/* Tablets */
@media (max-width: 900px) {
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

    .sidebar .sidebar-header {
        padding: 15px 8px;
    }

    .sidebar .logo {
        justify-content: center;
    }

    .sidebar .menu {
        padding: 18px 10px 24px;
    }

    .sidebar .menu-link {
        justify-content: center;
        padding: 10px 8px;
    }

    .sidebar .menu-left {
        justify-content: center;
    }

    .main,
    .sidebar.collapsed ~ .main {
        margin-left: 78px;
    }

    .topbar {
        min-height: 68px;
        padding-left: 20px;
        padding-right: 20px;
    }

    .topbar-title {
        font-size: 17px;
    }

    .content {
        padding: 22px;
    }

    .card-container {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .section-box {
        max-width: 100%;
    }
}

/* Mobile phones */
@media (max-width: 600px) {
    .sidebar,
    .sidebar.collapsed {
        width: 68px;
        --sidebar-width: 68px;
    }

    .sidebar .menu {
        padding: 14px 8px 20px;
    }

    .sidebar .menu-link {
        min-height: 44px;
        padding: 9px 6px;
    }

    .sidebar-toggle {
        top: 28px;
        right: 7px;
        width: 28px;
        height: 28px;
        font-size: 11px;
    }

    .main,
    .sidebar.collapsed ~ .main {
        margin-left: 68px;
    }

    .topbar {
        min-height: 64px;
        height: auto;
        padding: 10px 14px;
    }

    .topbar-left {
        gap: 9px;
        min-width: 0;
    }

    .topbar-icon {
        width: 36px;
        height: 36px;
        min-width: 36px;
        font-size: 18px;
    }

    .topbar-title {
        font-size: 15px;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .topbar-subtitle {
        font-size: 10px;
    }

    .content {
        padding: 16px 12px;
    }

    .page-header {
        margin-bottom: 18px;
    }

    .page-header h1 {
        font-size: 22px;
        line-height: 1.25;
    }

    .page-header p {
        font-size: 13px;
        line-height: 1.45;
    }

    .card-container {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .card {
        padding: 18px;
        min-width: 0;
    }

    .section-box {
        padding: 18px;
        border-radius: 8px;
        overflow-x: auto;
    }

    .section-box h2 {
        font-size: 20px;
        line-height: 1.3;
    }

    .section-box p {
        line-height: 1.5;
    }

    /* Prevent wide tables/data grids from breaking the page. */
    table {
        min-width: 620px;
    }

    .table-responsive,
    .table-container,
    .data-table-container,
    .table-wrapper {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
.clean-page-section {
        min-height: calc(100vh - 108px);
    }
    .kagera-page-section {
        min-height: calc(100vh - 108px);
    }

.clean-auction-frame {
        min-height: calc(100vh - 108px);
        height: calc(100vh - 108px);
    }
}
    .kagera-auction-frame {
        min-height: calc(100vh - 108px);
        height: calc(100vh - 108px);
    }
}

/* Very small phones */
@media (max-width: 400px) {
    .sidebar,
    .sidebar.collapsed {
        width: 60px;
        --sidebar-width: 60px;
    }

    .main,
    .sidebar.collapsed ~ .main {
        margin-left: 60px;
    }

    .sidebar-toggle {
        right: 5px;
        width: 26px;
        height: 26px;
        font-size: 10px;
    }

    .sidebar .menu {
        padding-left: 6px;
        padding-right: 6px;
    }

    .sidebar .menu-link {
        min-height: 42px;
        padding-left: 5px;
        padding-right: 5px;
    }

    .content {
        padding: 12px 9px;
    }

    .topbar {
        padding: 9px 10px;
    }

    .topbar-icon {
        width: 32px;
        height: 32px;
        min-width: 32px;
        font-size: 16px;
    }

    .topbar-title {
        font-size: 14px;
    }

    .page-header h1 {
        font-size: 20px;
    }
}


/* =========================================================
   DISPLAY-FIRST RESPONSIVE LAYOUT
   Compact navigation chrome so embedded dashboards receive
   the maximum practical viewport on every screen size.
========================================================= */
:root {
    --shell-sidebar: 220px;
    --shell-sidebar-collapsed: 62px;
    --shell-topbar: 54px;
}

.sidebar { width: var(--shell-sidebar); --sidebar-width: var(--shell-sidebar); }
.sidebar.collapsed { width: var(--shell-sidebar-collapsed); --sidebar-width: var(--shell-sidebar-collapsed); }
.sidebar-header { height: 68px; padding: 10px 42px 10px 14px; }
.logo-icon { font-size: 23px; }
.logo-text { font-size: 16px; }
.logo-subtitle { font-size: 10px; }
.menu { padding: 10px 8px 16px; }
.menu-item { margin-bottom: 3px; }
.menu-link { min-height: 40px; padding: 7px 9px; font-size: 13px; }
.menu-left { gap: 9px; }
.menu-icon { width: 22px; min-width: 22px; height: 22px; font-size: 16px; }
.menu-text { font-size: 13px; }
.submenu { margin: 3px 6px 5px 29px; padding-left: 7px; }
.submenu a { padding: 7px 8px; font-size: 12px; }
.sidebar-toggle { top: 20px; right: 8px; width: 27px; height: 27px; }
.main { margin-left: var(--shell-sidebar); min-width: 0; }
.sidebar.collapsed ~ .main { margin-left: var(--shell-sidebar-collapsed); }
.topbar { height: var(--shell-topbar); min-height: var(--shell-topbar); padding: 0 16px; }
.topbar-left { gap: 9px; min-width: 0; }
.topbar-icon { width: 32px; height: 32px; min-width: 32px; font-size: 16px; border-radius: 7px; }
.topbar-title { font-size: 16px; line-height: 1.15; }
.topbar-subtitle { font-size: 9px; margin-top: 1px; }
.topbar-right { gap: 10px; }
.market-label { font-size: 10px; }
.user-info { gap: 7px; padding-left: 10px; }
.user-avatar { width: 29px; height: 29px; font-size: 12px; }
.user-name { font-size: 11px; }
.logout { font-size: 10px; }
.content { padding: 8px; width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden; }
.kagera-page-section, .clean-page-section { min-height: calc(100dvh - var(--shell-topbar) - 16px); height: calc(100dvh - var(--shell-topbar) - 16px); }
.kagera-auction-frame, .clean-auction-frame { min-height: 100%; height: 100%; width: 100%; }

@media (max-width: 1100px) {
    :root { --shell-sidebar: 190px; }
    .sidebar { width: var(--shell-sidebar); --sidebar-width: var(--shell-sidebar); }
    .main { margin-left: var(--shell-sidebar); }
    .content { padding: 6px; }
    .market-label { display: none; }
}

@media (max-width: 900px) {
    :root { --shell-sidebar: 58px; --shell-sidebar-collapsed: 58px; --shell-topbar: 50px; }
    .sidebar, .sidebar.collapsed { width: var(--shell-sidebar); --sidebar-width: var(--shell-sidebar); }
    .sidebar .logo-text, .sidebar .logo-subtitle, .sidebar .menu-text, .sidebar .arrow { display: none; }
    .sidebar-header { height: 58px; padding: 8px 5px; }
    .sidebar .menu { padding: 8px 5px 12px; }
    .sidebar .menu-link { min-height: 38px; justify-content: center; padding: 6px 4px; }
    .sidebar .menu-left { justify-content: center; gap: 0; }
    .sidebar-toggle { display: none; }
    .main, .sidebar.collapsed ~ .main { margin-left: var(--shell-sidebar); }
    .topbar { padding: 0 10px; }
    .topbar-right { display: none; }
    .content { padding: 4px; }
    .kagera-page-section, .clean-page-section { min-height: calc(100dvh - var(--shell-topbar) - 8px); height: calc(100dvh - var(--shell-topbar) - 8px); }
}

@media (max-width: 600px) {
    :root { --shell-sidebar: 50px; --shell-sidebar-collapsed: 50px; --shell-topbar: 46px; }
    .sidebar, .sidebar.collapsed { width: var(--shell-sidebar); --sidebar-width: var(--shell-sidebar); }
    .main, .sidebar.collapsed ~ .main { margin-left: var(--shell-sidebar); }
    .sidebar-header { height: 50px; padding: 6px 3px; }
    .logo-icon { font-size: 19px; }
    .sidebar .menu { padding: 6px 4px 10px; }
    .sidebar .menu-link { min-height: 36px; padding: 5px 3px; }
    .menu-icon { width: 20px; min-width: 20px; height: 20px; font-size: 15px; }
    .topbar { height: var(--shell-topbar); min-height: var(--shell-topbar); padding: 0 7px; }
    .topbar-icon { width: 28px; height: 28px; min-width: 28px; font-size: 14px; }
    .topbar-title { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-subtitle { display: none; }
    .content { padding: 2px; }
    .kagera-page-section, .clean-page-section { min-height: calc(100dvh - var(--shell-topbar) - 4px); height: calc(100dvh - var(--shell-topbar) - 4px); }
}

@media (max-width: 400px) {
    :root { --shell-sidebar: 46px; --shell-sidebar-collapsed: 46px; --shell-topbar: 44px; }
    .sidebar, .sidebar.collapsed { width: var(--shell-sidebar); --sidebar-width: var(--shell-sidebar); }
    .main, .sidebar.collapsed ~ .main { margin-left: var(--shell-sidebar); }
    .topbar-left { gap: 6px; }
    .topbar-title { font-size: 12px; }
}


/* SMART COMPACT NAVIGATION */
.mobile-account-button,.mobile-account-menu{display:none}
@media(max-width:900px){
 .topbar-right{display:flex!important;margin-left:auto;position:relative;flex:0 0 auto;gap:0}
 .topbar-right>.market-label,.topbar-right>.user-info{display:none!important}
 .mobile-account-button{display:inline-flex;width:30px;height:30px;border:0;border-radius:50%;align-items:center;justify-content:center;background:#6d4c41;color:#fff;font-size:11px;font-weight:700;cursor:pointer}
 .mobile-account-menu{position:absolute;top:calc(100% + 7px);right:0;width:min(245px,calc(100vw - var(--shell-sidebar) - 18px));padding:10px;background:#fff;border:1px solid #e6dedb;border-radius:9px;box-shadow:0 10px 28px rgba(45,29,25,.18);z-index:5000}
 .mobile-account-menu.open{display:block}
 .mobile-account-market{font-size:10px;color:#8b7b75;padding:1px 2px 8px;border-bottom:1px solid #eee7e4}
 .mobile-account-user{display:flex;align-items:center;gap:8px;padding:9px 2px 8px;min-width:0}
 .mobile-account-avatar{width:27px;height:27px;flex:0 0 27px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#6d4c41;color:#fff;font-size:11px;font-weight:700}
 .mobile-account-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#4e342e;font-size:11px;font-weight:600}
 .mobile-logout{display:block;padding:7px 9px;border-radius:6px;background:#f6f1ef;color:#5d4037;text-decoration:none;text-align:center;font-size:11px;font-weight:700}
 .sidebar .menu-item{position:relative}
 .sidebar .menu-item.mobile-open>.collapsed-tooltip{display:block!important;position:fixed;left:var(--shell-sidebar)!important;min-width:175px;max-width:min(230px,calc(100vw - var(--shell-sidebar) - 8px));padding:6px;border-radius:0 8px 8px 0;background:#2b1b18;box-shadow:0 8px 24px rgba(0,0,0,.24);z-index:4500}
 .sidebar .menu-item.mobile-open>.collapsed-tooltip a{display:block;padding:8px 10px;border-radius:5px;color:#fff;font-size:12px;line-height:1.25}
 .topbar-left{min-width:0;flex:1 1 auto}
 .topbar-title{max-width:100%;overflow:hidden;text-overflow:ellipsis}
}
@media(max-width:600px){
 .mobile-account-button{width:28px;height:28px;font-size:10px}
 .mobile-account-menu{width:min(220px,calc(100vw - var(--shell-sidebar) - 12px));top:calc(100% + 5px)}
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
                class="menu-link active"
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
                    <a onclick="openCleanAuction(this)">

                    Clean Auction
                </a>
                </li>

                <li>
                    <a onclick="openKageraAuction(this)">
                        Kagera Auction
                    </a>
                </li>

            </ul>


            <!-- COLLAPSED TOOLTIP -->

            <div class="collapsed-tooltip">

               
<a onclick="openCleanAuction(this)">

                    Clean Auction
                </a>
                <a onclick="openKageraAuction(this)">
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


        <div class="topbar-right" id="topbarRight">

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

            <button type="button" class="mobile-account-button" id="mobileAccountButton"
                    aria-label="Open account menu" aria-expanded="false"
                    onclick="toggleMobileAccountMenu(event)">
                <?= strtoupper(substr($_SESSION["username"], 0, 1)) ?>
            </button>

            <div class="mobile-account-menu" id="mobileAccountMenu">
                <div class="mobile-account-market">Tanzania Coffee Market</div>
                <div class="mobile-account-user">
                    <span class="mobile-account-avatar"><?= strtoupper(substr($_SESSION["username"], 0, 1)) ?></span>
                    <span class="mobile-account-name"><?= htmlspecialchars($_SESSION["username"]) ?></span>
                </div>
                <a href="login.php?logout=1" class="mobile-logout">Logout</a>
            </div>

        </div>

    </div>


    <!-- CONTENT -->

    <div class="content">


        <!-- DASHBOARD -->

        <section
            id="dashboard"
            class="section kagera-page-section active">

            <iframe
                id="dashboardFrame"
                src="dashboard.php"
                title="Kagera Auction Dashboard"
                class="kagera-auction-frame">
            </iframe>

        </section>


        <!-- KAGERA AUCTION -->

        <section
            id="kagera-auction"
            class="section kagera-page-section">

            <iframe
                id="kageraAuctionFrame"
                src="about:blank"
                title="Kagera Auction"
                class="kagera-auction-frame">
            </iframe>

        </section>

        <!-- CLEAN AUCTION -->

        <section
            id="clean-auction"
            class="section clean-page-section">

            <iframe
                id="cleanAuctionFrame"
                src="about:blank"
                title="Clean Auction"
                class="clean-auction-frame">
            </iframe>

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
    const menuItem=element.parentElement;
    const compact=window.matchMedia("(max-width: 900px)").matches;

    document.querySelectorAll(".menu-item").forEach(function(item){
        if(item!==menuItem){
            item.classList.remove("open","mobile-open");
            const tip=item.querySelector(".collapsed-tooltip");
            if(tip) tip.style.display="";
        }
    });

    if(compact){
        const tip=menuItem.querySelector(".collapsed-tooltip");
        if(!tip) return;
        const opening=!menuItem.classList.contains("mobile-open");
        menuItem.classList.toggle("mobile-open",opening);
        menuItem.classList.remove("open");
        if(opening){
            tip.style.display="block";
            positionCollapsedTooltip(menuItem);
        }else{
            tip.style.display="";
        }
        return;
    }
    menuItem.classList.toggle("open");
}

function toggleMobileAccountMenu(event){
    if(event) event.stopPropagation();
    const menu=document.getElementById("mobileAccountMenu");
    const button=document.getElementById("mobileAccountButton");
    if(!menu||!button) return;
    const opening=!menu.classList.contains("open");
    menu.classList.toggle("open",opening);
    button.setAttribute("aria-expanded",opening?"true":"false");
}

document.addEventListener("click",function(event){
    const menu=document.getElementById("mobileAccountMenu");
    const button=document.getElementById("mobileAccountButton");
    if(menu&&button&&!menu.contains(event.target)&&!button.contains(event.target)){
        menu.classList.remove("open");
        button.setAttribute("aria-expanded","false");
    }
    if(window.matchMedia("(max-width: 900px)").matches&&!event.target.closest(".sidebar .menu-item")){
        document.querySelectorAll(".menu-item.mobile-open").forEach(function(item){
            item.classList.remove("mobile-open");
            const tip=item.querySelector(".collapsed-tooltip");
            if(tip) tip.style.display="";
        });
    }
});

/* =========================================================
   CLEAN AUCTION NAVIGATION
========================================================= */

function openCleanAuction(clickedElement) {
    document.querySelectorAll(".menu-item.mobile-open").forEach(function(item){
        item.classList.remove("mobile-open");
        const tip=item.querySelector(".collapsed-tooltip");
        if(tip) tip.style.display="";
    });


    document.querySelectorAll(".section").forEach(function(section) {
        section.classList.remove("active");
    });

    const CleanSection = document.getElementById("clean-auction");

    if (CleanSection) {
        CleanSection.classList.add("active");
    }

    const frame = document.getElementById("cleanAuctionFrame");

    if (frame) {
        /* The Kagera page normally has its own sidebar offset.
           Inside index.php it must start at the main-content edge. */
        frame.onload = function () {
            try {
                const CleanDocument = frame.contentDocument || frame.contentWindow.document;
                const CleanMain = CleanDocument.querySelector(".clean-main");

                if (CleanMain) {
                    CleanMain.style.marginLeft = "0";
                    CleanMain.style.minHeight = "100%";
                }
            } catch (error) {
                console.warn("Unable to adjust Kagera page layout.", error);
            }
        };

        if (frame.getAttribute("src") === "about:blank") {
            frame.src = "Clean_auction.php";
        }
    }

    document.querySelectorAll(".menu-link, .submenu a").forEach(function(link) {
        link.classList.remove("active");
    });

    if (clickedElement) {
        clickedElement.classList.add("active");
    }

    const topbarTitle = document.getElementById("topbarTitle");

    if (topbarTitle) {
        topbarTitle.textContent = "Clean Auction";
    }
}

/* =========================================================
   KAGERA AUCTION NAVIGATION
========================================================= */

function openKageraAuction(clickedElement) {
    document.querySelectorAll(".menu-item.mobile-open").forEach(function(item){
        item.classList.remove("mobile-open");
        const tip=item.querySelector(".collapsed-tooltip");
        if(tip) tip.style.display="";
    });


    document.querySelectorAll(".section").forEach(function(section) {
        section.classList.remove("active");
    });

    const kageraSection = document.getElementById("kagera-auction");

    if (kageraSection) {
        kageraSection.classList.add("active");
    }

    const frame = document.getElementById("kageraAuctionFrame");

    if (frame) {
        /* The Kagera page normally has its own sidebar offset.
           Inside index.php it must start at the main-content edge. */
        frame.onload = function () {
            try {
                const kageraDocument = frame.contentDocument || frame.contentWindow.document;
                const kageraMain = kageraDocument.querySelector(".kagera-main");

                if (kageraMain) {
                    kageraMain.style.marginLeft = "0";
                    kageraMain.style.minHeight = "100%";
                }
            } catch (error) {
                console.warn("Unable to adjust Kagera page layout.", error);
            }
        };

        if (frame.getAttribute("src") === "about:blank") {
            frame.src = "kagera_auction.php";
        }
    }

    document.querySelectorAll(".menu-link, .submenu a").forEach(function(link) {
        link.classList.remove("active");
    });

    if (clickedElement) {
        clickedElement.classList.add("active");
    }

    const topbarTitle = document.getElementById("topbarTitle");

    if (topbarTitle) {
        topbarTitle.textContent = "Kagera Auction";
    }
}

/* =========================================================
   SHOW SECTION
========================================================= */

function showSection(
    sectionId,
    clickedElement
) {


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