<?php
session_start();
if (!isset($_SESSION['id'])) {
    header('Location: index.php');
    exit();
}

require_once 'conexao_pdo.php';
require_once 'crypto_helper.php';
require_once 'fatura_helper.php';

$current_user_id = $_SESSION['id'];
$current_user_name = $_SESSION['nome'] ?? 'Usuário';
$is_admin = isset($_SESSION['nivel']) && $_SESSION['nivel'] === 'adm';

date_default_timezone_set('America/Sao_Paulo');

function money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$msg = '';
$msgType = '';

// -------------------- AÇÕES POST --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Cadastrar novo cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'create') {
        $nome = trim($_POST['card_nome'] ?? '');
        $titular = trim($_POST['card_titular'] ?? '');
        $limite = str_replace(',', '.', trim($_POST['card_limite'] ?? ''));
        $dia_vencimento = (int)($_POST['card_dia_vencimento'] ?? 1);
        $dia_fechamento = (int)($_POST['card_dia_fechamento'] ?? 1);
        $armazenar = isset($_POST['card_armazenar']) && $_POST['card_armazenar'] === '1' ? 1 : 0;
        $cartao_adicional_de = !empty($_POST['card_adicional_de']) ? (int)$_POST['card_adicional_de'] : null;

        if (!$nome || !$titular || !$limite || !is_numeric($limite)) {
            $msg = 'Preencha todos os campos obrigatórios.'; $msgType = 'error';
        } elseif ($dia_vencimento < 1 || $dia_vencimento > 31 || $dia_fechamento < 1 || $dia_fechamento > 31) {
            $msg = 'Dias de vencimento e fechamento devem estar entre 1 e 31.'; $msgType = 'error';
        } else {
            $numero_enc = null; $ccv_enc = null; $validade_enc = null; $nome_imp_enc = null; $iv = null; $ultimos4 = null;

            if ($armazenar) {
                $numero = trim($_POST['card_numero'] ?? '');
                $ccv = trim($_POST['card_ccv'] ?? '');
                $validade = trim($_POST['card_validade'] ?? '');
                $nome_impresso = trim($_POST['card_nome_impresso'] ?? '');

                if ($numero) {
                    $ultimos4 = substr(preg_replace('/\D/', '', $numero), -4);
                    $enc = encryptData($numero);
                    $numero_enc = $enc['ciphertext'];
                    $iv = $enc['iv'];
                }
                if ($ccv) {
                    $enc = encryptData($ccv);
                    $ccv_enc = $enc['ciphertext'];
                    if (!$iv) $iv = $enc['iv'];
                }
                if ($validade) {
                    $enc = encryptData($validade);
                    $validade_enc = $enc['ciphertext'];
                    if (!$iv) $iv = $enc['iv'];
                }
                if ($nome_impresso) {
                    $enc = encryptData($nome_impresso);
                    $nome_imp_enc = $enc['ciphertext'];
                    if (!$iv) $iv = $enc['iv'];
                }
            }

            $stmt = $pdo->prepare("INSERT INTO credit_cards (user_id, nome, armazenar_dados, numero_encrypted, ccv_encrypted, validade_encrypted, nome_impresso_encrypted, ultimos4, iv, titular, limite, dia_vencimento, dia_fechamento, cartao_adicional_de)
                VALUES (:uid, :nome, :armazenar, :numero, :ccv, :validade, :nome_imp, :ultimos4, :iv, :titular, :limite, :dia_venc, :dia_fech, :adicional)");
            $stmt->execute([
                ':uid' => $current_user_id,
                ':nome' => $nome,
                ':armazenar' => $armazenar,
                ':numero' => $numero_enc,
                ':ccv' => $ccv_enc,
                ':validade' => $validade_enc,
                ':nome_imp' => $nome_imp_enc,
                ':ultimos4' => $ultimos4,
                ':iv' => $iv,
                ':titular' => $titular,
                ':limite' => $limite,
                ':dia_venc' => $dia_vencimento,
                ':dia_fech' => $dia_fechamento,
                ':adicional' => $cartao_adicional_de,
            ]);
            $msg = "Cartão \"{$nome}\" cadastrado com sucesso!"; $msgType = 'success';
        }
    }

    // Editar cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'edit') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        $nome = trim($_POST['card_nome'] ?? '');
        $titular = trim($_POST['card_titular'] ?? '');
        $limite = str_replace(',', '.', trim($_POST['card_limite'] ?? ''));
        $dia_vencimento = (int)($_POST['card_dia_vencimento'] ?? 1);
        $dia_fechamento = (int)($_POST['card_dia_fechamento'] ?? 1);
        $cartao_adicional_de = !empty($_POST['card_adicional_de']) ? (int)$_POST['card_adicional_de'] : null;

        if ($card_id && $nome && $titular && $limite && is_numeric($limite)) {
            $pdo->prepare("UPDATE credit_cards SET nome=:nome, titular=:titular, limite=:limite, dia_vencimento=:dia_venc, dia_fechamento=:dia_fech, cartao_adicional_de=:adicional WHERE id=:id AND user_id=:uid")
                ->execute([':nome'=>$nome, ':titular'=>$titular, ':limite'=>$limite, ':dia_venc'=>$dia_vencimento, ':dia_fech'=>$dia_fechamento, ':adicional'=>$cartao_adicional_de, ':id'=>$card_id, ':uid'=>$current_user_id]);
            $msg = "Cartão atualizado!"; $msgType = 'success';
        }
    }

    // Desativar/Ativar cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'toggle_status') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        if ($card_id) {
            $pdo->prepare("UPDATE credit_cards SET ativo = NOT ativo WHERE id=:id AND user_id=:uid")
                ->execute([':id'=>$card_id, ':uid'=>$current_user_id]);
            $msg = "Status do cartão atualizado!"; $msgType = 'success';
        }
    }

    // Excluir cartão (remove vínculos e o cartão)
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'delete_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        if ($card_id) {
            // Verificar propriedade
            $chk = $pdo->prepare("SELECT id FROM credit_cards WHERE id=:id AND user_id=:uid");
            $chk->execute([':id'=>$card_id, ':uid'=>$current_user_id]);
            if ($chk->fetch()) {
                // Desvincular cartões adicionais (apontar para null)
                $pdo->prepare("UPDATE credit_cards SET cartao_adicional_de = NULL WHERE cartao_adicional_de = :id")
                    ->execute([':id'=>$card_id]);
                // Remover vínculos expense_card_link (as expenses continuam existindo, apenas sem cartão)
                $pdo->prepare("DELETE FROM expense_card_link WHERE card_id = :id")
                    ->execute([':id'=>$card_id]);
                // Remover cartão
                $pdo->prepare("DELETE FROM credit_cards WHERE id=:id AND user_id=:uid")
                    ->execute([':id'=>$card_id, ':uid'=>$current_user_id]);
                $msg = "Cartão excluído com sucesso!"; $msgType = 'success';
                // Redirecionar para listagem
                header("Location: cartoes.php"); exit;
            } else {
                $msg = "Cartão não encontrado."; $msgType = 'error';
            }
        }
    }

    // Excluir lançamento individual de um cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'delete_expense') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        $card_view = (int)($_POST['card_view'] ?? 0);
        if ($expense_id) {
            // Verificar se a despesa pertence ao usuário
            $chk = $pdo->prepare("SELECT id FROM expenses WHERE id=:id AND user_id=:uid");
            $chk->execute([':id'=>$expense_id, ':uid'=>$current_user_id]);
            if ($chk->fetch()) {
                // Remover vínculo com cartão
                $pdo->prepare("DELETE FROM expense_card_link WHERE expense_id = :id")
                    ->execute([':id'=>$expense_id]);
                // Remover a despesa
                $pdo->prepare("DELETE FROM expenses WHERE id=:id AND user_id=:uid")
                    ->execute([':id'=>$expense_id, ':uid'=>$current_user_id]);
                $msg = "Lançamento excluído!"; $msgType = 'success';
            }
        }
        if ($card_view) { header("Location: cartoes.php?view={$card_view}"); exit; }
    }

    // Limpar cartão — excluir TODOS os lançamentos vinculados
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'clear_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        if ($card_id) {
            $chk = $pdo->prepare("SELECT id FROM credit_cards WHERE id=:id AND user_id=:uid");
            $chk->execute([':id'=>$card_id, ':uid'=>$current_user_id]);
            if ($chk->fetch()) {
                // Buscar todas as expenses vinculadas
                $expIds = $pdo->prepare("SELECT expense_id FROM expense_card_link WHERE card_id = :cid");
                $expIds->execute([':cid'=>$card_id]);
                $ids = $expIds->fetchAll(PDO::FETCH_COLUMN);
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM expense_card_link WHERE card_id = ?")->execute([$card_id]);
                    $pdo->prepare("DELETE FROM expenses WHERE id IN ({$placeholders}) AND user_id = ?")->execute(array_merge($ids, [$current_user_id]));
                }
                $msg = "Todos os lançamentos do cartão foram removidos!"; $msgType = 'success';
            }
        }
        header("Location: cartoes.php?view={$card_id}"); exit;
    }

    // Toggle pago/pendente de lançamento individual do cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'toggle_expense_paid') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        $card_view = (int)($_POST['card_view'] ?? 0);
        if ($expense_id) {
            $chk = $pdo->prepare("SELECT id, paid FROM expenses WHERE id=:id AND user_id=:uid");
            $chk->execute([':id'=>$expense_id, ':uid'=>$current_user_id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $newPaid = $row['paid'] ? 0 : 1;
                $pdo->prepare("UPDATE expenses SET paid=:paid, payment_date=CASE WHEN :paid2=1 THEN COALESCE(payment_date, CURDATE()) ELSE NULL END WHERE id=:id AND user_id=:uid")
                    ->execute([':paid'=>$newPaid, ':paid2'=>$newPaid, ':id'=>$expense_id, ':uid'=>$current_user_id]);
            }
        }
        if ($card_view) { header("Location: cartoes.php?view={$card_view}"); exit; }
    }

    // Editar lançamento individual do cartão
    if (isset($_POST['action_card']) && $_POST['action_card'] === 'edit_expense') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        $card_view = (int)($_POST['card_view'] ?? 0);
        $exp_name = trim($_POST['exp_name'] ?? '');
        $exp_amount = str_replace(',', '.', trim($_POST['exp_amount'] ?? ''));
        if ($expense_id && $exp_name && $exp_amount && is_numeric($exp_amount)) {
            $pdo->prepare("UPDATE expenses SET name=:name, amount=:amount WHERE id=:id AND user_id=:uid")
                ->execute([':name'=>$exp_name, ':amount'=>$exp_amount, ':id'=>$expense_id, ':uid'=>$current_user_id]);
            $msg = "Lançamento atualizado!"; $msgType = 'success';
        }
        if ($card_view) { header("Location: cartoes.php?view={$card_view}"); exit; }
    }
}

// -------------------- DADOS --------------------
$cards = $pdo->prepare("SELECT * FROM credit_cards WHERE user_id = :uid ORDER BY ativo DESC, nome ASC");
$cards->execute([':uid' => $current_user_id]);
$allCards = $cards->fetchAll(PDO::FETCH_ASSOC);

// Calcular utilização de cada cartão
$cardsWithUsage = [];
foreach ($allCards as $card) {
    $usage = getCardUtilization($pdo, $card['id']);
    $card['usado'] = $usage['usado'];
    $card['disponivel'] = $usage['disponivel'];
    $card['percentual'] = $usage['percentual'];
    $card['limite_efetivo'] = $usage['limite'];
    $cardsWithUsage[] = $card;
}

$metrics = getConsolidatedCardMetrics($pdo, $current_user_id);

// Detalhes de um cartão específico
$viewCard = null;
$viewFaturas = [];
$viewEvolucao = [];
$viewCompras = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    $stmt = $pdo->prepare("SELECT * FROM credit_cards WHERE id=:id AND user_id=:uid");
    $stmt->execute([':id'=>$viewId, ':uid'=>$current_user_id]);
    $viewCard = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($viewCard) {
        $usage = getCardUtilization($pdo, $viewCard['id']);
        $viewCard = array_merge($viewCard, $usage);
        $viewFaturas = getProximasFaturas($pdo, $viewCard['id']);
        $viewEvolucao = getEvolucaoGastosCartao($pdo, $viewCard['id']);

        // Histórico de compras
        $stmtCompras = $pdo->prepare("
            SELECT e.*, ecl.fatura_period
            FROM expenses e
            JOIN expense_card_link ecl ON ecl.expense_id = e.id
            WHERE ecl.card_id = :card_id
            ORDER BY e.due_date DESC
            LIMIT 50
        ");
        $stmtCompras->execute([':card_id' => $viewCard['id']]);
        $viewCompras = $stmtCompras->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Cartões de Crédito</title>
<link rel="icon" href="favicon.png" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink: #e2e8f0;
    --bg: #06080f;
    --surface: #0d1117;
    --surface2: #161b26;
    --accent: #00d4ff;
    --accent-hover: #00b8e6;
    --accent-light: rgba(0,212,255,0.1);
    --success: #00ff88;
    --success-light: rgba(0,255,136,0.1);
    --danger: #ff2d55;
    --danger-light: rgba(255,45,85,0.1);
    --warning: #ffd600;
    --warning-light: rgba(255,214,0,0.1);
    --purple: #a855f7;
    --purple-light: rgba(168,85,247,0.1);
    --muted: #64748b;
    --border: rgba(255,255,255,0.08);
    --glow-accent: 0 0 20px rgba(0,212,255,0.15);
    --glow-danger: 0 0 20px rgba(255,45,85,0.15);
    --glow-success: 0 0 20px rgba(0,255,136,0.15);
    --glow-warning: 0 0 20px rgba(255,214,0,0.15);
    --glow-purple: 0 0 20px rgba(168,85,247,0.15);
    --sidebar-w: 180px;
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

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 10% 0%, rgba(0,212,255,0.06) 0%, transparent 50%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(168,85,247,0.04) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
    z-index: 0;
}

/* ===== SIDEBAR ===== */
.sidebar {
    width: var(--sidebar-w);
    background: #080b12;
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 100;
    transition: width 0.3s;
    border-right: 1px solid rgba(0,212,255,0.1);
}

.sidebar-logo { padding: 14px 10px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.07); }
.sidebar-logo-icon { width: 28px; height: 28px; background: rgba(0,212,255,0.15); border: 1px solid rgba(0,212,255,0.3); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 0 15px rgba(0,212,255,0.2); }
.sidebar-logo-icon svg { width: 15px; height: 15px; color: var(--accent); }
.sidebar-logo-text { font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; color: #fff; white-space: nowrap; }

.sidebar-section { padding: 12px 8px 4px; }
.sidebar-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.3); padding: 0 8px; margin-bottom: 6px; }

.sidebar-nav { padding: 0 8px; display: flex; flex-direction: column; gap: 2px; }

.nav-item {
    display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px;
    color: rgba(255,255,255,0.6); text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.15s;
}
.nav-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
.nav-item.active { background: rgba(0,212,255,0.12); color: var(--accent); border: 1px solid rgba(0,212,255,0.25); box-shadow: 0 0 12px rgba(0,212,255,0.1); }
.nav-item svg { width: 14px; height: 14px; flex-shrink: 0; }

.sidebar-footer { padding: 10px 8px; border-top: 1px solid rgba(255,255,255,0.07); margin-top: auto; }
.user-info { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; margin-bottom: 8px; }
.user-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 11px; color: rgba(255,255,255,0.4); }
.sidebar-actions { display: flex; flex-direction: column; gap: 4px; }
.sidebar-action { display: flex; align-items: center; gap: 6px; padding: 5px 8px; border-radius: 8px; color: rgba(255,255,255,0.6); text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.15s; border: none; background: none; cursor: pointer; width: 100%; }
.sidebar-action:hover { background: rgba(255,255,255,0.07); color: #fff; }
.sidebar-action svg { width: 13px; height: 13px; }

/* ===== MAIN ===== */
.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    min-height: 100vh;
    position: relative;
    z-index: 1;
}

.topbar {
    padding: 20px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: rgba(13,17,23,0.6);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar h1 { font-size: 18px; font-weight: 700; }
.topbar p { font-size: 12px; color: var(--muted); margin-top: 2px; }
.topbar-right { display: flex; align-items: center; gap: 10px; }

.content { padding: 24px 32px; }

/* ===== STAT CARDS ===== */
.stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px; }

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 18px 20px;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border);
    transition: all 0.2s;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.stat-card.purple { border-color: rgba(168,85,247,0.2); }
.stat-card.purple:hover { box-shadow: var(--glow-purple); }
.stat-card.purple::before { background: linear-gradient(90deg, var(--purple), transparent); }
.stat-card.accent { border-color: rgba(0,212,255,0.2); }
.stat-card.accent:hover { box-shadow: var(--glow-accent); }
.stat-card.accent::before { background: linear-gradient(90deg, var(--accent), transparent); }
.stat-card.success { border-color: rgba(0,255,136,0.2); }
.stat-card.success:hover { box-shadow: var(--glow-success); }
.stat-card.success::before { background: linear-gradient(90deg, var(--success), transparent); }
.stat-card.danger { border-color: rgba(255,45,85,0.2); }
.stat-card.danger:hover { box-shadow: var(--glow-danger); }
.stat-card.danger::before { background: linear-gradient(90deg, var(--danger), transparent); }
.stat-card.warning { border-color: rgba(255,214,0,0.2); }
.stat-card.warning:hover { box-shadow: var(--glow-warning); }
.stat-card.warning::before { background: linear-gradient(90deg, var(--warning), transparent); }

.stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 6px; }
.stat-value { font-size: 20px; font-weight: 700; font-family: 'Space Mono', monospace; }
.stat-icon { position: absolute; top: 16px; right: 16px; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.stat-icon svg { width: 18px; height: 18px; }
.stat-card.purple .stat-icon { background: var(--purple-light); color: var(--purple); }
.stat-card.accent .stat-icon { background: var(--accent-light); color: var(--accent); }
.stat-card.success .stat-icon { background: var(--success-light); color: var(--success); }
.stat-card.danger .stat-icon { background: var(--danger-light); color: var(--danger); }
.stat-card.warning .stat-icon { background: var(--warning-light); color: var(--warning); }

/* ===== CARDS GRID ===== */
.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; margin-bottom: 24px; }

.credit-card-visual {
    background: var(--surface);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    transition: all 0.2s;
    cursor: pointer;
    text-decoration: none;
    color: var(--ink);
    display: block;
}
.credit-card-visual:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(0,0,0,0.3), var(--glow-purple); border-color: rgba(168,85,247,0.3); }
.credit-card-visual.inactive { opacity: 0.5; }

.cc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.cc-name { font-size: 16px; font-weight: 700; }
.cc-chip { width: 40px; height: 28px; background: linear-gradient(135deg, #ffd600, #ff9500); border-radius: 6px; }

.cc-number { font-family: 'Space Mono', monospace; font-size: 15px; letter-spacing: 2px; color: rgba(255,255,255,0.7); margin-bottom: 16px; }

.cc-footer { display: flex; justify-content: space-between; align-items: flex-end; }
.cc-titular { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
.cc-titular-name { font-size: 13px; font-weight: 600; color: var(--ink); }

.cc-usage-bar { width: 100%; height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; margin-top: 16px; overflow: hidden; }
.cc-usage-fill { height: 100%; border-radius: 3px; transition: width 0.5s ease; }
.cc-usage-fill.low { background: var(--success); }
.cc-usage-fill.medium { background: var(--warning); }
.cc-usage-fill.high { background: var(--danger); }

.cc-usage-info { display: flex; justify-content: space-between; margin-top: 8px; font-size: 11px; color: var(--muted); }
.cc-usage-info strong { font-family: 'Space Mono', monospace; }

.cc-badge {
    position: absolute; top: 12px; right: 12px;
    padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 600;
}
.cc-badge.active { background: var(--success-light); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.cc-badge.inactive { background: var(--danger-light); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }
.cc-badge.adicional { background: var(--purple-light); color: var(--purple); border: 1px solid rgba(168,85,247,0.2); }

/* ===== CARD DETAIL VIEW ===== */
.detail-section { margin-bottom: 24px; }
.detail-section h2 { font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.detail-section h2 svg { width: 20px; height: 20px; color: var(--purple); }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }

.detail-panel {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
}

.detail-panel h3 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 12px; }

.data-table { width: 100%; border-collapse: collapse; }
.data-table th { padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); text-align: left; background: var(--surface2); border-bottom: 1px solid var(--border); }
.data-table td { padding: 11px 16px; font-size: 13px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(0,212,255,0.03); }

.item-amount { font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; }
.item-date { color: var(--muted); font-size: 12px; }

.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge.paid { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.badge.unpaid { background: rgba(255,45,85,0.1); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }

.chart-container { background: var(--surface); border-radius: var(--radius); padding: 20px; border: 1px solid var(--border); }
.chart-container canvas { max-height: 300px; }

.back-link {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
    background: var(--surface2); border: 1px solid var(--border); border-radius: 8px;
    color: var(--ink); text-decoration: none; font-size: 13px; font-weight: 500;
    transition: all 0.15s; margin-bottom: 20px;
}
.back-link:hover { background: rgba(0,212,255,0.08); border-color: var(--accent); color: var(--accent); }
.back-link svg { width: 14px; height: 14px; }

/* ===== MODAL ===== */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.open { display: flex; }
.modal { background: var(--surface); border-radius: 16px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.4), 0 0 40px rgba(168,85,247,0.05); border: 1px solid rgba(168,85,247,0.1); animation: modalIn 0.2s ease; }
@keyframes modalIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-title { font-size: 15px; font-weight: 700; }
.modal-close { width: 30px; height: 30px; border-radius: 8px; border: none; background: var(--surface2); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; font-size: 18px; line-height: 1; color: var(--muted); }
.modal-close:hover { background: var(--border); color: var(--ink); }
.modal-body { padding: 20px 24px; }
.modal-footer { padding: 16px 24px 20px; display: flex; align-items: center; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--border); }

.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 4px; }
.form-group input, .form-group select {
    width: 100%; padding: 9px 10px; border: 1.5px solid var(--border); border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 13px; color: var(--ink); background: var(--surface2);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,212,255,0.1); }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.sensitive-fields { border: 1px solid rgba(168,85,247,0.2); border-radius: 10px; padding: 16px; margin-bottom: 14px; background: rgba(168,85,247,0.03); }
.sensitive-fields .section-label { font-size: 11px; font-weight: 600; color: var(--purple); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }

.btn-save { display: inline-flex; align-items: center; gap: 6px; padding: 9px 20px; background: var(--accent); color: #fff; border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-save:hover { background: var(--accent-hover); }
.btn-cancel { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; background: var(--surface2); color: var(--ink); border: 1px solid var(--border); border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s; }
.btn-cancel:hover { background: var(--border); }

.btn-add-card {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
    background: linear-gradient(135deg, var(--purple), var(--accent)); color: #fff; border: none; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s;
    box-shadow: 0 0 20px rgba(168,85,247,0.2);
}
.btn-add-card:hover { box-shadow: 0 0 30px rgba(168,85,247,0.3); transform: translateY(-1px); }
.btn-add-card svg { width: 16px; height: 16px; }

.msg-box { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
.msg-box.success { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.25); }
.msg-box.error { background: rgba(255,45,85,0.1); color: var(--danger); border: 1px solid rgba(255,45,85,0.25); }

.empty-state { padding: 60px 32px; text-align: center; color: var(--muted); }
.empty-state svg { width: 48px; height: 48px; opacity: 0.3; margin-bottom: 12px; }
.empty-state p { font-size: 14px; }

/* ===== LIGHT THEME ===== */
body.light { --ink: #1e293b; --bg: #f0f2f7; --surface: #ffffff; --surface2: #f8fafc; --accent: #2563eb; --accent-hover: #1d4ed8; --accent-light: rgba(37,99,235,0.1); --success: #059669; --success-light: rgba(5,150,105,0.1); --danger: #dc2626; --danger-light: rgba(220,38,38,0.1); --warning: #d97706; --warning-light: rgba(217,119,6,0.1); --purple: #7c3aed; --purple-light: rgba(124,58,237,0.1); --muted: #64748b; --border: #e5e7eb; }
body.light::before, body.light::after { display: none; }
body.light .sidebar { background: #0d1117; border-right: 1px solid rgba(255,255,255,0.07); }
body.light .sidebar-logo-icon { background: var(--accent); border-color: transparent; box-shadow: none; }
body.light .sidebar-logo-icon svg { color: #fff; }
body.light .nav-item.active { background: var(--accent); color: #fff; border-color: transparent; box-shadow: none; }
body.light .topbar { background: var(--surface); backdrop-filter: none; }
body.light .stat-card { border-color: var(--border); }
body.light .data-table tbody tr:hover { background: #fafbfc; }
body.light .cc-number { color: rgba(30,41,59,0.5); }
body.light .cc-usage-bar { background: rgba(0,0,0,0.06); }
body.light .credit-card-visual:hover { box-shadow: 0 8px 32px rgba(0,0,0,0.1); }

/* ===== RESPONSIVE ===== */
@media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(3, 1fr); } .detail-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .sidebar { width: 60px; }
    .sidebar-logo-text, .sidebar-section-label, .nav-item span, .user-name, .user-role, .sidebar-action span { display: none; }
    .main { margin-left: 60px; }
    .topbar { padding: 12px 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .cards-grid { grid-template-columns: 1fr; }
    .content { padding: 16px; }
}

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
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
        <div class="sidebar-section-label">Menu</div>
    </div>

    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="cartoes.php" class="nav-item active">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
            <span>Cartões</span>
        </a>
        <a href="compartilhamento.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>Compartilhamento</span>
        </a>
        <?php if ($is_admin): ?>
        <a href="usuarios.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <span>Usuários</span>
        </a>
        <?php endif; ?>
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
            <a href="dashboard.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                <span>Voltar</span>
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

<!-- ===== MAIN ===== -->
<main class="main">

    <div class="topbar">
        <div>
            <h1><?= $viewCard ? safe($viewCard['nome']) : 'Cartões de Crédito' ?></h1>
            <p><?= $viewCard ? 'Detalhes do cartão' : 'Gerencie seus cartões de crédito' ?></p>
        </div>
        <div class="topbar-right">
            <button class="btn-theme-toggle" id="themeToggle" title="Alternar tema" onclick="toggleTheme()" style="width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;font-size:16px;">
                <span id="themeIcon">☀️</span>
            </button>
            <?php if (!$viewCard): ?>
            <button class="btn-add-card" onclick="document.getElementById('cardModal').classList.add('open')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Novo cartão
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

        <?php if ($msg): ?>
        <div class="msg-box <?= $msgType ?>"><?= safe($msg) ?></div>
        <?php endif; ?>

        <?php if ($viewCard): ?>
        <!-- ===== DETALHE DO CARTÃO ===== -->
        <a href="cartoes.php" class="back-link">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Voltar para cartões
        </a>

        <!-- Indicadores do cartão -->
        <div class="stat-grid" style="grid-template-columns: repeat(4, 1fr);">
            <div class="stat-card purple">
                <div class="stat-label">Limite total</div>
                <div class="stat-value"><?= money($viewCard['limite']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Valor utilizado</div>
                <div class="stat-value"><?= money($viewCard['usado']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" /></svg>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Disponível</div>
                <div class="stat-value"><?= money($viewCard['disponivel']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
            </div>
            <div class="stat-card <?= $viewCard['percentual'] > 80 ? 'danger' : ($viewCard['percentual'] > 50 ? 'warning' : 'accent') ?>">
                <div class="stat-label">% Utilização</div>
                <div class="stat-value"><?= number_format($viewCard['percentual'], 1, ',', '.') ?>%</div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" /><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" /></svg>
                </div>
            </div>
        </div>

        <!-- Info do cartão -->
        <div class="detail-grid">
            <div class="detail-panel">
                <h3>Informações do Cartão</h3>
                <table class="data-table">
                    <tr><td style="color:var(--muted)">Titular</td><td><?= safe($viewCard['titular']) ?></td></tr>
                    <tr><td style="color:var(--muted)">Número</td><td style="font-family:'Space Mono',monospace"><?= maskCardNumber($viewCard['ultimos4']) ?></td></tr>
                    <tr><td style="color:var(--muted)">Dia do fechamento</td><td><?= $viewCard['dia_fechamento'] ?></td></tr>
                    <tr><td style="color:var(--muted)">Dia do vencimento</td><td><?= $viewCard['dia_vencimento'] ?></td></tr>
                    <tr><td style="color:var(--muted)">Status</td><td><span class="badge <?= $viewCard['ativo'] ? 'paid' : 'unpaid' ?>"><?= $viewCard['ativo'] ? 'Ativo' : 'Inativo' ?></span></td></tr>
                    <?php if ($viewCard['cartao_adicional_de']): ?>
                    <tr><td style="color:var(--muted)">Cartão adicional de</td><td>
                        <?php
                        $parent = $pdo->prepare("SELECT nome FROM credit_cards WHERE id=:id");
                        $parent->execute([':id'=>$viewCard['cartao_adicional_de']]);
                        echo safe($parent->fetchColumn());
                        ?>
                    </td></tr>
                    <?php endif; ?>
                </table>

                <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn-cancel" style="color:var(--accent);border-color:rgba(0,212,255,0.3);" onclick="openEditCardModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        Editar cartão
                    </button>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action_card" value="toggle_status">
                        <input type="hidden" name="card_id" value="<?= $viewCard['id'] ?>">
                        <button type="submit" class="btn-cancel" onclick="return confirm('<?= $viewCard['ativo'] ? 'Desativar' : 'Ativar' ?> este cartão?')">
                            <?= $viewCard['ativo'] ? 'Desativar' : 'Ativar' ?> cartão
                        </button>
                    </form>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action_card" value="delete_card">
                        <input type="hidden" name="card_id" value="<?= $viewCard['id'] ?>">
                        <button type="submit" class="btn-cancel" style="color:var(--danger);border-color:rgba(255,45,85,0.3);" onclick="return confirm('Tem certeza que deseja EXCLUIR este cartão? Os lançamentos vinculados serão desvinculados mas não apagados.')">
                            Excluir cartão
                        </button>
                    </form>
                </div>
            </div>

            <!-- Próximas faturas -->
            <div class="detail-panel">
                <h3>Próximas Faturas</h3>
                <?php if ($viewFaturas): ?>
                <table class="data-table">
                    <thead><tr><th>Fatura</th><th>Lançamentos</th><th>Total Previsto</th></tr></thead>
                    <tbody>
                    <?php foreach ($viewFaturas as $fat): ?>
                    <?php
                        // Contar lançamentos e parcelas desta fatura
                        $stmtFatCount = $pdo->prepare("SELECT COUNT(*) as qtd FROM expense_card_link ecl JOIN expenses e ON e.id = ecl.expense_id WHERE ecl.card_id = :cid AND ecl.fatura_period = :period");
                        $stmtFatCount->execute([':cid'=>$viewCard['id'], ':period'=>$fat['period']]);
                        $fatCount = (int)$stmtFatCount->fetchColumn();
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?php
                                $dtFat = DateTime::createFromFormat('Y-m-d', $fat['period'] . '-01');
                                if ($dtFat) {
                                    $meses = ['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
                                    echo $meses[(int)$dtFat->format('n') - 1] . '/' . $dtFat->format('Y');
                                } else {
                                    echo $fat['period'];
                                }
                            ?></div>
                            <?php
                                // Calcular data de vencimento efetiva deste mês
                                $fatY = (int)substr($fat['period'], 0, 4);
                                $fatM = (int)substr($fat['period'], 5, 2);
                                $lastDayFat = (int)(new DateTime("$fatY-$fatM-01"))->format('t');
                                $diaVencEfetivo = min((int)$viewCard['dia_vencimento'], $lastDayFat);
                            ?>
                            <div style="font-size:11px;color:var(--muted);">Venc. <?= sprintf('%02d/%02d/%04d', $diaVencEfetivo, $fatM, $fatY) ?></div>
                        </td>
                        <td style="text-align:center;"><span class="badge" style="background:var(--accent-light);color:var(--accent);border:1px solid rgba(0,212,255,0.2);"><?= $fatCount ?> itens</span></td>
                        <td class="item-amount" style="color:var(--danger)"><?= money($fat['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:var(--muted); font-size:13px; padding:20px 0; text-align:center;">Nenhuma fatura futura</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Evolução de gastos -->
        <?php if ($viewEvolucao): ?>
        <div class="detail-section">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>
                Evolução dos Gastos
            </h2>
            <div class="chart-container">
                <canvas id="evolucaoChart" height="250"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Histórico de compras -->
        <div class="detail-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h2 style="margin-bottom:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                    Histórico de Compras
                </h2>
                <?php if ($viewCompras): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action_card" value="clear_card">
                    <input type="hidden" name="card_id" value="<?= $viewCard['id'] ?>">
                    <button type="submit" class="btn-cancel" style="color:var(--danger);border-color:rgba(255,45,85,0.3);font-size:12px;padding:6px 14px;" onclick="return confirm('ATENÇÃO: Esta ação irá EXCLUIR PERMANENTEMENTE todos os lançamentos e parcelas vinculados a este cartão. Deseja continuar?')">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        Limpar Cartão
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($viewCompras): ?>
            <div class="detail-panel" style="overflow-x:auto;">
                <table class="data-table">
                    <thead><tr><th>Descrição</th><th>Valor</th><th>Data</th><th>Fatura</th><th>Status</th><th style="text-align:center;">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($viewCompras as $compra): ?>
                    <tr id="row-<?= (int)$compra['id'] ?>">
                        <td>
                            <span class="display-field"><?= safe($compra['name']) ?></span>
                            <input type="text" class="edit-field-input" name="exp_name" value="<?= safe($compra['name']) ?>" style="display:none;width:100%;padding:6px 8px;border:1.5px solid var(--border);border-radius:6px;font-family:'Sora',sans-serif;font-size:12px;color:var(--ink);background:var(--surface2);">
                        </td>
                        <td>
                            <span class="display-field item-amount"><?= money($compra['amount']) ?></span>
                            <input type="text" class="edit-field-input" name="exp_amount" value="<?= number_format((float)$compra['amount'], 2, ',', '') ?>" style="display:none;width:90px;padding:6px 8px;border:1.5px solid var(--border);border-radius:6px;font-family:'Space Mono',monospace;font-size:12px;color:var(--ink);background:var(--surface2);">
                        </td>
                        <td class="item-date"><?php
                            $dt = new DateTime($compra['due_date']);
                            echo $dt->format('d/m/Y');
                        ?></td>
                        <td class="item-date"><?php
                            $dt = DateTime::createFromFormat('Y-m-d', $compra['fatura_period'] . '-01');
                            echo $dt ? mb_strtoupper($dt->format('M/Y'), 'UTF-8') : $compra['fatura_period'];
                        ?></td>
                        <td>
                            <span class="badge <?= $compra['paid'] ? 'paid' : 'unpaid' ?>"><?= $compra['paid'] ? 'Pago' : 'Pendente' ?></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;align-items:center;">
                                <!-- Botão Alternar Pago/Pendente -->
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action_card" value="toggle_expense_paid">
                                    <input type="hidden" name="expense_id" value="<?= (int)$compra['id'] ?>">
                                    <input type="hidden" name="card_view" value="<?= $viewCard['id'] ?>">
                                    <?php if ($compra['paid']): ?>
                                    <button type="submit" title="Marcar como Pendente" style="width:28px;height:28px;border-radius:6px;border:1px solid rgba(0,212,255,0.3);background:rgba(0,212,255,0.1);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;color:var(--accent);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" title="Marcar como Pago" style="width:28px;height:28px;border-radius:6px;border:1px solid rgba(0,255,136,0.3);background:rgba(0,255,136,0.1);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;color:var(--success);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </button>
                                    <?php endif; ?>
                                </form>
                                <!-- Botão Editar (toggle inline) -->
                                <button type="button" title="Editar lançamento" onclick="toggleEditRow(<?= (int)$compra['id'] ?>)" style="width:28px;height:28px;border-radius:6px;border:1px solid rgba(255,214,0,0.2);background:rgba(255,214,0,0.08);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;color:var(--warning);">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <!-- Botão Salvar edição (hidden por padrão) -->
                                <form method="post" style="display:inline;" id="save-form-<?= (int)$compra['id'] ?>" class="save-edit-form" data-id="<?= (int)$compra['id'] ?>">
                                    <input type="hidden" name="action_card" value="edit_expense">
                                    <input type="hidden" name="expense_id" value="<?= (int)$compra['id'] ?>">
                                    <input type="hidden" name="card_view" value="<?= $viewCard['id'] ?>">
                                    <input type="hidden" name="exp_name" class="save-name" value="">
                                    <input type="hidden" name="exp_amount" class="save-amount" value="">
                                    <button type="submit" title="Salvar alterações" onclick="return prepareSaveEdit(<?= (int)$compra['id'] ?>)" style="display:none;width:28px;height:28px;border-radius:6px;border:1px solid rgba(0,255,136,0.2);background:rgba(0,255,136,0.08);cursor:pointer;align-items:center;justify-content:center;transition:all 0.15s;color:var(--success);" class="save-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </button>
                                </form>
                                <!-- Botão Excluir -->
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action_card" value="delete_expense">
                                    <input type="hidden" name="expense_id" value="<?= (int)$compra['id'] ?>">
                                    <input type="hidden" name="card_view" value="<?= $viewCard['id'] ?>">
                                    <button type="submit" title="Excluir lançamento" onclick="return confirm('Excluir este lançamento do cartão?')" style="width:28px;height:28px;border-radius:6px;border:1px solid rgba(255,45,85,0.2);background:rgba(255,45,85,0.08);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;color:var(--danger);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="detail-panel">
                <p style="color:var(--muted); font-size:13px; padding:20px 0; text-align:center;">Nenhuma compra registrada neste cartão</p>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ===== LISTAGEM DE CARTÕES ===== -->

        <!-- Stat cards consolidados -->
        <?php if ($cardsWithUsage): ?>
        <div class="stat-grid">
            <div class="stat-card purple">
                <div class="stat-label">Limite total</div>
                <div class="stat-value"><?= money($metrics['limite_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Comprometido</div>
                <div class="stat-value"><?= money($metrics['usado_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" /></svg>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Disponível</div>
                <div class="stat-value"><?= money($metrics['disponivel_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
            </div>
            <div class="stat-card accent">
                <div class="stat-label">Cartões ativos</div>
                <div class="stat-value"><?= $metrics['qtd_cartoes'] ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                </div>
            </div>
            <div class="stat-card <?= $metrics['percentual_geral'] > 80 ? 'danger' : ($metrics['percentual_geral'] > 50 ? 'warning' : 'accent') ?>">
                <div class="stat-label">% Utilização</div>
                <div class="stat-value"><?= number_format($metrics['percentual_geral'], 1, ',', '.') ?>%</div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" /><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" /></svg>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cards visuais -->
        <?php if ($cardsWithUsage): ?>
        <div class="cards-grid">
            <?php foreach ($cardsWithUsage as $c): ?>
            <a href="?view=<?= $c['id'] ?>" class="credit-card-visual <?= $c['ativo'] ? '' : 'inactive' ?>">
                <?php if ($c['cartao_adicional_de']): ?>
                <span class="cc-badge adicional">Adicional</span>
                <?php else: ?>
                <span class="cc-badge <?= $c['ativo'] ? 'active' : 'inactive' ?>"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span>
                <?php endif; ?>

                <div class="cc-header">
                    <span class="cc-name"><?= safe($c['nome']) ?></span>
                    <div class="cc-chip"></div>
                </div>

                <div class="cc-number"><?= maskCardNumber($c['ultimos4']) ?></div>

                <div class="cc-footer">
                    <div>
                        <div class="cc-titular">Titular</div>
                        <div class="cc-titular-name"><?= safe($c['titular']) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="cc-titular">Limite</div>
                        <div class="cc-titular-name" style="font-family:'Space Mono',monospace;"><?= money($c['limite']) ?></div>
                    </div>
                </div>

                <div class="cc-usage-bar">
                    <div class="cc-usage-fill <?= $c['percentual'] > 80 ? 'high' : ($c['percentual'] > 50 ? 'medium' : 'low') ?>" style="width: <?= min(100, $c['percentual']) ?>%"></div>
                </div>
                <div class="cc-usage-info">
                    <span>Usado: <strong><?= money($c['usado']) ?></strong></span>
                    <span>Disponível: <strong><?= money($c['disponivel']) ?></strong></span>
                    <span><strong><?= number_format($c['percentual'], 1, ',', '.') ?>%</strong></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
            <p>Nenhum cartão cadastrado</p>
            <p style="font-size:12px; margin-top:4px;">Clique em "Novo cartão" para começar</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /content -->
</main>

<!-- ===== MODAL EDITAR CARTÃO ===== -->
<?php if ($viewCard): ?>
<div class="modal-overlay" id="editCardModal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Editar Cartão — <?= safe($viewCard['nome']) ?></div>
            <button class="modal-close" onclick="document.getElementById('editCardModal').classList.remove('open')">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action_card" value="edit">
            <input type="hidden" name="card_id" value="<?= $viewCard['id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nome do cartão *</label>
                    <input type="text" name="card_nome" value="<?= safe($viewCard['nome']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Titular do cartão *</label>
                    <input type="text" name="card_titular" value="<?= safe($viewCard['titular']) ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Limite do cartão *</label>
                        <input type="text" name="card_limite" inputmode="decimal" value="<?= number_format((float)$viewCard['limite'], 2, ',', '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Cartão adicional de</label>
                        <select name="card_adicional_de">
                            <option value="">Nenhum (cartão principal)</option>
                            <?php foreach ($allCards as $c): ?>
                            <?php if ($c['ativo'] && !$c['cartao_adicional_de'] && $c['id'] != $viewCard['id']): ?>
                            <option value="<?= $c['id'] ?>" <?= ($viewCard['cartao_adicional_de'] == $c['id']) ? 'selected' : '' ?>><?= safe($c['nome']) ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Dia de vencimento da fatura *</label>
                        <input type="number" name="card_dia_vencimento" min="1" max="31" value="<?= (int)$viewCard['dia_vencimento'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Dia de fechamento da fatura *</label>
                        <input type="number" name="card_dia_fechamento" min="1" max="31" value="<?= (int)$viewCard['dia_fechamento'] ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editCardModal').classList.remove('open')">Cancelar</button>
                <button type="submit" class="btn-save">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===== MODAL NOVO CARTÃO ===== -->
<div class="modal-overlay" id="cardModal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Novo Cartão de Crédito</div>
            <button class="modal-close" onclick="document.getElementById('cardModal').classList.remove('open')">×</button>
        </div>
        <form method="post" id="cardForm">
            <input type="hidden" name="action_card" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nome do cartão *</label>
                    <input type="text" name="card_nome" placeholder="Ex: Nubank, Inter, C6 Bank" required>
                </div>

                <div class="form-group">
                    <label>Titular do cartão *</label>
                    <input type="text" name="card_titular" placeholder="Nome do titular" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Limite do cartão *</label>
                        <input type="text" name="card_limite" inputmode="decimal" placeholder="0,00" required>
                    </div>
                    <div class="form-group">
                        <label>Cartão adicional de</label>
                        <select name="card_adicional_de">
                            <option value="">Nenhum (cartão principal)</option>
                            <?php foreach ($allCards as $c): ?>
                            <?php if ($c['ativo'] && !$c['cartao_adicional_de']): ?>
                            <option value="<?= $c['id'] ?>"><?= safe($c['nome']) ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Dia de vencimento da fatura *</label>
                        <input type="number" name="card_dia_vencimento" min="1" max="31" value="10" required>
                    </div>
                    <div class="form-group">
                        <label>Dia de fechamento da fatura *</label>
                        <input type="number" name="card_dia_fechamento" min="1" max="31" value="3" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Armazenar dados completos do cartão?</label>
                    <select name="card_armazenar" id="cardArmazenar" onchange="toggleSensitiveFields()">
                        <option value="0">Não</option>
                        <option value="1">Sim</option>
                    </select>
                </div>

                <div class="sensitive-fields" id="sensitiveFields" style="display:none;">
                    <div class="section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        Dados sensíveis (criptografados)
                    </div>
                    <div class="form-group">
                        <label>Número do cartão</label>
                        <input type="text" name="card_numero" placeholder="0000 0000 0000 0000" maxlength="19">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>CCV</label>
                            <input type="text" name="card_ccv" placeholder="000" maxlength="4">
                        </div>
                        <div class="form-group">
                            <label>Data de validade</label>
                            <input type="text" name="card_validade" placeholder="MM/AA">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nome impresso no cartão</label>
                        <input type="text" name="card_nome_impresso" placeholder="NOME COMO NO CARTÃO" style="text-transform:uppercase;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="document.getElementById('cardModal').classList.remove('open')">Cancelar</button>
                <button type="submit" class="btn-save">Cadastrar cartão</button>
            </div>
        </form>
    </div>
</div>

<?php if ($viewCard && $viewEvolucao): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    const labels = <?= json_encode(array_map(function($e) {
        $dt = DateTime::createFromFormat('Y-m-d', $e['period'] . '-01');
        return $dt ? mb_strtoupper($dt->format('M/y'), 'UTF-8') : $e['period'];
    }, $viewEvolucao)) ?>;
    const data = <?= json_encode(array_map(fn($e) => (float)$e['total'], $viewEvolucao)) ?>;

    const light = document.body.classList.contains('light');
    new Chart(document.getElementById('evolucaoChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Gastos no cartão',
                data: data,
                backgroundColor: light ? 'rgba(124,58,237,0.15)' : 'rgba(168,85,247,0.2)',
                borderColor: light ? '#7c3aed' : '#a855f7',
                borderWidth: 1.5,
                borderRadius: 6,
                barPercentage: 0.5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2})
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { family: 'Sora', size: 10 }, color: light ? '#6b7280' : '#64748b' } },
                y: {
                    grid: { color: light ? '#f3f4f6' : 'rgba(255,255,255,0.05)' },
                    ticks: {
                        font: { family: 'Sora', size: 10 },
                        color: light ? '#6b7280' : '#64748b',
                        callback: v => 'R$ ' + v.toLocaleString('pt-BR')
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script>
// Theme
(function() {
    const saved = localStorage.getItem('finanzas_theme');
    if (saved === 'light') {
        document.body.classList.add('light');
        document.getElementById('themeIcon').textContent = '🌙';
    } else {
        document.getElementById('themeIcon').textContent = '☀️';
    }
})();

function toggleTheme() {
    const isLight = document.body.classList.toggle('light');
    document.getElementById('themeIcon').textContent = isLight ? '🌙' : '☀️';
    localStorage.setItem('finanzas_theme', isLight ? 'light' : 'dark');
}

function toggleSensitiveFields() {
    const sel = document.getElementById('cardArmazenar');
    document.getElementById('sensitiveFields').style.display = sel.value === '1' ? '' : 'none';
}

// Keyboard: Escape fecha modais
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('cardModal').classList.remove('open');
        var ecm = document.getElementById('editCardModal');
        if (ecm) ecm.classList.remove('open');
    }
});

// Abrir modal de editar cartão
function openEditCardModal() {
    var modal = document.getElementById('editCardModal');
    if (modal) modal.classList.add('open');
}

// Toggle edição inline no histórico de compras
function toggleEditRow(id) {
    var row = document.getElementById('row-' + id);
    if (!row) return;
    var displays = row.querySelectorAll('.display-field');
    var inputs = row.querySelectorAll('.edit-field-input');
    var saveBtn = row.querySelector('.save-btn');

    var isEditing = inputs[0].style.display !== 'none';
    displays.forEach(el => el.style.display = isEditing ? '' : 'none');
    inputs.forEach(el => el.style.display = isEditing ? 'none' : '');
    if (saveBtn) saveBtn.style.display = isEditing ? 'none' : 'inline-flex';
}

// Preparar dados para salvar edição
function prepareSaveEdit(id) {
    var row = document.getElementById('row-' + id);
    if (!row) return false;
    var nameInput = row.querySelector('input[name="exp_name"]');
    var amountInput = row.querySelector('input[name="exp_amount"]');
    var form = document.getElementById('save-form-' + id);
    if (form && nameInput && amountInput) {
        form.querySelector('.save-name').value = nameInput.value;
        form.querySelector('.save-amount').value = amountInput.value;
    }
    return true;
}
</script>
</body>
</html>
