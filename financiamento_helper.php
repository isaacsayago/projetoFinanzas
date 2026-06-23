<?php
/**
 * Funções auxiliares para o módulo de Financiamentos e Empréstimos.
 */

/**
 * Gera todas as parcelas de um financiamento na tabela loan_installments.
 * Marca as primeiras N parcelas como pagas conforme already_paid_installments.
 *
 * @param PDO $pdo
 * @param int $loanId
 */
function generateLoanInstallments(PDO $pdo, int $loanId): void {
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = :id");
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) return;

    $total = (int)$loan['total_installments'];
    $amount = (float)$loan['installment_amount'];
    $alreadyPaid = (int)$loan['already_paid_installments'];
    $firstDate = new DateTime($loan['first_due_date']);
    $originalDay = (int)$firstDate->format('d');

    $ins = $pdo->prepare("
        INSERT INTO loan_installments (loan_id, installment_number, amount, due_date, period, paid, payment_date)
        VALUES (:lid, :num, :amount, :due, :period, :paid, :pdate)
    ");

    for ($i = 1; $i <= $total; $i++) {
        $dt = clone $firstDate;
        if ($i > 1) {
            $dt->modify('first day of this month');
            $dt->modify('+' . ($i - 1) . ' months');
            $lastDayOfMonth = (int)$dt->format('t');
            $day = min($originalDay, $lastDayOfMonth);
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), $day);
        }

        $dueDate = $dt->format('Y-m-d');
        $period = $dt->format('Y-m');
        $paid = ($i <= $alreadyPaid) ? 1 : 0;
        $paymentDate = $paid ? $dueDate : null;

        $ins->execute([
            ':lid' => $loanId,
            ':num' => $i,
            ':amount' => $amount,
            ':due' => $dueDate,
            ':period' => $period,
            ':paid' => $paid,
            ':pdate' => $paymentDate,
        ]);
    }

    // Auto-calcular last_due_date
    $lastDt = clone $firstDate;
    $lastDt->modify('first day of this month');
    $lastDt->modify('+' . ($total - 1) . ' months');
    $lastDay = min($originalDay, (int)$lastDt->format('t'));
    $lastDt->setDate((int)$lastDt->format('Y'), (int)$lastDt->format('m'), $lastDay);

    $pdo->prepare("UPDATE loans SET last_due_date = :ld WHERE id = :id")
        ->execute([':ld' => $lastDt->format('Y-m-d'), ':id' => $loanId]);
}

/**
 * Retorna parcelas de financiamento como pseudo-rows para o dashboard.
 * Mesmo padrão de getFaturasConsolidadas() do fatura_helper.php.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string|null $period Período YYYY-MM (null = todos)
 * @return array Lista de pseudo-rows compatíveis com expenseRows
 */
function getFinanciamentosConsolidados(PDO $pdo, int $userId, ?string $period = null): array {
    $wherePeriod = $period ? "AND li.period = :period" : "";

    $sql = "
        SELECT l.id as loan_id, l.name as loan_name, l.category, l.total_installments,
               li.id as installment_id, li.installment_number, li.amount, li.due_date,
               li.period, li.paid, li.payment_date
        FROM loan_installments li
        JOIN loans l ON l.id = li.loan_id
        WHERE l.user_id = :uid AND l.active = 1 {$wherePeriod}
        ORDER BY li.due_date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $params = [':uid' => $userId];
    if ($period) $params[':period'] = $period;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id'                   => 'fin_' . $r['loan_id'] . '_' . $r['installment_id'],
            'user_id'              => $userId,
            'shared_with_user_id'  => null,
            'name'                 => 'FINANCIAMENTO ' . $r['loan_name'],
            'amount'               => (float)$r['amount'],
            'due_date'             => $r['due_date'],
            'type'                 => 'expense',
            'planned'              => 0,
            'period'               => $r['period'],
            'paid'                 => (int)$r['paid'],
            'payment_date'         => $r['payment_date'],
            'is_financiamento'     => true,
            'loan_id'              => (int)$r['loan_id'],
            'installment_id'       => (int)$r['installment_id'],
            'installment_number'   => (int)$r['installment_number'],
            'total_installments'   => (int)$r['total_installments'],
        ];
    }
    return $result;
}

/**
 * Retorna métricas consolidadas de todos os financiamentos ativos do usuário.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function getLoanMetrics(PDO $pdo, int $userId): array {
    $stmtLoans = $pdo->prepare("
        SELECT COUNT(*) as qtd,
               COALESCE(SUM(total_amount), 0) as total_contratado,
               COALESCE(SUM(installment_amount * already_paid_installments), 0) as est_pago
        FROM loans WHERE user_id = :uid AND active = 1
    ");
    $stmtLoans->execute([':uid' => $userId]);
    $row = $stmtLoans->fetch(PDO::FETCH_ASSOC);

    // Calcular valor efetivamente pago via loan_installments
    $stmtPago = $pdo->prepare("
        SELECT COALESCE(SUM(li.amount), 0) as total_pago
        FROM loan_installments li
        JOIN loans l ON l.id = li.loan_id
        WHERE l.user_id = :uid AND l.active = 1 AND li.paid = 1
    ");
    $stmtPago->execute([':uid' => $userId]);
    $totalPago = (float)$stmtPago->fetchColumn();

    $totalContratado = (float)$row['total_contratado'];
    $totalRestante = max(0, $totalContratado - $totalPago);
    $percentual = $totalContratado > 0 ? round(($totalPago / $totalContratado) * 100, 2) : 0;

    // Parcelas pagas e restantes
    $stmtParcelas = $pdo->prepare("
        SELECT COUNT(*) as total_parcelas,
               SUM(CASE WHEN li.paid = 1 THEN 1 ELSE 0 END) as parcelas_pagas
        FROM loan_installments li
        JOIN loans l ON l.id = li.loan_id
        WHERE l.user_id = :uid AND l.active = 1
    ");
    $stmtParcelas->execute([':uid' => $userId]);
    $parcRow = $stmtParcelas->fetch(PDO::FETCH_ASSOC);

    return [
        'qtd_financiamentos'  => (int)$row['qtd'],
        'total_contratado'    => $totalContratado,
        'total_pago'          => $totalPago,
        'total_restante'      => $totalRestante,
        'percentual_quitacao' => $percentual,
        'total_parcelas'      => (int)$parcRow['total_parcelas'],
        'parcelas_pagas'      => (int)$parcRow['parcelas_pagas'],
        'parcelas_restantes'  => (int)$parcRow['total_parcelas'] - (int)$parcRow['parcelas_pagas'],
    ];
}

/**
 * Retorna detalhes completos de um financiamento específico.
 *
 * @param PDO $pdo
 * @param int $loanId
 * @param int $userId
 * @return array|null
 */
function getLoanDetail(PDO $pdo, int $loanId, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $loanId, ':uid' => $userId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) return null;

    // Buscar todas as parcelas
    $stmtI = $pdo->prepare("
        SELECT * FROM loan_installments WHERE loan_id = :lid ORDER BY installment_number ASC
    ");
    $stmtI->execute([':lid' => $loanId]);
    $installments = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    // Calcular métricas
    $totalPago = 0;
    $parcelasPagas = 0;
    $proximaParcela = null;
    foreach ($installments as $inst) {
        if ($inst['paid']) {
            $totalPago += (float)$inst['amount'];
            $parcelasPagas++;
        } elseif (!$proximaParcela) {
            $proximaParcela = $inst;
        }
    }

    $totalContratado = (float)$loan['total_amount'];
    $totalRestante = max(0, $totalContratado - $totalPago);
    $percentual = $totalContratado > 0 ? round(($totalPago / $totalContratado) * 100, 2) : 0;

    $loan['installments'] = $installments;
    $loan['total_pago'] = $totalPago;
    $loan['parcelas_pagas'] = $parcelasPagas;
    $loan['parcelas_restantes'] = (int)$loan['total_installments'] - $parcelasPagas;
    $loan['total_restante'] = $totalRestante;
    $loan['percentual_quitacao'] = $percentual;
    $loan['proxima_parcela'] = $proximaParcela;

    return $loan;
}

/**
 * Mapa de categorias para exibição legível.
 */
function getLoanCategoryLabel(string $category): string {
    $labels = [
        'moto' => 'Moto',
        'carro' => 'Carro',
        'casa' => 'Casa',
        'emprestimo_pessoal' => 'Empréstimo Pessoal',
        'consignado' => 'Consignado',
        'outros' => 'Outros',
    ];
    return $labels[$category] ?? 'Outros';
}
