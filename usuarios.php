<?php
session_start();
if (!isset($_SESSION['id'])) {
    header('Location: index.php');
    exit();
}
// Somente admin (isaac.sayago@gmail.com) acessa esta página
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'adm') {
    header('Location: dashboard.php');
    exit();
}

require_once 'conexao_pdo.php';

$msg = '';
$msgType = '';

// ---- AÇÕES ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Criar usuário
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $nivel = (isset($_POST['nivel']) && $_POST['nivel'] === 'adm') ? 'adm' : 'user';

        if (!$nome || !$email || !$senha) {
            $msg = 'Preencha todos os campos.'; $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'E-mail inválido.'; $msgType = 'error';
        } elseif (strlen($senha) < 6) {
            $msg = 'Senha deve ter ao menos 6 caracteres.'; $msgType = 'error';
        } else {
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $msg = 'Este e-mail já está cadastrado.'; $msgType = 'error';
            } else {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel) VALUES (:nome, :email, :senha, :nivel)")
                    ->execute([':nome' => $nome, ':email' => $email, ':senha' => $hash, ':nivel' => $nivel]);
                $msg = "Usuário \"{$nome}\" criado com sucesso!"; $msgType = 'success';
            }
        }
    }

    // Alterar senha
    if (isset($_POST['action']) && $_POST['action'] === 'change_pass') {
        $uid   = (int)$_POST['uid'];
        $senha = trim($_POST['nova_senha'] ?? '');
        if (strlen($senha) < 6) {
            $msg = 'Nova senha deve ter ao menos 6 caracteres.'; $msgType = 'error';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id")
                ->execute([':senha' => $hash, ':id' => $uid]);
            $msg = 'Senha atualizada com sucesso!'; $msgType = 'success';
        }
    }

    // Alterar nível de acesso
    if (isset($_POST['action']) && $_POST['action'] === 'change_nivel') {
        $uid   = (int)$_POST['uid'];
        $nivel = ($_POST['novo_nivel'] ?? '') === 'adm' ? 'adm' : 'user';
        $pdo->prepare("UPDATE usuarios SET nivel = :nivel WHERE id = :id")
            ->execute([':nivel' => $nivel, ':id' => $uid]);
        $msg = 'Nível de acesso atualizado!'; $msgType = 'success';
    }
}

// Lista de usuários
$users = $pdo->query("SELECT id, nome, email, nivel, created_at FROM usuarios ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

$current_user_name = $_SESSION['nome'] ?? 'Administrador';
$is_admin = true; // somente admin acessa esta página

function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Usuários</title>
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
    --glow-accent: 0 0 20px rgba(0,212,255,0.15);
    --glow-purple: 0 0 20px rgba(168,85,247,0.15);
    --muted: #64748b;
    --border: rgba(255,255,255,0.08);
    --sidebar-w: 220px;
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
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(255,45,85,0.04) 0%, transparent 50%);
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

/* SIDEBAR */
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

.sidebar-logo {
    padding: 16px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}

.sidebar-logo-icon {
    width: 32px; height: 32px;
    background: rgba(0,212,255,0.15);
    border: 1px solid rgba(0,212,255,0.3);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 15px rgba(0,212,255,0.2);
}

.sidebar-logo-icon svg { width: 18px; height: 18px; color: var(--accent); }

.sidebar-logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
}

.sidebar-section { padding: 14px 12px 4px; }
.sidebar-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.3); padding: 0 8px; margin-bottom: 6px; }

.sidebar-nav {
    padding: 0 12px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.nav-item {
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
    margin-bottom: 0;
}

.nav-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
.nav-item.active { background: rgba(0,212,255,0.12); color: var(--accent); border: 1px solid rgba(0,212,255,0.25); box-shadow: 0 0 12px rgba(0,212,255,0.1); }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }

.sidebar-footer {
    padding: 14px 12px;
    border-top: 1px solid rgba(255,255,255,0.07);
    margin-top: auto;
}

.user-info { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; margin-bottom: 8px; }
.user-avatar-sb { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name-sb { font-size: 12px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role-sb { font-size: 11px; color: rgba(255,255,255,0.4); }
.sidebar-actions { display: flex; flex-direction: column; gap: 4px; }
.sidebar-action {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
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
    font-family: 'Sora', sans-serif;
}
.sidebar-action:hover { background: rgba(255,255,255,0.07); color: #fff; }
.sidebar-action svg { width: 15px; height: 15px; }

/* MAIN */
.main { margin-left: var(--sidebar-w); flex: 1; position: relative; z-index: 1; }

.topbar {
    background: rgba(13,17,23,0.85);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.topbar h1 {
    font-size: 18px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
}

.topbar p { font-size: 12px; color: var(--muted); margin-top: 1px; }

.content { padding: 28px; }

/* ALERT */
.alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert.success { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.25); }
.alert.error { background: rgba(255,45,85,0.1); color: var(--danger); border: 1px solid rgba(255,45,85,0.25); }

/* CARDS */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }

.card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
}

.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-header h2 { font-size: 14px; font-weight: 700; }
.card-header-icon {
    width: 30px; height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card-header-icon.blue { background: rgba(0,212,255,0.1); color: var(--accent); }
.card-header-icon svg { width: 16px; height: 16px; }

.card-body { padding: 20px; }

/* FORM */
.field { margin-bottom: 14px; }

.field label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    margin-bottom: 5px;
}

.field input,
.field select {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: var(--surface2);
    transition: border-color 0.2s, box-shadow 0.2s;
}

.field input:focus,
.field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,212,255,0.1);
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    background: var(--accent);
    color: #0a0e1a;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
    justify-content: center;
}

.btn-primary:hover { background: #00b8e6; transform: translateY(-1px); box-shadow: 0 4px 20px rgba(0,212,255,0.35); }

/* USERS TABLE */
.users-card { background: var(--surface); border-radius: var(--radius); border: 1px solid rgba(255,255,255,0.06); overflow: hidden; }

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
    padding: 12px 16px;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(0,212,255,0.03); }

.user-cell { display: flex; align-items: center; gap: 10px; }

.user-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}

.user-name { font-weight: 600; }
.user-email { font-size: 11px; color: var(--muted); }

.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.badge.adm { background: rgba(255,214,0,0.12); color: var(--warning); border: 1px solid rgba(255,214,0,0.2); }
.badge.user { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }

/* PASSWORD CHANGE INLINE */
.pass-form { display: flex; gap: 6px; align-items: center; }

.pass-input {
    padding: 6px 10px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    width: 140px;
    color: var(--ink);
    background: var(--surface2);
    transition: border-color 0.2s;
}

.pass-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,212,255,0.1); }

.btn-sm {
    padding: 6px 12px;
    border: none;
    border-radius: 7px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-sm.primary { background: var(--accent); color: #0a0e1a; }
.btn-sm.primary:hover { background: #00b8e6; }

/* Botão criar — compacto, alinhado à direita */
.btn-create {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    background: var(--accent);
    color: #0a0e1a;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-create svg { width: 13px; height: 13px; }
.btn-create:hover { background: #00b8e6; transform: translateY(-1px); box-shadow: 0 3px 15px rgba(0,212,255,0.3); }

/* Select de nível inline */
.nivel-form { display: inline-flex; }
.nivel-select {
    padding: 4px 8px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink);
    background: var(--surface2);
    cursor: pointer;
    transition: border-color 0.2s;
}
.nivel-select:focus { outline: none; border-color: var(--accent); }
.nivel-select:hover { border-color: var(--accent); }

@media (max-width: 900px) {
    .two-col { grid-template-columns: 1fr; }
}

/* LIGHT MODE */
body.light {
    --ink: #1e293b; --bg: #f0f2f7; --surface: #ffffff; --surface2: #f8fafc;
    --accent: #2563eb; --accent-hover: #1d4ed8; --accent-light: rgba(37,99,235,0.1);
    --success: #059669; --success-light: rgba(5,150,105,0.1);
    --danger: #dc2626; --danger-light: rgba(220,38,38,0.1);
    --warning: #d97706; --warning-light: rgba(217,119,6,0.1);
    --purple: #7c3aed; --purple-light: rgba(124,58,237,0.1);
    --muted: #64748b; --border: #e5e7eb;
}
body.light::before, body.light::after { display: none; }
body.light .sidebar { background: #0d1117; border-right: 1px solid rgba(255,255,255,0.07); }
body.light .sidebar-logo-icon { background: var(--accent); border-color: transparent; box-shadow: none; }
body.light .sidebar-logo-icon svg { color: #fff; }
body.light .nav-item.active { background: var(--accent); color: #fff; border-color: transparent; box-shadow: none; }
body.light .topbar { background: var(--surface); backdrop-filter: none; }
body.light .data-table tbody tr:hover { background: #fafbfc; }
body.light .btn-primary { color: #fff; }
body.light .btn-create { color: #fff; }
body.light .field input, body.light .field select { background: #fff; }
body.light .pass-input { background: #fff; }
body.light .nivel-select { background: #fff; }

/* RESPONSIVE */
@media (max-width: 768px) {
    .sidebar { width: 60px; }
    .sidebar-logo-text, .sidebar-section-label, .nav-item span, .user-name-sb, .user-role-sb, .sidebar-action span { display: none; }
    .main { margin-left: 60px; }
    .topbar { padding: 12px 16px; }
    .content { padding: 16px; }
}
</style>
</head>
<body>

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
        <a href="cartoes.php" class="nav-item">
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
        <a href="usuarios.php" class="nav-item active">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <span>Usuários</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar-sb"><?= mb_strtoupper(mb_substr($current_user_name, 0, 1)) ?></div>
            <div>
                <div class="user-name-sb"><?= safe($current_user_name) ?></div>
                <div class="user-role-sb">Administrador</div>
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

<main class="main">
    <div class="topbar">
        <div>
            <h1>Gestão de Usuários</h1>
            <p>Painel exclusivo do administrador</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button class="btn-theme-toggle" id="themeToggle" title="Alternar tema" onclick="toggleTheme()" style="width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;font-size:16px;">
                <span id="themeIcon">☀️</span>
            </button>
        </div>
    </div>

    <div class="content">

        <?php if ($msg): ?>
        <div class="alert <?= $msgType ?>">
            <?= $msgType === 'success' ? '✓' : '✕' ?> <?= safe($msg) ?>
        </div>
        <?php endif; ?>

        <div class="two-col">
            <!-- CRIAR USUÁRIO -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <h2>Criar novo usuário</h2>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="create">
                        <div class="field">
                            <label>Nome completo</label>
                            <input type="text" name="nome" placeholder="Ex: João Silva" required>
                        </div>
                        <div class="field">
                            <label>E-mail</label>
                            <input type="email" name="email" placeholder="joao@email.com" required>
                        </div>
                        <div class="field">
                            <label>Senha inicial</label>
                            <input type="password" name="senha" placeholder="Mínimo 6 caracteres" required minlength="6">
                        </div>
                        <div class="field">
                            <label>Nível de acesso</label>
                            <select name="nivel">
                                <option value="user">Usuário comum</option>
                                <option value="adm">Administrador</option>
                            </select>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:4px;">
                            <button type="submit" class="btn-create">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                                Criar usuário
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- INFO -->
            <div>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-body" style="padding:16px 20px;">
                        <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                            <p style="font-weight:600;color:var(--ink);margin-bottom:8px;">📋 Como funciona:</p>
                            <p>• Cada usuário enxerga <strong>apenas seus próprios</strong> lançamentos.</p>
                            <p>• Nenhum usuário vê os dados dos outros.</p>
                            <p>• Somente você (admin) pode criar e editar usuários.</p>
                            <p>• Para resetar senha ou trocar nível, use a tabela abaixo.</p>
                        </div>
                    </div>
                </div>
                <div style="background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);padding:16px 20px;">
                    <div style="font-size:13px;">
                        <span style="font-weight:700;font-size:24px;font-family:'Space Mono',monospace;"><?= count($users) ?></span>
                        <span style="color:var(--muted);margin-left:6px;">usuários cadastrados</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- USERS LIST -->
        <div class="users-card">
            <div class="card-header" style="padding:16px 20px;border-bottom:1px solid var(--border);">
                <div class="card-header-icon blue">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <h2>Todos os usuários</h2>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>Nível</th>
                        <th>Cadastro</th>
                        <th>Alterar senha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar"><?= mb_strtoupper(mb_substr($u['nome'], 0, 1)) ?></div>
                                <div>
                                    <div class="user-name"><?= safe($u['nome']) ?></div>
                                    <div class="user-email"><?= safe($u['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <form method="post" class="nivel-form">
                                <input type="hidden" name="action" value="change_nivel">
                                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                                <select name="novo_nivel" class="nivel-select" onchange="this.form.submit()" title="Alterar nível">
                                    <option value="user" <?= $u['nivel'] === 'user' ? 'selected' : '' ?>>👤 Usuário</option>
                                    <option value="adm"  <?= $u['nivel'] === 'adm'  ? 'selected' : '' ?>>★ Admin</option>
                                </select>
                            </form>
                        </td>
                        <td style="color:var(--muted);font-size:12px;">
                            <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                        </td>
                        <td>
                            <form method="post" class="pass-form" onsubmit="return confirm('Alterar senha de <?= safe($u['nome']) ?>?')">
                                <input type="hidden" name="action" value="change_pass">
                                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                                <input type="password" name="nova_senha" class="pass-input" placeholder="Nova senha" minlength="6" required>
                                <button type="submit" class="btn-sm primary">Salvar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

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
</body>
</html>