<?php
session_start();
if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

require_once 'conexao_pdo.php';
require_once 'fatura_helper.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['id'];
$action = $_GET['action'] ?? 'metrics';

if ($action === 'metrics') {
    $metrics = getConsolidatedCardMetrics($pdo, $userId);
    echo json_encode($metrics);
    exit;
}

if ($action === 'card_detail' && isset($_GET['id'])) {
    $cardId = (int)$_GET['id'];

    // Verificar propriedade
    $check = $pdo->prepare("SELECT * FROM credit_cards WHERE id=:id AND user_id=:uid");
    $check->execute([':id' => $cardId, ':uid' => $userId]);
    $card = $check->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        http_response_code(404);
        echo json_encode(['error' => 'Cartão não encontrado']);
        exit;
    }

    $usage = getCardUtilization($pdo, $cardId);
    $faturas = getProximasFaturas($pdo, $cardId);
    $evolucao = getEvolucaoGastosCartao($pdo, $cardId);

    // Remover dados sensíveis criptografados do JSON
    unset($card['numero_encrypted'], $card['ccv_encrypted'], $card['validade_encrypted'], $card['nome_impresso_encrypted'], $card['iv']);

    echo json_encode([
        'card' => $card,
        'usage' => $usage,
        'faturas' => $faturas,
        'evolucao' => $evolucao,
    ]);
    exit;
}

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT id, nome, titular, limite, dia_vencimento, dia_fechamento, ativo, ultimos4, cartao_adicional_de FROM credit_cards WHERE user_id = :uid ORDER BY ativo DESC, nome ASC");
    $stmt->execute([':uid' => $userId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cards as &$c) {
        $usage = getCardUtilization($pdo, $c['id']);
        $c['usado'] = $usage['usado'];
        $c['disponivel'] = $usage['disponivel'];
        $c['percentual'] = $usage['percentual'];
    }

    echo json_encode(['cards' => $cards]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida']);
