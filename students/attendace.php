<?php
// schedule.php — NU SAMS | Student Portal — Attendance QR Generator
// National University - Student Assistant Management System
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance QR Generator | NU SAMS</title>
    <style>
        /* ============================================================
           CSS VARIABLES — Design System
        ============================================================ */
        :root {
            /* Colors */
            --clr-white:           #FFFFFF;
            --clr-bg-page:         linear-gradient(137.05deg, #EFF6FF 0%, #FFFFFF 50%, #FFFBEB 100%);
            --clr-border:          #E5E7EB;
            --clr-border-card:     #F3F4F6;
            --clr-bg-muted:        #F9FAFB;
            --clr-bg-grey:         #F3F4F6;

            --clr-text-primary:    #101828;
            --clr-text-body:       #364153;
            --clr-text-muted:      #4A5565;
            --clr-text-subtle:     #6A7282;
            --clr-text-light:      #BEDBFF;

            /* Brand Blue (sidebar) */
            --clr-navy:            #003087;
            --grad-navy-v:         linear-gradient(180deg,
                                       #003087 0%, #00328B 10%, #00358E 20%, #003792 30%,
                                       #003995 40%, #003B99 50%, #003E9C 60%, #0040A0 70%,
                                       #0042A4 80%, #0045A7 90%, #0047AB 100%);
            --grad-navy-h:         linear-gradient(90deg,
                                       #003087 0%, #00328B 10%, #00358E 20%, #003792 30%,
                                       #003995 40%, #003B99 50%, #003E9C 60%, #0040A0 70%,
                                       #0042A4 80%, #0045A7 90%, #0047AB 100%);
            --grad-navy-diag:      linear-gradient(143.29deg,
                                       #003087 0%, #00328B 10%, #00358E 20%, #003792 30%,
                                       #003995 40%, #003B99 50%, #003E9C 60%, #0040A0 70%,
                                       #0042A4 80%, #0045A7 90%, #0047AB 100%);

            /* Accent */
            --clr-gold:            #FFB81C;
            --clr-green:           #00C950;
            --clr-red:             #E7000B;
            --clr-alert:           #FB2C36;
            --clr-red-border:      #FFC9C9;
            --grad-red-soft:       linear-gradient(154.63deg, #FEF2F2 0%, #FFF7ED 100%);
            --grad-gold-soft:      linear-gradient(90deg, #FFFBEB 0%, #FFF7ED 100%);

            /* Shadows */
            --shadow-sm:   0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.10);
            --shadow-md:   0 10px 15px rgba(0,0,0,.10), 0 4px 6px rgba(0,0,0,.10);
            --shadow-sm2:  0 4px 6px rgba(0,0,0,.10),  0 2px 4px rgba(0,0,0,.10);

            /* Sidebar */
            --sidebar-width: 288px;

            /* Typography */
            --fs-xs:   12px;
            --fs-sm:   14px;
            --fs-base: 16px;
            --fs-md:   18px;
            --fs-lg:   20px;
            --fs-xl:   24px;
            --fs-2xl:  36px;

            /* Spacing */
            --sp-4:   4px;
            --sp-8:   8px;
            --sp-12:  12px;
            --sp-16:  16px;
            --sp-24:  24px;
            --sp-32:  32px;

            /* Radius */
            --radius-sm:   10px;
            --radius-md:   14px;
            --radius-lg:   16px;
            --radius-pill: 9999px;
        }

        /* ============================================================
           RESET & BASE
        ============================================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(137.05deg, #EFF6FF 0%, #FFFFFF 50%, #FFFBEB 100%);
            min-height: 100vh;
            display: flex;
        }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; font-family: inherit; border: none; background: none; }
        img { display: block; max-width: 100%; }
        ul { list-style: none; }

        /* ============================================================
           APP LAYOUT
        ============================================================ */
        .app { display: flex; width: 100%; min-height: 100vh; }

        /* ============================================================
           SIDEBAR
        ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--grad-navy-v);
            box-shadow: 0 25px 50px rgba(0,0,0,.25);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        /* Sidebar – Header */
        .sidebar__header {
            border-bottom: 1px solid rgba(255,255,255,.20);
            padding: var(--sp-24) var(--sp-24) 0;
            height: 105px;
            flex-shrink: 0;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
        }

        .sidebar__logo {
            width: 48px;
            height: 48px;
            background: var(--clr-white);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar__logo-text {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-navy);
            line-height: 32px;
        }

        .sidebar__brand-info { display: flex; flex-direction: column; }

        .sidebar__app-name {
            font-size: var(--fs-lg);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 28px;
        }

        .sidebar__app-sub {
            font-size: var(--fs-xs);
            font-weight: 400;
            color: var(--clr-text-light);
            line-height: 16px;
            white-space: nowrap;
        }

        /* Sidebar – Nav */
        .sidebar__nav {
            flex: 1;
            padding: var(--sp-24) var(--sp-16) 0;
        }

        .nav__list { display: flex; flex-direction: column; gap: var(--sp-8); }
        .nav__item { display: block; }

        .nav__link {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
            padding-left: var(--sp-16);
            border-radius: var(--radius-md);
            transition: background 0.15s;
        }

        .nav__link:hover { background: rgba(255,255,255,.10); }

        .nav__link--active {
            background: var(--clr-white);
            box-shadow: var(--shadow-md);
        }

        .nav__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        .nav__icon svg { width: 100%; height: 100%; }

        .nav__label {
            font-size: var(--fs-base);
            font-weight: 700;
            color: var(--clr-white);
            line-height: 24px;
            white-space: nowrap;
        }

        .nav__link--active .nav__label { color: var(--clr-navy); }

        /* Sidebar – Footer */
        .sidebar__footer {
            border-top: 1px solid rgba(255,255,255,.20);
            padding: 17px var(--sp-16) var(--sp-16);
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
            flex-shrink: 0;
        }

        .sidebar__user-card {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-md);
            padding: var(--sp-16) var(--sp-16) var(--sp-8);
            display: flex;
            flex-direction: column;
            gap: 2px;
            height: 92px;
        }

        .sidebar__user-label {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-light);
            line-height: 20px;
        }

        .sidebar__user-name {
            font-size: var(--fs-base);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 24px;
        }

        .sidebar__user-id {
            font-size: var(--fs-xs);
            font-weight: 400;
            color: var(--clr-text-light);
            line-height: 16px;
        }

        .sidebar__logout {
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            height: 48px;
            padding-left: var(--sp-16);
            border-radius: var(--radius-md);
            background: rgba(255,255,255,.10);
            width: 100%;
            transition: background 0.15s;
        }
        .sidebar__logout:hover { background: rgba(255,255,255,.18); }

        .sidebar__logout-icon { width: 20px; height: 20px; flex-shrink: 0; }
        .sidebar__logout-icon svg { width: 100%; height: 100%; }

        .sidebar__logout-label {
            font-size: var(--fs-base);
            font-weight: 700;
            color: var(--clr-white);
            line-height: 24px;
        }

        /* Hamburger */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
            flex-shrink: 0;
        }
        .hamburger:hover { background: rgba(0,0,0,.06); }
        .hamburger__bar {
            width: 20px;
            height: 2px;
            background: var(--clr-text-muted);
            border-radius: 2px;
            transition: transform 0.25s, opacity 0.25s;
        }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(2) { opacity: 0; }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 99;
        }
        .sidebar-overlay--hidden  { display: none; }
        .sidebar-overlay--visible { display: block; }

        /* ============================================================
           MAIN
        ============================================================ */
        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* ============================================================
           TOP BAR
        ============================================================ */
        .topbar {
            background: var(--clr-white);
            border-bottom: 1px solid var(--clr-border);
            box-shadow: var(--shadow-sm);
            height: 85px;
            padding: var(--sp-16) var(--sp-32);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            position: relative;
        }

        .topbar__inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 52px;
        }

        .topbar__left { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__heading { display: flex; flex-direction: column; gap: 0; }

        .topbar__title {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 32px;
        }

        .topbar__subtitle {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-muted);
            line-height: 20px;
            white-space: nowrap;
        }

        .topbar__actions { display: flex; align-items: center; gap: var(--sp-8); }

        .topbar__action-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background 0.15s;
        }
        .topbar__action-btn:hover { background: var(--clr-bg-muted); }
        .topbar__action-btn svg { width: 24px; height: 24px; }

        .topbar__badge {
            position: absolute;
            top: 0;
            right: 0;
            width: 12px;
            height: 12px;
            background: var(--clr-alert);
            border: 2px solid var(--clr-white);
            border-radius: 50%;
        }

        /* ============================================================
           PAGE CONTENT
        ============================================================ */
        .page-content {
            flex: 1;
            padding: var(--sp-32) 74.5px var(--sp-32) 59.5px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-32);
        }

        /* ============================================================
           PAGE HEADER
        ============================================================ */
        .page-header { display: flex; flex-direction: column; gap: var(--sp-8); }

        .page-header__title-row {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 40px;
        }

        .page-header__icon { width: 40px; height: 40px; flex-shrink: 0; }
        .page-header__icon svg { width: 100%; height: 100%; }

        .page-header__title {
            font-size: var(--fs-2xl);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 40px;
            white-space: nowrap;
        }

        .page-header__subtitle {
            font-size: var(--fs-md);
            font-weight: 500;
            color: var(--clr-text-muted);
            line-height: 28px;
        }

        /* ============================================================
           MAIN PANEL GRID (left card + right sidebar cards)
        ============================================================ */
        .panel-grid {
            display: flex;
            gap: var(--sp-32);
            align-items: flex-start;
        }

        /* ---- LEFT CARD ---- */
        .qr-card {
            flex: 1;
            background: var(--clr-white);
            border: 2px solid var(--clr-border-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 34px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-32);
        }

        /* Mode toggle (QR / OTP) */
        .mode-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--sp-16);
            height: 56px;
        }

        .btn-mode {
            height: 56px;
            border-radius: var(--radius-md);
            font-size: var(--fs-base);
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-8);
            transition: opacity 0.15s;
            box-shadow: var(--shadow-md);
        }
        .btn-mode:hover { opacity: .88; }
        .btn-mode svg { width: 20px; height: 20px; }

        .btn-mode--active {
            background: var(--grad-navy-h);
            color: var(--clr-white);
            box-shadow: var(--shadow-md);
        }

        .btn-mode--inactive {
            background: var(--clr-bg-grey);
            color: var(--clr-text-muted);
            box-shadow: none;
        }

        /* Check In/Out toggle */
        .checkin-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--sp-16);
            height: 48px;
        }

        .btn-checkin {
            height: 48px;
            border-radius: var(--radius-md);
            font-size: var(--fs-base);
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.15s;
        }
        .btn-checkin:hover { opacity: .88; }

        .btn-checkin--active {
            background: var(--clr-green);
            color: var(--clr-white);
            box-shadow: var(--shadow-md);
        }

        .btn-checkin--inactive {
            background: var(--clr-bg-grey);
            color: var(--clr-text-muted);
        }

        /* Expiry Banner */
        .expiry-banner {
            background: linear-gradient(90deg, #FFFBEB 0%, #FFF7ED 100%);
            border: 2px solid var(--clr-gold);
            border-radius: var(--radius-md);
            padding: 18px;
            height: 88px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .expiry-banner__left {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
        }

        .expiry-banner__icon { width: 24px; height: 24px; flex-shrink: 0; }
        .expiry-banner__icon svg { width: 100%; height: 100%; }

        .expiry-banner__info { display: flex; flex-direction: column; gap: 0; }

        .expiry-banner__label {
            font-size: var(--fs-sm);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .expiry-banner__value {
            font-size: var(--fs-xl);
            font-weight: 900;
            color: var(--clr-navy);
            line-height: 32px;
            white-space: nowrap;
        }

        .expiry-banner__refresh {
            width: 48px;
            height: 48px;
            background: var(--clr-white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .expiry-banner__refresh:hover { background: var(--clr-bg-muted); }
        .expiry-banner__refresh svg { width: 24px; height: 24px; }

        /* QR Panel */
        .qr-panel-wrap { display: flex; flex-direction: column; gap: var(--sp-24); }

        .qr-display {
            background: var(--grad-navy-diag);
            border-radius: var(--radius-lg);
            height: 514px;
            position: relative;
            overflow: hidden;
        }

        .qr-display__code-wrap {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            top: 32px;
            width: 364px;
            height: 364px;
            background: var(--clr-white);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-display__code-wrap img {
            width: 300px;
            height: 300px;
            object-fit: contain;
        }

        .qr-display__info {
            position: absolute;
            bottom: 32px;
            left: 32px;
            right: 32px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-8);
            align-items: center;
        }

        .qr-display__label {
            font-size: var(--fs-lg);
            font-weight: 900;
            color: var(--clr-white);
            line-height: 28px;
            text-align: center;
        }

        .qr-display__sublabel {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-light);
            line-height: 20px;
            text-align: center;
        }

        /* Code ID strip */
        .code-id-strip {
            background: var(--clr-bg-muted);
            border-radius: var(--radius-md);
            padding: var(--sp-16);
            height: 72px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-4);
        }

        .code-id-strip__label {
            font-size: var(--fs-xs);
            font-weight: 700;
            color: var(--clr-text-subtle);
            line-height: 16px;
            text-align: center;
            letter-spacing: .05em;
        }

        .code-id-strip__value {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: var(--fs-sm);
            font-weight: 700;
            color: var(--clr-text-body);
            line-height: 20px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* How It Works */
        .how-it-works {
            background: #EFF6FF;
            border: 2px solid #BEDBFF;
            border-radius: var(--radius-md);
            padding: 26px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
        }

        .how-it-works__title {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .how-it-works__list {
            display: flex;
            flex-direction: column;
            gap: var(--sp-8);
        }

        .how-it-works__item {
            display: flex;
            gap: var(--sp-8);
            height: 20px;
            align-items: flex-start;
        }

        .how-it-works__num {
            font-size: var(--fs-sm);
            font-weight: 900;
            color: var(--clr-navy);
            line-height: 20px;
            flex-shrink: 0;
        }

        .how-it-works__text {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-body);
            line-height: 20px;
            white-space: nowrap;
        }

        /* ---- RIGHT SIDEBAR CARDS ---- */
        .right-col {
            width: 362.656px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        .info-card {
            background: var(--clr-white);
            border: 2px solid var(--clr-border-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 26px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
        }

        .info-card__title {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        /* Status rows inside Current Status card */
        .status-rows { display: flex; flex-direction: column; gap: var(--sp-16); }

        .status-row {
            background: var(--clr-bg-muted);
            border-radius: var(--radius-md);
            padding: var(--sp-16);
            height: 84px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-4);
        }

        .status-row__label {
            font-size: var(--fs-sm);
            font-weight: 700;
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .status-row__value {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-navy);
            line-height: 28px;
            white-space: nowrap;
        }

        .status-row__value-with-icon {
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            height: 28px;
        }

        .status-row__value-with-icon svg { width: 20px; height: 20px; flex-shrink: 0; }

        .status-row__value-text {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 28px;
            white-space: nowrap;
        }

        /* Recent Logs empty state */
        .recent-logs-empty {
            height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0;
        }

        .recent-logs-empty__icon-wrap {
            width: 64px;
            height: 64px;
            background: var(--clr-bg-grey);
            border-radius: var(--radius-pill);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--sp-12);
        }
        .recent-logs-empty__icon-wrap svg { width: 32px; height: 32px; }

        .recent-logs-empty__text {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-subtle);
            line-height: 20px;
            text-align: center;
        }

        /* Security Notice card */
        .security-card {
            border: 2px solid var(--clr-red-border);
            border-radius: var(--radius-lg);
            background: var(--grad-red-soft);
            padding: var(--sp-24);
            display: flex;
            flex-direction: column;
            gap: var(--sp-12);
        }

        .security-card__title {
            font-size: var(--fs-md);
            font-weight: 900;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .security-card__body {
            font-size: var(--fs-sm);
            font-weight: 500;
            color: var(--clr-text-body);
            line-height: 20px;
        }

        .security-card__body strong {
            font-weight: 900;
            color: var(--clr-red);
        }

        .security-card__footnote {
            font-size: var(--fs-xs);
            font-weight: 500;
            color: var(--clr-text-muted);
            line-height: 16px;
        }

        /* ============================================================
           RESPONSIVE — TABLET (≤1024px)
        ============================================================ */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                height: 100vh;
                transition: left 0.28s ease;
                z-index: 200;
            }
            .sidebar--open { left: 0; }
            .hamburger { display: flex; }
            .topbar { padding: var(--sp-16); }
            .page-content { padding: var(--sp-16); }
            .panel-grid { flex-direction: column; }
            .right-col { width: 100%; }
            .how-it-works__text { white-space: normal; }
        }

        /* ============================================================
           RESPONSIVE — MOBILE (≤768px)
        ============================================================ */
        @media (max-width: 768px) {
            .topbar__title { font-size: 18px; }
            .topbar__subtitle { font-size: var(--fs-xs); }
            .page-content { padding: var(--sp-12); gap: var(--sp-16); }
            .page-header__title { font-size: 26px; }
            .qr-card { padding: var(--sp-16); gap: var(--sp-16); }
            .qr-display { height: 380px; }
            .qr-display__code-wrap { width: 260px; height: 260px; top: 24px; }
            .qr-display__code-wrap img { width: 210px; height: 210px; }
            .expiry-banner { height: auto; padding: var(--sp-12) var(--sp-16); }
            .mode-toggle, .checkin-toggle { gap: var(--sp-8); }
            .code-id-strip__value { font-size: var(--fs-xs); }
            .right-col { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>

<div class="sidebar-overlay sidebar-overlay--hidden" id="sidebarOverlay"></div>

<div class="app">

    <!-- ================================================================
         SIDEBAR
    ================================================================ -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Student portal navigation">

        <!-- Brand Header -->
        <div class="sidebar__header">
            <div class="sidebar__brand">
                <div class="sidebar__logo" aria-hidden="true">
                    <span class="sidebar__logo-text">NU</span>
                </div>
                <div class="sidebar__brand-info">
                    <span class="sidebar__app-name">SAMS</span>
                    <span class="sidebar__app-sub">Student Assistant Management</span>
                </div>
            </div>
        </div>

        <!-- Navigation Links -->
        <nav class="sidebar__nav" aria-label="Main menu">
            <ul class="nav__list">
                <li class="nav__item">
                    <a href="dashboard.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="2" width="7" height="7" rx="1.5" fill="rgba(255,255,255,0.7)"/>
                                <rect x="11" y="2" width="7" height="7" rx="1.5" fill="rgba(255,255,255,0.7)"/>
                                <rect x="2" y="11" width="7" height="7" rx="1.5" fill="rgba(255,255,255,0.7)"/>
                                <rect x="11" y="11" width="7" height="7" rx="1.5" fill="rgba(255,255,255,0.7)"/>
                            </svg>
                        </span>
                        <span class="nav__label">Dashboard</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="schedule.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="4" width="16" height="14" rx="2" stroke="rgba(255,255,255,0.7)" stroke-width="1.5"/>
                                <path d="M6 2v4M14 2v4" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M2 9h16" stroke="rgba(255,255,255,0.7)" stroke-width="1.2"/>
                            </svg>
                        </span>
                        <span class="nav__label">My Schedule</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="attendance.php" class="nav__link nav__link--active" aria-current="page">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="10" cy="10" r="8" stroke="#003087" stroke-width="1.5"/>
                                <path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="#003087" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="nav__label">Attendance</span>
                    </a>
                </li>
                <li class="nav__item">
                    <a href="profile.php" class="nav__link">
                        <span class="nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="10" cy="7" r="4" stroke="rgba(255,255,255,0.7)" stroke-width="1.5"/>
                                <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" stroke="rgba(255,255,255,0.7)" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span class="nav__label">Profile</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- User Info + Logout -->
        <div class="sidebar__footer">
            <div class="sidebar__user-card">
                <span class="sidebar__user-label">Logged in as</span>
                <span class="sidebar__user-name">Juan Dela Cruz</span>
                <span class="sidebar__user-id">Student ID: 2021-12345</span>
            </div>
            <button class="sidebar__logout" type="button" onclick="window.location.href='logout.php'">
                <span class="sidebar__logout-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M13 14l3-4-3-4M16 10H7" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="sidebar__logout-label">Logout</span>
            </button>
        </div>
    </aside>

    <!-- ================================================================
         MAIN
    ================================================================ -->
    <main class="main">

        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar__inner">
                <div class="topbar__left">
                    <button class="hamburger" id="hamburgerBtn" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                        <span class="hamburger__bar"></span>
                        <span class="hamburger__bar"></span>
                        <span class="hamburger__bar"></span>
                    </button>
                    <div class="topbar__heading">
                        <span class="topbar__title">Student Portal</span>
                        <span class="topbar__subtitle">National University - Lipa Campus</span>
                    </div>
                </div>
                <div class="topbar__actions">
                    <!-- Bell -->
                    <a href="#" class="topbar__action-btn" aria-label="Notifications">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" stroke="#4A5565" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="topbar__badge" aria-label="New notifications"></span>
                    </a>
                    <!-- Settings -->
                    <a href="#" class="topbar__action-btn" aria-label="Settings">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 002.572-1.065z" stroke="#4A5565" stroke-width="1.8"/>
                            <circle cx="12" cy="12" r="3" stroke="#4A5565" stroke-width="1.8"/>
                        </svg>
                    </a>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <section class="page-content" aria-label="Attendance QR Generator">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header__title-row">
                    <div class="page-header__icon" aria-hidden="true">
                        <!-- QR / attendance icon -->
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="4" y="4" width="13" height="13" rx="2" stroke="#003087" stroke-width="2.2"/>
                            <rect x="8" y="8" width="5" height="5" rx="1" fill="#003087"/>
                            <rect x="23" y="4" width="13" height="13" rx="2" stroke="#003087" stroke-width="2.2"/>
                            <rect x="27" y="8" width="5" height="5" rx="1" fill="#003087"/>
                            <rect x="4" y="23" width="13" height="13" rx="2" stroke="#003087" stroke-width="2.2"/>
                            <rect x="8" y="27" width="5" height="5" rx="1" fill="#003087"/>
                            <rect x="23" y="23" width="6" height="6" rx="1" fill="#003087"/>
                            <rect x="31" y="23" width="6" height="6" rx="1" fill="#003087"/>
                            <rect x="23" y="31" width="6" height="6" rx="1" fill="#003087"/>
                            <rect x="31" y="31" width="6" height="6" rx="1" fill="#003087"/>
                        </svg>
                    </div>
                    <h1 class="page-header__title">Attendance QR Generator</h1>
                </div>
                <p class="page-header__subtitle">Display QR code or OTP for students to check in/out</p>
            </div>

            <!-- Panel Grid -->
            <div class="panel-grid">

                <!-- LEFT CARD -->
                <div class="qr-card">

                    <!-- Mode Toggle -->
                    <div class="mode-toggle" role="group" aria-label="Display mode">
                        <button class="btn-mode btn-mode--active" id="btnQrMode" type="button" aria-pressed="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="2" width="6" height="6" rx="1" stroke="white" stroke-width="1.4"/>
                                <rect x="3" y="3" width="4" height="4" rx=".5" fill="white"/>
                                <rect x="12" y="2" width="6" height="6" rx="1" stroke="white" stroke-width="1.4"/>
                                <rect x="13" y="3" width="4" height="4" rx=".5" fill="white"/>
                                <rect x="2" y="12" width="6" height="6" rx="1" stroke="white" stroke-width="1.4"/>
                                <rect x="3" y="13" width="4" height="4" rx=".5" fill="white"/>
                                <rect x="12" y="12" width="3" height="3" rx=".5" fill="white"/>
                                <rect x="16" y="12" width="2" height="2" rx=".3" fill="white"/>
                                <rect x="12" y="16" width="2" height="2" rx=".3" fill="white"/>
                                <rect x="15.5" y="15.5" width="2.5" height="2.5" rx=".3" fill="white"/>
                            </svg>
                            QR Code Mode
                        </button>
                        <button class="btn-mode btn-mode--inactive" id="btnOtpMode" type="button" aria-pressed="false">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 10h12M4 6h12M4 14h8" stroke="#4A5565" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                            OTP Mode
                        </button>
                    </div>

                    <!-- Check In / Check Out Toggle -->
                    <div class="checkin-toggle" role="group" aria-label="Attendance type">
                        <button class="btn-checkin btn-checkin--active" id="btnCheckIn" type="button" aria-pressed="true">Check In</button>
                        <button class="btn-checkin btn-checkin--inactive" id="btnCheckOut" type="button" aria-pressed="false">Check Out</button>
                    </div>

                    <!-- Expiry Banner -->
                    <div class="expiry-banner" aria-live="polite" aria-label="Code expiry countdown">
                        <div class="expiry-banner__left">
                            <span class="expiry-banner__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="12" cy="12" r="10" stroke="#FFB81C" stroke-width="1.8"/>
                                    <path d="M12 7v5l3.5 3.5" stroke="#FFB81C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <div class="expiry-banner__info">
                                <span class="expiry-banner__label">Code expires in:</span>
                                <span class="expiry-banner__value" id="countdownDisplay">5 seconds</span>
                            </div>
                        </div>
                        <button class="expiry-banner__refresh" type="button" id="btnRefresh" aria-label="Refresh code">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" stroke="#4A5565" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>

                    <!-- QR Display + Code ID -->
                    <div class="qr-panel-wrap">
                        <div class="qr-display" aria-label="QR code for attendance check-in">
                            <div class="qr-display__code-wrap">
                                <!-- Inline QR code SVG (static representation matching the Figma design) -->
                                <svg width="300" height="300" viewBox="0 0 300 300" xmlns="http://www.w3.org/2000/svg" aria-label="Attendance QR code">
                                    <!-- Outer quiet zone / background -->
                                    <rect width="300" height="300" fill="white"/>
                                    <!-- Top-left finder pattern -->
                                    <rect x="10" y="10" width="80" height="80" rx="4" fill="#003087"/>
                                    <rect x="22" y="22" width="56" height="56" rx="2" fill="white"/>
                                    <rect x="34" y="34" width="32" height="32" rx="2" fill="#003087"/>
                                    <!-- Top-right finder pattern -->
                                    <rect x="210" y="10" width="80" height="80" rx="4" fill="#003087"/>
                                    <rect x="222" y="22" width="56" height="56" rx="2" fill="white"/>
                                    <rect x="234" y="34" width="32" height="32" rx="2" fill="#003087"/>
                                    <!-- Bottom-left finder pattern -->
                                    <rect x="10" y="210" width="80" height="80" rx="4" fill="#003087"/>
                                    <rect x="22" y="222" width="56" height="56" rx="2" fill="white"/>
                                    <rect x="34" y="234" width="32" height="32" rx="2" fill="#003087"/>
                                    <!-- Data modules (representative pattern matching Figma) -->
                                    <rect x="104" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="10" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="26" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="26" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="26" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="42" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="42" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="42" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="42" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="58" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="58" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="58" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="74" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="74" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="74" width="12" height="12" fill="#003087"/>
                                    <!-- Middle band -->
                                    <rect x="10" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="26" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="58" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="74" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="200" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="232" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="104" width="12" height="12" fill="#003087"/>
                                    <rect x="10" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="42" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="74" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="216" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="248" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="280" y="120" width="12" height="12" fill="#003087"/>
                                    <rect x="10" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="26" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="58" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="90" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="200" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="232" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="136" width="12" height="12" fill="#003087"/>
                                    <rect x="10" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="58" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="216" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="248" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="280" y="152" width="12" height="12" fill="#003087"/>
                                    <rect x="26" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="74" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="200" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="232" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="280" y="168" width="12" height="12" fill="#003087"/>
                                    <rect x="10" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="42" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="90" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="216" y="184" width="12" height="12" fill="#003087"/>
                                    <rect x="248" y="184" width="12" height="12" fill="#003087"/>
                                    <!-- Bottom rows -->
                                    <rect x="104" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="216" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="280" y="210" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="226" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="226" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="226" width="12" height="12" fill="#003087"/>
                                    <rect x="200" y="226" width="12" height="12" fill="#003087"/>
                                    <rect x="248" y="226" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="242" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="242" width="12" height="12" fill="#003087"/>
                                    <rect x="184" y="242" width="12" height="12" fill="#003087"/>
                                    <rect x="216" y="242" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="242" width="12" height="12" fill="#003087"/>
                                    <rect x="104" y="258" width="12" height="12" fill="#003087"/>
                                    <rect x="136" y="258" width="12" height="12" fill="#003087"/>
                                    <rect x="168" y="258" width="12" height="12" fill="#003087"/>
                                    <rect x="248" y="258" width="12" height="12" fill="#003087"/>
                                    <rect x="280" y="258" width="12" height="12" fill="#003087"/>
                                    <rect x="120" y="274" width="12" height="12" fill="#003087"/>
                                    <rect x="152" y="274" width="12" height="12" fill="#003087"/>
                                    <rect x="200" y="274" width="12" height="12" fill="#003087"/>
                                    <rect x="232" y="274" width="12" height="12" fill="#003087"/>
                                    <rect x="264" y="274" width="12" height="12" fill="#003087"/>
                                </svg>
                            </div>
                            <div class="qr-display__info">
                                <p class="qr-display__label" id="qrModeLabel">✅ CHECK IN</p>
                                <p class="qr-display__sublabel">Scan this QR code to log attendance</p>
                            </div>
                        </div>

                        <!-- Code ID -->
                        <div class="code-id-strip">
                            <span class="code-id-strip__label">CURRENT CODE ID:</span>
                            <span class="code-id-strip__value" id="codeIdDisplay">SAMS-1775388912546-SVU0JA</span>
                        </div>
                    </div>

                    <!-- How It Works -->
                    <div class="how-it-works" role="note" aria-label="How the QR attendance system works">
                        <h2 class="how-it-works__title">📋 How It Works</h2>
                        <ol class="how-it-works__list">
                            <li class="how-it-works__item">
                                <span class="how-it-works__num">1.</span>
                                <span class="how-it-works__text">Student opens SAMS mobile app and goes to Attendance</span>
                            </li>
                            <li class="how-it-works__item">
                                <span class="how-it-works__num">2.</span>
                                <span class="how-it-works__text">Student scans this QR code with their camera</span>
                            </li>
                            <li class="how-it-works__item">
                                <span class="how-it-works__num">3.</span>
                                <span class="how-it-works__text">Student enters their 4-digit PIN to verify</span>
                            </li>
                            <li class="how-it-works__item">
                                <span class="how-it-works__num">4.</span>
                                <span class="how-it-works__text">Attendance is logged automatically</span>
                            </li>
                        </ol>
                    </div>

                </div><!-- /qr-card -->

                <!-- RIGHT COLUMN -->
                <div class="right-col">

                    <!-- Current Status Card -->
                    <div class="info-card" role="region" aria-label="Current Status">
                        <h2 class="info-card__title">Current Status</h2>
                        <div class="status-rows">
                            <div class="status-row">
                                <span class="status-row__label">Display Mode</span>
                                <span class="status-row__value" id="statusDisplayMode">📱 QR Code</span>
                            </div>
                            <div class="status-row">
                                <span class="status-row__label">Attendance Type</span>
                                <span class="status-row__value" id="statusAttType">✅ Check In</span>
                            </div>
                            <div class="status-row">
                                <span class="status-row__label">Location</span>
                                <div class="status-row__value-with-icon">
                                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M10 2a6 6 0 00-6 6c0 4 6 10 6 10s6-6 6-10a6 6 0 00-6-6z" stroke="#003087" stroke-width="1.5"/>
                                        <circle cx="10" cy="8" r="2" fill="#003087"/>
                                    </svg>
                                    <span class="status-row__value-text">SDAO Office</span>
                                </div>
                            </div>
                            <div class="status-row">
                                <span class="status-row__label">Current Time</span>
                                <div class="status-row__value-with-icon">
                                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="10" cy="10" r="8" stroke="#003087" stroke-width="1.5"/>
                                        <path d="M10 6v4l3 2" stroke="#003087" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span class="status-row__value-text" id="currentTime">07:35 PM</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Logs Card -->
                    <div class="info-card" role="region" aria-label="Recent Logs">
                        <h2 class="info-card__title">Recent Logs</h2>
                        <div class="recent-logs-empty">
                            <div class="recent-logs-empty__icon-wrap" aria-hidden="true">
                                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="16" cy="16" r="13" stroke="#99A1AF" stroke-width="2"/>
                                    <path d="M10.5 16.5l4 4 7-8" stroke="#99A1AF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <p class="recent-logs-empty__text">No attendance logs yet</p>
                        </div>
                    </div>

                    <!-- Security Notice Card -->
                    <div class="security-card" role="note" aria-label="Security Notice">
                        <h2 class="security-card__title">🔒 Security Notice</h2>
                        <p class="security-card__body">
                            This code refreshes every <strong>5 seconds</strong> to prevent unauthorized access.
                        </p>
                        <p class="security-card__footnote">
                            Students must be physically present at the duty location to scan or view the code.
                        </p>
                    </div>

                </div><!-- /right-col -->

            </div><!-- /panel-grid -->

        </section>
    </main>
</div><!-- /app -->

<script>
(function () {
    'use strict';

    /* ---- Helpers ---- */
    function rand(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }

    function makeCodeId() {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        var suffix = '';
        for (var i = 0; i < 6; i++) suffix += chars[rand(0, chars.length - 1)];
        return 'SAMS-' + Date.now() + '-' + suffix;
    }

    /* ---- Hamburger / Sidebar ---- */
    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar   = document.getElementById('sidebar');
    var overlay   = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('sidebar--open');
        overlay.classList.remove('sidebar-overlay--hidden');
        overlay.classList.add('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('sidebar--open');
        overlay.classList.add('sidebar-overlay--hidden');
        overlay.classList.remove('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (hamburger) hamburger.addEventListener('click', function () {
        sidebar.classList.contains('sidebar--open') ? closeSidebar() : openSidebar();
    });
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('sidebar--open')) {
            closeSidebar();
            hamburger && hamburger.focus();
        }
    });

    /* ---- Mode Toggle (QR / OTP) ---- */
    var btnQr  = document.getElementById('btnQrMode');
    var btnOtp = document.getElementById('btnOtpMode');
    var statusMode = document.getElementById('statusDisplayMode');

    function setMode(mode) {
        if (mode === 'qr') {
            btnQr.classList.replace('btn-mode--inactive', 'btn-mode--active');
            btnQr.setAttribute('aria-pressed', 'true');
            btnOtp.classList.replace('btn-mode--active', 'btn-mode--inactive');
            btnOtp.setAttribute('aria-pressed', 'false');
            if (statusMode) statusMode.textContent = '📱 QR Code';
        } else {
            btnOtp.classList.replace('btn-mode--inactive', 'btn-mode--active');
            btnOtp.setAttribute('aria-pressed', 'true');
            btnQr.classList.replace('btn-mode--active', 'btn-mode--inactive');
            btnQr.setAttribute('aria-pressed', 'false');
            if (statusMode) statusMode.textContent = '🔢 OTP';
        }
    }

    if (btnQr)  btnQr.addEventListener('click',  function () { setMode('qr'); });
    if (btnOtp) btnOtp.addEventListener('click',  function () { setMode('otp'); });

    /* ---- Check In / Out Toggle ---- */
    var btnIn   = document.getElementById('btnCheckIn');
    var btnOut  = document.getElementById('btnCheckOut');
    var statusAtt  = document.getElementById('statusAttType');
    var qrLabel    = document.getElementById('qrModeLabel');

    function setCheckin(type) {
        if (type === 'in') {
            btnIn.classList.replace('btn-checkin--inactive', 'btn-checkin--active');
            btnIn.setAttribute('aria-pressed', 'true');
            btnOut.classList.replace('btn-checkin--active', 'btn-checkin--inactive');
            btnOut.setAttribute('aria-pressed', 'false');
            if (statusAtt) statusAtt.textContent = '✅ Check In';
            if (qrLabel)   qrLabel.textContent   = '✅ CHECK IN';
        } else {
            btnOut.classList.replace('btn-checkin--inactive', 'btn-checkin--active');
            btnOut.setAttribute('aria-pressed', 'true');
            btnIn.classList.replace('btn-checkin--active', 'btn-checkin--inactive');
            btnIn.setAttribute('aria-pressed', 'false');
            if (statusAtt) statusAtt.textContent = '🚪 Check Out';
            if (qrLabel)   qrLabel.textContent   = '🚪 CHECK OUT';
        }
    }

    if (btnIn)  btnIn.addEventListener('click',  function () { setCheckin('in'); });
    if (btnOut) btnOut.addEventListener('click',  function () { setCheckin('out'); });

    /* ---- Countdown & Auto-Refresh ---- */
    var countdownEl  = document.getElementById('countdownDisplay');
    var codeIdEl     = document.getElementById('codeIdDisplay');
    var btnRefresh   = document.getElementById('btnRefresh');
    var INTERVAL_SEC = 5;
    var remaining    = INTERVAL_SEC;
    var timer        = null;

    function refreshCode() {
        remaining = INTERVAL_SEC;
        if (codeIdEl) codeIdEl.textContent = makeCodeId();
        updateCountdown();
    }

    function updateCountdown() {
        if (countdownEl) {
            countdownEl.textContent = remaining + ' second' + (remaining !== 1 ? 's' : '');
        }
    }

    function tick() {
        remaining--;
        if (remaining <= 0) {
            refreshCode();
        } else {
            updateCountdown();
        }
    }

    timer = setInterval(tick, 1000);
    if (btnRefresh) btnRefresh.addEventListener('click', function () {
        clearInterval(timer);
        refreshCode();
        timer = setInterval(tick, 1000);
    });

    /* ---- Live Clock ---- */
    var clockEl = document.getElementById('currentTime');

    function updateClock() {
        var now = new Date();
        var h   = now.getHours();
        var m   = now.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        if (clockEl) clockEl.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    }

    updateClock();
    setInterval(updateClock, 1000);

})();
</script>

</body>
</html>