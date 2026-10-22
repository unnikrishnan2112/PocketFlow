<?php
require_once __DIR__ . '/auth.php';
pfRequireUser();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f6f7fb">
  <meta name="description" content="PocketFlow — your mobile-first, source-wise money manager.">
  <meta name="csrf-token" content="<?= pfEscape(pfCsrf()) ?>">
  <title>PocketFlow — Money Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <div class="brand-lockup">
      <span class="brand-logo"><i class="bi bi-layers-fill"></i></span>
      <div><strong>Pocket<span>Flow</span></strong><small>PERSONAL MONEY MANAGER</small></div>
    </div>
    <div class="topbar-actions">
      <form method="post" action="account.php" class="logout-form">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf" value="<?= pfEscape(pfCsrf()) ?>">
        <button class="logout-button" type="submit" aria-label="Sign out" title="Sign out"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
      </form>
      <button class="round-icon" type="button" id="top-add-source" aria-label="Create income source"><i class="bi bi-plus-lg"></i></button>
    </div>
  </header>

  <main id="app-main" aria-live="polite">
    <!-- HOME -->
    <section class="view active" id="view-home">
      <div class="home-greeting"><div><span class="eyebrow">YOUR FINANCIAL SPACE</span><h1>Good <span id="greeting-time">morning</span>,<br><span class="greeting-user"><?= pfEscape(explode(' ', trim((string)$_SESSION['user_name']))[0] ?: 'there') ?></span> <span class="wave">✳</span></h1></div><span class="greeting-circle"><i class="bi bi-grid-1x2-fill"></i></span></div>
      <div class="balance-card">
        <div class="balance-top"><span><i class="bi bi-wallet2 me-2"></i>Total available balance</span><button type="button" class="eye-button" id="toggle-balance" aria-label="Hide or show balance"><i class="bi bi-eye"></i></button></div>
        <div class="balance-amount" id="balance-amount">₹0.00</div>
        <div class="balance-tag"><i class="bi bi-shield-check"></i> Across all income sources</div>
        <div class="balance-stats">
          <div><span class="stat-icon income"><i class="bi bi-arrow-down-left"></i></span><div><small>Income this month</small><strong id="home-income">₹0</strong></div></div>
          <span class="balance-divider"></span>
          <div><span class="stat-icon expense"><i class="bi bi-arrow-up-right"></i></span><div><small>Spent this month</small><strong id="home-expense">₹0</strong></div></div>
        </div>
      </div>

      <div class="section-head"><div><span class="eyebrow">SEPARATE POCKETS</span><h2>My income sources</h2></div><button class="text-action" data-view="sources">See all <i class="bi bi-arrow-up-right"></i></button></div>
      <div class="source-scroll" id="home-sources"></div>

      <div class="section-head"><div><span class="eyebrow">ONE TAP AWAY</span><h2>Quick actions</h2></div></div>
      <div class="quick-actions">
        <button class="quick-action" data-sheet="income"><span class="quick-icon quick-income"><i class="bi bi-arrow-down-left"></i></span><strong>Add income</strong><small>Money received</small></button>
        <button class="quick-action" data-sheet="expense"><span class="quick-icon quick-expense"><i class="bi bi-arrow-up-right"></i></span><strong>Add expense</strong><small>Money spent</small></button>
        <button class="quick-action" data-sheet="transfer"><span class="quick-icon quick-transfer"><i class="bi bi-arrow-left-right"></i></span><strong>Transfer</strong><small>Move between</small></button>
      </div>

      <div class="section-head section-recent"><div><span class="eyebrow">THE LATEST</span><h2>Recent activity</h2></div><button class="text-action" data-view="activity">View all <i class="bi bi-arrow-up-right"></i></button></div>
      <div class="white-panel transaction-list" id="home-recent"></div>
      <div class="section-head"><div><span class="eyebrow">THIS MONTH</span><h2>Spending snapshot</h2></div><button class="text-action" data-view="insights">Insights <i class="bi bi-arrow-up-right"></i></button></div>
      <div class="white-panel mini-insights" id="home-categories"></div>
    </section>

    <!-- SOURCES -->
    <section class="view" id="view-sources">
      <div class="page-heading"><span class="eyebrow">WHERE YOUR MONEY LIVES</span><h1>Income sources</h1><p>Track each pocket independently. Spending from one source never affects another.</p></div>
      <div class="summary-line"><span>Combined balance</span><strong id="sources-balance">₹0</strong></div>
      <div class="source-actions"><button class="primary-small" data-sheet="source"><i class="bi bi-plus-lg"></i> New source</button><button class="secondary-small" data-sheet="transfer"><i class="bi bi-arrow-left-right"></i> Transfer money</button></div>
      <div class="source-full-list" id="source-full-list"></div>
      <div class="info-note"><i class="bi bi-info-circle"></i><span>Every transaction belongs to a source. An expense can only use that source's available balance.</span></div>
    </section>

    <!-- ACTIVITY -->
    <section class="view" id="view-activity">
      <div class="page-heading"><span class="eyebrow">YOUR MONEY TRAIL</span><h1>Transactions</h1><p>Find every income, expense and transfer.</p></div>
      <div class="activity-search"><i class="bi bi-search"></i><input id="filter-search" type="search" placeholder="Search transactions..." autocomplete="off" aria-label="Search transactions"></div>
      <div class="filter-toolbar"><select id="filter-source" class="filter-select" aria-label="Filter by source"><option value="">All sources</option></select><input class="filter-month" type="month" id="filter-month" aria-label="Filter by month" title="Filter by month"></div>
      <div class="filter-chips" id="filter-kinds"><button class="active" data-kind="all">All</button><button data-kind="income">Income</button><button data-kind="expense">Expenses</button><button data-kind="transfer">Transfers</button></div>
      <div class="activity-list white-panel" id="activity-list"></div>
      <div class="history-footer"><span id="activity-count"></span><button type="button" id="load-more" class="secondary-small" hidden>Load more <i class="bi bi-arrow-down"></i></button></div>
    </section>

    <!-- INSIGHTS -->
    <section class="view" id="view-insights">
      <div class="page-heading"><span class="eyebrow">BETTER MONEY DECISIONS</span><h1>Insights</h1><p>See exactly where your money comes from and where it goes.</p></div>
      <div class="insight-totals"><div><span class="insight-dot green"></span><small>Total income</small><strong id="insight-income">₹0</strong></div><div><span class="insight-dot coral"></span><small>Total expenses</small><strong id="insight-expense">₹0</strong></div></div>
      <div class="section-head"><div><span class="eyebrow">LAST 6 MONTHS</span><h2>Cash flow</h2></div></div>
      <div class="white-panel chart-card"><div class="chart-legend"><span><b class="green"></b> Income</span><span><b class="purple"></b> Expenses</span></div><div class="chart-holder"><canvas id="flow-chart" role="img" aria-label="Income and expenses over the last six months"></canvas></div></div>
      <div class="section-head"><div><span class="eyebrow">THIS MONTH</span><h2>Spending by category</h2></div></div>
      <div class="white-panel category-panel" id="insights-categories"></div>
      <div class="section-head"><div><span class="eyebrow">BALANCE SPLIT</span><h2>Source distribution</h2></div></div>
      <div class="white-panel distribution-panel" id="insights-distribution"></div>
    </section>
  </main>

  <nav class="bottom-nav" aria-label="Main navigation">
    <button type="button" class="nav-item-app active" data-view="home"><i class="bi bi-house-door-fill"></i><span>Home</span></button>
    <button type="button" class="nav-item-app" data-view="sources"><i class="bi bi-wallet2"></i><span>Sources</span></button>
    <button type="button" class="nav-add" data-sheet="action" aria-label="Add new transaction"><i class="bi bi-plus-lg"></i></button>
    <button type="button" class="nav-item-app" data-view="activity"><i class="bi bi-clock-history"></i><span>Activity</span></button>
    <button type="button" class="nav-item-app" data-view="insights"><i class="bi bi-pie-chart"></i><span>Insights</span></button>
  </nav>
</div>

<div class="sheet-overlay" id="sheet-overlay" aria-hidden="true">
  <div class="sheet-backdrop" data-close-sheet></div>
  <div class="action-sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title">
    <span class="sheet-handle"></span>
    <div class="sheet-head"><div><small id="sheet-subtitle">MANAGE YOUR MONEY</small><h2 id="sheet-title">New transaction</h2></div><button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div id="sheet-content" class="sheet-content"></div>
  </div>
</div>
<div id="toast" class="app-toast" role="status" aria-live="polite"></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
