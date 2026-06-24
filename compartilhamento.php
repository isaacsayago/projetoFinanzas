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

function money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$msg = '';
$msgType = '';

// -------------------- AÇÕES POST --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Trocar senha
    if (isset($_POST['action_change_password'])) {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha  = $_POST['nova_senha']  ?? '';
        $confirma    = $_POST['confirma_senha'] ?? '';
        $stmtU = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id");
        $stmtU->execute([':id' => $current_user_id]);
        $userRow = $stmtU->fetch(PDO::FETCH_ASSOC);
        if (!$userRow || !password_verify($senha_atual, $userRow['senha'])) {
            $msg = 'Senha atual incorreta.'; $msgType = 'error';
        } elseif (strlen($nova_senha) < 6) {
            $msg = 'A nova senha deve ter no mínimo 6 caracteres.'; $msgType = 'error';
        } elseif ($nova_senha !== $confirma) {
            $msg = 'As senhas não coincidem.'; $msgType = 'error';
        } else {
            $pdo->prepare("UPDATE usuarios SET senha = :s WHERE id = :id")
                ->execute([':s' => password_hash($nova_senha, PASSWORD_DEFAULT), ':id' => $current_user_id]);
            $msg = 'Senha alterada com sucesso!'; $msgType = 'success';
        }
    }

    // Enviar convite
    if (isset($_POST['action_share']) && $_POST['action_share'] === 'invite') {
        $email = trim($_POST['invite_email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Informe um e-mail válido.'; $msgType = 'error';
        } else {
            // Buscar usuário pelo email
            $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $invitee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invitee) {
                $msg = 'Nenhum usuário encontrado com este e-mail.'; $msgType = 'error';
            } elseif ($invitee['id'] == $current_user_id) {
                $msg = 'Você não pode convidar a si mesmo.'; $msgType = 'error';
            } else {
                // Verificar se já existe convite
                $check = $pdo->prepare("SELECT id, status FROM account_shares WHERE owner_id = :oid AND invitee_id = :iid");
                $check->execute([':oid' => $current_user_id, ':iid' => $invitee['id']]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($existing && $existing['status'] === 'pending') {
                    $msg = 'Já existe um convite pendente para este usuário.'; $msgType = 'error';
                } elseif ($existing && $existing['status'] === 'accepted') {
                    $msg = 'Você já compartilha sua conta com este usuário.'; $msgType = 'error';
                } else {
                    if ($existing && $existing['status'] === 'rejected') {
                        // Re-enviar convite rejeitado
                        $pdo->prepare("UPDATE account_shares SET status = 'pending', responded_at = NULL, created_at = NOW() WHERE id = :id")
                            ->execute([':id' => $existing['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO account_shares (owner_id, invitee_id) VALUES (:oid, :iid)")
                            ->execute([':oid' => $current_user_id, ':iid' => $invitee['id']]);
                    }

                    // Criar notificação para o convidado
                    $pdo->prepare("INSERT INTO notifications (user_id, tipo, mensagem, link, ref_id) VALUES (:uid, 'share_invite', :msg, 'compartilhamento.php', :ref)")
                        ->execute([
                            ':uid' => $invitee['id'],
                            ':msg' => safe($current_user_name) . ' quer compartilhar a conta com você.',
                            ':ref' => $pdo->lastInsertId() ?: $existing['id'],
                        ]);

                    $msg = "Convite enviado para {$invitee['nome']}!"; $msgType = 'success';
                }
            }
        }
    }

    // Aceitar/Rejeitar convite
    if (isset($_POST['action_share']) && in_array($_POST['action_share'], ['accept', 'reject'])) {
        $shareId = (int)($_POST['share_id'] ?? 0);
        $newStatus = $_POST['action_share'] === 'accept' ? 'accepted' : 'rejected';

        $stmt = $pdo->prepare("UPDATE account_shares SET status = :status, responded_at = NOW() WHERE id = :id AND invitee_id = :uid");
        $stmt->execute([':status' => $newStatus, ':id' => $shareId, ':uid' => $current_user_id]);

        if ($stmt->rowCount() > 0) {
            // Notificar o dono
            $owner = $pdo->prepare("SELECT owner_id FROM account_shares WHERE id = :id");
            $owner->execute([':id' => $shareId]);
            $ownerId = $owner->fetchColumn();

            $statusText = $newStatus === 'accepted' ? 'aceitou' : 'recusou';
            $pdo->prepare("INSERT INTO notifications (user_id, tipo, mensagem, link, ref_id) VALUES (:uid, 'share_response', :msg, 'compartilhamento.php', :ref)")
                ->execute([
                    ':uid' => $ownerId,
                    ':msg' => safe($current_user_name) . " {$statusText} seu convite de compartilhamento.",
                    ':ref' => $shareId,
                ]);

            $msg = $newStatus === 'accepted' ? 'Compartilhamento aceito!' : 'Convite recusado.';
            $msgType = 'success';
        }
    }

    // Cancelar compartilhamento
    if (isset($_POST['action_share']) && $_POST['action_share'] === 'cancel') {
        $shareId = (int)($_POST['share_id'] ?? 0);
        // Deletar permissões associadas e o compartilhamento
        $pdo->prepare("DELETE FROM share_permissions WHERE share_id = :sid")->execute([':sid' => $shareId]);
        $pdo->prepare("DELETE FROM account_shares WHERE id = :id AND (owner_id = :uid OR invitee_id = :uid2)")
            ->execute([':id' => $shareId, ':uid' => $current_user_id, ':uid2' => $current_user_id]);
        $msg = 'Compartilhamento removido.'; $msgType = 'success';
    }

    // Atualizar permissões
    if (isset($_POST['action_share']) && $_POST['action_share'] === 'update_permissions') {
        $shareId = (int)($_POST['share_id'] ?? 0);

        // Verificar que o usuário é o dono deste compartilhamento
        $check = $pdo->prepare("SELECT id FROM account_shares WHERE id = :id AND owner_id = :uid AND status = 'accepted'");
        $check->execute([':id' => $shareId, ':uid' => $current_user_id]);
        if ($check->fetch()) {
            // Remover permissões antigas
            $pdo->prepare("DELETE FROM share_permissions WHERE share_id = :sid")->execute([':sid' => $shareId]);

            // Adicionar novas permissões
            $types = $_POST['perm_types'] ?? [];
            $canEdit = isset($_POST['perm_can_edit']) ? 1 : 0;

            foreach ($types as $type) {
                if (in_array($type, ['card', 'expense', 'income', 'all'])) {
                    $pdo->prepare("INSERT INTO share_permissions (share_id, resource_type, can_view, can_edit) VALUES (:sid, :type, 1, :edit)")
                        ->execute([':sid' => $shareId, ':type' => $type, ':edit' => $canEdit]);
                }
            }
            $msg = 'Permissões atualizadas!'; $msgType = 'success';
        }
    }

    // Alternar conta compartilhada
    if (isset($_POST['action_share']) && $_POST['action_share'] === 'switch_account') {
        $shareOwnerId = (int)($_POST['view_user_id'] ?? 0);
        if ($shareOwnerId === 0) {
            unset($_SESSION['viewing_shared_user_id']);
            unset($_SESSION['viewing_shared_user_name']);
        } else {
            // Verificar que tem permissão
            $check = $pdo->prepare("SELECT u.nome FROM account_shares a JOIN usuarios u ON u.id = a.owner_id WHERE a.owner_id = :oid AND a.invitee_id = :iid AND a.status = 'accepted'");
            $check->execute([':oid' => $shareOwnerId, ':iid' => $current_user_id]);
            $sharedName = $check->fetchColumn();
            if ($sharedName) {
                $_SESSION['viewing_shared_user_id'] = $shareOwnerId;
                $_SESSION['viewing_shared_user_name'] = $sharedName;
            }
        }
        header('Location: dashboard.php');
        exit;
    }
}

// -------------------- DADOS --------------------
// Convites enviados por mim
$sentStmt = $pdo->prepare("
    SELECT a.*, u.nome as invitee_nome, u.email as invitee_email
    FROM account_shares a
    JOIN usuarios u ON u.id = a.invitee_id
    WHERE a.owner_id = :uid
    ORDER BY a.created_at DESC
");
$sentStmt->execute([':uid' => $current_user_id]);
$sentShares = $sentStmt->fetchAll(PDO::FETCH_ASSOC);

// Convites recebidos
$receivedStmt = $pdo->prepare("
    SELECT a.*, u.nome as owner_nome, u.email as owner_email
    FROM account_shares a
    JOIN usuarios u ON u.id = a.owner_id
    WHERE a.invitee_id = :uid
    ORDER BY a.created_at DESC
");
$receivedStmt->execute([':uid' => $current_user_id]);
$receivedShares = $receivedStmt->fetchAll(PDO::FETCH_ASSOC);

// Permissões dos compartilhamentos aceitos enviados por mim
$permissionsMap = [];
foreach ($sentShares as $s) {
    if ($s['status'] === 'accepted') {
        $permStmt = $pdo->prepare("SELECT * FROM share_permissions WHERE share_id = :sid");
        $permStmt->execute([':sid' => $s['id']]);
        $permissionsMap[$s['id']] = $permStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Contas compartilhadas comigo (aceitas)
$sharedAccounts = array_filter($receivedShares, fn($s) => $s['status'] === 'accepted');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Compartilhamento</title>
<link rel="icon" href="favicon.png" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink: #e2e8f0; --bg: #06080f; --surface: #0d1117; --surface2: #161b26;
    --accent: #00d4ff; --accent-hover: #00b8e6; --accent-light: rgba(0,212,255,0.1);
    --success: #00ff88; --success-light: rgba(0,255,136,0.1);
    --danger: #ff2d55; --danger-light: rgba(255,45,85,0.1);
    --warning: #ffd600; --warning-light: rgba(255,214,0,0.1);
    --purple: #a855f7; --purple-light: rgba(168,85,247,0.1);
    --muted: #64748b; --border: rgba(255,255,255,0.08);
    --glow-accent: 0 0 20px rgba(0,212,255,0.15);
    --glow-purple: 0 0 20px rgba(168,85,247,0.15);
    --sidebar-w: 180px; --radius: 12px;
}

body { font-family: 'Sora', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; font-size: 14px; line-height: 1.5; }
body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(ellipse 80% 50% at 10% 0%, rgba(0,212,255,0.06) 0%, transparent 50%), radial-gradient(ellipse 60% 40% at 90% 100%, rgba(168,85,247,0.04) 0%, transparent 50%); pointer-events: none; z-index: 0; }
body::after { content: ''; position: fixed; inset: 0; background-image: linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px); background-size: 60px 60px; pointer-events: none; z-index: 0; }

/* Sidebar */
.sidebar { width: var(--sidebar-w); background: #080b12; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; overflow: hidden; z-index: 100; transition: width 0.3s; border-right: 1px solid rgba(0,212,255,0.1); }
.sidebar-logo { padding: 14px 10px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.07); }
.sidebar-logo-icon { width: 28px; height: 28px; background: rgba(0,212,255,0.15); border: 1px solid rgba(0,212,255,0.3); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 0 15px rgba(0,212,255,0.2); }
.sidebar-logo-icon svg { width: 15px; height: 15px; color: var(--accent); }
.sidebar-logo-text { font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; color: #fff; white-space: nowrap; }
.sidebar-section { padding: 12px 8px 4px; }
.sidebar-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.3); padding: 0 8px; margin-bottom: 6px; }
.sidebar-nav { padding: 0 8px; display: flex; flex-direction: column; gap: 2px; }
.nav-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; color: rgba(255,255,255,0.6); text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.15s; }
.nav-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
.nav-item.active { background: rgba(0,212,255,0.12); color: var(--accent); border: 1px solid rgba(0,212,255,0.25); box-shadow: 0 0 12px rgba(0,212,255,0.1); }
.nav-item svg { width: 14px; height: 14px; flex-shrink: 0; }
.sidebar-footer { padding: 10px 8px; border-top: 1px solid rgba(255,255,255,0.07); margin-top: auto; }
.user-info { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; margin-bottom: 8px; }
.user-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12px; font-weight: 600; color: #fff; }
.user-role { font-size: 11px; color: rgba(255,255,255,0.4); }
.sidebar-actions { display: flex; flex-direction: column; gap: 4px; }
.sidebar-action { display: flex; align-items: center; gap: 6px; padding: 5px 8px; border-radius: 8px; color: rgba(255,255,255,0.6); text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.15s; border: none; background: none; cursor: pointer; width: 100%; }
.sidebar-action:hover { background: rgba(255,255,255,0.07); color: #fff; }
.sidebar-action svg { width: 13px; height: 13px; }

/* Main */
.main { margin-left: var(--sidebar-w); flex: 1; min-height: 100vh; position: relative; z-index: 1; }
.topbar { padding: 20px 32px; display: flex; align-items: center; justify-content: space-between; gap: 16px; background: rgba(13,17,23,0.6); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 50; }
.topbar h1 { font-size: 18px; font-weight: 700; }
.topbar p { font-size: 12px; color: var(--muted); margin-top: 2px; }
.content { padding: 24px 32px; }

/* Panels */
.section-panel { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 24px; overflow: hidden; }
.section-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.section-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.section-title svg { width: 18px; height: 18px; color: var(--accent); }
.section-body { padding: 20px; }
.section-count { font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 20px; background: var(--surface2); color: var(--muted); }

/* Tables */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th { padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); text-align: left; background: var(--surface2); border-bottom: 1px solid var(--border); }
.data-table td { padding: 11px 16px; font-size: 13px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(0,212,255,0.03); }

.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge.pending { background: var(--warning-light); color: var(--warning); border: 1px solid rgba(255,214,0,0.2); }
.badge.accepted { background: var(--success-light); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.badge.rejected { background: var(--danger-light); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }

/* Forms */
.invite-form { display: flex; gap: 10px; align-items: flex-end; }
.form-group { flex: 1; }
.form-group label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 4px; }
.form-group input, .form-group select { width: 100%; padding: 9px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; color: var(--ink); background: var(--surface2); transition: border-color 0.2s; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,212,255,0.1); }

.btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; background: var(--accent); color: #fff; border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-primary:hover { background: var(--accent-hover); }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; border: none; font-family: 'Sora', sans-serif; font-weight: 600; cursor: pointer; transition: all 0.15s; }
.btn-success { background: var(--success-light); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.btn-success:hover { background: var(--success); color: #0a0e1a; }
.btn-danger { background: var(--danger-light); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }
.btn-danger:hover { background: var(--danger); color: #fff; }
.btn-outline { background: var(--surface2); color: var(--ink); border: 1px solid var(--border); }
.btn-outline:hover { background: var(--border); }

/* Permissions */
.perm-grid { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.perm-check { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--surface2); border-radius: 8px; border: 1px solid var(--border); font-size: 12px; cursor: pointer; transition: all 0.15s; }
.perm-check:hover { border-color: var(--accent); }
.perm-check input[type="checkbox"] { accent-color: var(--accent); }

/* Shared accounts */
.shared-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.shared-card { background: var(--surface); border-radius: var(--radius); padding: 20px; border: 1px solid var(--border); transition: all 0.2s; }
.shared-card:hover { border-color: rgba(0,212,255,0.3); box-shadow: var(--glow-accent); }
.shared-card-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.shared-card-email { font-size: 12px; color: var(--muted); margin-bottom: 12px; }

.msg-box { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
.msg-box.success { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.25); }
.msg-box.error { background: rgba(255,45,85,0.1); color: var(--danger); border: 1px solid rgba(255,45,85,0.25); }

.empty-msg { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; }

/* Light theme */
body.light { --ink: #1e293b; --bg: #f0f2f7; --surface: #ffffff; --surface2: #f8fafc; --accent: #2563eb; --accent-hover: #1d4ed8; --accent-light: rgba(37,99,235,0.1); --success: #059669; --success-light: rgba(5,150,105,0.1); --danger: #dc2626; --danger-light: rgba(220,38,38,0.1); --warning: #d97706; --warning-light: rgba(217,119,6,0.1); --purple: #7c3aed; --purple-light: rgba(124,58,237,0.1); --muted: #64748b; --border: #e5e7eb; }
body.light::before, body.light::after { display: none; }
body.light .sidebar { background: #0d1117; border-right: 1px solid rgba(255,255,255,0.07); }
body.light .sidebar-logo-icon { background: var(--accent); border-color: transparent; box-shadow: none; }
body.light .sidebar-logo-icon svg { color: #fff; }
body.light .nav-item.active { background: var(--accent); color: #fff; border-color: transparent; box-shadow: none; }
body.light .topbar { background: var(--surface); backdrop-filter: none; }
body.light .data-table tbody tr:hover { background: #fafbfc; }

/* Responsive */
@media (max-width: 768px) {
    .sidebar { width: 60px; }
    .sidebar-logo-text, .sidebar-section-label, .nav-item span, .user-name, .user-role, .sidebar-action span { display: none; }
    .main { margin-left: 60px; }
    .topbar { padding: 12px 16px; }
    .content { padding: 16px; }
    .invite-form { flex-direction: column; }
    .shared-cards { grid-template-columns: 1fr; }
}

::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
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

    <div class="sidebar-section">
        <div class="sidebar-section-label">Menu</div>
    </div>

    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
            <span>Dashboard</span>
        </a>
        <a href="cartoes.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
            <span>Cartões</span>
        </a>
        <a href="financiamentos.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
            <span>Financiamentos</span>
        </a>
        <a href="compartilhamento.php" class="nav-item active">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            <span>Compartilhamento</span>
        </a>
        <?php if ($is_admin): ?>
        <a href="usuarios.php" class="nav-item">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
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
            <a href="#" class="sidebar-action" onclick="document.getElementById('passwordModal').classList.add('open'); return false;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
                <span>Minha conta</span>
            </a>
            <form method="post" action="logout.php" style="width:100%">
                <button type="submit" class="sidebar-action">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
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
            <h1>Compartilhamento de Conta</h1>
            <p>Convide pessoas para visualizar e gerenciar suas finanças</p>
        </div>
        <div>
            <button class="btn-theme-toggle" id="themeToggle" title="Alternar tema" onclick="toggleTheme()" style="width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;font-size:16px;">
                <span id="themeIcon">☀️</span>
            </button>
        </div>
    </div>

    <div class="content">

        <?php if ($msg): ?>
        <div class="msg-box <?= $msgType ?>"><?= safe($msg) ?></div>
        <?php endif; ?>

        <!-- Contas compartilhadas comigo -->
        <?php if ($sharedAccounts): ?>
        <div class="section-panel" style="border-color: rgba(0,212,255,0.2); margin-bottom: 24px;">
            <div class="section-header">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                    Contas compartilhadas comigo
                </div>
            </div>
            <div class="section-body">
                <div class="shared-cards">
                    <?php foreach ($sharedAccounts as $sa): ?>
                    <div class="shared-card">
                        <div class="shared-card-name"><?= safe($sa['owner_nome']) ?></div>
                        <div class="shared-card-email"><?= safe($sa['owner_email']) ?></div>
                        <form method="post" style="display:flex;gap:8px;">
                            <input type="hidden" name="action_share" value="switch_account">
                            <input type="hidden" name="view_user_id" value="<?= $sa['owner_id'] ?>">
                            <button type="submit" class="btn-primary" style="flex:1;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                Acessar conta
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Enviar convite -->
        <div class="section-panel">
            <div class="section-header">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Convidar pessoa
                </div>
            </div>
            <div class="section-body">
                <form method="post" class="invite-form">
                    <input type="hidden" name="action_share" value="invite">
                    <div class="form-group">
                        <label>E-mail da pessoa</label>
                        <input type="email" name="invite_email" placeholder="pessoa@email.com" required>
                    </div>
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        Enviar convite
                    </button>
                </form>
            </div>
        </div>

        <!-- Convites recebidos pendentes -->
        <?php
        $pendingReceived = array_filter($receivedShares, fn($s) => $s['status'] === 'pending');
        if ($pendingReceived):
        ?>
        <div class="section-panel" style="border-color: rgba(255,214,0,0.2);">
            <div class="section-header">
                <div class="section-title" style="color:var(--warning);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                    Convites pendentes
                </div>
                <span class="section-count"><?= count($pendingReceived) ?></span>
            </div>
            <div class="section-body">
                <?php foreach ($pendingReceived as $pr): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--surface2);border-radius:8px;margin-bottom:8px;">
                    <div>
                        <div style="font-weight:600;font-size:13px;"><?= safe($pr['owner_nome']) ?></div>
                        <div style="font-size:11px;color:var(--muted);"><?= safe($pr['owner_email']) ?> quer compartilhar a conta</div>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action_share" value="accept">
                            <input type="hidden" name="share_id" value="<?= $pr['id'] ?>">
                            <button type="submit" class="btn-sm btn-success">Aceitar</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action_share" value="reject">
                            <input type="hidden" name="share_id" value="<?= $pr['id'] ?>">
                            <button type="submit" class="btn-sm btn-danger">Recusar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Meus compartilhamentos (enviados) -->
        <div class="section-panel">
            <div class="section-header">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
                    Meus compartilhamentos
                </div>
                <span class="section-count"><?= count($sentShares) ?></span>
            </div>
            <?php if ($sentShares): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pessoa</th>
                        <th>E-mail</th>
                        <th>Status</th>
                        <th>Permissões</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sentShares as $ss): ?>
                <tr>
                    <td style="font-weight:500;"><?= safe($ss['invitee_nome']) ?></td>
                    <td style="color:var(--muted);font-size:12px;"><?= safe($ss['invitee_email']) ?></td>
                    <td><span class="badge <?= $ss['status'] ?>"><?= $ss['status'] === 'pending' ? 'Pendente' : ($ss['status'] === 'accepted' ? 'Aceito' : 'Recusado') ?></span></td>
                    <td>
                        <?php if ($ss['status'] === 'accepted'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action_share" value="update_permissions">
                            <input type="hidden" name="share_id" value="<?= $ss['id'] ?>">
                            <div class="perm-grid">
                                <?php
                                $currentPerms = $permissionsMap[$ss['id']] ?? [];
                                $permTypes = array_column($currentPerms, 'resource_type');
                                $hasEdit = !empty(array_filter($currentPerms, fn($p) => $p['can_edit']));
                                ?>
                                <label class="perm-check"><input type="checkbox" name="perm_types[]" value="all" <?= in_array('all', $permTypes) ? 'checked' : '' ?>> Tudo</label>
                                <label class="perm-check"><input type="checkbox" name="perm_types[]" value="expense" <?= in_array('expense', $permTypes) ? 'checked' : '' ?>> Despesas</label>
                                <label class="perm-check"><input type="checkbox" name="perm_types[]" value="income" <?= in_array('income', $permTypes) ? 'checked' : '' ?>> Receitas</label>
                                <label class="perm-check"><input type="checkbox" name="perm_types[]" value="card" <?= in_array('card', $permTypes) ? 'checked' : '' ?>> Cartões</label>
                                <label class="perm-check"><input type="checkbox" name="perm_can_edit" value="1" <?= $hasEdit ? 'checked' : '' ?>> Pode editar</label>
                            </div>
                            <button type="submit" class="btn-sm btn-outline">Salvar permissões</button>
                        </form>
                        <?php else: ?>
                        <span style="color:var(--muted);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action_share" value="cancel">
                            <input type="hidden" name="share_id" value="<?= $ss['id'] ?>">
                            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Remover este compartilhamento?')">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-msg">Nenhum compartilhamento criado. Convide alguém pelo e-mail acima.</div>
            <?php endif; ?>
        </div>

        <!-- Histórico de convites recebidos -->
        <?php
        $historyReceived = array_filter($receivedShares, fn($s) => $s['status'] !== 'pending');
        if ($historyReceived):
        ?>
        <div class="section-panel">
            <div class="section-header">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Convites recebidos (histórico)
                </div>
            </div>
            <table class="data-table">
                <thead><tr><th>De</th><th>E-mail</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($historyReceived as $hr): ?>
                <tr>
                    <td style="font-weight:500;"><?= safe($hr['owner_nome']) ?></td>
                    <td style="color:var(--muted);font-size:12px;"><?= safe($hr['owner_email']) ?></td>
                    <td><span class="badge <?= $hr['status'] ?>"><?= $hr['status'] === 'accepted' ? 'Aceito' : 'Recusado' ?></span></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action_share" value="cancel">
                            <input type="hidden" name="share_id" value="<?= $hr['id'] ?>">
                            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Remover este compartilhamento?')">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</main>

<script>
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
</script>

<!-- MODAL SENHA -->
<div class="modal-overlay" id="passwordModal" onclick="if(event.target===this)this.classList.remove('open')" style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:1000;display:none;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:400px;position:relative;">
        <button onclick="document.getElementById('passwordModal').classList.remove('open')" style="position:absolute;top:12px;right:14px;background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;line-height:1;">&times;</button>
        <h3 style="margin-bottom:20px;font-size:16px;font-weight:600;">Alterar Senha</h3>
        <form method="post">
            <input type="hidden" name="action_change_password" value="1">
            <div style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px;">Senha atual</label>
                    <input type="password" name="senha_atual" required style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px;">Nova senha</label>
                    <input type="password" name="nova_senha" required minlength="6" style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:5px;">Confirmar nova senha</label>
                    <input type="password" name="confirma_senha" required minlength="6" style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px;">
                    <button type="button" onclick="document.getElementById('passwordModal').classList.remove('open')" style="padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--ink);cursor:pointer;font-family:'Sora',sans-serif;font-size:13px;">Cancelar</button>
                    <button type="submit" style="padding:8px 16px;background:var(--accent);border:none;border-radius:8px;color:#000;font-weight:600;cursor:pointer;font-family:'Sora',sans-serif;font-size:13px;">Salvar</button>
                </div>
            </div>
        </form>
    </div>
</div>
<style>#passwordModal.open { display: flex; }</style>
<?php if ($msgType === 'error' && strpos($msg, 'Senha') !== false): ?>
<script>document.getElementById('passwordModal').classList.add('open');</script>
<?php endif; ?>
</body>
</html>
