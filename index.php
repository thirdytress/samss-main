<?php
// index.php - NU SAMS Landing Page
// Student Assistant Management System - National University Lipa
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SAMS – Student Assistant Management System | NU Lipa</title>
  <meta name="description" content="The official Student Assistant Management System for NU Lipa's Student Development and Activities Office (SDAO)." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-primary:         #003087;
      --color-primary-end:     #004aab;
      --color-gold:            #ffb81c;
      --color-gold-end:        #ffa500;
      --color-dark:            #101828;
      --color-body:            #364153;
      --color-muted:           #4a5565;
      --color-muted-light:     #99a1af;
      --color-blue-light:      #dbeafe;
      --color-white:           #ffffff;
      --color-border:          #e5e7eb;
      --color-card-border:     #f3f4f6;
      --color-footer-bg:       #101828;
      --color-footer-divider:  #1e2939;
      --color-hero-bg-start:   #eff6ff;
      --color-hero-bg-mid:     #ffffff;
      --color-hero-bg-end:     #fffbeb;
      --color-steps-bg-start:  #dbeafe;
      --color-steps-bg-end:    #e0e7ff;

      --grad-primary:          linear-gradient(90deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-primary-135:      linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-primary-163:      linear-gradient(163deg, var(--color-primary) 0%, var(--color-primary-end) 100%);
      --grad-gold:             linear-gradient(135deg, var(--color-gold) 0%, var(--color-gold-end) 100%);
      --grad-hero:             linear-gradient(119deg, var(--color-hero-bg-start) 0%, var(--color-hero-bg-mid) 50%, var(--color-hero-bg-end) 100%);
      --grad-steps:            linear-gradient(161deg, var(--color-steps-bg-start) 0%, var(--color-steps-bg-end) 100%);

      --shadow-card:           0 10px 15px 0 rgba(0,0,0,.10), 0 4px 6px 0 rgba(0,0,0,.10);
      --shadow-hero-card:      0 25px 50px 0 rgba(0,0,0,.25);
      --shadow-nav:            0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.10);

      --radius-sm:    10px;
      --radius-md:    14px;
      --radius-lg:    16px;
      --radius-xl:    24px;
      --radius-pill:  9999px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   20px;
      --font-2xl:  24px;
      --font-3xl:  30px;
      --font-4xl:  36px;
      --font-5xl:  48px;
      --font-6xl:  60px;

      --space-1:   4px;
      --space-2:   8px;
      --space-3:   12px;
      --space-4:   16px;
      --space-5:   20px;
      --space-6:   24px;
      --space-8:   32px;
      --space-10:  40px;
      --space-12:  48px;
      --space-14:  56px;
      --space-16:  64px;
      --space-20:  80px;

      --nav-height: 80px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Inter', sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--grad-hero);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; max-width: 100%; }
    ul { list-style: none; }

    /* =============================================
       LAYOUT HELPERS
    ============================================= */
    .container {
      width: 100%;
      max-width: 1280px;
      margin-inline: auto;
      padding-inline: var(--space-8);
    }

    /* =============================================
       NAVIGATION
    ============================================= */
    .nav {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      z-index: 100;
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--color-border);
      box-shadow: var(--shadow-nav);
    }
    .nav__inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: var(--nav-height);
    }
    .nav__brand {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      text-decoration: none;
    }
    .nav__logo {
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      background: var(--grad-primary-135);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-white);
      flex-shrink: 0;
    }
    .nav__brand-text {}
    .nav__brand-name {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.4;
    }
    .nav__brand-sub {
      font-size: var(--font-xs);
      font-weight: 400;
      color: var(--color-muted);
      white-space: nowrap;
    }
    .nav__actions {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }
    .nav__link--login {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-primary);
      padding: var(--space-2) var(--space-6);
      border-radius: var(--radius-md);
      transition: background .2s;
    }
    .nav__link--login:hover { background: rgba(0,48,135,.06); }
    .nav__link--cta {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
      padding: var(--space-2) var(--space-6);
      border-radius: var(--radius-md);
      background: var(--grad-primary);
      transition: opacity .2s;
    }
    .nav__link--cta:hover { opacity: .88; }

    /* Hamburger */
    .nav__hamburger {
      display: none;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 5px;
      width: 40px;
      height: 40px;
      background: none;
      border: none;
      cursor: pointer;
      padding: 4px;
      border-radius: var(--radius-sm);
      transition: background .2s;
    }
    .nav__hamburger:hover { background: rgba(0,48,135,.07); }
    .nav__hamburger-bar {
      display: block;
      width: 22px;
      height: 2px;
      background: var(--color-dark);
      border-radius: 2px;
      transition: transform .3s, opacity .3s;
    }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(2) { opacity: 0; }
    .nav__hamburger[aria-expanded="true"] .nav__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    /* Mobile menu */
    .nav__mobile-menu {
      display: none;
      flex-direction: column;
      gap: var(--space-2);
      padding: var(--space-4) 0 var(--space-4);
      border-top: 1px solid var(--color-border);
    }
    .nav__mobile-menu.is-open { display: flex; }
    .nav__mobile-menu a {
      font-size: var(--font-base);
      font-weight: 600;
      color: var(--color-dark);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-sm);
      transition: background .2s;
    }
    .nav__mobile-menu a:hover { background: rgba(0,48,135,.06); }
    .nav__mobile-menu .nav__mobile-cta {
      background: var(--grad-primary);
      color: var(--color-white);
      text-align: center;
    }

    /* =============================================
       HERO SECTION
    ============================================= */
    .hero {
      padding-top: calc(var(--nav-height) + var(--space-16));
      padding-bottom: var(--space-20);
      background: var(--grad-hero);
    }
    .hero__inner {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-8);
      align-items: center;
    }
    .hero__badge {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      border: 2px solid var(--color-gold);
      background: rgba(255,184,28,.18);
      border-radius: var(--radius-pill);
      padding: var(--space-2) var(--space-4);
      margin-bottom: var(--space-4);
    }
    .hero__badge-icon {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }
    .hero__badge-text {
      font-size: var(--font-base);
      font-weight: 900;
      color: var(--color-primary);
      white-space: nowrap;
    }
    .hero__heading {
      font-size: var(--font-6xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.25;
      margin-bottom: var(--space-4);
    }
    .hero__heading-accent {
      background: var(--grad-primary);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .hero__subheading {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-body);
      line-height: 1.625;
      margin-bottom: var(--space-4);
    }
    .hero__subheading strong {
      font-weight: 900;
      color: var(--color-primary);
    }
    .hero__desc {
      font-size: 18px;
      font-weight: 500;
      color: var(--color-muted);
      line-height: 1.625;
      margin-bottom: var(--space-12);
      max-width: 574px;
    }
    .hero__actions {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      flex-wrap: wrap;
    }
    .hero__btn--apply {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      background: var(--grad-primary);
      color: var(--color-white);
      font-size: var(--font-lg);
      font-weight: 900;
      padding: 0 var(--space-8);
      height: 60px;
      border-radius: var(--radius-lg);
      transition: opacity .2s;
      white-space: nowrap;
    }
    .hero__btn--apply:hover { opacity: .88; }
    .hero__btn--apply img { width: 24px; height: 24px; flex-shrink: 0; }
    .hero__btn--login {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--color-primary);
      color: var(--color-primary);
      font-size: var(--font-lg);
      font-weight: 700;
      padding: 0 var(--space-8);
      height: 64px;
      border-radius: var(--radius-lg);
      transition: background .2s;
      white-space: nowrap;
    }
    .hero__btn--login:hover { background: rgba(0,48,135,.06); }

    /* Hero card */
    .hero__card {
      background: var(--grad-primary-135);
      border: 4px solid var(--color-gold);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-hero-card);
      padding: var(--space-12) var(--space-12) var(--space-1);
      display: flex;
      flex-direction: column;
    }
    .hero__card-inner {
      background: rgba(255,255,255,.12);
      border-radius: var(--radius-lg);
      padding: var(--space-8);
      flex: 1;
    }
    .hero__card-icon {
      width: 96px;
      height: 96px;
      margin-bottom: var(--space-5);
    }
    .hero__card-title {
      font-size: var(--font-3xl);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-3);
    }
    .hero__card-desc {
      font-size: var(--font-lg);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.55;
      margin-bottom: var(--space-6);
      max-width: 407px;
    }
    .hero__card-perks {
      display: flex;
      flex-direction: column;
      gap: var(--space-3);
    }
    .hero__card-perk {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      background: rgba(255,255,255,.22);
      border-radius: var(--radius-sm);
      padding: 0 var(--space-3);
      height: 48px;
    }
    .hero__card-perk img { width: 24px; height: 24px; flex-shrink: 0; }
    .hero__card-perk-text {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-white);
    }

    /* =============================================
       FEATURES SECTION
    ============================================= */
    .features {
      padding: var(--space-20) 0;
      background: var(--grad-hero);
    }
    .section__header {
      text-align: center;
      margin-bottom: var(--space-16);
    }
    .section__title {
      font-size: var(--font-4xl);
      font-weight: 900;
      color: var(--color-dark);
      line-height: 1.1;
      margin-bottom: var(--space-4);
    }
    .section__subtitle {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-muted);
    }
    .features__grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-8);
    }
    .feature-card {
      background: var(--color-white);
      border: 2px solid var(--color-card-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: var(--space-8);
    }
    .feature-card__icon-wrap {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-lg);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: var(--space-8);
    }
    .feature-card__icon-wrap--blue { background: var(--grad-primary-135); }
    .feature-card__icon-wrap--gold { background: var(--grad-gold); }
    .feature-card__icon-wrap img { width: 32px; height: 32px; }
    .feature-card__title {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: var(--space-4);
    }
    .feature-card__desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted);
      line-height: 1.5;
    }

    /* =============================================
       STEPS SECTION
    ============================================= */
    .steps {
      padding: var(--space-20) 0;
      background: var(--grad-steps);
    }
    .steps__grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: var(--space-8);
    }
    .step {
      text-align: center;
    }
    .step__number {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: var(--color-primary);
      border: 4px solid var(--color-gold);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto var(--space-6);
      font-size: var(--font-3xl);
      font-weight: 900;
      color: var(--color-white);
    }
    .step__title {
      font-size: var(--font-xl);
      font-weight: 900;
      color: var(--color-dark);
      margin-bottom: var(--space-4);
    }
    .step__desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-body);
      line-height: 1.5;
      max-width: 250px;
      margin-inline: auto;
    }

    /* =============================================
       CTA SECTION
    ============================================= */
    .cta {
      padding: var(--space-20) 0;
      background: var(--grad-hero);
    }
    .cta__inner {
      background: var(--grad-primary-163);
      border: 4px solid var(--color-gold);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-hero-card);
      padding: var(--space-16) var(--space-16);
      text-align: center;
    }
    .cta__title {
      font-size: var(--font-5xl);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-4);
    }
    .cta__desc {
      font-size: var(--font-xl);
      font-weight: 500;
      color: var(--color-blue-light);
      line-height: 1.5;
      margin-bottom: var(--space-12);
      max-width: 560px;
      margin-inline: auto;
      margin-bottom: var(--space-12);
    }
    .cta__btn {
      display: inline-flex;
      align-items: center;
      gap: var(--space-3);
      background: var(--color-gold);
      color: var(--color-primary);
      font-size: var(--font-xl);
      font-weight: 900;
      padding: 0 var(--space-12);
      height: 68px;
      border-radius: var(--radius-lg);
      transition: opacity .2s;
    }
    .cta__btn:hover { opacity: .88; }
    .cta__btn img { width: 28px; height: 28px; }

    /* =============================================
       FOOTER
    ============================================= */
    .footer {
      background: var(--color-footer-bg);
      padding: var(--space-16) 0 0;
    }
    .footer__grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: var(--space-8);
      padding-bottom: var(--space-8);
    }
    .footer__brand {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }
    .footer__logo {
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      background: var(--color-gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-2xl);
      font-weight: 900;
      color: var(--color-primary);
      flex-shrink: 0;
    }
    .footer__brand-name {
      font-size: var(--font-lg);
      font-weight: 900;
      color: var(--color-white);
    }
    .footer__brand-sub {
      font-size: var(--font-sm);
      font-weight: 400;
      color: var(--color-muted-light);
    }
    .footer__desc {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      max-width: 349px;
    }
    .footer__col-title {
      font-size: var(--font-lg);
      font-weight: 900;
      color: var(--color-white);
      margin-bottom: var(--space-4);
    }
    .footer__links {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .footer__links a {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      transition: color .2s;
    }
    .footer__links a:hover { color: var(--color-white); }
    .footer__contact p {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
      line-height: 1.5;
      margin-bottom: var(--space-2);
    }
    .footer__bottom {
      border-top: 1px solid var(--color-footer-divider);
      padding: var(--space-8) 0;
      text-align: center;
    }
    .footer__copy {
      font-size: var(--font-base);
      font-weight: 500;
      color: var(--color-muted-light);
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .nav__actions { display: none; }
      .nav__hamburger { display: flex; }

      .hero__inner {
        grid-template-columns: 1fr;
        gap: var(--space-12);
      }
      .hero__heading { font-size: 44px; }
      .hero__card { max-width: 540px; margin-inline: auto; width: 100%; }

      .features__grid { grid-template-columns: repeat(2, 1fr); }
      .steps__grid    { grid-template-columns: repeat(2, 1fr); }

      .footer__grid { grid-template-columns: 1fr 1fr; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      :root { --nav-height: 64px; }

      .hero { padding-top: calc(var(--nav-height) + var(--space-10)); }
      .hero__heading { font-size: 32px; }
      .hero__subheading { font-size: var(--font-base); }
      .hero__desc { font-size: var(--font-base); }
      .hero__btn--apply,
      .hero__btn--login { font-size: var(--font-base); height: 52px; padding: 0 var(--space-6); }
      .hero__actions { flex-direction: column; align-items: flex-start; }

      .section__title { font-size: 26px; }
      .section__subtitle { font-size: var(--font-base); }

      .features__grid { grid-template-columns: 1fr; }
      .steps__grid    { grid-template-columns: 1fr; }

      .cta__title { font-size: 28px; }
      .cta__desc  { font-size: var(--font-base); }
      .cta__btn   { font-size: var(--font-base); height: 56px; padding: 0 var(--space-8); }

      .footer__grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- ======================================================
       NAVIGATION
  ====================================================== -->
  <header class="nav" role="banner">
    <div class="container">
      <nav class="nav__inner" aria-label="Main navigation">
        <!-- Brand -->
        <a class="nav__brand" href="index.php" aria-label="SAMS Home">
          <div class="nav__logo" aria-hidden="true">NU</div>
          <div class="nav__brand-text">
            <div class="nav__brand-name">SAMS</div>
            <div class="nav__brand-sub">Student Assistant Management System</div>
          </div>
        </a>

        <!-- Desktop actions -->
        <div class="nav__actions">
          <a class="nav__link--login" href="login.php">Login</a>
          <a class="nav__link--cta" href="register.php">Get Started</a>
        </div>

        <!-- Hamburger (tablet / mobile) -->
        <button
          class="nav__hamburger"
          aria-expanded="false"
          aria-controls="mobile-menu"
          aria-label="Toggle navigation menu"
        >
          <span class="nav__hamburger-bar"></span>
          <span class="nav__hamburger-bar"></span>
          <span class="nav__hamburger-bar"></span>
        </button>
      </nav>

      <!-- Mobile menu -->
      <div id="mobile-menu" class="nav__mobile-menu" role="menu">
        <a href="#features" role="menuitem">Features</a>
        <a href="#how-it-works" role="menuitem">How It Works</a>
        <a href="login.php" role="menuitem">Login</a>
        <a href="register.php" class="nav__mobile-cta" role="menuitem">Get Started</a>
      </div>
    </div>
  </header>

  <main>

    <!-- ====================================================
         HERO SECTION
    ==================================================== -->
    <section class="hero" aria-labelledby="hero-heading">
      <div class="container">
        <div class="hero__inner">

          <!-- Left: text -->
          <div class="hero__content">
            <!-- Badge -->
            <div class="hero__badge">
              <img
                class="hero__badge-icon"
                src="https://www.figma.com/api/mcp/asset/aeea810e-4743-4cba-abb9-e2d0b156ec1a"
                alt=""
                aria-hidden="true"
              />
              <span class="hero__badge-text">National University – Lipa Campus</span>
            </div>

            <!-- Heading -->
            <h1 class="hero__heading" id="hero-heading">
              Welcome to <span class="hero__heading-accent">SAMS</span>
            </h1>

            <!-- Subheading -->
            <p class="hero__subheading">
              The official <strong>Student Assistant Management System</strong> for NU Lipa's Student Development and Activities Office (SDAO).
            </p>

            <!-- Description -->
            <p class="hero__desc">
              An integrated mobile and web-based platform that automates application processing, scheduling with algorithm-guided assignment, and secure attendance monitoring using QR + PIN/OTP validation - all with real-time administrative dashboard for efficient management.
            </p>

            <!-- CTAs -->
            <div class="hero__actions">
              <a class="hero__btn--apply" href="register.php">
                Apply as Student Assistant
                <img
                  src="https://www.figma.com/api/mcp/asset/e6d2026f-610d-4753-909d-1feb9c6d5d78"
                  alt=""
                  aria-hidden="true"
                />
              </a>
              <a class="hero__btn--login" href="login.php">Login</a>
            </div>
          </div>

          <!-- Right: card -->
          <div class="hero__card" aria-label="Join SDAO Today">
            <div class="hero__card-inner">
              <img
                class="hero__card-icon"
                src="https://www.figma.com/api/mcp/asset/a786d8f7-7b8a-4e5f-b06a-4cefaf2f8196"
                alt="SDAO graduation cap icon"
              />
              <h2 class="hero__card-title">Join SDAO Today!</h2>
              <p class="hero__card-desc">Be part of the team that shapes student life at NU Lipa</p>
              <ul class="hero__card-perks">
                <li class="hero__card-perk">
                  <img src="https://www.figma.com/api/mcp/asset/a5a2bd59-40a7-4388-84d4-ffda6653728c" alt="" aria-hidden="true" />
                  <span class="hero__card-perk-text">Flexible Schedule</span>
                </li>
                <li class="hero__card-perk">
                  <img src="https://www.figma.com/api/mcp/asset/a5a2bd59-40a7-4388-84d4-ffda6653728c" alt="" aria-hidden="true" />
                  <span class="hero__card-perk-text">Gain Leadership Experience</span>
                </li>
                <li class="hero__card-perk">
                  <img src="https://www.figma.com/api/mcp/asset/a5a2bd59-40a7-4388-84d4-ffda6653728c" alt="" aria-hidden="true" />
                  <span class="hero__card-perk-text">Financial Support</span>
                </li>
              </ul>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- ====================================================
         FEATURES SECTION
    ==================================================== -->
    <section class="features" id="features" aria-labelledby="features-heading">
      <div class="container">
        <header class="section__header">
          <h2 class="section__title" id="features-heading">Powerful Features for Students &amp; Admin</h2>
          <p class="section__subtitle">Everything you need to manage student assistant duties efficiently</p>
        </header>

        <div class="features__grid">
          <!-- Card 1 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <img src="https://www.figma.com/api/mcp/asset/09fc8456-4ef8-43eb-92b4-132059be29a0" alt="" aria-hidden="true" />
            </div>
            <h3 class="feature-card__title">Easy Registration</h3>
            <p class="feature-card__desc">4-step application process with auto-filtering questions and instant submission</p>
          </article>

          <!-- Card 2 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <img src="https://www.figma.com/api/mcp/asset/6efd79e8-fe80-4bcc-884c-1772020825e1" alt="" aria-hidden="true" />
            </div>
            <h3 class="feature-card__title">Smart Scheduling</h3>
            <p class="feature-card__desc">View your duty schedule in calendar or list format with real-time updates</p>
          </article>

          <!-- Card 3 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--blue">
              <img src="https://www.figma.com/api/mcp/asset/8f8ba024-d133-483d-8f9a-0ca75514a603" alt="" aria-hidden="true" />
            </div>
            <h3 class="feature-card__title">QR Attendance</h3>
            <p class="feature-card__desc">Secure check-in/out with QR code scanning and PIN verification</p>
          </article>

          <!-- Card 4 -->
          <article class="feature-card">
            <div class="feature-card__icon-wrap feature-card__icon-wrap--gold">
              <img src="https://www.figma.com/api/mcp/asset/b47f8ccf-5461-4bbc-8dcd-d68c86a9cda3" alt="" aria-hidden="true" />
            </div>
            <h3 class="feature-card__title">Real-time Updates</h3>
            <p class="feature-card__desc">Get instant notifications about schedule changes and announcements</p>
          </article>
        </div>
      </div>
    </section>

    <!-- ====================================================
         HOW IT WORKS SECTION
    ==================================================== -->
    <section class="steps" id="how-it-works" aria-labelledby="steps-heading">
      <div class="container">
        <header class="section__header">
          <h2 class="section__title" id="steps-heading">How SAMS Works</h2>
          <p class="section__subtitle">Get started in 4 simple steps</p>
        </header>

        <div class="steps__grid">
          <div class="step">
            <div class="step__number" aria-hidden="true">1</div>
            <h3 class="step__title">Register</h3>
            <p class="step__desc">Complete the 4-step registration process with your information and documents</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">2</div>
            <h3 class="step__title">Wait for Approval</h3>
            <p class="step__desc">Miss Zai reviews your application and approves qualified students</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">3</div>
            <h3 class="step__title">Get Scheduled</h3>
            <p class="step__desc">Receive your duty schedule and view it in your dashboard</p>
          </div>
          <div class="step">
            <div class="step__number" aria-hidden="true">4</div>
            <h3 class="step__title">Start Working</h3>
            <p class="step__desc">Log attendance with QR code and complete your assigned duties</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ====================================================
         CTA SECTION
    ==================================================== -->
    <section class="cta" aria-labelledby="cta-heading">
      <div class="container">
        <div class="cta__inner">
          <h2 class="cta__title" id="cta-heading">Ready to Join SDAO?</h2>
          <p class="cta__desc">Apply now and become part of the team that creates amazing experiences for NU Lipa students!</p>
          <a class="cta__btn" href="register.php">
            <img src="https://www.figma.com/api/mcp/asset/a8f5d1fb-aa39-48cc-afbe-2280c6352853" alt="" aria-hidden="true" />
            Apply Now
            <img src="https://www.figma.com/api/mcp/asset/c0b99c95-ab8f-40c1-8cad-c515c5bdbc28" alt="" aria-hidden="true" />
          </a>
        </div>
      </div>
    </section>

  </main>

  <!-- ======================================================
       FOOTER
  ====================================================== -->
  <footer class="footer" role="contentinfo">
    <div class="container">
      <div class="footer__grid">

        <!-- Brand column -->
        <div>
          <div class="footer__brand">
            <div class="footer__logo" aria-hidden="true">NU</div>
            <div>
              <div class="footer__brand-name">SAMS</div>
              <div class="footer__brand-sub">Student Assistant Management</div>
            </div>
          </div>
          <p class="footer__desc">Official system of the Student Development and Activities Office at NU Lipa</p>
        </div>

        <!-- Quick links column -->
        <div>
          <h3 class="footer__col-title">Quick Links</h3>
          <nav class="footer__links" aria-label="Footer navigation">
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
            <a href="#about">About SDAO</a>
            <a href="#contact">Contact Us</a>
          </nav>
        </div>

        <!-- Contact column -->
        <div>
          <h3 class="footer__col-title">Contact SDAO</h3>
          <div class="footer__contact">
            <p>Student Development and Activities Office</p>
            <p>National University – Lipa</p>
            <p>Tambo, Lipa City, Batangas</p>
          </div>
        </div>

      </div>

      <!-- Bottom bar -->
      <div class="footer__bottom">
        <p class="footer__copy">
          &copy; <?php echo date('Y'); ?> National University – Lipa Campus. All rights reserved.
        </p>
      </div>
    </div>
  </footer>

  <script>
    (function () {
      'use strict';

      /* ---- Hamburger toggle ---- */
      var btn  = document.querySelector('.nav__hamburger');
      var menu = document.getElementById('mobile-menu');

      if (btn && menu) {
        btn.addEventListener('click', function () {
          var isOpen = menu.classList.toggle('is-open');
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        /* Close menu on link click */
        menu.querySelectorAll('a').forEach(function (link) {
          link.addEventListener('click', function () {
            menu.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
          });
        });

        /* Close menu on outside click */
        document.addEventListener('click', function (e) {
          if (!btn.contains(e.target) && !menu.contains(e.target)) {
            menu.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
          }
        });
      }
    })();
  </script>

</body>
</html>