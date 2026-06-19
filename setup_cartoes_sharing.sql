-- ============================================================
-- Finanzas — Módulo de Cartões de Crédito + Compartilhamento
-- Executar no banco `financeiro`
-- ============================================================

USE financeiro;

-- Cartões de Crédito
CREATE TABLE IF NOT EXISTS credit_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
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
    dia_vencimento TINYINT NOT NULL,
    dia_fechamento TINYINT NOT NULL,
    cartao_adicional_de INT NULL,
    ativo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cc_user (user_id),
    INDEX idx_cc_adicional (cartao_adicional_de),
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (cartao_adicional_de) REFERENCES credit_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vínculo entre despesas e cartões (controle de fatura)
CREATE TABLE IF NOT EXISTS expense_card_link (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    card_id INT NOT NULL,
    fatura_period VARCHAR(7) NOT NULL,
    UNIQUE KEY uk_ecl_expense (expense_id),
    INDEX idx_ecl_card_fatura (card_id, fatura_period),
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compartilhamento de conta (convites)
CREATE TABLE IF NOT EXISTS account_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    invitee_id INT NOT NULL,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    INDEX idx_as_owner (owner_id),
    INDEX idx_as_invitee (invitee_id),
    UNIQUE KEY uk_as_pair (owner_id, invitee_id),
    FOREIGN KEY (owner_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (invitee_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissões granulares de compartilhamento
CREATE TABLE IF NOT EXISTS share_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    share_id INT NOT NULL,
    resource_type ENUM('card','expense','income','all') NOT NULL,
    resource_id INT NULL,
    can_view TINYINT(1) DEFAULT 1,
    can_edit TINYINT(1) DEFAULT 0,
    INDEX idx_sp_share (share_id),
    FOREIGN KEY (share_id) REFERENCES account_shares(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notificações in-platform
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    mensagem TEXT NOT NULL,
    link VARCHAR(255) NULL,
    lida TINYINT(1) DEFAULT 0,
    ref_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user_lida (user_id, lida),
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
