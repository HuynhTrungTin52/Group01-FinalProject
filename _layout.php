<?php
// _layout.php - Shared HTML head, sidebar, topbar. Included by every admin page.
// Expects: $pageTitle (string), $activeNav (string: 'dashboard'|'users'|'transactions')
if (!isset($activeNav)) $activeNav = 'dashboard';
if (!isset($pageTitle)) $pageTitle = 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> • Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root {
    --purple-900:#3b2070;
    --purple-700:#5a3cbd;
    --purple-500:#7c5cff;
    --purple-300:#c7b8ff;
    --purple-100:#efeaff;
    --purple-50:#f7f4ff;
    --ink:#1e1b2e;
    --muted:#6c6a7a;
  }
  html, body { background: var(--purple-50); }
  body { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; color: var(--ink); }
  a { text-decoration: none; }

  /* Sidebar */
  .sidebar {
    background: linear-gradient(180deg, var(--purple-700) 0%, var(--purple-900) 100%);
    color: #fff; min-height: 100vh; padding: 1.25rem 1rem;
    position: sticky; top: 0;
  }
  .sidebar .brand { font-weight: 700; letter-spacing:.3px; font-size:1.1rem; }
  .sidebar .nav-link {
    color: rgba(255,255,255,.85); border-radius: 12px;
    padding: .65rem .9rem; margin-bottom:.35rem; display:flex; align-items:center; gap:.65rem;
    transition: background .15s ease, transform .15s ease;
  }
  .sidebar .nav-link:hover { background: rgba(255,255,255,.08); color:#fff; }
  .sidebar .nav-link.active { background: rgba(255,255,255,.18); color:#fff; }
  .sidebar .nav-link i { font-size: 1.05rem; }

  /* Topbar */
  .topbar {
    background: transparent; padding: 1rem 1.5rem; display:flex; align-items:center; justify-content:space-between;
  }
  .topbar h1 { font-size: 1.4rem; margin:0; font-weight: 700; }
  .topbar .avatar {
    width:40px;height:40px;border-radius:50%;
    background: linear-gradient(135deg,var(--purple-500),var(--purple-700));
    color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;
  }

  /* Content */
  .content { padding: 0 1.5rem 2rem 1.5rem; }

  /* Cards */
  .stat-card {
    border:0; border-radius: 18px;
    background: linear-gradient(135deg,#e9defd 0%,#d9c9fb 100%);
    color: var(--purple-900);
    box-shadow: 0 8px 24px rgba(90,60,180,.08);
  }
  .stat-card .stat-value { font-size: 1.9rem; font-weight: 800; }
  .stat-card .stat-label { font-size: .9rem; color:#5a4b87; font-weight:500; }
  .stat-card .stat-icon { font-size:1.6rem; color: var(--purple-700); }

  /* Tab pills - for user management */
  .pill-tabs { display:flex; gap:12px; flex-wrap: wrap; }
  .pill-tab {
    flex:1 1 180px; min-width: 150px; cursor:pointer;
    border-radius: 18px; padding: 14px 18px;
    background: linear-gradient(135deg,#e9defd,#d4c0fa);
    color: var(--purple-900);
    border: 2px solid transparent;
    transition: transform .15s ease, box-shadow .15s ease;
    display:flex; flex-direction:column; gap:4px;
  }
  .pill-tab:hover { transform: translateY(-1px); }
  .pill-tab.active {
    background: linear-gradient(135deg,var(--purple-700),var(--purple-900));
    color:#fff; box-shadow: 0 10px 24px rgba(90,60,180,.25);
  }
  .pill-tab .count { font-size:1.6rem; font-weight:800; line-height:1; }
  .pill-tab .label { font-size:.95rem; font-weight:600; opacity:.95; }
  .pill-tab .dot { width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;}

  /* Table card */
  .panel-card {
    background: #fff; border: 0; border-radius: 18px;
    box-shadow: 0 8px 24px rgba(90,60,180,.06);
  }
  .search-input { border-radius: 999px; padding-left: 2.75rem; }
  .search-wrap { position: relative; }
  .search-wrap .bi-search { position:absolute; left:1rem; top:50%; transform:translateY(-50%); color: var(--muted); }

  table.admin-table thead th {
    background: #f0ebff; color: var(--purple-900); font-weight: 600;
    border-bottom: 0;
  }
  table.admin-table tbody td { vertical-align: middle; }

  .badge-status { border-radius: 999px; padding: .35em .7em; font-weight:600; }
  .bs-pending   { background:#fff4cc; color:#8a6d00; }
  .bs-verified  { background:#d4f5dd; color:#176634; }
  .bs-disabled  { background:#fcd7d7; color:#8a1f1f; }
  .bs-updating  { background:#ffe0c2; color:#8a4a00; }
  .bs-locked    { background:#d9d4ff; color:#3b2070; }
  .bs-completed { background:#d4f5dd; color:#176634; }
  .bs-cancelled { background:#fcd7d7; color:#8a1f1f; }

  /* Buttons */
  .btn-approve { background:#16a34a;border-color:#16a34a;color:#fff; }
  .btn-approve:hover { background:#138a3f;border-color:#138a3f;color:#fff; }
  .btn-reject  { background:#dc2626;border-color:#dc2626;color:#fff; }
  .btn-reject:hover { background:#b91c1c;border-color:#b91c1c;color:#fff; }
  .btn-docs    { background:#eab308;border-color:#eab308;color:#1e1b2e; }
  .btn-docs:hover { background:#ca9a06;border-color:#ca9a06;color:#1e1b2e; }
  .btn-unlock  { background:#6f42c1;border-color:#6f42c1;color:#fff; }
  .btn-unlock:hover { background:#5a35a3;border-color:#5a35a3;color:#fff; }

  .action-explain {
    background:#f1ecff; border-radius:12px; padding:14px 16px; color: var(--purple-900);
  }

  /* ID Image previews */
  .id-image {
    background:#e8e5f2; border-radius:12px; height:180px; width:100%;
    display:flex;align-items:center;justify-content:center; overflow:hidden; color:#8a86a3;
  }
  .id-image img { width:100%; height:100%; object-fit:cover; }

  /* Alerts banner */
  .ready-banner {
    background:#fff3d6; border-radius:12px; padding:14px 18px; color:#7a5b00; font-size:.92rem;
  }

  /* Responsiveness */
  @media (max-width: 991.98px) {
    .sidebar { min-height: auto; position: static; }
  }
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row g-0">
    <!-- Sidebar -->
    <aside class="col-12 col-lg-2 sidebar">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-shield-check fs-3"></i>
        <div class="brand">Admin Panel</div>
      </div>
      <nav class="nav flex-column" data-testid="sidebar-nav">
        <a href="admin_dashboard.php" class="nav-link <?= $activeNav==='dashboard'?'active':'' ?>" data-testid="nav-dashboard">
          <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        <a href="admin_dashboard.php#users" class="nav-link <?= $activeNav==='users'?'active':'' ?>" data-testid="nav-users">
          <i class="bi bi-people-fill"></i> User Management
        </a>
        <a href="transaction_approval.php" class="nav-link <?= $activeNav==='transactions'?'active':'' ?>" data-testid="nav-transactions">
          <i class="bi bi-cash-coin"></i> Transaction Approval
        </a>
        <a href="logout.php" class="nav-link mt-4" data-testid="nav-logout">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="col-12 col-lg-10">
      <div class="topbar">
        <div>
          <h1><?= htmlspecialchars($pageTitle) ?></h1>
          <div class="text-muted small">Manage users, KYC and transactions</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="text-muted small d-none d-md-inline"><?= htmlspecialchars($_SESSION['admin_email'] ?? 'admin') ?></span>
          <div class="avatar" data-testid="admin-avatar">A</div>
        </div>
      </div>
      <div class="content">