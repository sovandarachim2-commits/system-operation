<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
$user = current_user();
$profileAvatarUrl = (function_exists('user_profile_image_url') && $user) ? user_profile_image_url($user) : '';
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$can = static function(string $perm): bool {
    return function_exists('has_permission') ? has_permission($perm) : false;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Order System</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <link rel="apple-touch-icon-precomposed" href="<?= htmlspecialchars($BASE_URL) ?>/public/image.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/khmer-os-classic" rel="stylesheet">
    <link href="<?= htmlspecialchars($BASE_URL) ?>/public/css/app.css" rel="stylesheet">
    <script src="<?= htmlspecialchars($BASE_URL) ?>/public/js/admin-shell.js" defer></script>
    <style>
        /* Simple Responsive Admin Layout */
        .admin-sidebar {
            background: radial-gradient(1200px 600px at -200px -200px, rgba(253,176,76,0.08), transparent 60%), #232323;
            min-height: 100vh;
            color: #fdb04c;
        }
        /* Desktop styles */
        @media (min-width: 768px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 250px;
                height: 100vh;
                z-index: 1000;
                overflow-y: auto;
                transition: transform 0.3s ease;
            }
            .admin-sidebar.hidden {
                transform: translateX(-100%);
            }
            .admin-content {
                margin-left: 250px;
                width: calc(100% - 250px);
                transition: margin-left 0.3s ease, width 0.3s ease;
            }
            .admin-content.expanded {
                margin-left: 0;
                width: 100%;
            }
        }
        .admin-sidebar .nav-link {
            color: #fdb04c;
            padding: 12px 18px;
            border-radius: 10px;
            margin: 4px 10px;
            font-size: 1rem;
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.06s ease;
        }
        .admin-sidebar .nav-link:hover,
        .admin-sidebar .nav-link.active {
            background: #fdb04c;
            color: #232323;
        }
        .admin-sidebar .nav-link i[class*="bi-"] {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.9rem;
            height: 1.9rem;
            border-radius: 12px;
            margin-right: 0.75rem !important;
            flex-shrink: 0;
            font-size: 1rem;
            color: var(--menu-icon-color, #3b2a0d);
            background: var(--menu-icon-bg, linear-gradient(135deg, #ffe7b3 0%, #fdb04c 100%));
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 10px 18px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.38);
            transition: transform 0.15s ease, color 0.2s ease, opacity 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            opacity: 1;
            filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.16));
        }
        .admin-sidebar .nav-link:hover { box-shadow: 0 6px 16px rgba(0,0,0,0.15) inset, 0 2px 10px rgba(0,0,0,0.08); }
        .admin-sidebar .nav-link:hover i[class*="bi-"],
        .admin-sidebar .nav-link.active i[class*="bi-"] {
            transform: translateY(-1px) scale(1.05);
            opacity: 1;
            filter: drop-shadow(0 6px 10px rgba(253, 176, 76, 0.25));
            box-shadow: 0 12px 22px rgba(0, 0, 0, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .admin-sidebar .nav-link.active {
            position: relative;
            background: linear-gradient(90deg, rgba(253,176,76,0.58) 0%, rgba(253,176,76,0.36) 100%);
            box-shadow: inset 0 0 0 1px rgba(253,176,76,0.45);
        }
        .admin-sidebar .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;   
            top: 0;
            bottom: 0;
            width: 4px;
            background: #ffd060;
            border-top-left-radius: 4px;
            border-bottom-left-radius: 4px;
        }
        /* Section label styling */
        .admin-sidebar li.text-uppercase.small.text-secondary {
            color: #ffd18a !important;
            opacity: 0.9;
            letter-spacing: .06em;
            margin: 6px 16px 2px;
            padding-top: 6px;
            border-top: 1px dashed rgba(253,176,76,0.25);
        }
        /* Menu dropdown - clean accordion style */
        .admin-sidebar .menu-category-toggle {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 10px 16px;
            color: #fdb04c;
            background: transparent;
            border: none;
            border-radius: 10px;
            margin: 3px 8px;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
            text-align: left;
        }
        .admin-sidebar .menu-category-toggle:hover {
            background: rgba(253,176,76,0.15);
            color: #fff;
        }
        .admin-sidebar .menu-category-toggle[aria-expanded="true"] {
            background: rgba(253,176,76,0.2);
            color: #fff;
        }
        .admin-sidebar .menu-category-toggle .bi-chevron-down {
            margin-left: auto;
            transition: transform 0.25s ease;
        }
        .admin-sidebar .menu-category-toggle[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
        }
        .admin-sidebar .collapse .nav-link {
            padding: 12px 18px;
            margin: 4px 10px;
            font-size: 1rem;
            border-radius: 10px;
            min-height: 44px;
        }
        /* Top progress bar (YouTube / NProgress style) */
        .ajax-top-progress-wrap {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            z-index: 11000;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .ajax-top-progress-wrap.is-active {
            opacity: 1;
        }
        .ajax-top-progress-wrap .ajax-top-progress-bar {
            height: 100%;
            width: 35%;
            border-radius: 0 2px 2px 0;
            background: linear-gradient(90deg, #e8a028, #fdb04c, #ffcf7a, #fdb04c);
            box-shadow: 0 0 12px rgba(253, 176, 76, 0.55);
            transform: translateX(-100%);
        }
        .ajax-top-progress-wrap.is-active:not(.is-done) .ajax-top-progress-bar {
            animation: ajaxTopIndeterminate 0.9s ease-in-out infinite;
        }
        .ajax-top-progress-wrap.is-done .ajax-top-progress-bar {
            animation: none;
            width: 100% !important;
            transform: translateX(0) !important;
            transition: width 0.28s ease-out, transform 0.28s ease-out;
        }
        @keyframes ajaxTopIndeterminate {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(320%); }
        }
        #mainPageContent {
            transition: opacity 0.22s ease, transform 0.22s ease;
        }
        #mainPageContent.is-loading-content {
            opacity: 0.38;
            transform: translateY(4px);
            pointer-events: none;
        }
        #mainPageContent.content-fade-in {
            animation: mainContentFadeIn 0.38s ease forwards;
        }
        @keyframes mainContentFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: none; }
        }
        .ajax-content-skeleton-overlay {
            position: absolute;
            inset: 0;
            z-index: 6;
            padding: 1.25rem;
            pointer-events: none;
            border-radius: 12px;
        }
        .ajax-content-skeleton-overlay .sk-row {
            height: 14px;
            border-radius: 7px;
            margin-bottom: 12px;
            background: linear-gradient(90deg, #e9ecef 0%, #f8f9fa 45%, #e9ecef 90%);
            background-size: 200% 100%;
            animation: skeletonShimmer 1.1s ease-in-out infinite;
        }
        .ajax-content-skeleton-overlay .sk-row.w-50 { width: 50%; }
        .ajax-content-skeleton-overlay .sk-row.w-70 { width: 70%; }
        .ajax-content-skeleton-overlay .sk-card {
            height: 120px;
            border-radius: 12px;
            margin-top: 8px;
            background: linear-gradient(90deg, #e9ecef 0%, #f8f9fa 45%, #e9ecef 90%);
            background-size: 200% 100%;
            animation: skeletonShimmer 1.1s ease-in-out infinite;
        }
        @keyframes skeletonShimmer {
            0% { background-position: 100% 0; }
            100% { background-position: -100% 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ajax-top-progress-wrap .ajax-top-progress-bar { animation: none !important; }
            .ajax-top-progress-wrap.is-active:not(.is-done) .ajax-top-progress-bar {
                transform: translateX(0);
                width: 100%;
                opacity: 0.85;
            }
            #mainPageContent { transition: none; }
            #mainPageContent.content-fade-in { animation: none; opacity: 1; transform: none; }
            .ajax-content-skeleton-overlay .sk-row,
            .ajax-content-skeleton-overlay .sk-card { animation: none; }
        }
        .admin-sidebar .nav-link .bi-bar-chart-line { --menu-icon-bg: linear-gradient(135deg, #62c8ff 0%, #0090dd 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-graph-up,
        .admin-sidebar .nav-link .bi-graph-up-arrow { --menu-icon-bg: linear-gradient(135deg, #6de89a 0%, #12b84a 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-calendar-event { --menu-icon-bg: linear-gradient(135deg, #ff85a8 0%, #e8254e 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-gear,
        .admin-sidebar .nav-link .bi-gear-heart { --menu-icon-bg: linear-gradient(135deg, #ffb86c 0%, #e87500 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-cart-check,
        .admin-sidebar .nav-link .bi-cart4 { --menu-icon-bg: linear-gradient(135deg, #d580ff 0%, #9418f0 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-megaphone,
        .admin-sidebar .nav-link .bi-chat-dots { --menu-icon-bg: linear-gradient(135deg, #cc66ff 0%, #8800e8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-bag-check { --menu-icon-bg: linear-gradient(135deg, #ff7070 0%, #e01515 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-receipt,
        .admin-sidebar .nav-link .bi-file-earmark-text,
        .admin-sidebar .nav-link .bi-file-text,
        .admin-sidebar .nav-link .bi-file-earmark-bar-graph { --menu-icon-bg: linear-gradient(135deg, #6aaeff 0%, #1850d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-plus-circle,
        .admin-sidebar .nav-link .bi-check-circle { --menu-icon-bg: linear-gradient(135deg, #58e888 0%, #0ab844 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-clock-history { --menu-icon-bg: linear-gradient(135deg, #5ac0ff 0%, #0092dd 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-printer { --menu-icon-bg: linear-gradient(135deg, #ff7878 0%, #e02020 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-journal-text,
        .admin-sidebar .nav-link .bi-journal-bookmark { --menu-icon-bg: linear-gradient(135deg, #60b4ff 0%, #1568d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-calendar-date,
        .admin-sidebar .nav-link .bi-calendar-day,
        .admin-sidebar .nav-link .bi-calendar-check { --menu-icon-bg: linear-gradient(135deg, #ffd060 0%, #e89000 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-funnel { --menu-icon-bg: linear-gradient(135deg, #ff7080 0%, #e0102a 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-house-door,
        .admin-sidebar .nav-link .bi-speedometer2 { --menu-icon-bg: linear-gradient(135deg, #5ac8ff 0%, #0098e8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-box-seam,
        .admin-sidebar .nav-link .bi-archive { --menu-icon-bg: linear-gradient(135deg, #80e060 0%, #28b800 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-eye,
        .admin-sidebar .nav-link .bi-diagram-3 { --menu-icon-bg: linear-gradient(135deg, #40e8be 0%, #00b898 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-arrow-left-right,
        .admin-sidebar .nav-link .bi-arrow-repeat,
        .admin-sidebar .nav-link .bi-arrow-return-left { --menu-icon-bg: linear-gradient(135deg, #ffc840 0%, #e89000 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-pencil-square,
        .admin-sidebar .nav-link .bi-activity { --menu-icon-bg: linear-gradient(135deg, #a0b0ff 0%, #3040d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-collection,
        .admin-sidebar .nav-link .bi-clipboard2-data,
        .admin-sidebar .nav-link .bi-clipboard-data { --menu-icon-bg: linear-gradient(135deg, #50d0ff 0%, #00a0d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-geo-alt,
        .admin-sidebar .nav-link .bi-truck { --menu-icon-bg: linear-gradient(135deg, #ffb070 0%, #e07500 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-tags-fill,
        .admin-sidebar .nav-link .bi-tags,
        .admin-sidebar .nav-link .bi-tag { --menu-icon-bg: linear-gradient(135deg, #ffc858 0%, #e89400 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-calculator,
        .admin-sidebar .nav-link .bi-currency-dollar,
        .admin-sidebar .nav-link .bi-credit-card,
        .admin-sidebar .nav-link .bi-bank,
        .admin-sidebar .nav-link .bi-wallet2,
        .admin-sidebar .nav-link .bi-cash-stack,
        .admin-sidebar .nav-link .bi-cash-coin,
        .admin-sidebar .nav-link .bi-piggy-bank { --menu-icon-bg: linear-gradient(135deg, #ff7090 0%, #e0105a 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-building { --menu-icon-bg: linear-gradient(135deg, #ff8070 0%, #e03020 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-sticky,
        .admin-sidebar .nav-link .bi-sticky-note { --menu-icon-bg: linear-gradient(135deg, #ffcc30 0%, #e8a000 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-people-fill,
        .admin-sidebar .nav-link .bi-people { --menu-icon-bg: linear-gradient(135deg, #70b0ff 0%, #1858e8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-shield-lock,
        .admin-sidebar .nav-link .bi-tools,
        .admin-sidebar .nav-link .bi-image,
        .admin-sidebar .nav-link .bi-envelope { --menu-icon-bg: linear-gradient(135deg, #90a8c8 0%, #4a6890 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-x-circle { --menu-icon-bg: linear-gradient(135deg, #ff7070 0%, #e01515 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-qr-code-scan,
        .admin-sidebar .nav-link .bi-qr-code { --menu-icon-bg: linear-gradient(135deg, #ffc858 0%, #e89400 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-list-ul { --menu-icon-bg: linear-gradient(135deg, #a0b0ff 0%, #3040d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-robot { --menu-icon-bg: linear-gradient(135deg, #60b4ff 0%, #1568d8 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-shop,
        .admin-sidebar .nav-link .bi-shop-window { --menu-icon-bg: linear-gradient(135deg, #40e8be 0%, #00b898 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-gift { --menu-icon-bg: linear-gradient(135deg, #ff85a8 0%, #e8254e 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-bookmark-star { --menu-icon-bg: linear-gradient(135deg, #ffd060 0%, #e89000 100%); --menu-icon-color: #fff; }
        .admin-sidebar .nav-link .bi-clipboard-check { --menu-icon-bg: linear-gradient(135deg, #6de89a 0%, #12b84a 100%); --menu-icon-color: #fff; }
        /* Dropdown chevron - add icon for collapse toggles */
        .admin-sidebar .nav-link.dropdown-toggle {
            display: flex;
            align-items: center;
        }
        .admin-sidebar .nav-link.dropdown-toggle::after {
            margin-left: auto;
            transition: transform 0.25s ease;
        }
        .admin-sidebar .nav-link[aria-expanded="true"].dropdown-toggle::after {
            transform: rotate(180deg);
        }
        .admin-sidebar .navbar-brand {
            color: #fdb04c;
            padding: 15px 20px;
            font-weight: bold;
        }
        .admin-topbar {
            background: rgba(35, 35, 35, 0.92);
            color: #fdb04c;
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.06), 0 8px 24px rgba(0, 0, 0, 0.12);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .admin-topbar .btn:not(.admin-topbar-avatar-btn) {
            background: #ffb44c;
            color: #232323;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: background 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
        }
        .admin-topbar .btn:not(.admin-topbar-avatar-btn):hover {
            background: #fdb04c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .admin-topbar .btn:not(.admin-topbar-avatar-btn):active {
            transform: translateY(0);
        }
        .admin-topbar .admin-topbar-avatar-btn {
            background: transparent !important;
            color: inherit;
            padding: 0 !important;
            box-shadow: none !important;
            transform: none !important;
            line-height: 0;
        }
        .admin-topbar .admin-topbar-avatar-btn:hover,
        .admin-topbar .admin-topbar-avatar-btn:focus-visible {
            background: transparent !important;
            transform: none !important;
            box-shadow: 0 0 0 2px rgba(253, 176, 76, 0.5) !important;
        }
        .admin-topbar .ms-auto > .dropdown {
            flex: 0 0 auto;
        }
        .admin-topbar-shell {
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: space-between;
            min-width: 0;
        }
        .admin-topbar-start {
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            min-width: 0;
            flex: 0 0 auto;
        }
        .admin-topbar-actions {
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            min-width: 0;
            flex: 0 0 auto;
        }
        .app-public-navbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: linear-gradient(180deg, #1a1d21 0%, #212529 100%) !important;
        }
        .app-public-navbar .navbar-brand {
            letter-spacing: -0.02em;
        }
        .app-public-navbar .nav-link {
            border-radius: 8px;
            margin: 0 2px;
            padding: 0.5rem 0.75rem !important;
            transition: color 0.2s ease, background 0.2s ease, transform 0.15s ease;
        }
        .app-public-navbar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-1px);
        }
        .app-public-navbar .nav-link.active {
            background: rgba(253, 176, 76, 0.2);
            color: #fdb04c !important;
        }
        .public-menu-overlay {
            position: fixed;
            inset: 0;
            z-index: 1038;
            display: none;
        }
        .public-menu-overlay.show {
            display: block;
        }
        @media (max-width: 991px) {
            .app-public-navbar .container-fluid {
                justify-content: flex-start;
            }
            .app-public-navbar #mainNavbar {
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1060;
                display: block !important;
                width: min(84vw, 300px);
                height: 100vh !important;
                max-height: 100vh;
                padding: 1rem .85rem;
                overflow-y: auto;
                background:
                    radial-gradient(600px 280px at -160px -120px, rgba(253, 176, 76, 0.28), transparent 58%),
                    linear-gradient(180deg, #232323 0%, #2c2720 54%, #171717 100%);
                box-shadow: 18px 0 34px rgba(0, 0, 0, 0.34);
                transform: translateX(-100%);
                /* delay visibility:hidden until slide-out finishes (0.3s) */
                visibility: hidden;
                transition: transform 0.3s ease, visibility 0s linear 0.3s;
                border-right: 1px solid rgba(253, 176, 76, 0.22);
            }
            .app-public-navbar #mainNavbar.show {
                transform: translateX(0);
                visibility: visible;
                /* show visibility instantly on open */
                transition: transform 0.3s ease, visibility 0s linear 0s;
            }
            .app-public-navbar #mainNavbar .navbar-nav {
                gap: .25rem;
                margin-bottom: 1rem !important;
            }
            .app-public-navbar #mainNavbar .nav-link {
                display: flex;
                align-items: center;
                gap: .72rem;
                color: rgba(255, 255, 255, 0.95) !important;
                padding: .82rem .95rem !important;
                font-size: 1rem;
                font-weight: 600;
                border-radius: 8px;
                min-height: 52px;
                cursor: pointer;
                position: relative;
                transition: background 0.18s ease, box-shadow 0.18s ease, transform 0.1s ease;
            }
            /* Right-chevron arrow to signal "tap to navigate" */
            .app-public-navbar #mainNavbar .nav-link::after {
                content: "\f285"; /* bi-chevron-right */
                font-family: "bootstrap-icons";
                font-size: .8rem;
                margin-left: auto;
                opacity: 0.35;
                transition: opacity 0.18s ease, transform 0.18s ease;
                flex-shrink: 0;
            }
            .app-public-navbar #mainNavbar .nav-link.active::after,
            .app-public-navbar #mainNavbar .nav-link:hover::after {
                opacity: 0.85;
                transform: translateX(3px);
            }
            .app-public-navbar #mainNavbar .nav-link:active {
                transform: scale(0.97);
                background: rgba(253, 176, 76, 0.22) !important;
            }
            .app-public-navbar #mainNavbar .nav-link.active,
            .app-public-navbar #mainNavbar .nav-link:hover {
                background: linear-gradient(90deg, rgba(253, 176, 76, 0.40), rgba(253, 176, 76, 0.14));
                color: #ffd060 !important;
                transform: none;
                box-shadow: inset 4px 0 0 #fdb04c;
            }
            /* Colorful icon badges for mobile public menu */
            .app-public-navbar #mainNavbar .nav-link i[class*="bi-"] {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2rem;
                height: 2rem;
                border-radius: 10px;
                flex-shrink: 0;
                font-size: 1rem;
                color: var(--pub-icon-color, #fff);
                background: var(--pub-icon-bg, linear-gradient(135deg, #ffc858 0%, #e89400 100%));
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }
            .app-public-navbar #mainNavbar .nav-link.active i[class*="bi-"],
            .app-public-navbar #mainNavbar .nav-link:hover i[class*="bi-"] {
                transform: translateY(-1px) scale(1.06);
                box-shadow: 0 6px 14px rgba(0, 0, 0, 0.28);
            }
            /* Per-icon colors */
            .app-public-navbar #mainNavbar .nav-link .bi-plus-circle    { --pub-icon-bg: linear-gradient(135deg, #58e888 0%, #0ab844 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-bar-chart-line  { --pub-icon-bg: linear-gradient(135deg, #62c8ff 0%, #0090dd 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-clock-history   { --pub-icon-bg: linear-gradient(135deg, #5ac0ff 0%, #0092dd 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-list-ul         { --pub-icon-bg: linear-gradient(135deg, #a0b0ff 0%, #3040d8 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-speedometer2    { --pub-icon-bg: linear-gradient(135deg, #5ac8ff 0%, #0098e8 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-printer         { --pub-icon-bg: linear-gradient(135deg, #ff7878 0%, #e02020 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-box-seam        { --pub-icon-bg: linear-gradient(135deg, #80e060 0%, #28b800 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-journal-text    { --pub-icon-bg: linear-gradient(135deg, #60b4ff 0%, #1568d8 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-x-circle        { --pub-icon-bg: linear-gradient(135deg, #ff7070 0%, #e01515 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-chat-dots       { --pub-icon-bg: linear-gradient(135deg, #cc66ff 0%, #8800e8 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-qr-code-scan    { --pub-icon-bg: linear-gradient(135deg, #ffc858 0%, #e89400 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-house-door      { --pub-icon-bg: linear-gradient(135deg, #5ac8ff 0%, #0098e8 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-calendar-date   { --pub-icon-bg: linear-gradient(135deg, #ffd060 0%, #e89000 100%); }
            .app-public-navbar #mainNavbar .nav-link .bi-receipt         { --pub-icon-bg: linear-gradient(135deg, #6aaeff 0%, #1850d8 100%); }
            .public-menu-title {
                color: #fff;
                font-size: 1.2rem;
                font-weight: 700;
                margin-bottom: 0;
                letter-spacing: -0.01em;
            }
            .public-menu-head {
                padding: .35rem .15rem 1rem;
                margin-bottom: .35rem;
                border-bottom: 1px solid rgba(253, 176, 76, 0.18);
            }
            .app-public-navbar #mainNavbar .navbar-text {
                color: rgba(255, 255, 255, 0.9) !important;
                font-weight: 600;
            }
            .app-public-navbar #mainNavbar .navbar-nav.ms-auto {
                border-top: 1px solid rgba(253, 176, 76, 0.18);
                padding-top: 1rem;
                margin-top: 1rem;
            }
            .app-public-navbar #mainNavbar .nav-item.d-flex {
                margin: 0 .75rem .35rem .75rem !important;
            }
            .app-public-navbar #mainNavbar .nav-item.d-flex + .nav-item {
                padding: 0 .95rem .35rem;
            }
            .app-public-navbar #mainNavbar .nav-item.d-flex + .nav-item .navbar-text {
                display: block;
                margin-right: 0 !important;
                color: rgba(255, 255, 255, 0.74) !important;
                font-size: .95rem;
            }
            .public-menu-close {
                width: 38px;
                height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.08) !important;
            }
            .public-menu-close {
                border-color: rgba(255, 255, 255, .45) !important;
                color: #fff !important;
            }
        }
        @media (max-width: 767px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: -250px;
                width: 250px;
                height: 100vh;
                z-index: 1050;
                transition: left 0.3s ease;
            }
            .admin-sidebar.show {
                left: 0;
            }
            .admin-content {
                margin-left: 0 !important;
                min-width: 0;
                width: 100%;
            }
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1040;
                display: none;
            }
            .sidebar-overlay.show {
                display: block;
            }
            
            /* Phone / tablet: one compact top row — avoid full-width second row */
            .admin-topbar {
                padding-top: 8px !important;
                padding-bottom: 8px !important;
            }
            .admin-topbar-shell {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: space-between !important;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }
            .admin-topbar-start,
            .admin-topbar-actions {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
            }
            .admin-topbar-actions {
                margin-left: auto !important;
                width: auto !important;
                max-width: none !important;
                margin-top: 0 !important;
                align-self: center !important;
            }
            .admin-topbar .btn:not(.admin-topbar-avatar-btn) {
                font-size: 12px !important;
                padding: 8px 10px !important;
                min-height: 36px !important;
                white-space: nowrap;
            }
            .admin-topbar-shell #sidebarToggleDesktop,
            .admin-topbar-shell #adminRefreshContent {
                padding-left: 10px !important;
                padding-right: 10px !important;
                min-width: 40px;
            }
            @media (max-width: 576px) {
                .admin-topbar-shell {
                    gap: 6px !important;
                    padding-left: 8px !important;
                    padding-right: 8px !important;
                }
                .admin-topbar .btn:not(.admin-topbar-avatar-btn) {
                    font-size: 11px !important;
                    padding: 6px 8px !important;
                    min-height: 34px !important;
                }
                /* Do not grow Scan — flex-grow was collapsing layout on narrow viewports */
                .admin-topbar-scan-btn {
                    flex: 0 0 auto !important;
                    min-width: 0;
                    justify-content: center;
                }
                .admin-topbar-actions {
                    gap: 6px !important;
                    min-width: 0;
                }
                .admin-topbar-actions .dropdown {
                    flex: 0 0 auto;
                }
                .admin-topbar .admin-topbar-avatar-btn {
                    flex: 0 0 auto !important;
                    min-width: auto !important;
                    overflow: visible !important;
                }
            }
        }
        /* Hide admin chrome when printing (invoice-style) */
        @media print {
            .ajax-top-progress-wrap { display: none !important; }
            .admin-topbar, .admin-sidebar, .sidebar-overlay {
                display: none !important;
            }
            /* Override any .d-flex rule that might re-show the topbar */
            .admin-topbar.d-flex { display: none !important; }
            .admin-content { margin-left: 0 !important; width: 100% !important; }
        }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php require_once __DIR__ . '/page_loader.php'; ?>
<?php
// Admin sidebar: only for admin role or users with admin-exclusive permissions.
//  s/cashiers have orders.view, receipts.view etc. - must not show admin layout to them.
$userRoles = function_exists('user_role_names') ? user_role_names(get_db_connection(), $user ?: []) : [($user['role'] ?? '')];
$isAdminRole = in_array('admin', $userRoles, true);
$hasAdminOnlyPerm = $can('users.view') || $can('users_activity.view') || $can('roles.view') || $can('role_permissions.view') || $can('maintenance.view') || $can('statistics.view') || $can('purchase_orders.view') || $can('finance_dashboard.view') || $can('manage_categories.view') || $can('accountant_dashboard.view') || $can('cashflow.view') || $can('cashflow_categories.view') || $can('cashflow_spending.view') || $can('pages.view') || $can('marketing_take.view') || $can('marketing_take.create') || $can('marketing_take_report.view') || $can('marketing_take_approve.view') || $can('marketing_take_reconcile.view') || $can('product_sets.view') || $can('lucky_box_sets.view') || $can('product_set_qr_code_settings.view') || $can('product_set_qr_labels.view') || $can('product_set_qr_label_history.view') || $can('offline_stock.view') || $can('offline_sales.view') || $can('offline_daily_report.view');
$showAdminSidebar = $user && ($isAdminRole || $hasAdminOnlyPerm);

// Only show category labels when user has at least one menu item in that category
$showCatDashboard = $isAdminRole || $can('statistics.view') || $can('daily_summary.view') || $can('activity.view');
$showCatSales = $isAdminRole || $can('orders.view') || $can('sold_products.view') || $can('receipts.view') || $can('print_orders.view') || $can('print_history.view') || $can('cashier_date.view') || $can('order_filter.view') || $can('broadcast.view');
$showCatMarketing = $isAdminRole || $can('marketing_take.view') || $can('marketing_take.create') || $can('marketing_take_approve.view') || $can('marketing_take_reconcile.view') || $can('marketing_take_report.view');
$showCatInventory = $isAdminRole || $can('inventory.view') || $can('inventory_view.view') || $can('inventory_box_settings.view') || $can('stock_dashboard.view') || $can('stock_operations.view') || $can('storage_locations.view') || $can('storage_receipts.view') || $can('categories.view') || $can('eod_eom_reports.view') || $can('stock_reports.view') || $can('order_audit.view');
$showCatOffline = $isAdminRole || $can('offline_stock.view') || $can('stock_operations.view') || $can('offline_sales.view') || $can('offline_sales.create') || $can('offline_daily_report.view') || $can('offline_sellers.manage');
$showCatPurchasing = $isAdminRole || $can('purchase_orders.view') || $can('purchase_vendors.view') || $can('purchase_receiving.view') || $can('purchase_payments.view') || $can('purchase_reports.view');
$showCatFinance = $isAdminRole || $can('finance_dashboard.view') || $can('spending.view') || $can('finance_reports.view') || $can('manage_categories.view');
$showCatReport = $isAdminRole || $can('daily_summary.view') || $can('return_report.view') || $can('product_return_report.view') || $can('cashflow.view') || $can('finance_dashboard.view') || $can('finance_reports.view') || $can('purchase_reports.view') || $can('stock_reports.view') || $can('closing_report.view') || $can('accountant_daily.view') || $can('accountant_product.view') || $can('delivery_report.view') || $can('marketing_take_report.view');
$showCatReturnManagement = $isAdminRole || $can('return_report.view') || $can('product_return_report.view');
$showCatCashflow = $isAdminRole || $can('cashflow.view') || $can('cashflow_categories.view') || $can('cashflow_spending.view') || $can('cashflow_topup.view') || $can('bank_transfer.view');
$showCatAccounting = $isAdminRole || $can('accountant_dashboard.view') || $can('accountant_daily.view') || $can('accountant_product.view') || $can('financial_summary.view') || $can('closing_report.view') || $can('delivery_report.view') || $can('commission_sales.view') || $can('payment_management.view') || $can('telegram_bot_reminder.view');
$showCatProductManagement = $isAdminRole || $can('product_costs.view') || $can('product_sets.view') || $can('lucky_box_sets.view') || $can('product_set_qr_code_settings.view') || $can('product_set_qr_labels.view') || $can('product_set_qr_label_history.view') || $can('brands.view');
$showCatScannerConfig = $isAdminRole || $can('scanner_home.view') || $can('items_view.view') || $can('out_items_delivery_by.view');
$showCatSetup = $isAdminRole || $can('pages.view') || $can('delivery_types.view') || $can('delivery_costs.view') || $can('logos.view') || $can('note_options.view') || $can('money_exchange.view');
$showCatAdmin = (isset($user['username']) && $user['username'] === 'admin') || $can('users.view') || $can('users_activity.view') || $can('maintenance.view') || $can('roles.view') || $can('role_permissions.view');
$menuLogo = null;
if ($showAdminSidebar) {
    try {
        $menuLogo = get_default_logo(get_db_connection());
    } catch (Throwable $e) {}
}
?>
<?php if ($showAdminSidebar): ?>
    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="d-flex flex-grow-1">
        <!-- Admin Sidebar -->
        <nav class="admin-sidebar flex-shrink-0" id="adminSidebar">
            <div class="d-flex flex-column h-100">
                <div class="p-3 border-bottom border-secondary d-flex align-items-center gap-2">
                    <a class="navbar-brand text-white text-decoration-none d-flex align-items-center gap-2" href="<?= htmlspecialchars($BASE_URL) ?>/index.php">
                        <img src="<?= $menuLogo ? htmlspecialchars(uploaded_file_url($menuLogo['file_path'], 'logos')) : htmlspecialchars($BASE_URL) . '/public/image.png' ?>" alt="Logo" class="sidebar-logo" style="height: 36px; width: auto; object-fit: contain;">
                        <span>Order System</span>
                    </a>
                    <button class="btn btn-sm btn-outline-light d-md-none ms-auto" id="sidebarClose" type="button" aria-label="Close sidebar">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="flex-grow-1 overflow-auto pt-3" id="sidebarAccordion">
                    <ul class="nav nav-pills flex-column mb-auto">
                        <?php
                        $dashboardPages = ['statistics.php','daily_summary.php','activity.php'];
                        $isDashboardSection = in_array($current, $dashboardPages, true);
                        if ($showCatDashboard): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catDashboard" role="button" aria-expanded="<?= $isDashboardSection ? 'true' : 'false' ?>">
                                <i class="bi bi-speedometer2 me-2"></i>
                                <span>Dashboard</span>
                            </a>
                            <div class="collapse <?= $isDashboardSection ? 'show' : '' ?>" id="catDashboard">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'statistics.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('statistics.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/statistics.php"><i class="bi bi-bar-chart-line me-2"></i><span>Statistics</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'daily_summary.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('daily_summary.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/daily_summary.php"><i class="bi bi-graph-up me-2"></i><span>Daily Summary</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'activity.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('activity.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/activity.php"><i class="bi bi-calendar-event me-2"></i><span>Daily Activity</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $salesPages = ['order_management.php','sold_products.php','create_receipt_order.php','history_receipt.php','print_orders.php','print_sessions.php','print_history.php','dashboard.php','order_filter.php','broadcast.php'];
                        $showCatSalesOpen = in_array($current, $salesPages, true);
                        if ($showCatSales): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catSales" role="button" aria-expanded="<?= $showCatSalesOpen ? 'true' : 'false' ?>">
                                <i class="bi bi-cart-check me-2"></i>
                                <span>Sales</span>
                            </a>
                            <div class="collapse <?= $showCatSalesOpen ? 'show' : '' ?>" id="catSales">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'order_management.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/order_management.php"><i class="bi bi-gear me-2"></i><span>Order Management</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'sold_products.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('sold_products.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/sold_products.php"><i class="bi bi-bag-check me-2"></i><span>Sold Products</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'create_receipt_order.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('receipts.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/create_receipt_order.php"><i class="bi bi-receipt me-2"></i><span>Create Receipt</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'history_receipt.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('receipts.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/history_receipt.php"><i class="bi bi-clock-history me-2"></i><span>History Receipt</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'print_orders.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('print_orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_orders.php"><i class="bi bi-printer me-2"></i><span>Print Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'print_sessions.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('print_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_sessions.php"><i class="bi bi-box-seam me-2"></i><span>Print Sessions</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'print_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('print_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_history.php"><i class="bi bi-journal-text me-2"></i><span>Print History</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'dashboard.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashier_date.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/dashboard.php"><i class="bi bi-calendar-date me-2"></i><span>Set Date</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'order_filter.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('order_filter.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/order_filter.php"><i class="bi bi-funnel me-2"></i><span>Order Filter</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'broadcast.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('broadcast.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/broadcast.php"><i class="bi bi-chat-dots me-2"></i><span>Send Message</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $reportPages = ['daily_summary.php','return_report.php','product_return_report.php','cashflow_report.php','finance_dashboard.php','finance_reports.php','purchase_reports.php','stock_reports.php','accountant_closing_report.php','closing_report.php','accountant_daily_reports.php','accountant_product_reports.php','delivery_report.php','marketing_take_report.php'];
                        $isReportSection = in_array($current, $reportPages, true);
                        if ($showCatReport): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catReport" role="button" aria-expanded="<?= $isReportSection ? 'true' : 'false' ?>">
                                <i class="bi bi-file-earmark-bar-graph me-2"></i>
                                <span>Report</span>
                            </a>
                            <div class="collapse <?= $isReportSection ? 'show' : '' ?>" id="catReport">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'daily_summary.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('daily_summary.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/daily_summary.php"><i class="bi bi-calendar-day me-2"></i><span>Daily Summary</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'return_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('return_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/return_report.php"><i class="bi bi-arrow-return-left me-2"></i><span>Return Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_return_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_return_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_return_report.php"><i class="bi bi-clipboard-data me-2"></i><span>Product Return Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_report.php"><i class="bi bi-cash-coin me-2"></i><span>Cash Flow Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_dashboard.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('finance_dashboard.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_dashboard.php"><i class="bi bi-speedometer2 me-2"></i><span>Finance Dashboard</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('finance_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_reports.php"><i class="bi bi-graph-up me-2"></i><span>Finance Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_reports.php"><i class="bi bi-cart4 me-2"></i><span>Purchase Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('stock_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_reports.php"><i class="bi bi-clipboard-data me-2"></i><span>Stock Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['accountant_closing_report.php','closing_report.php']) ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('closing_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_closing_report.php"><i class="bi bi-file-earmark-bar-graph me-2"></i><span>Closing Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_daily_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('accountant_daily.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_daily_reports.php"><i class="bi bi-calendar-day me-2"></i><span>Accountant Daily Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_product_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('accountant_product.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_product_reports.php"><i class="bi bi-box-seam me-2"></i><span>Accountant Product Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('delivery_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_report.php"><i class="bi bi-truck me-2"></i><span>Delivery Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'marketing_take_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('marketing_take_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/marketing_take_report.php"><i class="bi bi-megaphone me-2"></i><span>Market Suggest Report</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $mktPages = ['marketing_take_report.php','marketing_take_create.php','marketing_take_approve.php','marketing_take_approve_history.php','marketing_take_reconcile.php','marketing_take_reconcile_history.php'];
                        $isMarketingSection = in_array($current, $mktPages, true);
                        if ($showCatMarketing): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catMarketing" role="button" aria-expanded="<?= $isMarketingSection ? 'true' : 'false' ?>">
                                <i class="bi bi-megaphone me-2"></i>
                                <span>Marketing</span>
                            </a>
                            <div class="collapse <?= $isMarketingSection ? 'show' : '' ?>" id="catMarketing">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'marketing_take_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('marketing_take_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/marketing_take_report.php"><i class="bi bi-graph-up me-2"></i><span>Market Suggest Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['marketing_take_create.php']) ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('marketing_take.create')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/marketing_take_create.php"><i class="bi bi-plus-circle me-2"></i><span>Create Market Take</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['marketing_take_approve.php','marketing_take_approve_history.php']) ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('marketing_take_approve.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/marketing_take_approve.php"><i class="bi bi-check-circle me-2"></i><span>Approve Market Take</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['marketing_take_reconcile.php','marketing_take_reconcile_history.php']) ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('marketing_take_reconcile.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/marketing_take_reconcile.php"><i class="bi bi-arrow-repeat me-2"></i><span>Reconcile Market Take</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $invPages = ['inventory.php','inventory_view.php','stock_dashboard.php','stock_operations.php','product_movement_tracking.php','storage_locations.php','storage_receipts.php','stock_categories.php','eod_eom_stock_reports.php','stock_reports.php','order_edit_audit.php'];
                        $isInventorySection = in_array($current, $invPages, true);
                        if ($showCatInventory): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catInventory" role="button" aria-expanded="<?= $isInventorySection ? 'true' : 'false' ?>">
                                <i class="bi bi-box-seam me-2"></i>
                                <span>Inventory</span>
                            </a>
                            <div class="collapse <?= $isInventorySection ? 'show' : '' ?>" id="catInventory">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'inventory.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('inventory.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/inventory.php"><i class="bi bi-box-seam me-2"></i><span>Inventory &amp; Cashier</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'inventory_view.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('inventory_view.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/inventory_view.php"><i class="bi bi-eye me-2"></i><span>Inventory View</span></a></li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'box_units_settings.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('inventory_box_settings.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/box_units_settings.php">
                                            <i class="bi bi-calculator me-2"></i>
                                            <span>Set Box to Unit</span>
                                        </a>
                                    </li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_dashboard.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('stock_dashboard.view') || $can('stock_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_dashboard.php"><i class="bi bi-speedometer2 me-2"></i><span>Stock Dashboard</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_operations.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('stock_operations.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_operations.php"><i class="bi bi-arrow-left-right me-2"></i><span>Stock In/Out</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_movement_tracking.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('stock_operations.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_movement_tracking.php"><i class="bi bi-diagram-3 me-2"></i><span>Product Movement Tracking</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'storage_locations.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('storage_locations.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/storage_locations.php"><i class="bi bi-geo-alt me-2"></i><span>Storage Locations</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'storage_receipts.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('storage_receipts.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/storage_receipts.php"><i class="bi bi-archive me-2"></i><span>Storage Receipts</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_categories.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('categories.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_categories.php"><i class="bi bi-tags-fill me-2"></i><span>Categories</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'eod_eom_stock_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('eod_eom_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/eod_eom_stock_reports.php"><i class="bi bi-calendar-check me-2"></i><span>EOD/EOM Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'stock_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('stock_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/stock_reports.php"><i class="bi bi-clipboard-data me-2"></i><span>Stock Reports</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'order_edit_audit.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('order_audit.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/order_edit_audit.php"><i class="bi bi-activity me-2"></i><span>Order Edit Audit</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $offlinePages = ['offline_stock.php','offline_sale_new.php','offline_sale_orders.php','offline_team.php','offline_daily_sales.php','offline_closing_report.php'];
                        $isOfflineSection = in_array($current, $offlinePages, true);
                        if ($showCatOffline): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catOffline" role="button" aria-expanded="<?= $isOfflineSection ? 'true' : 'false' ?>">
                                <i class="bi bi-shop me-2"></i>
                                <span>Offline Management</span>
                            </a>
                            <div class="collapse <?= $isOfflineSection ? 'show' : '' ?>" id="catOffline">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'offline_stock.php' ? 'active' : '' ?> <?= ($isAdminRole || $can('offline_stock.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_stock.php"><i class="bi bi-shop-window me-2"></i><span>Stock Offline</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= in_array($current, ['offline_sale_new.php','offline_sale_orders.php'], true) ? 'active' : '' ?> <?= ($isAdminRole || $can('offline_sales.view') || $can('offline_sales.create')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_orders.php"><i class="bi bi-receipt me-2"></i><span>Sale Orders</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'offline_team.php' ? 'active' : '' ?> <?= ($isAdminRole || $can('offline_sellers.manage')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_team.php"><i class="bi bi-people-fill me-2"></i><span>Offline Team</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'offline_daily_sales.php' ? 'active' : '' ?> <?= ($isAdminRole || $can('offline_daily_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_daily_sales.php"><i class="bi bi-graph-up-arrow me-2"></i><span>Daily Offline Sales</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'offline_closing_report.php' ? 'active' : '' ?> <?= ($isAdminRole || $can('offline_daily_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_closing_report.php"><i class="bi bi-clipboard-check me-2"></i><span>Offline Closing Report</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $prodPages = ['product_costs.php','product_set_management.php','lucky_box_sets.php','product_set_qr_code_settings.php','product_set_qr_labels.php','product_set_qr_label_history.php','product_set_report.php'];
                        $isProductMgmtSection = in_array($current, $prodPages, true);
                        if ($showCatProductManagement): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catProductMgmt" role="button" aria-expanded="<?= $isProductMgmtSection ? 'true' : 'false' ?>">
                                <i class="bi bi-tags me-2"></i>
                                <span>Product Management</span>
                            </a>
                            <div class="collapse <?= $isProductMgmtSection ? 'show' : '' ?>" id="catProductMgmt">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_costs.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_costs.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_costs.php"><i class="bi bi-tags me-2"></i><span>Product Cost</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_set_management.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_sets.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_management.php"><i class="bi bi-collection me-2"></i><span>Product Set</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'lucky_box_sets.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('lucky_box_sets.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/lucky_box_sets.php"><i class="bi bi-gift me-2"></i><span>Lucky Box Sets</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_set_qr_code_settings.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_set_qr_code_settings.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_code_settings.php"><i class="bi bi-pencil-square me-2"></i><span>Set QR Custom Code</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_set_qr_labels.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_set_qr_labels.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php"><i class="bi bi-qr-code me-2"></i><span>Set QR Labels</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_set_qr_label_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_set_qr_label_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_label_history.php"><i class="bi bi-clock-history me-2"></i><span>QR Label History</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_set_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_sets.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_report.php"><i class="bi bi-clipboard2-data me-2"></i><span>Product Set Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'brands.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('brands.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/brands.php"><i class="bi bi-bookmark-star me-2"></i><span>Brands</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $purchPages = ['purchase_orders.php','purchase_vendors.php','purchase_receiving.php','purchase_receiving_history.php','purchase_returns.php','purchase_return_history.php','purchase_payments.php','purchase_reports.php','vendor_product_detail.php','invoice_settings.php'];
                        $isPurchasingSection = in_array($current, $purchPages, true);
                        if ($showCatPurchasing): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catPurchasing" role="button" aria-expanded="<?= $isPurchasingSection ? 'true' : 'false' ?>">
                                <i class="bi bi-cart4 me-2"></i>
                                <span>Purchasing</span>
                            </a>
                            <div class="collapse <?= $isPurchasingSection ? 'show' : '' ?>" id="catPurchasing">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_orders.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_orders.php">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <span>Purchase Orders</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_vendors.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_vendors.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_vendors.php">
                                            <i class="bi bi-building me-2"></i>
                                            <span>Vendors</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_receiving.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_receiving.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_receiving.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Receiving</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_receiving_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_receiving.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_receiving_history.php">
                                            <i class="bi bi-clock-history me-2"></i>
                                            <span>Receiving History</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_returns.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_receiving.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_returns.php">
                                            <i class="bi bi-arrow-return-left me-2"></i>
                                            <span>Returns to Vendor</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_return_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_receiving.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_return_history.php">
                                            <i class="bi bi-clock-history me-2"></i>
                                            <span>Return History</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_payments.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_payments.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_payments.php">
                                            <i class="bi bi-credit-card me-2"></i>
                                            <span>Purchase Payments</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'purchase_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/purchase_reports.php">
                                            <i class="bi bi-graph-up me-2"></i>
                                            <span>Purchase Reports</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'vendor_product_detail.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/vendor_product_detail.php">
                                            <i class="bi bi-box-seam me-2"></i>
                                            <span>Vendor Product Detail</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'invoice_settings.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('purchase_orders.view') || $can('logos.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/invoice_settings.php">
                                            <i class="bi bi-receipt me-2"></i>
                                            <span>Invoice Settings</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $finPages = ['finance_dashboard.php','add_spending.php','add_topup.php','finance_reports.php','finance_spending_detail.php','topup_report.php','manage_categories.php'];
                        $isFinanceSection = in_array($current, $finPages, true);
                        if ($showCatFinance): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catFinance" role="button" aria-expanded="<?= $isFinanceSection ? 'true' : 'false' ?>">
                                <i class="bi bi-cash-stack me-2"></i>
                                <span>Finance &amp; Costs</span>
                            </a>
                            <div class="collapse <?= $isFinanceSection ? 'show' : '' ?>" id="catFinance">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_dashboard.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('finance_dashboard.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_dashboard.php">
                                            <i class="bi bi-speedometer2 me-2"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'add_spending.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('spending.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/add_spending.php">
                                            <i class="bi bi-piggy-bank me-2"></i>
                                            <span>Add Spending</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('spending.view') || $can('finance_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_reports.php">
                                            <i class="bi bi-clock-history me-2"></i>
                                            <span>Spending History</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'finance_spending_detail.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('spending.view') || $can('finance_reports.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/finance_spending_detail.php">
                                            <i class="bi bi-collection me-2"></i>
                                            <span>Spending by Sub-Category</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'add_topup.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('finance_dashboard.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/add_topup.php">
                                            <i class="bi bi-plus-circle me-2"></i>
                                            <span>Top Up</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'topup_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('finance_dashboard.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/topup_report.php">
                                            <i class="bi bi-clock-history me-2"></i>
                                            <span>Top Up History</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'manage_categories.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('manage_categories.view') || $can('categories.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/manage_categories.php">
                                            <i class="bi bi-tags me-2"></i>
                                            <span>Manage Categories</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $retPages = ['return_report.php','product_return_report.php'];
                        $isReturnsSection = in_array($current, $retPages, true);
                        if ($showCatReturnManagement): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catReturns" role="button" aria-expanded="<?= $isReturnsSection ? 'true' : 'false' ?>">
                                <i class="bi bi-arrow-return-left me-2"></i>
                                <span>Return Management</span>
                            </a>
                            <div class="collapse <?= $isReturnsSection ? 'show' : '' ?>" id="catReturns">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'return_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('return_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/return_report.php"><i class="bi bi-arrow-return-left me-2"></i><span>Return Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'product_return_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('product_return_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_return_report.php"><i class="bi bi-clipboard-data me-2"></i><span>Product Return Report</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $cfPages = ['cashflow_summary.php','cashflow_report.php','cashflow.php','cashflow_categories.php','cashflow_topup_add.php','cashflow_topup_history.php','cashflow_add_spending.php','cashflow_spending_history.php','bank_transfer_add.php','bank_transfer_history.php'];
                        $isCashflowSection = in_array($current, $cfPages, true);
                        if ($showCatCashflow): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catCashflow" role="button" aria-expanded="<?= $isCashflowSection ? 'true' : 'false' ?>">
                                <i class="bi bi-cash-coin me-2"></i>
                                <span>Cash Flow</span>
                            </a>
                            <div class="collapse <?= $isCashflowSection ? 'show' : '' ?>" id="catCashflow">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_summary.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_summary.php"><i class="bi bi-speedometer2 me-2"></i><span>Summary (Total &amp; Balance)</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_report.php"><i class="bi bi-file-earmark-bar-graph me-2"></i><span>Cash Flow Report</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php"><i class="bi bi-cash-coin me-2"></i><span>By Payment Method</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_categories.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow_categories.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_categories.php"><i class="bi bi-tags me-2"></i><span>Categories</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_topup_add.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow_topup.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_add.php"><i class="bi bi-plus-circle me-2"></i><span>Top Up</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_topup_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow_topup.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_history.php"><i class="bi bi-clock-history me-2"></i><span>Top Up History</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_add_spending.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow_spending.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php"><i class="bi bi-wallet2 me-2"></i><span>Add Spending</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'cashflow_spending_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('cashflow_spending.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_spending_history.php"><i class="bi bi-clock-history me-2"></i><span>Spending History</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'bank_transfer_add.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('bank_transfer.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/bank_transfer_add.php"><i class="bi bi-bank me-2"></i><span>Transfer to Other Bank</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'bank_transfer_history.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('bank_transfer.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/bank_transfer_history.php"><i class="bi bi-clock-history me-2"></i><span>Transfer History</span></a></li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $accPages = ['accountant_dashboard.php','accountant_daily_reports.php','accountant_product_reports.php','accountant_financial_summary.php','accountant_closing_report.php','delivery_report.php'];
                        $isAccountingSection = in_array($current, $accPages, true);
                        if ($showCatAccounting): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catAccounting" role="button" aria-expanded="<?= $isAccountingSection ? 'true' : 'false' ?>">
                                <i class="bi bi-calculator me-2"></i>
                                <span>Accounting</span>
                            </a>
                            <div class="collapse <?= $isAccountingSection ? 'show' : '' ?>" id="catAccounting">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_dashboard.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('accountant_dashboard.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_dashboard.php">
                                            <i class="bi bi-speedometer2 me-2"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_daily_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('accountant_daily.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_daily_reports.php">
                                            <i class="bi bi-calendar-day me-2"></i>
                                            <span>Daily Reports</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_product_reports.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('accountant_product.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_product_reports.php">
                                            <i class="bi bi-box-seam me-2"></i>
                                            <span>Product Reports</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_financial_summary.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('financial_summary.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_financial_summary.php">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <span>Financial Summary</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'accountant_closing_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('closing_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/accountant_closing_report.php">
                                            <i class="bi bi-file-earmark-bar-graph me-2"></i>
                                            <span>Closing Report</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_report.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('delivery_report.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_report.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Delivery Report</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'commission_summary.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('commission_sales.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/commission_summary.php">
                                            <i class="bi bi-cash-coin me-2"></i>
                                            <span>Commission Sales</span>
                                        </a>
                                    </li>
                                       <li class="nav-item">
                                           <a class="nav-link d-flex align-items-center text-white <?= $current === 'payment_management.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('payment_management.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/payment_management.php">
                                               <i class="bi bi-credit-card me-2"></i>
                                               <span>Payment Management</span>
                                           </a>
                                       </li>
                                       <li class="nav-item">
                                            <a class="nav-link d-flex align-items-center text-white <?= $current === 'telegram_bot_remider.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('telegram_bot_reminder.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/telegram_bot_remider.php">
                                                 <i class="bi bi-robot me-2"></i>
                                                 <span>Telegram Reminder Bot</span>
                                             </a>
                                        </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>                   
                        <?php 
                        if ($showCatScannerConfig): 
                        $scannerConfigPages = ['out_items_delivery_by.php'];
                        $isScannerConfigSection = in_array($current, $scannerConfigPages, true);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catScannerConfig" role="button" aria-expanded="<?= $isScannerConfigSection ? 'true' : 'false' ?>">
                                <i class="bi bi-qr-code-scan me-2"></i>
                                <span>Scanner Config</span>
                            </a>
                            <div class="collapse <?= $isScannerConfigSection ? 'show' : '' ?>" id="catScannerConfig">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= ((($user['role'] ?? '') === 'admin') || $can('scanner_home.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php">
                                            <i class="bi bi-house-door me-2"></i>
                                            <span>AI Scan Home</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= ((($user['role'] ?? '') === 'admin') || $can('items_view.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/view_all_items.php">
                                            <i class="bi bi-list-ul me-2"></i>
                                            <span>View All Items</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= ($current === 'out_items_delivery_by.php') ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('out_items_delivery_by.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/out_items_config/out_items_delivery_by.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Delivery By Config</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $setupPages = ['pages.php','delivery_types.php','delivery_costs.php','logos.php','manage_note_options.php','money_exchange.php'];
                        $isSetupSection = in_array($current, $setupPages, true);
                        if ($showCatSetup): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catSetup" role="button" aria-expanded="<?= $isSetupSection ? 'true' : 'false' ?>">
                                <i class="bi bi-gear-heart me-2"></i>
                                <span>Setup</span>
                            </a>
                            <div class="collapse <?= $isSetupSection ? 'show' : '' ?>" id="catSetup">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'pages.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('pages.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/pages.php">
                                            <i class="bi bi-file-text me-2"></i>
                                            <span>Pages</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_types.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('delivery_types.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_types.php">
                                            <i class="bi bi-truck me-2"></i>
                                            <span>Delivery Types</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'delivery_costs.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('delivery_costs.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/delivery_costs.php">
                                            <i class="bi bi-currency-dollar me-2"></i>
                                            <span>Delivery Price</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'logos.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('logos.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/logos.php">
                                            <i class="bi bi-image me-2"></i>
                                            <span>Logos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'manage_note_options.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('note_options.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/manage_note_options.php">
                                            <i class="bi bi-sticky me-2"></i>
                                            <span>Note Options</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link d-flex align-items-center text-white <?= $current === 'money_exchange.php' ? 'active' : '' ?> <?= ((($user['role'] ?? '') === 'admin') || $can('money_exchange.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/money_exchange.php">
                                            <i class="bi bi-cash-coin me-2"></i>
                                            <span>Money Exchange</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $isMainAdmin = isset($user['username']) && $user['username'] === 'admin';
                        $adminPages = ['users.php','user_activity_log.php','role_permissions.php','roles.php','maintenance.php'];
                        $isAdminSection = in_array($current, $adminPages, true);
                        if ($showCatAdmin): ?>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center text-white dropdown-toggle" data-bs-toggle="collapse" href="#catAdmin" role="button" aria-expanded="<?= $isAdminSection ? 'true' : 'false' ?>">
                                <i class="bi bi-shield-lock me-2"></i>
                                <span>Administration</span>
                            </a>
                            <div class="collapse <?= $isAdminSection ? 'show' : '' ?>" id="catAdmin">
                                <ul class="nav nav-pills flex-column ms-3">
                                    <?php if ($isMainAdmin || (function_exists('has_permission') && has_permission('users.view'))): ?>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'users.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/users.php"><i class="bi bi-people-fill me-2"></i><span>Users</span></a></li>
                                    <?php endif; ?>
                                    <?php if ($isMainAdmin || (function_exists('has_permission') && has_permission('users_activity.view'))): ?>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'user_activity_log.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/user_activity/user_activity_log.php"><i class="bi bi-journal-text me-2"></i><span>User activity</span></a></li>
                                    <?php endif; ?>
                                    <?php if ($isMainAdmin || (function_exists('has_permission') && has_permission('roles.view')) || (function_exists('has_permission') && has_permission('role_permissions.view'))): ?>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'role_permissions.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/role_permissions.php"><i class="bi bi-shield-lock me-2"></i><span>Role Permissions</span></a></li>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'roles.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/roles.php"><i class="bi bi-people me-2"></i><span>Roles</span></a></li>
                                    <?php endif; ?>
                                    <?php if ($isMainAdmin || (function_exists('has_permission') && has_permission('maintenance.view'))): ?>
                                    <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= $current === 'maintenance.php' ? 'active' : '' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/maintenance.php"><i class="bi bi-tools me-2"></i><span>Maintenance</span></a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="p-3 border-top border-secondary small">
                    <div class="mb-2 d-flex align-items-center gap-2">
                        <?php if ($profileAvatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($profileAvatarUrl) ?>" alt="" width="40" height="40" class="rounded-circle flex-shrink-0 border border-secondary" style="object-fit:cover">
                        <?php else: ?>
                            <span class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center flex-shrink-0 text-white border border-secondary" style="width:40px;height:40px" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php">Profile</a>
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php">Logout</a>
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Admin Content -->
        <div class="admin-content flex-grow-1" id="adminContent">
            <div id="ajaxTopProgressWrap" class="ajax-top-progress-wrap" hidden aria-hidden="true">
                <div class="ajax-top-progress-bar"></div>
            </div>
            <!-- Top Bar (hidden when printing) -->
            <div class="admin-topbar admin-topbar-shell no-print d-flex justify-content-between align-items-center gap-2">
                <div class="admin-topbar-start d-flex align-items-center gap-2 gap-md-3 flex-shrink-0">
                    <button class="btn" id="sidebarToggleDesktop" type="button" aria-label="Toggle sidebar">
                        <i class="bi bi-list"></i>
                    </button>
                    <button class="btn" id="adminRefreshContent" type="button" title="Reload this page" aria-label="Reload this page">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <span class="admin-topbar-title text-truncate d-none d-md-inline">Admin Dashboard</span>
                </div>
                <div class="admin-topbar-actions d-flex align-items-center gap-2 ms-auto flex-shrink-0">
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php" class="btn admin-topbar-scan-btn text-nowrap">
                        <i class="bi bi-qr-code-scan me-1"></i>
                        <span class="d-none d-sm-inline">AI Scan</span><span class="d-sm-none">Scan</span>
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-link p-0 border-0 rounded-circle admin-topbar-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu" title="<?= htmlspecialchars($user['name'] ?? 'Account') ?>">
                            <?php if ($profileAvatarUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($profileAvatarUrl) ?>" alt="" width="40" height="40" class="rounded-circle border" style="object-fit:cover">
                            <?php else: ?>
                                <span class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-secondary border" style="width:40px;height:40px" aria-hidden="true"><i class="bi bi-person-fill fs-5"></i></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><h6 class="dropdown-header text-truncate mb-0" style="max-width:14rem"><?= htmlspecialchars($user['name'] ?? '') ?></h6><small class="dropdown-header text-muted pt-0"><?= htmlspecialchars($user['role'] ?? '') ?></small></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Main Content (AJAX target) -->
            <div id="mainPageContent" class="container-fluid py-3 flex-grow-1 d-flex flex-column" style="max-width:100%;min-width:0;overflow-x:hidden;position:relative;">
<?php else: ?>
    <?php $legacyRole = (string)($user['role'] ?? ''); ?>
    <!-- Sidebar layout for seller / cashier / scanner — same structure as admin -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="d-flex flex-grow-1">
        <nav class="admin-sidebar flex-shrink-0" id="adminSidebar">
            <div class="d-flex flex-column h-100">
                <div class="p-3 border-bottom border-secondary d-flex align-items-center gap-2">
                    <a class="navbar-brand text-white text-decoration-none d-flex align-items-center gap-2" href="<?= htmlspecialchars($BASE_URL) ?>/index.php">
                        <img src="<?= htmlspecialchars($BASE_URL) ?>/public/image.png" alt="Logo" style="height:36px;width:auto;object-fit:contain;">
                        <span>Order System</span>
                    </a>
                    <button class="btn btn-sm btn-outline-light d-md-none ms-auto" id="sidebarClose" type="button" aria-label="Close sidebar">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="flex-grow-1 overflow-auto pt-3">
                    <ul class="nav nav-pills flex-column mb-auto">
                        <?php if ($legacyRole === 'cashier' || $can('cashier_date.view') || $can('print_orders.view') || $can('print_history.view') || $can('cancelled_orders.view') || $can('broadcast.view')): ?>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('cashier_date.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/dashboard.php"><i class="bi bi-speedometer2 me-2"></i><span>Dashboard</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('print_orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_orders.php"><i class="bi bi-printer me-2"></i><span>Print Orders</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('print_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_sessions.php"><i class="bi bi-box-seam me-2"></i><span>Print Sessions</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('print_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/print_history.php"><i class="bi bi-journal-text me-2"></i><span>Print History</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('cancelled_orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/cancelled_orders.php"><i class="bi bi-x-circle me-2"></i><span>Cancelled Orders</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('broadcast.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/cashier/broadcast.php"><i class="bi bi-chat-dots me-2"></i><span>Send Message</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('inventory.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/admin/inventory.php"><i class="bi bi-archive me-2"></i><span>Inventory &amp; Cashier</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('scanner_home.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php"><i class="bi bi-qr-code-scan me-2"></i><span>AI Scan</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'cashier' || $can('items_view.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/view_all_items.php"><i class="bi bi-list-ul me-2"></i><span>View All Items</span></a></li>
                        <?php elseif ($legacyRole === 'seller' || $can('seller_orders.view') || $can('seller_statistics.view') || $can('order_history.view')): ?>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'seller' || $can('seller_orders.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/seller/order_new.php"><i class="bi bi-plus-circle me-2"></i><span>New Order</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'seller' || $can('seller_statistics.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/seller/statistics.php"><i class="bi bi-bar-chart-line me-2"></i><span>Statistics</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'seller' || $can('order_history.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/seller/orders.php"><i class="bi bi-clock-history me-2"></i><span>Order History</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'seller' || $can('items_view.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/view_all_items.php"><i class="bi bi-list-ul me-2"></i><span>View All Items</span></a></li>
                        <?php elseif ($legacyRole === 'scanner' || $can('scanner_home.view') || $can('items_view.view')): ?>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'scanner' || $can('scanner_home.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php"><i class="bi bi-qr-code-scan me-2"></i><span>AI Scan</span></a></li>
                            <li class="nav-item"><a class="nav-link d-flex align-items-center text-white <?= ($legacyRole === 'scanner' || $can('items_view.view')) ? '' : 'd-none' ?>" href="<?= htmlspecialchars($BASE_URL) ?>/scanner/view_all_items.php"><i class="bi bi-list-ul me-2"></i><span>View All Items</span></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="p-3 border-top border-secondary small">
                    <div class="mb-2 d-flex align-items-center gap-2">
                        <?php if ($profileAvatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($profileAvatarUrl) ?>" alt="" width="40" height="40" class="rounded-circle flex-shrink-0 border border-secondary" style="object-fit:cover">
                        <?php else: ?>
                            <span class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center flex-shrink-0 text-white border border-secondary" style="width:40px;height:40px" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php">Profile</a>
                        <a class="text-white text-decoration-none" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php">Logout</a>
                    </div>
                </div>
            </div>
        </nav>
        <div class="admin-content flex-grow-1" id="adminContent">
            <div class="admin-topbar admin-topbar-shell no-print d-flex justify-content-between align-items-center gap-2">
                <div class="admin-topbar-start d-flex align-items-center gap-2 gap-md-3 flex-shrink-0">
                    <button class="btn" id="sidebarToggleDesktop" type="button" aria-label="Toggle sidebar">
                        <i class="bi bi-list"></i>
                    </button>
                </div>
                <div class="admin-topbar-actions d-flex align-items-center gap-2 ms-auto flex-shrink-0">
                    <?php if ($legacyRole === 'cashier' || $legacyRole === 'scanner' || $can('scanner_home.view')): ?>
                    <a href="<?= htmlspecialchars($BASE_URL) ?>/scanner/home.php" class="btn admin-topbar-scan-btn text-nowrap">
                        <i class="bi bi-qr-code-scan me-1"></i>
                        <span class="d-none d-sm-inline">AI Scan</span><span class="d-sm-none">Scan</span>
                    </a>
                    <?php endif; ?>
                    <div class="dropdown">
                        <button class="btn btn-link p-0 border-0 rounded-circle admin-topbar-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu" title="<?= htmlspecialchars($user['name'] ?? 'Account') ?>">
                            <?php if ($profileAvatarUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($profileAvatarUrl) ?>" alt="" width="40" height="40" class="rounded-circle border" style="object-fit:cover">
                            <?php else: ?>
                                <span class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-secondary border" style="width:40px;height:40px" aria-hidden="true"><i class="bi bi-person-fill fs-5"></i></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><h6 class="dropdown-header text-truncate mb-0" style="max-width:14rem"><?= htmlspecialchars($user['name'] ?? '') ?></h6><small class="dropdown-header text-muted pt-0"><?= htmlspecialchars($user['role'] ?? '') ?></small></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div id="mainPageContent" class="container-fluid py-3 flex-grow-1 d-flex flex-column" style="max-width:100%;min-width:0;overflow-x:hidden;position:relative;">
<?php endif; ?>

<?php if (!$showAdminSidebar && $user): ?>
<script>
(function() {
    function initPublicSidebar() {
        if (window.__publicSidebarBound) return;
        window.__publicSidebarBound = true;

        var toggleBtn   = document.getElementById('sidebarToggleDesktop');
        var closeBtn    = document.getElementById('sidebarClose');
        var sidebar     = document.getElementById('adminSidebar');
        var content     = document.getElementById('adminContent');
        var overlay     = document.getElementById('sidebarOverlay');

        if (!sidebar) return;

        function isDesktop() { return window.innerWidth >= 768; }

        function updateAriaToggle(open) {
            if (!toggleBtn) return;
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', open ? 'Close sidebar' : 'Open sidebar');
        }

        function openSidebar() {
            sidebar.classList.remove('hidden');
            sidebar.classList.add('show');
            if (content) content.classList.remove('expanded');
            if (overlay) overlay.classList.toggle('show', !isDesktop());
            updateAriaToggle(true);
        }

        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebar.classList.add('hidden');
            if (content) content.classList.add('expanded');
            if (overlay) overlay.classList.remove('show');
            updateAriaToggle(false);
        }

        if (toggleBtn) toggleBtn.addEventListener('click', function() {
            sidebar.classList.contains('hidden') ? openSidebar() : closeSidebar();
        });
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (overlay)  overlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) closeSidebar();
        });

        window.addEventListener('resize', function() {
            if (isDesktop()) {
                if (overlay) overlay.classList.remove('show');
                updateAriaToggle(!sidebar.classList.contains('hidden'));
            } else if (sidebar.classList.contains('show') && overlay) {
                overlay.classList.add('show');
            }
        });

        // Close on nav-link click (mobile)
        sidebar.querySelectorAll('.nav-link[href]').forEach(function(a) {
            var h = (a.getAttribute('href') || '').trim();
            if (!h || h.charAt(0) === '#') return;
            a.addEventListener('click', function() { if (!isDesktop()) closeSidebar(); });
        });

        // Highlight current page link
        var path = (location.pathname.split('/').pop() || '').split('?')[0];
        sidebar.querySelectorAll('.nav-link[href]').forEach(function(a) {
            try {
                var seg = (new URL(a.href, location.href).pathname.split('/').pop() || '').split('?')[0];
                if (seg && seg === path) a.classList.add('active');
            } catch(e) {}
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPublicSidebar);
    } else {
        initPublicSidebar();
    }
})();
</script>
<?php endif; ?>

<?php if ($showAdminSidebar): ?>
<script>
(function () {
    function initAdminSidebarShell() {
        if (window.__adminSidebarShellBound) return;
        window.__adminSidebarShellBound = true;

        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
        const sidebarClose = document.getElementById('sidebarClose');
        const adminSidebar = document.getElementById('adminSidebar');
        const adminContent = document.getElementById('adminContent');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (!adminSidebar || !adminContent) return;

        function isDesktop() {
            return window.innerWidth >= 768;
        }

        function updateSidebarToggle(open) {
            if (!sidebarToggleDesktop) return;
            sidebarToggleDesktop.setAttribute('aria-expanded', open ? 'true' : 'false');
            sidebarToggleDesktop.setAttribute('aria-label', open ? 'Close sidebar' : 'Open sidebar');
            sidebarToggleDesktop.title = open ? 'Close sidebar' : 'Open sidebar';
        }

        function isSidebarOpen() {
            return isDesktop()
                ? !adminSidebar.classList.contains('hidden')
                : adminSidebar.classList.contains('show');
        }
        
        function openSidebar() {
            adminSidebar.classList.remove('hidden');
            adminSidebar.classList.add('show');
            adminContent.classList.remove('expanded');
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle('show', !isDesktop());
            }
            updateSidebarToggle(true);
        }
        
        function closeSidebar() {
            adminSidebar.classList.remove('show');
            adminSidebar.classList.add('hidden');
            adminContent.classList.add('expanded');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('show');
            }
            updateSidebarToggle(false);
        }
        
        function toggleDesktopSidebar() {
            if (isDesktop()) {
                if (adminSidebar.classList.contains('hidden')) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            } else {
                if (adminSidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }
        }
        
        // Event listeners
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                if (adminSidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }
        
        if (sidebarToggleDesktop) {
            sidebarToggleDesktop.addEventListener('click', toggleDesktopSidebar);
        }
        
        if (sidebarClose) {
            sidebarClose.addEventListener('click', closeSidebar);
        }
        
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }
        
        // Keep the sidebar state consistent when switching between phone and desktop widths.
        window.addEventListener('resize', function() {
            if (isDesktop()) {
                if (sidebarOverlay) {
                    sidebarOverlay.classList.remove('show');
                }
                updateSidebarToggle(!adminSidebar.classList.contains('hidden'));
            } else if (adminSidebar.classList.contains('show') && sidebarOverlay) {
                sidebarOverlay.classList.add('show');
            }
        });
        
        // Close sidebar on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && adminSidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
        
        // Preserve menu collapse state
        function preserveMenuState() {
            // Sidebar accordion only (avoid .collapse inside swapped #mainPageContent)
            const collapses = document.querySelectorAll('#sidebarAccordion .collapse');
            collapses.forEach(function(collapse) {
                collapse.addEventListener('shown.bs.collapse', function() {
                    localStorage.setItem('collapse_' + collapse.id, 'true');
                });
                collapse.addEventListener('hidden.bs.collapse', function() {
                    localStorage.setItem('collapse_' + collapse.id, 'false');
                });
            });
            
            // Do NOT restore collapse on refresh - all dropdowns stay hidden
            
            // Save active menu item state (exclude category headers: hash href / collapse toggles)
            const navLinks = document.querySelectorAll('.admin-sidebar .nav-link[href]');
            navLinks.forEach(function(link) {
                var h = (link.getAttribute('href') || '').trim();
                if (!h || h.charAt(0) === '#' || link.getAttribute('data-bs-toggle') === 'collapse') {
                    return;
                }
                link.addEventListener('click', function() {
                    // Remove active class from all links in the same submenu
                    const parentMenu = link.closest('.collapse');
                    if (parentMenu) {
                        parentMenu.querySelectorAll('.nav-link').forEach(function(l) {
                            l.classList.remove('active');
                        });
                    }
                    
                    // Add active class to clicked link
                    link.classList.add('active');
                    
                    // Save active menu item to localStorage
                    localStorage.setItem('active_menu_item', link.getAttribute('href'));
                });
            });
            
            // Restore active menu item from localStorage
            const savedActiveItem = localStorage.getItem('active_menu_item');
            const currentPath = window.location.pathname + window.location.search;
            
            // Prioritize saved item, but fall back to current page if saved item doesn't exist
            let targetActiveItem = savedActiveItem;
            
            // Check if saved item exists in the menu
            if (savedActiveItem) {
                const savedLink = document.querySelector(`.admin-sidebar .nav-link[href="${savedActiveItem}"]`);
                if (!savedLink) {
                    // Saved item doesn't exist, use current page
                    targetActiveItem = currentPath;
                }
            } else {
                // No saved item, use current page
                targetActiveItem = currentPath;
            }
            
            // Find and activate the target item
            const activeLink = document.querySelector(`.admin-sidebar .nav-link[href="${targetActiveItem}"]`);
            if (activeLink) {
                // Remove active class from all links
                navLinks.forEach(function(l) {
                    l.classList.remove('active');
                });
                // Add active class to target item (do not expand parent on refresh)
                activeLink.classList.add('active');
            }
        }
        
        // AJAX Navigation — bubble phase so Bootstrap collapse on category headers still runs
        function setupAjaxNavigation() {
            var sidebar = document.getElementById('adminSidebar');
            if (!sidebar || sidebar.dataset.ajaxNavBound === '1') return;
            sidebar.dataset.ajaxNavBound = '1';
            sidebar.addEventListener('click', function(e) {
                var link = e.target.closest('a.nav-link[href]');
                if (!link || !sidebar.contains(link)) return;
                var hrefAttr = (link.getAttribute('href') || '').trim();
                if (!hrefAttr || hrefAttr.charAt(0) === '#') return;
                if (link.getAttribute('data-bs-toggle') === 'collapse') return;
                var abs = link.href || hrefAttr;
                if (!isDesktop()) {
                    closeSidebar();
                }
                if (hrefAttr.indexOf('logout') >= 0 || hrefAttr.indexOf('profile') >= 0) return;
                if ((hrefAttr.indexOf('scanner/') >= 0 || hrefAttr.indexOf('/scanner/') >= 0) && hrefAttr.indexOf('out_items_config') < 0) return;
                if (/^https?:/i.test(hrefAttr)) return;
                e.preventDefault();
                e.stopPropagation();
                loadPage(abs);
            });
        }
        
        // Load page content via AJAX — top progress bar, skeleton, fade transition
        const pageCache = {};
        var navFetchController = null;

        function showTopProgress() {
            var wrap = document.getElementById('ajaxTopProgressWrap');
            if (!wrap) return;
            wrap.hidden = false;
            wrap.classList.remove('is-done');
            wrap.classList.add('is-active');
            wrap.setAttribute('aria-busy', 'true');
        }

        function completeTopProgress() {
            var wrap = document.getElementById('ajaxTopProgressWrap');
            if (!wrap) return;
            wrap.classList.add('is-done');
            setTimeout(function() {
                wrap.classList.remove('is-active', 'is-done');
                wrap.hidden = true;
                wrap.removeAttribute('aria-busy');
            }, 400);
        }

        function resetTopProgress() {
            var wrap = document.getElementById('ajaxTopProgressWrap');
            if (!wrap) return;
            wrap.classList.remove('is-active', 'is-done');
            wrap.hidden = true;
            wrap.removeAttribute('aria-busy');
        }

        function buildSkeletonOverlay() {
            var el = document.createElement('div');
            el.className = 'ajax-content-skeleton-overlay';
            el.innerHTML = '<div class="sk-row w-50"></div><div class="sk-row w-70"></div><div class="sk-row"></div><div class="sk-row"></div><div class="sk-card"></div>';
            return el;
        }

        function loadPage(url) {
            const mainContent = document.getElementById('mainPageContent');
            if (!mainContent) return;

            if (navFetchController) {
                try { navFetchController.abort(); } catch (e) {}
            }
            navFetchController = new AbortController();

            mainContent.style.position = 'relative';
            mainContent.classList.add('is-loading-content');
            mainContent.classList.remove('content-fade-in');

            var existingSkel = mainContent.querySelector('.ajax-content-skeleton-overlay');
            if (existingSkel) existingSkel.remove();
            mainContent.appendChild(buildSkeletonOverlay());

            showTopProgress();

            fetch(url, {
                credentials: 'same-origin',
                signal: navFetchController.signal,
                cache: 'no-store',
                headers: { 'X-Admin-Nav-Request': '1' }
            })
                .then(function(r) {
                    if (r.status === 401) {
                        return r.json().then(function(j) {
                            var loc = (j && j.redirect) ? j.redirect : <?= json_encode(rtrim($BASE_URL, '/') . '/login.php') ?>;
                            window.location.href = loc;
                            return Promise.reject(new Error('session_expired'));
                        }).catch(function() {
                            window.location.href = <?= json_encode(rtrim($BASE_URL, '/') . '/login.php') ?>;
                            return Promise.reject(new Error('session_expired'));
                        });
                    }
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(function(html) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const fetchedContent = doc.querySelector('#mainPageContent') || doc.querySelector('.admin-content .container-fluid') || doc.querySelector('.container-fluid');
                    if (fetchedContent) {
                        const htmlContent = fetchedContent.innerHTML;
                        pageCache[url] = htmlContent;
                        mainContent.classList.remove('is-loading-content');
                        removePreviousDeferredPageScripts();
                        mainContent.innerHTML = htmlContent;
                        executeScriptsOutsideMainPageContent(doc);
                        var t = doc.querySelector('title');
                        if (t) document.title = t.textContent;
                        history.pushState({}, '', url);
                        completeTopProgress();
                        mainContent.classList.remove('content-fade-in');
                        void mainContent.offsetWidth;
                        mainContent.classList.add('content-fade-in');
                        var fadeEnded = false;
                        function endContentFade() {
                            if (fadeEnded) return;
                            fadeEnded = true;
                            mainContent.classList.remove('content-fade-in');
                        }
                        mainContent.addEventListener('animationend', function onFade(e) {
                            if (e.animationName === 'mainContentFadeIn') {
                                mainContent.removeEventListener('animationend', onFade);
                                endContentFade();
                            }
                        });
                        setTimeout(endContentFade, 500);
                        reinitializeScripts(url);
                        updateActiveMenuItem(url);
                    } else {
                        mainContent.classList.remove('is-loading-content');
                        resetTopProgress();
                        mainContent.innerHTML = '<div class="alert alert-warning">Content could not be loaded. <a href="' + url + '">Reload</a></div>';
                    }
                })
                .catch(function(err) {
                    if (err.name === 'AbortError') return;
                    mainContent.classList.remove('is-loading-content');
                    resetTopProgress();
                    var sk = mainContent.querySelector('.ajax-content-skeleton-overlay');
                    if (sk) sk.remove();
                    mainContent.innerHTML = '<div class="alert alert-danger">Error. <a href="' + url + '">Reload page</a></div>';
                });
        }

        var adminRefreshBtn = document.getElementById('adminRefreshContent');
        if (adminRefreshBtn) {
            adminRefreshBtn.addEventListener('click', function() {
                loadPage(window.location.href);
            });
        }
        window.adminLoadPage = loadPage;

        /** Many PHP pages put &lt;script&gt; after layout/footer.php, so they sit outside #mainPageContent. AJAX only swaps innerHTML of #mainPageContent — those scripts never ran. */
        function removePreviousDeferredPageScripts() {
            document.querySelectorAll('script[data-admin-deferred="1"]').forEach(function(n) {
                if (n.parentNode) n.parentNode.removeChild(n);
            });
        }

        function executeScriptsOutsideMainPageContent(parsedDoc) {
            var fragmentMain = parsedDoc.getElementById('mainPageContent');
            if (!fragmentMain) return;
            var list = parsedDoc.querySelectorAll('script');
            list.forEach(function(s) {
                if (fragmentMain.contains(s)) return;
                var srcAttr = s.getAttribute('src') || '';
                if (srcAttr && /bootstrap.*bundle|html2canvas/i.test(srcAttr)) return;
                var inline = (s.textContent || '').trim();
                if (!srcAttr && !inline) return;
                var nu = document.createElement('script');
                nu.setAttribute('data-admin-deferred', '1');
                Array.prototype.forEach.call(s.attributes, function(attr) {
                    if (attr.name === 'data-admin-deferred') return;
                    nu.setAttribute(attr.name, attr.value);
                });
                if (srcAttr) {
                    nu.src = s.src;
                    nu.async = false;
                } else {
                    nu.textContent = s.textContent;
                }
                document.body.appendChild(nu);
            });
        }
        
        // Run injected <script> tags in order (avoids eval; matches browser execution model)
        function reinitializeScripts(contentUrl) {
            var mainContent = document.getElementById('mainPageContent');
            if (!mainContent) return;

            var scripts = Array.prototype.slice.call(mainContent.querySelectorAll('script'));
            scripts.forEach(function(oldScript) {
                var newScript = document.createElement('script');
                Array.prototype.forEach.call(oldScript.attributes, function(attr) {
                    newScript.setAttribute(attr.name, attr.value);
                });
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                    newScript.async = false;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });

            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                var t = bootstrap.Tooltip.getInstance(el);
                if (t) t.dispose();
            });
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
                var p = bootstrap.Popover.getInstance(el);
                if (p) p.dispose();
            });
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                try { new bootstrap.Tooltip(el); } catch (err) {}
            });
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
                try { new bootstrap.Popover(el); } catch (err) {}
            });

            var evtUrl = contentUrl || window.location.href;
            try {
                mainContent.dispatchEvent(new CustomEvent('admin:content-loaded', { bubbles: true, detail: { url: evtUrl } }));
            } catch (e) {}
        }
        
        // Update active menu item based on current URL
        function updateActiveMenuItem(url) {
            // Remove active class from all menu items
            const allNavLinks = document.querySelectorAll('.admin-sidebar .nav-link');
            allNavLinks.forEach(link => link.classList.remove('active'));
            
            // Find and activate the matching menu item
            const currentPath = url.split('/').pop().split('?')[0] || 'index.php';
            const matchingLink = document.querySelector(`.admin-sidebar .nav-link[href*="${currentPath}"]`);
            
            if (matchingLink) {
                matchingLink.classList.add('active');
                // Do not expand/collapse dropdown - menu stays as user left it
            }
        }
        
        // Handle browser back/forward
        window.addEventListener('popstate', function() {
            const path = window.location.pathname;
            if (path.includes('/admin/') || path.includes('/cashier/')) {
                loadPage(window.location.href);
            }
        });
        
        // Initialize menu state preservation and AJAX navigation
        updateSidebarToggle(isSidebarOpen());
        preserveMenuState();
        setupAjaxNavigation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminSidebarShell);
    } else {
        initAdminSidebarShell();
    }
})();
</script>
<?php endif; ?>
