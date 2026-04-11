<?php
// monthly_data.php
session_start();

header('Content-Type: application/json; charset=utf-8');

// 1. Verificação de ID de sessão
$userId = $_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? null;

if (!$userId) {
    // Retorna vazio se não logado
    echo json_encode(['labels' => [], 'expenses' => [], 'incomes' => []]);
    exit;
}

require_once 'conexao_pdo.php';

// ====================================================================
// CONFIGURAÇÃO DA LINHA DO TEMPO (RÉGUA)
// ====================================================================
// Lógica: 3 meses para trás, mês atual, 8 meses para frente.
// Proteção "Bug dia 31": Usamos sempre 'first day of this month' como âncora.

$anchorDate = new DateTime('first day of this month');

// Data Inicial: -3 meses
$startDateObj = clone $anchorDate;
$startDateObj->modify('-3 months');

// Data Final: +8 meses
$endDateObj = clone $anchorDate;
$endDateObj->modify('+8 months');

// Formata strings para a query SQL (Y-m)
$start = $startDateObj->format('Y-m');
$end   = $endDateObj->format('Y-m');


// ====================================================================
// 2. QUERY DO BANCO DE DADOS
// ====================================================================
$sql = "
SELECT period,
  SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
  SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS total_income
FROM expenses
WHERE period BETWEEN :start AND :end AND user_id = :user_id
GROUP BY period
ORDER BY period ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $start, ':end' => $end, ':user_id' => $userId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ====================================================================
// 3. MAPEAMENTO E PREENCHIMENTO DE BURACOS
// ====================================================================

// Mapeia o que veio do banco para acesso rápido
$map = [];
foreach ($data as $r) {
    $map[$r['period']] = [
        'e' => (float)$r['total_expense'],
        'i' => (float)$r['total_income']
    ];
}

$labels = [];
$expenses = [];
$incomes = [];

// Loop: Começa na data inicial (-3 meses) e vai até a data final (+8 meses)
// Usamos clone para não alterar a data original de referência
$current = clone $startDateObj;

while ($current <= $endDateObj) {
    $ym = $current->format('Y-m'); // Chave para buscar no map (ex: 2026-01)
    
    // Rótulo Visual (ex: 01/2026)
    $labels[] = $current->format('m/Y');
    
    // Se existir dados naquele mês, usa. Se não, zero.
    $expenses[] = $map[$ym]['e'] ?? 0;
    $incomes[]  = $map[$ym]['i'] ?? 0;
    
    // Avança 1 mês com segurança (dia 01 nunca pula mês)
    $current->modify('+1 month');
}

// ====================================================================
// 4. RETORNO JSON
// ====================================================================
echo json_encode([
    'labels' => $labels,
    'expenses' => $expenses,
    'incomes' => $incomes
]);