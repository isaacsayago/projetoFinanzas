<?php
session_start();
if (!isset($_SESSION['id'])) {
    header('Location: index.php');
    exit();
}

require_once 'conexao_pdo.php';
$current_user_id = $_SESSION['id'];
$current_user_name = $_SESSION['nome'] ?? 'Usuário';
$is_admin = isset($_SESSION['nivel']) && $_SESSION['nivel'] === 'adm';

date_default_timezone_set('America/Sao_Paulo');

// ---------- Helpers ----------
function money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function formataDataBr($data) {
    if (!$data) return '';
    if (in_array($data, ['0000-00-00', '0000-00-00 00:00:00'], true)) return '';
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : '';
}
function dataParaBanco($val) {
    if (!$val) return null;
    $d = DateTime::createFromFormat('Y-m-d', $val);
    if ($d && $d->format('Y-m-d') === $val) return $d->format('Y-m-d');
    $d = DateTime::createFromFormat('d/m/Y', $val);
    if ($d) return $d->format('Y-m-d');
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}
function periodoBr($periodo) {
    if (!$periodo || $periodo === 'all') return '';
    $data = DateTime::createFromFormat('Y-m-d', $periodo . '-01');
    return $data ? mb_strtoupper($data->format('M/Y'), 'UTF-8') : $periodo;
}

$today_iso = (new DateTime())->format('Y-m-d');
$today_br  = (new DateTime())->format('d/m/Y');
$currentMonth = (new DateTime('first day of this month'))->format('Y-m');
$selectedPeriod = $_GET['period'] ?? $currentMonth;
$action = $_GET['action'] ?? null;
$redirectUrl = "?period=" . urlencode($selectedPeriod);

// -------------------- AÇÕES GET --------------------
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $del = $pdo->prepare("DELETE FROM expenses WHERE id = :id AND user_id = :user_id");
    $del->execute([':id' => $id, ':user_id' => $current_user_id]);
    header("Location: " . $redirectUrl); exit;
}

if ($action === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cur = $pdo->prepare("SELECT paid FROM expenses WHERE id = :id AND user_id = :user_id");
    $cur->execute([':id' => $id, ':user_id' => $current_user_id]);
    $r = $cur->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $new = $r['paid'] ? 0 : 1;
        $u = $pdo->prepare("UPDATE expenses SET paid = :paid, payment_date = CASE WHEN :paid = 1 THEN COALESCE(payment_date, CURDATE()) ELSE NULL END WHERE id = :id AND user_id = :user_id");
        $u->execute([':paid' => $new, ':id' => $id, ':user_id' => $current_user_id]);
    }
    header("Location: " . $redirectUrl); exit;
}

if ($action === 'copy_prev' && $selectedPeriod !== 'all') {
    $targetDate = new DateTime($selectedPeriod . '-01');
    $prevDate = clone $targetDate;
    $prevDate->modify('-1 month');
    $prevPeriod = $prevDate->format('Y-m');
    $stmtCopy = $pdo->prepare("SELECT * FROM expenses WHERE period = :prev_period AND user_id = :user_id");
    $stmtCopy->execute([':prev_period' => $prevPeriod, ':user_id' => $current_user_id]);
    $itemsToCopy = $stmtCopy->fetchAll(PDO::FETCH_ASSOC);
    if ($itemsToCopy) {
        $insertStmt = $pdo->prepare("INSERT INTO expenses (user_id, name, amount, due_date, type, planned, period, paid) VALUES (:user_id, :name, :amount, :due_date, :type, :planned, :period, 0)");
        foreach ($itemsToCopy as $item) {
            $day = (new DateTime($item['due_date']))->format('d');
            $newDateStr = $selectedPeriod . '-' . $day;
            $testDate = DateTime::createFromFormat('Y-m-d', $newDateStr);
            if (!$testDate || $testDate->format('Y-m') !== $selectedPeriod) {
                $lastDay = new DateTime($selectedPeriod . '-01');
                $lastDay->modify('last day of this month');
                $newDateStr = $lastDay->format('Y-m-d');
            }
            $insertStmt->execute([
                ':user_id' => $current_user_id, ':name' => $item['name'], ':amount' => $item['amount'],
                ':due_date' => $newDateStr, ':type' => $item['type'], ':planned' => $item['planned'],
                ':period' => (new DateTime($newDateStr))->format('Y-m')
            ]);
        }
    }
    header("Location: " . $redirectUrl); exit;
}

$editRow = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $s = $pdo->prepare("SELECT * FROM expenses WHERE id = :id AND user_id = :user_id");
    $s->execute([':id' => $id, ':user_id' => $current_user_id]);
    $editRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------------- AÇÕES POST --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_id']) && $_POST['edit_id'] !== '') {
        $id = (int)$_POST['edit_id'];
        $name = trim($_POST['name_single'] ?? '');
        $amount = str_replace(',', '.', trim($_POST['amount_single'] ?? ''));
        $due_date = dataParaBanco(trim($_POST['date_single'] ?? ''));
        $type = (isset($_POST['type_single']) && $_POST['type_single'] === 'income') ? 'income' : 'expense';
        $planned = (isset($_POST['planned_single']) && $_POST['planned_single'] == '1') ? 1 : 0;
        $paid = (isset($_POST['paid_single']) && $_POST['paid_single'] == '1') ? 1 : 0;
        if ($name && $amount && $due_date && is_numeric($amount)) {
            $period = (new DateTime($due_date))->format('Y-m');
            $pdo->prepare("UPDATE expenses SET name=:name, amount=:amount, due_date=:due_date, type=:type, planned=:planned, period=:period, paid=:paid, payment_date=CASE WHEN :paid=1 THEN COALESCE(payment_date,CURDATE()) ELSE NULL END WHERE id=:id AND user_id=:user_id")
                ->execute([':name'=>$name,':amount'=>$amount,':due_date'=>$due_date,':type'=>$type,':planned'=>$planned,':period'=>$period,':paid'=>$paid,':id'=>$id,':user_id'=>$current_user_id]);
        }
        header("Location: " . $redirectUrl); exit;
    }

    if (isset($_POST['name']) && is_array($_POST['name'])) {
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, name, amount, due_date, type, planned, period) VALUES (:user_id, :name, :amount, :due_date, :type, :planned, :period)");
        foreach ($_POST['name'] as $i => $n) {
            $n = trim($n);
            $a = str_replace(',', '.', trim($_POST['amount'][$i] ?? ''));
            $d = dataParaBanco(trim($_POST['date'][$i] ?? ''));
            $type = isset($_POST['type'][$i]) && $_POST['type'][$i] === 'income' ? 'income' : 'expense';
            $planned = isset($_POST['planned'][$i]) && $_POST['planned'][$i] === '1' ? 1 : 0;
            if ($n && $a && $d && is_numeric($a)) {
                $stmt->execute([':user_id'=>$current_user_id,':name'=>$n,':amount'=>$a,':due_date'=>$d,':type'=>$type,':planned'=>$planned,':period'=>(new DateTime($d))->format('Y-m')]);
            }
        }
        header("Location: " . $redirectUrl); exit;
    }
}

// -------------------- CÁLCULOS --------------------
if ($selectedPeriod !== 'all') {
    $p = $pdo->prepare("SELECT SUM(CASE WHEN type='expense' AND paid=0 THEN amount ELSE 0 END) AS unpaid_expense, SUM(CASE WHEN type='income' AND paid=0 THEN amount ELSE 0 END) AS unpaid_income FROM expenses WHERE period=:period AND user_id=:user_id");
    $p->execute([':period'=>$selectedPeriod,':user_id'=>$current_user_id]);
    $pt = $p->fetch(PDO::FETCH_ASSOC);
    $due_now = $pt['unpaid_expense'] ?? 0;

    $f = $pdo->prepare("SELECT SUM(amount) as v FROM expenses WHERE paid=0 AND type='expense' AND period>:period AND user_id=:user_id");
    $f->execute([':period'=>$selectedPeriod,':user_id'=>$current_user_id]);
    $due_future = $f->fetch(PDO::FETCH_ASSOC)['v'] ?? 0;

    $t = $pdo->prepare("SELECT SUM(amount) as v FROM expenses WHERE paid=0 AND type='expense' AND user_id=:user_id");
    $t->execute([':user_id'=>$current_user_id]);
    $total_all = $t->fetch(PDO::FETCH_ASSOC)['v'] ?? 0;
} else {
    $tot = $pdo->prepare("SELECT SUM(CASE WHEN due_date<=:today AND paid=0 AND type='expense' THEN amount ELSE 0 END) AS due_now, SUM(CASE WHEN due_date>:today AND paid=0 AND type='expense' THEN amount ELSE 0 END) AS due_future, SUM(CASE WHEN paid=0 AND type='expense' THEN amount ELSE 0 END) AS total_all FROM expenses WHERE user_id=:user_id");
    $tot->execute([':today'=>$today_iso,':user_id'=>$current_user_id]);
    $tv = $tot->fetch(PDO::FETCH_ASSOC);
    $due_now = $tv['due_now'] ?? 0;
    $due_future = $tv['due_future'] ?? 0;
    $total_all = $tv['total_all'] ?? 0;
}

$g = $pdo->prepare("SELECT SUM(CASE WHEN type='income' AND paid=1 THEN amount ELSE 0 END) as rec, SUM(CASE WHEN type='expense' AND paid=1 THEN amount ELSE 0 END) as pag FROM expenses WHERE user_id=:user_id");
$g->execute([':user_id'=>$current_user_id]);
$gv = $g->fetch(PDO::FETCH_ASSOC);
$balanco_geral = ($gv['rec'] ?? 0) - ($gv['pag'] ?? 0);

$periodsStmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(due_date, '%Y-%m') as period FROM expenses WHERE user_id=:user_id ORDER BY period DESC");
$periodsStmt->execute([':user_id'=>$current_user_id]);
$periods = $periodsStmt->fetchAll(PDO::FETCH_COLUMN);

$namesStmt = $pdo->prepare("SELECT DISTINCT name FROM expenses WHERE user_id=:user_id ORDER BY name ASC");
$namesStmt->execute([':user_id'=>$current_user_id]);
$suggestionNames = $namesStmt->fetchAll(PDO::FETCH_COLUMN);

if ($selectedPeriod !== 'all') {
    $listStmt = $pdo->prepare("SELECT * FROM expenses WHERE period=:period AND user_id=:user_id ORDER BY type ASC, due_date ASC");
    $listStmt->execute([':period'=>$selectedPeriod,':user_id'=>$current_user_id]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $sumStmt = $pdo->prepare("SELECT SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS te, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS ti FROM expenses WHERE period=:period AND user_id=:user_id");
    $sumStmt->execute([':period'=>$selectedPeriod,':user_id'=>$current_user_id]);
    $sv = $sumStmt->fetch(PDO::FETCH_ASSOC);
    $saldo_mes = ($sv['ti'] ?? 0) - ($sv['te'] ?? 0);
    $total_expense_month = $sv['te'] ?? 0;
    $total_income_month = $sv['ti'] ?? 0;
} else {
    $listStmt = $pdo->prepare("SELECT * FROM expenses WHERE user_id=:user_id ORDER BY type ASC, due_date DESC LIMIT 200");
    $listStmt->execute([':user_id'=>$current_user_id]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $saldo_mes = 0; $total_expense_month = 0; $total_income_month = 0;
}

$expenseRows = array_filter($rows, fn($r) => ($r['type'] ?? 'expense') === 'expense');
$incomeRows  = array_filter($rows, fn($r) => ($r['type'] ?? 'expense') === 'income');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Dashboard</title>
<link rel="icon" href="favicon.png" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink: #0d1117;
    --bg: #f0f2f7;
    --surface: #ffffff;
    --surface2: #f8f9fc;
    --accent: #2563eb;
    --accent-hover: #1d4ed8;
    --accent-light: #eff6ff;
    --success: #059669;
    --success-light: #ecfdf5;
    --danger: #dc2626;
    --danger-light: #fef2f2;
    --warning: #d97706;
    --warning-light: #fffbeb;
    --muted: #6b7280;
    --border: #e5e7eb;
    --sidebar-w: 240px;
    --radius: 12px;
}

body {
    font-family: 'Sora', sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    display: flex;
    font-size: 14px;
    line-height: 1.5;
}

/* ===== SIDEBAR ===== */
.sidebar {
    width: var(--sidebar-w);
    background: var(--ink);
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 100;
    transition: width 0.3s;
}

.sidebar-logo {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}

.sidebar-logo-icon {
    width: 36px;
    height: 36px;
    background: var(--accent);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.sidebar-logo-icon svg { width: 20px; height: 20px; color: #fff; }

.sidebar-logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
}

.sidebar-section {
    padding: 16px 12px 4px;
}

.sidebar-section-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(255,255,255,0.3);
    padding: 0 8px;
    margin-bottom: 6px;
}

.period-nav {
    flex: 1;
    overflow-y: auto;
    padding: 0 12px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}

.period-nav::-webkit-scrollbar { width: 4px; }
.period-nav::-webkit-scrollbar-track { background: transparent; }
.period-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.period-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.15s;
    margin-bottom: 2px;
}

.period-item:hover {
    background: rgba(255,255,255,0.07);
    color: #fff;
}

.period-item.active {
    background: var(--accent);
    color: #fff;
}

.period-item svg { width: 15px; height: 15px; flex-shrink: 0; opacity: 0.7; }
.period-item.active svg { opacity: 1; }

.sidebar-footer {
    padding: 16px 12px;
    border-top: 1px solid rgba(255,255,255,0.07);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    margin-bottom: 8px;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    flex-shrink: 0;
}

.user-name {
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
}

.sidebar-actions { display: flex; flex-direction: column; gap: 4px; }

.sidebar-action {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.15s;
    border: none;
    background: none;
    cursor: pointer;
    width: 100%;
    text-align: left;
    font-family: 'Sora', sans-serif;
}

.sidebar-action:hover { background: rgba(255,255,255,0.07); color: #fff; }
.sidebar-action svg { width: 15px; height: 15px; }

/* ===== MAIN ===== */
.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ===== TOPBAR ===== */
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left h1 {
    font-size: 18px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
    letter-spacing: -0.5px;
}

.topbar-left p {
    font-size: 12px;
    color: var(--muted);
    margin-top: 1px;
}

.topbar-right { display: flex; align-items: center; gap: 10px; }

.btn-add-entry {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-add-entry:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
.btn-add-entry svg { width: 16px; height: 16px; }

/* ===== CONTENT ===== */
.content { padding: 24px 28px; flex: 1; }

/* ===== STAT CARDS ===== */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.07); }

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.stat-card.danger::before { background: var(--danger); }
.stat-card.warning::before { background: var(--warning); }
.stat-card.neutral::before { background: var(--muted); }
.stat-card.accent::before { background: var(--accent); }
.stat-card.success::before { background: var(--success); }

.stat-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-bottom: 8px;
}

.stat-value {
    font-size: 22px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
    color: var(--ink);
    letter-spacing: -0.5px;
}

.stat-value.positive { color: var(--success); }
.stat-value.negative { color: var(--danger); }

.stat-icon {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon svg { width: 18px; height: 18px; }
.stat-card.danger .stat-icon { background: var(--danger-light); color: var(--danger); }
.stat-card.warning .stat-icon { background: var(--warning-light); color: var(--warning); }
.stat-card.neutral .stat-icon { background: var(--surface2); color: var(--muted); }
.stat-card.accent .stat-icon { background: var(--accent-light); color: var(--accent); }
.stat-card.success .stat-icon { background: var(--success-light); color: var(--success); }

/* ===== CHART CARD ===== */
.chart-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.chart-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
}

.chart-subtitle { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* Chart inner layout: bar 85% | pie 15% */
.chart-inner {
    display: flex;
    align-items: center;
    gap: 0;
}

.chart-bar-wrap {
    flex: 0 0 83%;
    max-width: 83%;
    position: relative;
    height: 200px;
}

.chart-bar-wrap canvas { height: 200px !important; }

.chart-divider {
    width: 1px;
    height: 180px;
    background: var(--border);
    margin: 0 16px;
    flex-shrink: 0;
}

.chart-pie-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.pie-title {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    text-align: center;
}

.chart-pie-wrap canvas { max-width: 120px; max-height: 120px; }

.pie-empty {
    font-size: 11px;
    color: var(--muted);
    text-align: center;
    padding: 12px;
}

/* Dark Mode Toggle Button */
.btn-theme-toggle {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: all 0.2s;
    flex-shrink: 0;
}

.btn-theme-toggle:hover {
    background: var(--surface2);
    transform: rotate(15deg) scale(1.1);
}

/* ===== DARK MODE ===== */
body.dark {
    --ink: #f1f5f9;
    --bg: #0f1117;
    --surface: #1a1d26;
    --surface2: #22262f;
    --border: #2d3140;
    --muted: #8b92a5;
}

body.dark .sidebar { background: #0d0f14; }
body.dark .sidebar-logo { border-bottom-color: rgba(255,255,255,0.06); }

body.dark .data-table th { background: var(--surface2); }
body.dark .data-table tbody tr:hover { background: #1e2130; }

body.dark .btn-icon.toggle { background: #1e3a5f; color: #60a5fa; }
body.dark .btn-icon.edit { background: #3d2c0a; color: #fbbf24; }
body.dark .btn-icon.del { background: #3d1515; color: #f87171; }

body.dark .stat-card.danger .stat-icon { background: #3d1515; color: #f87171; }
body.dark .stat-card.warning .stat-icon { background: #3d2c0a; color: #fbbf24; }
body.dark .stat-card.neutral .stat-icon { background: var(--surface2); }
body.dark .stat-card.accent .stat-icon { background: #1e3a5f; color: #60a5fa; }
body.dark .stat-card.success .stat-icon { background: #0d2d1a; color: #34d399; }

body.dark .badge.paid { background: #0d2d1a; color: #34d399; }
body.dark .badge.unpaid { background: #3d1515; color: #f87171; }
body.dark .badge.planned { background: #1e3a5f; color: #60a5fa; }

body.dark .modal { background: var(--surface); }
body.dark .entry-row input,
body.dark .entry-row select,
body.dark .edit-field input,
body.dark .edit-field select { background: var(--surface2); color: var(--ink); border-color: var(--border); }

body.dark .btn-theme-toggle { border-color: var(--border); background: var(--surface2); }
body.dark .copy-prev-btn { background: var(--surface2); border-color: var(--border); color: var(--ink); }
body.dark .copy-prev-btn:hover { background: #1e3a5f; border-color: var(--accent); color: #60a5fa; }
body.dark .period-summary { background: var(--surface); border-color: var(--border); }
body.dark .edit-panel { background: var(--surface); border-color: var(--accent); }

/* ===== PERIOD SUMMARY ===== */
.period-summary {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 16px 20px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.summary-item { text-align: center; }
.summary-item .label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.summary-item .value { font-size: 17px; font-weight: 700; font-family: 'Space Mono', monospace; margin-top: 3px; }
.summary-item .value.red { color: var(--danger); }
.summary-item .value.green { color: var(--success); }

.summary-divider { width: 1px; height: 40px; background: var(--border); }

.copy-prev-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--surface2);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    cursor: pointer;
}
.copy-prev-btn:hover { background: #eef2ff; border-color: var(--accent); color: var(--accent); }
.copy-prev-btn svg { width: 14px; height: 14px; }

/* ===== TABLES ===== */
.tables-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.table-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
}

.table-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.table-card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
}

.table-card-title .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.dot.red { background: var(--danger); }
.dot.green { background: var(--success); }

.table-card-count {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 20px;
    background: var(--surface2);
    color: var(--muted);
}

.data-table { width: 100%; border-collapse: collapse; }

.data-table th {
    padding: 10px 16px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    text-align: left;
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
}

.data-table td {
    padding: 11px 16px;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.data-table tbody tr:last-child td { border-bottom: none; }

.data-table tbody tr:hover { background: #fafbfc; }

.item-name { font-weight: 500; }
.item-amount { font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; }
.item-date { color: var(--muted); font-size: 12px; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.badge.paid { background: var(--success-light); color: var(--success); }
.badge.unpaid { background: var(--danger-light); color: var(--danger); }
.badge.planned { background: var(--accent-light); color: var(--accent); }

.action-btns { display: flex; gap: 4px; align-items: center; }

.btn-icon {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    font-size: 12px;
}

.btn-icon svg { width: 13px; height: 13px; }
.btn-icon.toggle { background: #eff6ff; color: var(--accent); }
.btn-icon.toggle:hover { background: var(--accent); color: #fff; }
.btn-icon.edit { background: #fffbeb; color: var(--warning); }
.btn-icon.edit:hover { background: var(--warning); color: #fff; }
.btn-icon.del { background: #fef2f2; color: var(--danger); }
.btn-icon.del:hover { background: var(--danger); color: #fff; }

/* ===== EMPTY STATE ===== */
.empty-state {
    padding: 32px;
    text-align: center;
    color: var(--muted);
}

.empty-state svg { width: 32px; height: 32px; opacity: 0.3; margin-bottom: 8px; }
.empty-state p { font-size: 13px; }

/* ===== ADD ENTRY MODAL ===== */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
    z-index: 200;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.open { display: flex; }

.modal {
    background: var(--surface);
    border-radius: 16px;
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px rgba(0,0,0,0.2);
    animation: modalIn 0.2s ease;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title { font-size: 15px; font-weight: 700; }

.modal-close {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: none;
    background: var(--surface2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
    font-size: 18px;
    line-height: 1;
    color: var(--muted);
}

.modal-close:hover { background: var(--border); color: var(--ink); }

.modal-body { padding: 20px 24px; }

.entry-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
    gap: 8px;
    margin-bottom: 8px;
    align-items: end;
}

.entry-row label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    display: block;
    margin-bottom: 4px;
}

.entry-row input,
.entry-row select {
    width: 100%;
    padding: 9px 10px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.entry-row input:focus,
.entry-row select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}

.modal-footer {
    padding: 16px 24px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid var(--border);
}

.btn-add-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--ink);
}

.btn-add-row:hover { background: #eef2ff; border-color: var(--accent); color: var(--accent); }

.btn-save {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-save:hover { background: var(--accent-hover); }

/* ===== EDIT CARD ===== */
.edit-panel {
    background: var(--surface);
    border-radius: var(--radius);
    border: 2px solid var(--accent);
    padding: 20px 24px;
    margin-bottom: 20px;
}

.edit-panel h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--accent); }

.edit-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 12px; }

.edit-field label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 4px;
}

.edit-field input,
.edit-field select {
    width: 100%;
    padding: 9px 10px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: #fff;
    transition: border-color 0.2s;
}

.edit-field input:focus, .edit-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}

.edit-actions { display: flex; gap: 8px; margin-top: 16px; }

.btn-secondary {
    padding: 9px 16px;
    background: var(--surface2);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all 0.15s;
}

.btn-secondary:hover { background: var(--border); }

/* ===== DATALIST ===== */
datalist { display: none; }

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: #9ca3af; }

/* ===== RESPONSIVE ===== */
@media (max-width: 1100px) {
    .tables-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    :root { --sidebar-w: 60px; }
    .sidebar-logo-text, .sidebar-section-label, .period-item span, .user-name, .user-role, .sidebar-action span { display: none; }
    .sidebar-logo { justify-content: center; }
    .period-item { justify-content: center; }
    .sidebar-action { justify-content: center; }
    .user-info { justify-content: center; }
    .main { margin-left: 60px; }
    .content { padding: 16px; }
    .topbar { padding: 12px 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .entry-row { grid-template-columns: 1fr; }
    .edit-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <span class="sidebar-logo-text">Finanzas</span>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-label">Períodos</div>
        <a href="?period=all" class="period-item <?= $selectedPeriod === 'all' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h7" />
            </svg>
            <span>Todos</span>
        </a>
    </div>

    <div class="period-nav">
        <?php foreach ($periods as $p): ?>
            <?php if (!$p) continue; ?>
            <a href="?period=<?= urlencode($p) ?>" class="period-item <?= $selectedPeriod === $p ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span><?= safe(periodoBr($p)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= mb_strtoupper(mb_substr($current_user_name, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= safe($current_user_name) ?></div>
                <div class="user-role"><?= $is_admin ? 'Administrador' : 'Usuário' ?></div>
            </div>
        </div>
        <div class="sidebar-actions">
            <?php if ($is_admin): ?>
            <a href="usuarios.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span>Usuários</span>
            </a>
            <?php endif; ?>
            <form method="post" action="logout.php" style="width:100%">
                <button type="submit" class="sidebar-action">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- ===== MAIN ===== -->
<main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <h1><?= $selectedPeriod !== 'all' ? periodoBr($selectedPeriod) : 'Todos os períodos' ?></h1>
            <p>Hoje: <?= safe($today_br) ?></p>
        </div>
        <div class="topbar-right">
            <?php if ($selectedPeriod !== 'all'): ?>
            <a href="?action=copy_prev&period=<?= $selectedPeriod ?>" class="copy-prev-btn"
               onclick="return confirm('Copiar todos os lançamentos do mês anterior?')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Importar mês anterior
            </a>
            <?php endif; ?>
            <button class="btn-theme-toggle" id="themeToggle" title="Alternar tema" onclick="toggleTheme()">
                <span id="themeIcon">🌙</span>
            </button>
            <button class="btn-add-entry" onclick="document.getElementById('addModal').classList.add('open')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Novo lançamento
            </button>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- STAT CARDS -->
        <div class="stat-grid">
            <div class="stat-card danger">
                <div class="stat-label"><?= $selectedPeriod !== 'all' ? 'Despesas pendentes' : 'Dívidas atuais' ?></div>
                <div class="stat-value"><?= money($due_now) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
                    </svg>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label">Dívidas futuras</div>
                <div class="stat-value"><?= money($due_future) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="stat-card neutral">
                <div class="stat-label">Total não pago</div>
                <div class="stat-value"><?= money($total_all) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                    </svg>
                </div>
            </div>

            <div class="stat-card <?= ($selectedPeriod !== 'all' ? ($saldo_mes >= 0 ? 'success' : 'danger') : ($balanco_geral >= 0 ? 'success' : 'danger')) ?>">
                <div class="stat-label"><?= $selectedPeriod !== 'all' ? 'Saldo do mês' : 'Balanço geral' ?></div>
                <?php $bv = $selectedPeriod !== 'all' ? $saldo_mes : $balanco_geral; ?>
                <div class="stat-value <?= $bv >= 0 ? 'positive' : 'negative' ?>"><?= money($bv) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- CHART -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Histórico mensal</div>
                    <div class="chart-subtitle">Receitas vs Despesas — últimos 12 meses</div>
                </div>
            </div>
            <div class="chart-inner">
                <div class="chart-bar-wrap">
                    <canvas id="monthlyChart"></canvas>
                </div>
                <div class="chart-divider"></div>
                <div class="chart-pie-wrap">
                    <div class="pie-title">Despesas por categoria</div>
                    <canvas id="pieChart"></canvas>
                    <div id="pieEmpty" class="pie-empty" style="display:none;">Sem dados no período</div>
                </div>
            </div>
        </div>

        <?php if ($selectedPeriod !== 'all'): ?>
        <!-- PERIOD SUMMARY -->
        <div class="period-summary">
            <div class="summary-item">
                <div class="label">Despesas</div>
                <div class="value red"><?= money($total_expense_month) ?></div>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
                <div class="label">Receitas</div>
                <div class="value green"><?= money($total_income_month) ?></div>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
                <div class="label">Saldo</div>
                <div class="value <?= $saldo_mes >= 0 ? 'green' : 'red' ?>"><?= money($saldo_mes) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EDIT PANEL -->
        <?php if ($editRow): ?>
        <div class="edit-panel">
            <h3>✏️ Editando lançamento #<?= safe($editRow['id']) ?></h3>
            <form method="post">
                <input type="hidden" name="edit_id" value="<?= (int)$editRow['id'] ?>">
                <div class="edit-grid">
                    <div class="edit-field">
                        <label>Nome</label>
                        <input name="name_single" type="text" value="<?= safe($editRow['name']) ?>" required list="user-expense-names">
                    </div>
                    <div class="edit-field">
                        <label>Valor</label>
                        <input name="amount_single" type="text" value="<?= safe(str_replace('.', ',', $editRow['amount'])) ?>" required>
                    </div>
                    <div class="edit-field">
                        <label>Vencimento</label>
                        <input name="date_single" type="date" value="<?= safe($editRow['due_date']) ?>" required>
                    </div>
                    <div class="edit-field">
                        <label>Tipo</label>
                        <select name="type_single">
                            <option value="expense" <?= ($editRow['type'] ?? '') === 'expense' ? 'selected' : '' ?>>Despesa</option>
                            <option value="income" <?= ($editRow['type'] ?? '') === 'income' ? 'selected' : '' ?>>Receita</option>
                        </select>
                    </div>
                    <div class="edit-field">
                        <label>Planificado</label>
                        <select name="planned_single">
                            <option value="0" <?= empty($editRow['planned']) ? 'selected' : '' ?>>Não</option>
                            <option value="1" <?= !empty($editRow['planned']) ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="edit-field">
                        <label>Pago</label>
                        <select name="paid_single">
                            <option value="0" <?= empty($editRow['paid']) ? 'selected' : '' ?>>Não</option>
                            <option value="1" <?= !empty($editRow['paid']) ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                </div>
                <div class="edit-actions">
                    <button type="submit" class="btn-save">Salvar</button>
                    <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>?period=<?= $selectedPeriod ?>" class="btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABLES -->
        <div class="tables-grid">
            <!-- EXPENSES -->
            <div class="table-card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="dot red"></span>
                        Despesas
                    </div>
                    <span class="table-card-count"><?= count($expenseRows) ?></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Valor</th>
                            <th>Venc.</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenseRows as $r): ?>
                        <tr>
                            <td>
                                <div class="item-name"><?= safe($r['name']) ?></div>
                                <?php if (!empty($r['planned'])): ?>
                                <span class="badge planned" style="margin-top:2px;font-size:10px;">Planificado</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="item-amount" style="color:var(--danger)"><?= money($r['amount']) ?></span></td>
                            <td><span class="item-date"><?= safe(formataDataBr($r['due_date'])) ?></span></td>
                            <td>
                                <?php if ($r['paid']): ?>
                                    <span class="badge paid">✓ Pago</span>
                                <?php else: ?>
                                    <span class="badge unpaid">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon toggle" href="?action=toggle&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="<?= $r['paid'] ? 'Desmarcar' : 'Marcar pago' ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon edit" href="?action=edit&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon del" href="?action=delete&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Excluir" onclick="return confirm('Excluir este lançamento?')">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($expenseRows) === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p>Nenhuma despesa neste período</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- INCOME -->
            <div class="table-card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="dot green"></span>
                        Receitas
                    </div>
                    <span class="table-card-count"><?= count($incomeRows) ?></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Valor</th>
                            <th>Venc.</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeRows as $r): ?>
                        <tr>
                            <td>
                                <div class="item-name"><?= safe($r['name']) ?></div>
                                <?php if (!empty($r['planned'])): ?>
                                <span class="badge planned" style="margin-top:2px;font-size:10px;">Planificado</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="item-amount" style="color:var(--success)"><?= money($r['amount']) ?></span></td>
                            <td><span class="item-date"><?= safe(formataDataBr($r['due_date'])) ?></span></td>
                            <td>
                                <?php if ($r['paid']): ?>
                                    <span class="badge paid">✓ Recebido</span>
                                <?php else: ?>
                                    <span class="badge unpaid">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon toggle" href="?action=toggle&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="<?= $r['paid'] ? 'Desmarcar' : 'Marcar recebido' ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon edit" href="?action=edit&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon del" href="?action=delete&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Excluir" onclick="return confirm('Excluir este lançamento?')">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($incomeRows) === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p>Nenhuma receita neste período</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</main>

<!-- ===== ADD ENTRY MODAL ===== -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Novo lançamento</div>
            <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">×</button>
        </div>
        <div class="modal-body">
            <form method="post" id="addForm">
                <datalist id="user-expense-names">
                    <?php foreach ($suggestionNames as $n): ?><option value="<?= safe($n) ?>"><?php endforeach; ?>
                </datalist>
                <div id="entryRows">
                    <div class="entry-row">
                        <div>
                            <label>Nome / Descrição</label>
                            <input name="name[]" type="text" placeholder="Ex: Aluguel" list="user-expense-names" autocomplete="off" required>
                        </div>
                        <div>
                            <label>Valor</label>
                            <input name="amount[]" type="text" inputmode="decimal" placeholder="0,00" required>
                        </div>
                        <div>
                            <label>Vencimento</label>
                            <input name="date[]" type="date" value="<?= $today_iso ?>" required>
                        </div>
                        <div>
                            <label>Tipo</label>
                            <select name="type[]">
                                <option value="expense">Despesa</option>
                                <option value="income">Receita</option>
                            </select>
                        </div>
                        <div>
                            <label>Planificado</label>
                            <select name="planned[]">
                                <option value="1">Sim</option>
                                <option value="0">Não</option>
                            </select>
                        </div>
                        <div style="padding-bottom:1px;">
                            <label style="visibility:hidden">x</label>
                            <button type="button" class="btn-icon del" onclick="removeEntryRow(this)" style="width:36px;height:36px;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-add-row" onclick="addEntryRow()">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Adicionar linha
            </button>
            <button type="submit" form="addForm" class="btn-save">Salvar lançamentos</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ===== DARK MODE =====
(function() {
    const saved = localStorage.getItem('finanzas_theme');
    if (saved === 'dark') {
        document.body.classList.add('dark');
        document.getElementById('themeIcon').textContent = '☀️';
    }
})();

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark');
    document.getElementById('themeIcon').textContent = isDark ? '☀️' : '🌙';
    localStorage.setItem('finanzas_theme', isDark ? 'dark' : 'light');
    // Rebuild charts with new colors
    buildCharts();
}

function getChartColors() {
    const dark = document.body.classList.contains('dark');
    return {
        gridColor: dark ? '#2d3140' : '#f3f4f6',
        tickColor: dark ? '#8b92a5' : '#6b7280',
        expenseBg: dark ? 'rgba(248,113,113,0.18)' : 'rgba(220,38,38,0.12)',
        expenseBorder: dark ? '#f87171' : '#dc2626',
        incomeBg: dark ? 'rgba(52,211,153,0.18)' : 'rgba(5,150,105,0.12)',
        incomeBorder: dark ? '#34d399' : '#059669',
    };
}

let barChartInst = null;
let pieChartInst = null;

function buildCharts() {
    fetch('monthly_data.php?months_back=11')
      .then(r => r.json())
      .then(d => {
        const c = getChartColors();

        // ---- BAR CHART ----
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        if (barChartInst) barChartInst.destroy();
        barChartInst = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: d.labels,
            datasets: [
              {
                label: 'Despesas',
                data: d.expenses,
                backgroundColor: c.expenseBg,
                borderColor: c.expenseBorder,
                borderWidth: 1.5,
                borderRadius: 4,
                barPercentage: 0.35,
                categoryPercentage: 0.7,
              },
              {
                label: 'Receitas',
                data: d.incomes,
                backgroundColor: c.incomeBg,
                borderColor: c.incomeBorder,
                borderWidth: 1.5,
                borderRadius: 4,
                barPercentage: 0.35,
                categoryPercentage: 0.7,
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'top', labels: { usePointStyle: true, padding: 14, font: { family: 'Sora', size: 11 }, color: c.tickColor } },
              tooltip: {
                mode: 'index', intersect: false,
                callbacks: { label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2}) }
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { font: { family: 'Sora', size: 10 }, color: c.tickColor } },
              y: { grid: { color: c.gridColor }, beginAtZero: true, ticks: { font: { family: 'Sora', size: 10 }, color: c.tickColor, callback: v => 'R$' + (v/1000).toFixed(0) + 'k' } }
            }
          }
        });

        // ---- PIE CHART (expense categories from labels) ----
        buildPieChart();
      });
}

function buildPieChart() {
    // Use current period expenses grouped by first word / name from the visible table
    const rows = document.querySelectorAll('.table-card:first-child tbody tr:not(.empty-row)');
    const map = {};
    rows.forEach(tr => {
        const nameEl = tr.querySelector('.item-name');
        const amtEl  = tr.querySelector('.item-amount');
        if (!nameEl || !amtEl) return;
        const rawAmt = amtEl.textContent.replace(/[R$\s.]/g, '').replace(',', '.');
        const amt = parseFloat(rawAmt);
        if (isNaN(amt) || amt <= 0) return;
        // Simplify category: take first 2 words
        const words = nameEl.textContent.trim().split(/\s+/);
        const cat = words.slice(0, 2).join(' ');
        map[cat] = (map[cat] || 0) + amt;
    });

    const labels = Object.keys(map);
    const data   = Object.values(map);

    const canvas  = document.getElementById('pieChart');
    const emptyEl = document.getElementById('pieEmpty');

    if (labels.length === 0) {
        canvas.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }
    canvas.style.display = '';
    emptyEl.style.display = 'none';

    const palette = [
        '#ef4444','#f97316','#eab308','#22c55e','#06b6d4',
        '#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f59e0b',
        '#84cc16','#6366f1','#a855f7','#0ea5e9','#d946ef',
    ];

    const ctx2 = canvas.getContext('2d');
    if (pieChartInst) pieChartInst.destroy();
    pieChartInst = new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: palette.slice(0, labels.length),
                borderWidth: 0,
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '60%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return ` R$ ${ctx.parsed.toLocaleString('pt-BR', {minimumFractionDigits:2})} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

buildCharts();

// Add entry row
function addEntryRow() {
    const container = document.getElementById('entryRows');
    const firstRow = container.querySelector('.entry-row');
    const newRow = firstRow.cloneNode(true);
    newRow.querySelectorAll('input').forEach(i => { if (i.type !== 'date') i.value = ''; });
    newRow.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    container.appendChild(newRow);
    newRow.querySelector('input[type="text"]').focus();
}

function removeEntryRow(btn) {
    const rows = document.querySelectorAll('#entryRows .entry-row');
    if (rows.length > 1) btn.closest('.entry-row').remove();
    else {
        btn.closest('.entry-row').querySelectorAll('input').forEach(i => { if (i.type !== 'date') i.value = ''; });
    }
}

document.getElementById('addForm').addEventListener('submit', function(e) {
    const names = Array.from(document.querySelectorAll('input[name="name[]"]')).map(i => i.value.trim());
    if (!names.some(n => n)) {
        e.preventDefault();
        alert('Preencha ao menos uma linha.');
    }
});

// Keyboard shortcut: N = new entry
document.addEventListener('keydown', e => {
    if (e.key === 'n' && e.target.tagName === 'BODY') {
        document.getElementById('addModal').classList.add('open');
    }
    if (e.key === 'Escape') {
        document.getElementById('addModal').classList.remove('open');
    }
});
</script>
</body>
</html>

