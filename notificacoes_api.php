<?php
session_start();
if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

require_once 'conexao_pdo.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['id'];

// GET: listar notificações
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'count') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND lida = 0");
        $stmt->execute([':uid' => $userId]);
        echo json_encode(['count' => (int)$stmt->fetchColumn()]);
        exit;
    }

    // Listar notificações recentes
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $stmt = $pdo->prepare("SELECT id, tipo, mensagem, link, lida, ref_id, created_at FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['notifications' => $notifs]);
    exit;
}

// POST: marcar como lida
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'mark_read' && isset($data['id'])) {
        $pdo->prepare("UPDATE notifications SET lida = 1 WHERE id = :id AND user_id = :uid")
            ->execute([':id' => (int)$data['id'], ':uid' => $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET lida = 1 WHERE user_id = :uid AND lida = 0")
            ->execute([':uid' => $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida']);
}
