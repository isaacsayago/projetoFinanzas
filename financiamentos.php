<?php
session_start();
if (!isset($_SESSION['id'])) { header('Location: index.php'); exit(); }

require_once 'conexao_pdo.php';
require_once 'financiamento_helper.php';

$current_user_id = $_SESSION['id'];
$current_user_name = $_SESSION['nome'] ?? 'Usuário';
$is_admin = isset($_SESSION['nivel']) && $_SESSION['nivel'] === 'adm';

date_default_timezone_set('America/Sao_Paulo');

function money($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function formataDataBr($d) {
    if (!$d || in_array($d, ['0000-00-00', '0000-00-00 00:00:00'])) return '';
    $ts = strtotime($d); return $ts ? date('d/m/Y', $ts) : '';
}

$msg = ''; $msg_type = '';

// ---- AÇÕES POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Alternar parcela paga/não paga
    if (isset($_POST['action_toggle_installment'])) {
        $instId = (int)($_POST['installment_id'] ?? 0);
        if ($instId) {
            $pdo->prepare("
                UPDATE loan_installments li
                JOIN loans l ON l.id = li.loan_id
                SET li.paid = CASE WHEN li.paid = 1 THEN 0 ELSE 1 END,
                    li.payment_date = CASE WHEN li.paid = 0 THEN CURDATE() ELSE NULL END
                WHERE li.id = :iid AND l.user_id = :uid
            ")->execute([':iid' => $instId, ':uid' => $current_user_id]);
        }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // Antecipar parcelas (marcar X parcelas futuras como pagas)
    if (isset($_POST['action_anticipate'])) {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $qty    = max(1, (int)($_POST['anticipate_qty'] ?? 1));
        if ($loanId) {
            $stmt = $pdo->prepare("
                SELECT id FROM loan_installments
                WHERE loan_id = :lid AND paid = 0
                ORDER BY installment_number ASC
                LIMIT :qty
            ");
            $stmt->bindValue(':lid', $loanId, PDO::PARAM_INT);
            $stmt->bindValue(':qty', $qty, PDO::PARAM_INT);
            $stmt->execute();
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $upd = $pdo->prepare("UPDATE loan_installments SET paid = 1, payment_date = CURDATE() WHERE id IN ($ph)");
                $upd->execute($ids);
            }
            $msg = count($ids) . ' parcela(s) antecipada(s) com sucesso.';
            $msg_type = 'success';
        }
    }

    // Quitar antecipadamente (marcar todas as restantes como pagas)
    if (isset($_POST['action_quitacao'])) {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        if ($loanId) {
            $pdo->prepare("
                UPDATE loan_installments li
                JOIN loans l ON l.id = li.loan_id
                SET li.paid = 1, li.payment_date = CURDATE()
                WHERE l.user_id = :uid AND li.loan_id = :lid AND li.paid = 0
            ")->execute([':uid' => $current_user_id, ':lid' => $loanId]);
            $msg = 'Financiamento quitado antecipadamente!';
            $msg_type = 'success';
        }
    }

    // Encerrar / desativar financiamento
    if (isset($_POST['action_encerrar'])) {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        if ($loanId) {
            $pdo->prepare("UPDATE loans SET active = 0 WHERE id = :id AND user_id = :uid")
                ->execute([':id' => $loanId, ':uid' => $current_user_id]);
            header('Location: financiamentos.php'); exit;
        }
    }

    // Editar dados básicos do financiamento
    if (isset($_POST['action_edit_loan'])) {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $name   = trim($_POST['loan_name'] ?? '');
        $cat    = trim($_POST['loan_category'] ?? 'outros');
        $inst   = trim($_POST['loan_institution'] ?? '');
        $rate   = trim($_POST['loan_interest_rate'] ?? '');
        $notes  = trim($_POST['loan_notes'] ?? '');
        if ($loanId && $name) {
            $rateVal = ($rate !== '' && is_numeric(str_replace(',', '.', $rate)))
                ? (float)str_replace(',', '.', $rate) : null;
            $pdo->prepare("
                UPDATE loans SET name=:name, category=:cat, institution=:inst, interest_rate=:rate, notes=:notes
                WHERE id=:id AND user_id=:uid
            ")->execute([':name'=>$name,':cat'=>$cat,':inst'=>$inst?:null,':rate'=>$rateVal,':notes'=>$notes?:null,':id'=>$loanId,':uid'=>$current_user_id]);
            $msg = 'Financiamento atualizado.';
            $msg_type = 'success';
        }
    }
}

// ---- DADOS ----
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$loanDetail = null;
if ($viewId) {
    $loanDetail = getLoanDetail($pdo, $viewId, $current_user_id);
}

// Lista de todos os financiamentos
$stmtLoans = $pdo->prepare("
    SELECT l.*,
        (SELECT COUNT(*) FROM loan_installments li WHERE li.loan_id = l.id AND li.paid = 1) as parcelas_pagas_real,
        (SELECT COUNT(*) FROM loan_installments li WHERE li.loan_id = l.id) as total_parcelas_real,
        (SELECT COALESCE(SUM(li.amount),0) FROM loan_installments li WHERE li.loan_id = l.id AND li.paid = 1) as total_pago_real,
        (SELECT li.due_date FROM loan_installments li WHERE li.loan_id = l.id AND li.paid = 0 ORDER BY li.due_date ASC LIMIT 1) as proxima_data,
        (SELECT li.amount FROM loan_installments li WHERE li.loan_id = l.id AND li.paid = 0 ORDER BY li.due_date ASC LIMIT 1) as proxima_valor,
        (SELECT li.installment_number FROM loan_installments li WHERE li.loan_id = l.id AND li.paid = 0 ORDER BY li.due_date ASC LIMIT 1) as proxima_numero
    FROM loans l
    WHERE l.user_id = :uid AND l.active = 1
    ORDER BY l.created_at DESC
");
$stmtLoans->execute([':uid' => $current_user_id]);
$loans = $stmtLoans->fetchAll(PDO::FETCH_ASSOC);

$loanMetrics = getLoanMetrics($pdo, $current_user_id);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Financiamentos — Finanzas</title>
<link rel="icon" href="favicon.png" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --ink: #e2e8f0; --bg: #06080f; --surface: #0d1117; --surface2: #161b26;
    --accent: #00d4ff; --accent-hover: #00b8e6; --accent-light: rgba(0,212,255,0.1);
    --purple: #a855f7; --purple-light: rgba(168,85,247,0.1);
    --success: #00ff88; --success-light: rgba(0,255,136,0.1);
    --danger: #ff2d55; --danger-light: rgba(255,45,85,0.1);
    --warning: #ffd600; --warning-light: rgba(255,214,0,0.1);
    --muted: #64748b; --border: rgba(255,255,255,0.08);
    --glow-purple: 0 0 24px rgba(168,85,247,0.15);
    --sidebar-w: 180px; --radius: 12px;
}
body { font-family: 'Sora', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; font-size: 14px; line-height: 1.5; }
body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(ellipse 80% 50% at 10% 0%, rgba(168,85,247,0.06) 0%, transparent 50%), radial-gradient(ellipse 60% 40% at 90% 100%, rgba(0,212,255,0.04) 0%, transparent 50%); pointer-events: none; }

/* SIDEBAR */
.sidebar { width: var(--sidebar-w); min-height: 100vh; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; position: sticky; top: 0; }
.sidebar-logo { padding: 20px 16px 16px; display: flex; align-items: center; gap: 10px; }
.sidebar-logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg, #00d4ff, #a855f7); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sidebar-logo-icon svg { width: 18px; height: 18px; color: #fff; }
.sidebar-logo-text { font-size: 15px; font-weight: 700; background: linear-gradient(135deg, #00d4ff, #a855f7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding: 16px; }
.user-info { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--purple), var(--accent)); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12px; font-weight: 600; }
.user-role { font-size: 10px; color: var(--muted); }
.sidebar-actions { display: flex; flex-direction: column; gap: 2px; }
.sidebar-action { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; color: var(--muted); text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.15s; background: none; border: none; cursor: pointer; width: 100%; text-align: left; font-family: inherit; }
.sidebar-action:hover { background: var(--surface2); color: var(--ink); }
.sidebar-action.active { background: var(--purple-light); color: var(--purple); }
.sidebar-action svg { width: 16px; height: 16px; flex-shrink: 0; }

/* MAIN */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar { padding: 20px 32px 0; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.topbar-title { font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.topbar-title svg { width: 24px; height: 24px; color: var(--purple); }
.topbar-sub { font-size: 13px; color: var(--muted); margin-top: 2px; }
.content { padding: 24px 32px 40px; }

/* CARDS MÉTRICAS */
.stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 28px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; position: relative; overflow: hidden; }
.stat-card.purple { border-color: rgba(168,85,247,0.25); background: linear-gradient(135deg, rgba(168,85,247,0.08), transparent); }
.stat-card.success { border-color: rgba(0,255,136,0.2); background: linear-gradient(135deg, rgba(0,255,136,0.06), transparent); }
.stat-card.warning { border-color: rgba(255,214,0,0.2); background: linear-gradient(135deg, rgba(255,214,0,0.06), transparent); }
.stat-card.accent { border-color: rgba(0,212,255,0.2); background: linear-gradient(135deg, rgba(0,212,255,0.06), transparent); }
.stat-label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px; }
.stat-value { font-size: 18px; font-weight: 700; }
.stat-card.purple .stat-value { color: var(--purple); }
.stat-card.success .stat-value { color: var(--success); }
.stat-card.warning .stat-value { color: var(--warning); }
.stat-card.accent .stat-value { color: var(--accent); }
.stat-icon { position: absolute; bottom: 10px; right: 12px; opacity: 0.1; }
.stat-icon svg { width: 36px; height: 36px; }

/* LOAN CARDS */
.loans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; margin-bottom: 32px; }
.loan-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; transition: border-color 0.2s, box-shadow 0.2s; position: relative; }
.loan-card:hover { border-color: rgba(168,85,247,0.3); box-shadow: var(--glow-purple); }
.loan-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.loan-card-title { font-size: 15px; font-weight: 700; }
.loan-card-cat { font-size: 11px; color: var(--muted); margin-top: 2px; }
.loan-card-badge { padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--purple-light); color: var(--purple); white-space: nowrap; }
.loan-card-metrics { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.loan-metric { text-align: center; }
.loan-metric-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.loan-metric-value { font-size: 13px; font-weight: 700; margin-top: 2px; }
.progress-bar { height: 6px; background: var(--surface2); border-radius: 999px; overflow: hidden; margin-bottom: 14px; }
.progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--purple), var(--accent)); transition: width 0.6s ease; }
.loan-card-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.loan-next-info { font-size: 11px; color: var(--muted); }
.loan-next-info strong { color: var(--ink); }

/* DETAIL PAGE */
.detail-back { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.15s; margin-bottom: 20px; }
.detail-back:hover { color: var(--ink); }
.detail-back svg { width: 16px; height: 16px; }
.detail-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; }
.detail-title-block {}
.detail-title { font-size: 24px; font-weight: 700; }
.detail-sub { font-size: 13px; color: var(--muted); margin-top: 4px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* INSTALLMENTS TABLE */
.table-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 24px; }
.table-card-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.table-card-title { font-size: 13px; font-weight: 700; }
table { width: 100%; border-collapse: collapse; }
th { padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 11px 16px; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 13px; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,0.02); }
.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.badge-paid { background: var(--success-light); color: var(--success); }
.badge-pending { background: rgba(255,214,0,0.1); color: var(--warning); }
.badge-overdue { background: var(--danger-light); color: var(--danger); }
.td-num { color: var(--muted); font-size: 12px; }
.td-current { background: rgba(168,85,247,0.06) !important; }
.td-paid { opacity: 0.5; }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all 0.15s; }
.btn-primary { background: var(--purple); color: #fff; }
.btn-primary:hover { background: #9333ea; }
.btn-secondary { background: var(--surface2); color: var(--ink); border: 1px solid var(--border); }
.btn-secondary:hover { border-color: rgba(255,255,255,0.2); }
.btn-danger { background: var(--danger-light); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }
.btn-danger:hover { background: rgba(255,45,85,0.2); }
.btn-success { background: var(--success-light); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.btn-success:hover { background: rgba(0,255,136,0.2); }
.btn-sm { padding: 5px 10px; font-size: 11px; border-radius: 6px; }
.btn-icon-sm { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface2); color: var(--muted); cursor: pointer; transition: all 0.15s; font-size: 0; }
.btn-icon-sm:hover { border-color: var(--accent); color: var(--accent); }
.btn-icon-sm.paid-btn:hover { border-color: var(--success); color: var(--success); }
.btn-icon-sm svg { width: 14px; height: 14px; }

/* MODAL */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.open { display: flex; }
.modal { background: var(--surface); border-radius: 16px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.4), 0 0 40px rgba(168,85,247,0.08); border: 1px solid rgba(168,85,247,0.15); animation: modalIn 0.2s ease; }
.modal.wide { max-width: 740px; }
@keyframes modalIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-title { font-size: 15px; font-weight: 700; }
.modal-close { width: 30px; height: 30px; border-radius: 8px; border: none; background: var(--surface2); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--muted); }
.modal-close:hover { background: var(--border); color: var(--ink); }
.modal-body { padding: 20px 24px; }
.modal-footer { padding: 16px 24px 20px; display: flex; align-items: center; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--border); }

/* FORM */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.form-grid .span-2 { grid-column: 1 / -1; }
.form-grid label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); display: block; margin-bottom: 4px; }
.form-grid input, .form-grid select, .form-grid textarea { width: 100%; padding: 9px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; color: var(--ink); background: var(--surface2); transition: border-color 0.2s; }
.form-grid input:focus, .form-grid select:focus, .form-grid textarea:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(168,85,247,0.1); }
.form-grid textarea { resize: vertical; min-height: 72px; }

/* EMPTY STATE */
.empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
.empty-state svg { width: 48px; height: 48px; opacity: 0.3; margin-bottom: 12px; }
.empty-state h3 { font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.empty-state p { font-size: 13px; }

/* ALERT */
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
.alert-success { background: var(--success-light); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.alert-danger  { background: var(--danger-light);  color: var(--danger);  border: 1px solid rgba(255,45,85,0.2); }

/* PROGRESS DETAIL */
.progress-block { margin-bottom: 24px; }
.progress-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
.progress-bar-lg { height: 10px; background: var(--surface2); border-radius: 999px; overflow: hidden; }
.progress-fill-lg { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--purple), var(--accent)); transition: width 0.8s ease; }

/* ANTICIPATE FORM */
.anticipate-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.anticipate-row input[type=number] { width: 80px; padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--surface2); color: var(--ink); font-family: 'Sora',sans-serif; font-size: 13px; }
.anticipate-row input[type=number]:focus { outline: none; border-color: var(--purple); }

@media (max-width: 900px) {
    .sidebar { display: none; }
    .content { padding: 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .loans-grid { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .form-grid .span-2 { grid-column: 1; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <span class="sidebar-logo-text">Finanzas</span>
    </div>
    <div style="flex:1;"></div>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= mb_strtoupper(mb_substr($current_user_name, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($current_user_name) ?></div>
                <div class="user-role"><?= $is_admin ? 'Administrador' : 'Usuário' ?></div>
            </div>
        </div>
        <div class="sidebar-actions">
            <a href="dashboard.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="cartoes.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span>Cartões</span>
            </a>
            <a href="financiamentos.php" class="sidebar-action active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <span>Financiamentos</span>
            </a>
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

<!-- MAIN -->
<main class="main">
<div class="topbar">
    <div>
        <div class="topbar-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            Financiamentos e Empréstimos
        </div>
        <div class="topbar-sub">Gerencie seus compromissos financeiros de longo prazo</div>
    </div>
    <a href="dashboard.php#open_loan" class="btn btn-primary" onclick="sessionStorage.setItem('openLoanModal','1');">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        Novo Financiamento
    </a>
</div>

<div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($loanDetail): ?>
    <!-- ======= DETAIL VIEW ======= -->
    <?php
    $loan = $loanDetail;
    $percentual = $loan['percentual_quitacao'];
    $hoje = date('Y-m-d');
    ?>
    <a href="financiamentos.php" class="detail-back">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
        Voltar para a lista
    </a>

    <div class="detail-header">
        <div class="detail-title-block">
            <div class="detail-title">🏦 <?= htmlspecialchars($loan['name']) ?></div>
            <div class="detail-sub">
                <span><?= htmlspecialchars(getLoanCategoryLabel($loan['category'])) ?></span>
                <?php if ($loan['institution']): ?>
                <span>· <?= htmlspecialchars($loan['institution']) ?></span>
                <?php endif; ?>
                <?php if ($loan['interest_rate']): ?>
                <span>· <?= number_format($loan['interest_rate'], 2, ',', '.') ?>% a.m.</span>
                <?php endif; ?>
                <span>· Desde <?= formataDataBr($loan['first_due_date']) ?></span>
            </div>
        </div>
        <div class="detail-actions">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('editModal').classList.add('open')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                Editar
            </button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja encerrar este financiamento? Ele não aparecerá mais no dashboard.')">
                <input type="hidden" name="action_encerrar" value="1">
                <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Encerrar</button>
            </form>
        </div>
    </div>

    <!-- MÉTRICAS -->
    <div class="stat-grid" style="grid-template-columns: repeat(4,1fr); margin-bottom:20px;">
        <div class="stat-card purple">
            <div class="stat-label">Total Contratado</div>
            <div class="stat-value"><?= money($loan['total_amount']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" /></svg></div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Já Pago</div>
            <div class="stat-value"><?= money($loan['total_pago']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">Restante</div>
            <div class="stat-value"><?= money($loan['total_restante']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
        </div>
        <div class="stat-card accent">
            <div class="stat-label">Parcelas</div>
            <div class="stat-value"><?= $loan['parcelas_pagas'] ?>/<?= $loan['total_installments'] ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg></div>
        </div>
    </div>

    <!-- PROGRESS -->
    <div class="progress-block">
        <div class="progress-label">
            <span>Progresso de quitação</span>
            <span style="color:var(--purple);font-weight:700;"><?= number_format($percentual, 1, ',', '.') ?>%</span>
        </div>
        <div class="progress-bar-lg">
            <div class="progress-fill-lg" style="width:<?= min(100, $percentual) ?>%"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">
            <span>Início: <?= formataDataBr($loan['first_due_date']) ?></span>
            <span>Término previsto: <?= formataDataBr($loan['last_due_date']) ?></span>
        </div>
    </div>

    <!-- PRÓXIMA PARCELA + ANTECIPAÇÃO -->
    <?php if ($loan['proxima_parcela']): ?>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
        <div style="background:var(--purple-light);border:1px solid rgba(168,85,247,0.2);border-radius:10px;padding:12px 20px;display:flex;align-items:center;gap:14px;">
            <div>
                <div style="font-size:10px;color:var(--purple);font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">Próxima Parcela</div>
                <div style="font-size:16px;font-weight:700;"><?= money($loan['proxima_parcela']['amount']) ?></div>
                <div style="font-size:12px;color:var(--muted);">Parcela <?= $loan['proxima_parcela']['installment_number'] ?>/<?= $loan['total_installments'] ?> · <?= formataDataBr($loan['proxima_parcela']['due_date']) ?></div>
            </div>
        </div>
        <form method="post" class="anticipate-row">
            <input type="hidden" name="action_anticipate" value="1">
            <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
            <input type="number" name="anticipate_qty" value="1" min="1" max="<?= $loan['parcelas_restantes'] ?>">
            <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Antecipar as próximas ' + this.previousElementSibling.value + ' parcela(s)?')">
                Antecipar parcelas
            </button>
        </form>
        <?php if ($loan['parcelas_restantes'] > 0): ?>
        <form method="post" onsubmit="return confirm('Isso marcará TODAS as parcelas restantes como pagas. Confirmar?')">
            <input type="hidden" name="action_quitacao" value="1">
            <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
            <button type="submit" class="btn btn-success btn-sm">Quitar antecipadamente</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($loan['notes']): ?>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px 16px;font-size:13px;color:var(--muted);margin-bottom:24px;">
        <strong style="color:var(--ink);">Observações:</strong> <?= htmlspecialchars($loan['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- TABELA DE PARCELAS -->
    <div class="table-card">
        <div class="table-card-header">
            <div class="table-card-title">Histórico de Parcelas</div>
            <span style="font-size:12px;color:var(--muted);"><?= $loan['parcelas_pagas'] ?> pagas · <?= $loan['parcelas_restantes'] ?> restantes</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Vencimento</th>
                    <th>Valor</th>
                    <th>Pago em</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($loan['installments'] as $inst):
                $isOverdue = !$inst['paid'] && $inst['due_date'] < $hoje;
                $isCurrent = !$inst['paid'] && $loan['proxima_parcela'] && $inst['id'] == $loan['proxima_parcela']['id'];
            ?>
            <tr class="<?= $inst['paid'] ? 'td-paid' : ($isCurrent ? 'td-current' : '') ?>">
                <td class="td-num"><?= $inst['installment_number'] ?>/<?= $loan['total_installments'] ?></td>
                <td><?= formataDataBr($inst['due_date']) ?></td>
                <td style="font-weight:600;"><?= money($inst['amount']) ?></td>
                <td><?= $inst['payment_date'] ? formataDataBr($inst['payment_date']) : '<span style="color:var(--muted);">—</span>' ?></td>
                <td>
                    <?php if ($inst['paid']): ?>
                        <span class="badge badge-paid">✓ Pago</span>
                    <?php elseif ($isOverdue): ?>
                        <span class="badge badge-overdue">Atrasado</span>
                    <?php elseif ($isCurrent): ?>
                        <span class="badge" style="background:rgba(168,85,247,0.15);color:var(--purple);">Próxima</span>
                    <?php else: ?>
                        <span class="badge badge-pending">Pendente</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action_toggle_installment" value="1">
                        <input type="hidden" name="installment_id" value="<?= (int)$inst['id'] ?>">
                        <button type="submit" class="btn-icon-sm paid-btn" title="<?= $inst['paid'] ? 'Desmarcar' : 'Marcar como pago' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MODAL EDITAR -->
    <div class="modal-overlay" id="editModal" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="modal wide">
            <div class="modal-header">
                <span class="modal-title">Editar Financiamento</span>
                <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('open')">×</button>
            </div>
            <form method="post">
                <input type="hidden" name="action_edit_loan" value="1">
                <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="span-2">
                            <label>Nome *</label>
                            <input name="loan_name" type="text" value="<?= htmlspecialchars($loan['name']) ?>" required>
                        </div>
                        <div>
                            <label>Categoria</label>
                            <select name="loan_category">
                                <?php foreach (['moto'=>'Moto','carro'=>'Carro','casa'=>'Casa / Imóvel','emprestimo_pessoal'=>'Empréstimo Pessoal','consignado'=>'Consignado','outros'=>'Outros'] as $val => $lbl): ?>
                                <option value="<?= $val ?>"<?= $loan['category'] === $val ? ' selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Instituição</label>
                            <input name="loan_institution" type="text" value="<?= htmlspecialchars($loan['institution'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Taxa de Juros (% a.m.)</label>
                            <input name="loan_interest_rate" type="text" value="<?= $loan['interest_rate'] ? number_format($loan['interest_rate'], 2, ',', '.') : '' ?>">
                        </div>
                        <div class="span-2">
                            <label>Observações</label>
                            <textarea name="loan_notes"><?= htmlspecialchars($loan['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('editModal').classList.remove('open')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ======= LIST VIEW ======= -->

    <?php if (empty($loans)): ?>
    <div class="empty-state">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
        <h3>Nenhum financiamento cadastrado</h3>
        <p>Clique em "Novo Financiamento" no dashboard para começar.</p>
        <a href="dashboard.php" class="btn btn-primary" style="margin-top:20px;display:inline-flex;">Ir para o Dashboard</a>
    </div>
    <?php else: ?>

    <!-- MÉTRICAS CONSOLIDADAS -->
    <?php if ($loanMetrics['qtd_financiamentos'] > 0): ?>
    <div class="stat-grid" style="margin-bottom:28px;">
        <div class="stat-card purple">
            <div class="stat-label">Total Financiado</div>
            <div class="stat-value"><?= money($loanMetrics['total_contratado']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" /></svg></div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Já Pago</div>
            <div class="stat-value"><?= money($loanMetrics['total_pago']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">Restante</div>
            <div class="stat-value"><?= money($loanMetrics['total_restante']) ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
        </div>
        <div class="stat-card accent">
            <div class="stat-label">Parcelas</div>
            <div class="stat-value"><?= $loanMetrics['parcelas_pagas'] ?>/<?= $loanMetrics['total_parcelas'] ?></div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg></div>
        </div>
        <div class="stat-card <?= $loanMetrics['percentual_quitacao'] > 75 ? 'success' : ($loanMetrics['percentual_quitacao'] > 40 ? 'accent' : 'warning') ?>">
            <div class="stat-label">% Quitação</div>
            <div class="stat-value"><?= number_format($loanMetrics['percentual_quitacao'], 1, ',', '.') ?>%</div>
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" /></svg></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- CARDS DOS FINANCIAMENTOS -->
    <div class="loans-grid">
    <?php foreach ($loans as $loan):
        $pPagas = (int)$loan['parcelas_pagas_real'];
        $pTotal = (int)$loan['total_parcelas_real'];
        $pRestantes = $pTotal - $pPagas;
        $totalPago = (float)$loan['total_pago_real'];
        $totalContratado = (float)$loan['total_amount'];
        $totalRestante = max(0, $totalContratado - $totalPago);
        $percentual = $totalContratado > 0 ? round(($totalPago / $totalContratado) * 100, 1) : 0;
    ?>
    <div class="loan-card">
        <div class="loan-card-header">
            <div>
                <div class="loan-card-title"><?= htmlspecialchars($loan['name']) ?></div>
                <div class="loan-card-cat"><?= htmlspecialchars(getLoanCategoryLabel($loan['category'])) ?><?= $loan['institution'] ? ' · ' . htmlspecialchars($loan['institution']) : '' ?></div>
            </div>
            <span class="loan-card-badge"><?= $pPagas ?>/<?= $pTotal ?></span>
        </div>

        <div class="loan-card-metrics">
            <div class="loan-metric">
                <div class="loan-metric-label">Contratado</div>
                <div class="loan-metric-value" style="color:var(--purple)"><?= money($totalContratado) ?></div>
            </div>
            <div class="loan-metric">
                <div class="loan-metric-label">Pago</div>
                <div class="loan-metric-value" style="color:var(--success)"><?= money($totalPago) ?></div>
            </div>
            <div class="loan-metric">
                <div class="loan-metric-label">Restante</div>
                <div class="loan-metric-value" style="color:var(--warning)"><?= money($totalRestante) ?></div>
            </div>
        </div>

        <div class="progress-bar">
            <div class="progress-fill" style="width:<?= min(100, $percentual) ?>%"></div>
        </div>

        <div class="loan-card-footer">
            <div class="loan-next-info">
                <?php if ($loan['proxima_data']): ?>
                Próxima: <strong><?= money($loan['proxima_valor']) ?></strong> em <?= formataDataBr($loan['proxima_data']) ?>
                <?php else: ?>
                <span style="color:var(--success);">✓ Quitado</span>
                <?php endif; ?>
            </div>
            <a href="financiamentos.php?view=<?= (int)$loan['id'] ?>" class="btn btn-secondary btn-sm">
                Ver detalhes
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>
</main>

<script>
// Abrir modal de edição se mensagem e veio de edit
<?php if ($msg && $viewId && $msg_type === 'success' && isset($_POST['action_edit_loan'])): ?>
// nothing — modal closed after save
<?php endif; ?>
</script>
</body>
</html>
