<?php
/**
 * Funções auxiliares para cálculo de faturas e utilização de cartões.
 */

/**
 * Determina em qual fatura (período YYYY-MM) uma compra será lançada,
 * com base na data da compra e no dia de fechamento do cartão.
 *
 * Regra: compra ANTES do dia de fechamento → fatura do mês atual.
 *        compra NO dia ou APÓS o fechamento → fatura do próximo mês.
 *
 * @param string $purchaseDate Data da compra (Y-m-d)
 * @param int    $diaFechamento Dia de fechamento da fatura (1-31)
 * @return string Período da fatura (YYYY-MM)
 */
function calcularFaturaPeriod(string $purchaseDate, int $diaFechamento): string {
    $dt = new DateTime($purchaseDate);
    $year  = (int)$dt->format('Y');
    $month = (int)$dt->format('m');
    $day   = (int)$dt->format('d');

    // Ajustar dia de fechamento para meses curtos
    $lastDay = (int)$dt->format('t');
    $fechamentoEfetivo = min($diaFechamento, $lastDay);

    if ($day >= $fechamentoEfetivo) {
        // Cai na fatura do próximo mês
        $dt->modify('first day of next month');
        return $dt->format('Y-m');
    }

    return sprintf('%04d-%02d', $year, $month);
}

/**
 * Calcula a utilização de um cartão específico.
 * Considera todas as despesas vinculadas ao cartão (pagas ou não).
 *
 * @param PDO $pdo Conexão com o banco
 * @param int $cardId ID do cartão
 * @return array ['usado' => float, 'limite' => float, 'disponivel' => float, 'percentual' => float]
 */
function getCardUtilization(PDO $pdo, int $cardId): array {
    // Buscar limite do cartão (ou do cartão pai se for adicional)
    $stmtCard = $pdo->prepare("
        SELECT c.id, c.limite, c.cartao_adicional_de
        FROM credit_cards c WHERE c.id = :id
    ");
    $stmtCard->execute([':id' => $cardId]);
    $card = $stmtCard->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        return ['usado' => 0, 'limite' => 0, 'disponivel' => 0, 'percentual' => 0];
    }

    // Se for cartão adicional, usar o limite e somar gastos do pai + todos adicionais
    $parentId = $card['cartao_adicional_de'] ?: $card['id'];

    // Buscar limite do cartão principal
    if ($card['cartao_adicional_de']) {
        $stmtParent = $pdo->prepare("SELECT limite FROM credit_cards WHERE id = :id");
        $stmtParent->execute([':id' => $parentId]);
        $limite = (float)$stmtParent->fetchColumn();
    } else {
        $limite = (float)$card['limite'];
    }

    // Somar gastos de todos os cartões que compartilham o mesmo limite
    $stmtUsado = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0) as usado
        FROM expense_card_link ecl
        JOIN expenses e ON e.id = ecl.expense_id
        WHERE ecl.card_id IN (
            SELECT id FROM credit_cards WHERE id = :parentId OR cartao_adicional_de = :parentId2
        )
    ");
    $stmtUsado->execute([':parentId' => $parentId, ':parentId2' => $parentId]);
    $usado = (float)$stmtUsado->fetchColumn();

    $disponivel = max(0, $limite - $usado);
    $percentual = $limite > 0 ? round(($usado / $limite) * 100, 2) : 0;

    return [
        'usado'      => $usado,
        'limite'     => $limite,
        'disponivel' => $disponivel,
        'percentual' => $percentual,
    ];
}

/**
 * Retorna métricas consolidadas de todos os cartões do usuário.
 *
 * @param PDO $pdo Conexão com o banco
 * @param int $userId ID do usuário
 * @return array ['limite_total', 'usado_total', 'disponivel_total', 'percentual_geral', 'qtd_cartoes']
 */
function getConsolidatedCardMetrics(PDO $pdo, int $userId): array {
    // Buscar apenas cartões principais (não adicionais) para evitar contar limite duplicado
    $stmtLimite = $pdo->prepare("
        SELECT COALESCE(SUM(limite), 0) as limite_total, COUNT(*) as qtd
        FROM credit_cards
        WHERE user_id = :uid AND ativo = 1 AND cartao_adicional_de IS NULL
    ");
    $stmtLimite->execute([':uid' => $userId]);
    $row = $stmtLimite->fetch(PDO::FETCH_ASSOC);

    $limiteTotal = (float)$row['limite_total'];
    $qtdCartoes  = (int)$row['qtd'];

    // Contar também cartões adicionais na quantidade
    $stmtQtdTotal = $pdo->prepare("
        SELECT COUNT(*) FROM credit_cards WHERE user_id = :uid AND ativo = 1
    ");
    $stmtQtdTotal->execute([':uid' => $userId]);
    $qtdCartoesTotal = (int)$stmtQtdTotal->fetchColumn();

    // Somar utilização de todos os cartões do usuário
    $stmtUsado = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0) as usado
        FROM expense_card_link ecl
        JOIN expenses e ON e.id = ecl.expense_id
        JOIN credit_cards c ON c.id = ecl.card_id
        WHERE c.user_id = :uid AND c.ativo = 1
    ");
    $stmtUsado->execute([':uid' => $userId]);
    $usadoTotal = (float)$stmtUsado->fetchColumn();

    $disponivelTotal = max(0, $limiteTotal - $usadoTotal);
    $percentualGeral = $limiteTotal > 0 ? round(($usadoTotal / $limiteTotal) * 100, 2) : 0;

    return [
        'limite_total'     => $limiteTotal,
        'usado_total'      => $usadoTotal,
        'disponivel_total' => $disponivelTotal,
        'percentual_geral' => $percentualGeral,
        'qtd_cartoes'      => $qtdCartoesTotal,
    ];
}

/**
 * Retorna as próximas faturas de um cartão com valores.
 *
 * @param PDO $pdo
 * @param int $cardId
 * @param int $mesesFuturos Quantos meses à frente buscar
 * @return array Lista de faturas [['period' => 'YYYY-MM', 'total' => float, 'dia_vencimento' => int], ...]
 */
function getProximasFaturas(PDO $pdo, int $cardId, int $mesesFuturos = 6): array {
    $currentPeriod = date('Y-m');

    $stmt = $pdo->prepare("
        SELECT ecl.fatura_period, COALESCE(SUM(e.amount), 0) as total
        FROM expense_card_link ecl
        JOIN expenses e ON e.id = ecl.expense_id
        WHERE ecl.card_id = :card_id AND ecl.fatura_period >= :current
        GROUP BY ecl.fatura_period
        ORDER BY ecl.fatura_period ASC
        LIMIT :limite
    ");
    $stmt->bindValue(':card_id', $cardId, PDO::PARAM_INT);
    $stmt->bindValue(':current', $currentPeriod, PDO::PARAM_STR);
    $stmt->bindValue(':limite', $mesesFuturos, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retorna evolução mensal de gastos de um cartão (últimos N meses).
 *
 * @param PDO $pdo
 * @param int $cardId
 * @param int $meses
 * @return array [['period' => 'YYYY-MM', 'total' => float], ...]
 */
function getEvolucaoGastosCartao(PDO $pdo, int $cardId, int $meses = 12): array {
    $inicio = date('Y-m', strtotime("-{$meses} months"));

    $stmt = $pdo->prepare("
        SELECT ecl.fatura_period as period, COALESCE(SUM(e.amount), 0) as total
        FROM expense_card_link ecl
        JOIN expenses e ON e.id = ecl.expense_id
        WHERE ecl.card_id = :card_id AND ecl.fatura_period >= :inicio
        GROUP BY ecl.fatura_period
        ORDER BY ecl.fatura_period ASC
    ");
    $stmt->execute([':card_id' => $cardId, ':inicio' => $inicio]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
