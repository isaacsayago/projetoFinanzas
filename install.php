<?php
/**
 * Finanzas — Instalador / Atualizador de banco de dados
 *
 * Este script cria ou atualiza as tabelas necessárias sem apagar dados existentes.
 * Pode ser executado múltiplas vezes com segurança (idempotente).
 *
 * Acesse via navegador: http://localhost/projetoFinanzas/install.php
 */

session_start();

require_once 'conexao_pdo.php';

// Só admins ou acesso direto (sem login) podem rodar
if (isset($_SESSION['id']) && (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'adm')) {
    die('Acesso negado. Somente administradores podem executar a instalação.');
}

$results = [];
$errors = [];

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
    $stmt->execute([':table' => $table]);
    return $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
    $stmt->execute([':col' => $column]);
    return $stmt->rowCount() > 0;
}

function runSQL(PDO $pdo, string $sql, string $description, array &$results, array &$errors): void {
    try {
        $pdo->exec($sql);
        $results[] = "OK — {$description}";
    } catch (PDOException $e) {
        $errors[] = "ERRO — {$description}: " . $e->getMessage();
    }
}

// ============================================================
// 1. TABELA: credit_cards
// ============================================================
if (!tableExists($pdo, 'credit_cards')) {
    runSQL($pdo, "
        CREATE TABLE credit_cards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(10) UNSIGNED NOT NULL,
            nome VARCHAR(100) NOT NULL,
            armazenar_dados TINYINT(1) DEFAULT 0,
            numero_encrypted VARBINARY(512) NULL,
            ccv_encrypted VARBINARY(256) NULL,
            validade_encrypted VARBINARY(256) NULL,
            nome_impresso_encrypted VARBINARY(512) NULL,
            ultimos4 VARCHAR(4) NULL,
            iv VARBINARY(16) NULL,
            titular VARCHAR(100) NOT NULL,
            limite DECIMAL(12,2) NOT NULL,
            dia_vencimento TINYINT UNSIGNED NOT NULL,
            dia_fechamento TINYINT UNSIGNED NOT NULL,
            cartao_adicional_de INT UNSIGNED NULL,
            ativo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cc_user (user_id),
            INDEX idx_cc_adicional (cartao_adicional_de),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (cartao_adicional_de) REFERENCES credit_cards(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'Criar tabela credit_cards', $results, $errors);
} else {
    $results[] = 'SKIP — Tabela credit_cards já existe';
}

// ============================================================
// 2. TABELA: expense_card_link
// ============================================================
if (!tableExists($pdo, 'expense_card_link')) {
    runSQL($pdo, "
        CREATE TABLE expense_card_link (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            expense_id INT(11) NOT NULL,
            card_id INT UNSIGNED NOT NULL,
            fatura_period VARCHAR(7) NOT NULL,
            UNIQUE KEY uk_ecl_expense (expense_id),
            INDEX idx_ecl_card_fatura (card_id, fatura_period),
            FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
            FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'Criar tabela expense_card_link', $results, $errors);
} else {
    $results[] = 'SKIP — Tabela expense_card_link já existe';
}

// ============================================================
// 3. TABELA: account_shares
// ============================================================
if (!tableExists($pdo, 'account_shares')) {
    runSQL($pdo, "
        CREATE TABLE account_shares (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id INT(10) UNSIGNED NOT NULL,
            invitee_id INT(10) UNSIGNED NOT NULL,
            status ENUM('pending','accepted','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            INDEX idx_as_owner (owner_id),
            INDEX idx_as_invitee (invitee_id),
            UNIQUE KEY uk_as_pair (owner_id, invitee_id),
            FOREIGN KEY (owner_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (invitee_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'Criar tabela account_shares', $results, $errors);
} else {
    $results[] = 'SKIP — Tabela account_shares já existe';
}

// ============================================================
// 4. TABELA: share_permissions
// ============================================================
if (!tableExists($pdo, 'share_permissions')) {
    runSQL($pdo, "
        CREATE TABLE share_permissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            share_id INT UNSIGNED NOT NULL,
            resource_type ENUM('card','expense','income','all') NOT NULL,
            resource_id INT UNSIGNED NULL,
            can_view TINYINT(1) DEFAULT 1,
            can_edit TINYINT(1) DEFAULT 0,
            INDEX idx_sp_share (share_id),
            FOREIGN KEY (share_id) REFERENCES account_shares(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'Criar tabela share_permissions', $results, $errors);
} else {
    $results[] = 'SKIP — Tabela share_permissions já existe';
}

// ============================================================
// 5. TABELA: notifications
// ============================================================
if (!tableExists($pdo, 'notifications')) {
    runSQL($pdo, "
        CREATE TABLE notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(10) UNSIGNED NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            mensagem TEXT NOT NULL,
            link VARCHAR(255) NULL,
            lida TINYINT(1) DEFAULT 0,
            ref_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user_lida (user_id, lida),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", 'Criar tabela notifications', $results, $errors);
} else {
    $results[] = 'SKIP — Tabela notifications já existe';
}

// ============================================================
// 6. COLUNA shared_with_user_id na tabela expenses
//    Marca lançamentos compartilhados entre dois usuários
// ============================================================
if (tableExists($pdo, 'expenses') && !columnExists($pdo, 'expenses', 'shared_with_user_id')) {
    runSQL($pdo, "
        ALTER TABLE expenses
        ADD COLUMN shared_with_user_id INT(10) UNSIGNED NULL DEFAULT NULL AFTER user_id,
        ADD INDEX idx_exp_shared (shared_with_user_id)
    ", 'Adicionar coluna shared_with_user_id em expenses', $results, $errors);
} else {
    $results[] = 'SKIP — Coluna shared_with_user_id já existe em expenses';
}

// ============================================================
// RESULTADO
// ============================================================
$hasErrors = count($errors) > 0;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Instalação</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Sora', sans-serif; background: #06080f; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(ellipse 80% 50% at 10% 0%, rgba(0,212,255,0.06) 0%, transparent 50%), radial-gradient(ellipse 60% 40% at 90% 100%, rgba(168,85,247,0.04) 0%, transparent 50%); pointer-events: none; }
.container { background: #0d1117; border-radius: 16px; border: 1px solid rgba(0,212,255,0.15); max-width: 640px; width: 100%; padding: 32px; position: relative; box-shadow: 0 24px 64px rgba(0,0,0,0.4); }
h1 { font-size: 20px; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
h1 span { font-size: 24px; }
.subtitle { font-size: 12px; color: #64748b; margin-bottom: 24px; }
.result-item { padding: 10px 14px; border-radius: 8px; margin-bottom: 6px; font-size: 13px; font-family: 'Space Mono', monospace; }
.result-item.ok { background: rgba(0,255,136,0.08); color: #00ff88; border-left: 3px solid #00ff88; }
.result-item.skip { background: rgba(0,212,255,0.06); color: #00d4ff; border-left: 3px solid #00d4ff; }
.result-item.error { background: rgba(255,45,85,0.08); color: #ff2d55; border-left: 3px solid #ff2d55; }
.summary { margin-top: 20px; padding: 16px; border-radius: 10px; font-size: 14px; font-weight: 600; text-align: center; }
.summary.success { background: rgba(0,255,136,0.1); color: #00ff88; border: 1px solid rgba(0,255,136,0.25); }
.summary.failure { background: rgba(255,45,85,0.1); color: #ff2d55; border: 1px solid rgba(255,45,85,0.25); }
.btn-back { display: inline-flex; align-items: center; gap: 6px; margin-top: 16px; padding: 9px 18px; background: #00d4ff; color: #fff; border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
.btn-back:hover { background: #00b8e6; }
</style>
</head>
<body>
<div class="container">
    <h1><span>&#9881;</span> Finanzas — Instalação</h1>
    <div class="subtitle">Criação e atualização das tabelas do banco de dados</div>

    <?php foreach ($results as $r): ?>
        <?php
        $class = 'ok';
        if (str_starts_with($r, 'SKIP')) $class = 'skip';
        ?>
        <div class="result-item <?= $class ?>"><?= htmlspecialchars($r) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $e): ?>
        <div class="result-item error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if ($hasErrors): ?>
        <div class="summary failure">Instalação concluída com erros. Verifique os problemas acima.</div>
    <?php else: ?>
        <div class="summary success">Instalação concluída com sucesso! Todas as tabelas estão prontas.</div>
    <?php endif; ?>

    <div style="text-align:center;">
        <a href="dashboard.php" class="btn-back">Ir para o Dashboard</a>
    </div>
</div>
</body>
</html>
