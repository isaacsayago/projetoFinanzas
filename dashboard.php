<?php
session_start();
if (!isset($_SESSION['id'])) {
    header('Location: index.php');
    exit();
}

require_once 'conexao_pdo.php';
require_once 'fatura_helper.php';
$current_user_id = $_SESSION['id'];
$current_user_name = $_SESSION['nome'] ?? 'Usuário';
$is_admin = isset($_SESSION['nivel']) && $_SESSION['nivel'] === 'adm';

date_default_timezone_set('America/Sao_Paulo');

// ---- Modo conta compartilhada ----
$viewing_shared = false;
$data_user_id = $current_user_id; // ID usado para filtrar dados
$shared_owner_name = '';
if (isset($_SESSION['viewing_shared_user_id']) && $_SESSION['viewing_shared_user_id'] != $current_user_id) {
    $viewing_shared = true;
    $data_user_id = (int)$_SESSION['viewing_shared_user_id'];
    $shared_owner_name = $_SESSION['viewing_shared_user_name'] ?? '';
}

// Voltar para minha conta
if (isset($_GET['action']) && $_GET['action'] === 'exit_shared') {
    unset($_SESSION['viewing_shared_user_id'], $_SESSION['viewing_shared_user_name']);
    header('Location: dashboard.php');
    exit;
}

// Buscar compartilhamentos aceitos (para saber com quem posso criar lançamentos compartilhados)
$activeShares = [];
$stmtShares = $pdo->prepare("
    SELECT a.id as share_id, a.owner_id, a.invitee_id, u_owner.nome as owner_nome, u_inv.nome as invitee_nome
    FROM account_shares a
    JOIN usuarios u_owner ON u_owner.id = a.owner_id
    JOIN usuarios u_inv ON u_inv.id = a.invitee_id
    WHERE (a.owner_id = :uid1 OR a.invitee_id = :uid2) AND a.status = 'accepted'
");
$stmtShares->execute([':uid1' => $current_user_id, ':uid2' => $current_user_id]);
$activeShares = $stmtShares->fetchAll(PDO::FETCH_ASSOC);

// Montar lista de parceiros para select de compartilhamento
$sharePartners = [];
foreach ($activeShares as $sh) {
    if ($sh['owner_id'] == $current_user_id) {
        $sharePartners[$sh['invitee_id']] = $sh['invitee_nome'];
    } else {
        $sharePartners[$sh['owner_id']] = $sh['owner_nome'];
    }
}

// ---------- Helpers ----------
function money($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function formataDataBr($data) {
    if (!$data) return '';
    if (in_array($data, ['0000-00-00', '0000-00-00 00:00:00'], true)) return '';
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : '';
}
function dataParaBanco($val) {
    if (!$val) return null;
    $d = DateTime::createFromFormat('Y-m-d', $val);
    if ($d && $d->format('Y-m-d') === $val) return $d->format('Y-m-d');
    $d = DateTime::createFromFormat('d/m/Y', $val);
    if ($d) return $d->format('Y-m-d');
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}
function periodoBr($periodo) {
    if (!$periodo || $periodo === 'all') return '';
    $data = DateTime::createFromFormat('Y-m-d', $periodo . '-01');
    return $data ? mb_strtoupper($data->format('M/Y'), 'UTF-8') : $periodo;
}

$today_iso = (new DateTime())->format('Y-m-d');
$today_br  = (new DateTime())->format('d/m/Y');
$currentMonth = (new DateTime('first day of this month'))->format('Y-m');
$selectedPeriod = $_GET['period'] ?? $currentMonth;
$action = $_GET['action'] ?? null;
$redirectUrl = "?period=" . urlencode($selectedPeriod);

// -------------------- AÇÕES GET --------------------
// Permite ação em: expenses do próprio usuário OU compartilhados com ele
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $del = $pdo->prepare("DELETE FROM expenses WHERE id = :id AND (user_id = :uid OR shared_with_user_id = :uid2)");
    $del->execute([':id' => $id, ':uid' => $current_user_id, ':uid2' => $current_user_id]);
    header("Location: " . $redirectUrl); exit;
}

if ($action === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cur = $pdo->prepare("SELECT paid FROM expenses WHERE id = :id AND (user_id = :uid OR shared_with_user_id = :uid2)");
    $cur->execute([':id' => $id, ':uid' => $current_user_id, ':uid2' => $current_user_id]);
    $r = $cur->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $new = $r['paid'] ? 0 : 1;
        $u = $pdo->prepare("UPDATE expenses SET paid = :paid, payment_date = CASE WHEN :paid2 = 1 THEN COALESCE(payment_date, CURDATE()) ELSE NULL END WHERE id = :id AND (user_id = :uid OR shared_with_user_id = :uid2)");
        $u->execute([':paid' => $new, ':paid2' => $new, ':id' => $id, ':uid' => $current_user_id, ':uid2' => $current_user_id]);
    }
    header("Location: " . $redirectUrl); exit;
}

if ($action === 'copy_prev' && $selectedPeriod !== 'all') {
    $targetDate = new DateTime($selectedPeriod . '-01');
    $prevDate = clone $targetDate;
    $prevDate->modify('-1 month');
    $prevPeriod = $prevDate->format('Y-m');
    $stmtCopy = $pdo->prepare("SELECT * FROM expenses WHERE period = :prev_period AND user_id = :user_id");
    $stmtCopy->execute([':prev_period' => $prevPeriod, ':user_id' => $current_user_id]);
    $itemsToCopy = $stmtCopy->fetchAll(PDO::FETCH_ASSOC);
    if ($itemsToCopy) {
        $insertStmt = $pdo->prepare("INSERT INTO expenses (user_id, name, amount, due_date, type, planned, period, paid) VALUES (:user_id, :name, :amount, :due_date, :type, :planned, :period, 0)");
        foreach ($itemsToCopy as $item) {
            $day = (new DateTime($item['due_date']))->format('d');
            $newDateStr = $selectedPeriod . '-' . $day;
            $testDate = DateTime::createFromFormat('Y-m-d', $newDateStr);
            if (!$testDate || $testDate->format('Y-m') !== $selectedPeriod) {
                $lastDay = new DateTime($selectedPeriod . '-01');
                $lastDay->modify('last day of this month');
                $newDateStr = $lastDay->format('Y-m-d');
            }
            $insertStmt->execute([
                ':user_id' => $current_user_id, ':name' => $item['name'], ':amount' => $item['amount'],
                ':due_date' => $newDateStr, ':type' => $item['type'], ':planned' => $item['planned'],
                ':period' => (new DateTime($newDateStr))->format('Y-m')
            ]);
        }
    }
    header("Location: " . $redirectUrl); exit;
}

$editRow = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $s = $pdo->prepare("SELECT * FROM expenses WHERE id = :id AND (user_id = :uid OR shared_with_user_id = :uid2)");
    $s->execute([':id' => $id, ':uid' => $current_user_id, ':uid2' => $current_user_id]);
    $editRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------------- AÇÕES POST --------------------
$senha_msg = '';
$senha_msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Alterar senha do próprio usuário
    if (isset($_POST['action_change_password'])) {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirma_senha = $_POST['confirma_senha'] ?? '';

        $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $current_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($senha_atual, $user['senha'])) {
            $senha_msg = 'Senha atual incorreta.';
            $senha_msg_type = 'error';
        } elseif (strlen($nova_senha) < 6) {
            $senha_msg = 'Nova senha deve ter ao menos 6 caracteres.';
            $senha_msg_type = 'error';
        } elseif ($nova_senha !== $confirma_senha) {
            $senha_msg = 'A confirmação não coincide com a nova senha.';
            $senha_msg_type = 'error';
        } else {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id")
                ->execute([':senha' => $hash, ':id' => $current_user_id]);
            $senha_msg = 'Senha alterada com sucesso!';
            $senha_msg_type = 'success';
        }
    }

    // Pagar fatura inteira de um cartão
    if (isset($_POST['action_pay_fatura'])) {
        $fatCardId = (int)($_POST['fatura_card_id'] ?? 0);
        $fatPeriod = trim($_POST['fatura_period'] ?? '');
        if ($fatCardId && $fatPeriod) {
            // Marcar todas as expenses desta fatura como pagas
            $stmtPayFat = $pdo->prepare("
                UPDATE expenses e
                JOIN expense_card_link ecl ON ecl.expense_id = e.id
                SET e.paid = 1, e.payment_date = COALESCE(e.payment_date, CURDATE())
                WHERE ecl.card_id = :cid AND ecl.fatura_period = :period AND e.user_id = :uid
            ");
            $stmtPayFat->execute([':cid'=>$fatCardId, ':period'=>$fatPeriod, ':uid'=>$current_user_id]);
        }
        header("Location: " . $redirectUrl); exit;
    }

    if (isset($_POST['edit_id']) && $_POST['edit_id'] !== '') {
        $id = (int)$_POST['edit_id'];
        $name = trim($_POST['name_single'] ?? '');
        $amount = str_replace(',', '.', trim($_POST['amount_single'] ?? ''));
        $due_date = dataParaBanco(trim($_POST['date_single'] ?? ''));
        $type = (isset($_POST['type_single']) && $_POST['type_single'] === 'income') ? 'income' : 'expense';
        $planned = (isset($_POST['planned_single']) && $_POST['planned_single'] == '1') ? 1 : 0;
        $paid = (isset($_POST['paid_single']) && $_POST['paid_single'] == '1') ? 1 : 0;
        if ($name && $amount && $due_date && is_numeric($amount)) {
            $period = (new DateTime($due_date))->format('Y-m');
            $pdo->prepare("UPDATE expenses SET name=:name, amount=:amount, due_date=:due_date, type=:type, planned=:planned, period=:period, paid=:paid, payment_date=CASE WHEN :paid2=1 THEN COALESCE(payment_date,CURDATE()) ELSE NULL END WHERE id=:id AND (user_id=:uid OR shared_with_user_id=:uid2)")
                ->execute([':name'=>$name,':amount'=>$amount,':due_date'=>$due_date,':type'=>$type,':planned'=>$planned,':period'=>$period,':paid'=>$paid,':paid2'=>$paid,':id'=>$id,':uid'=>$current_user_id,':uid2'=>$current_user_id]);
        }
        header("Location: " . $redirectUrl); exit;
    }

    if (isset($_POST['name']) && is_array($_POST['name'])) {
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, shared_with_user_id, name, amount, due_date, type, planned, period) VALUES (:user_id, :shared_uid, :name, :amount, :due_date, :type, :planned, :period)");
        $stmtCardLink = $pdo->prepare("INSERT INTO expense_card_link (expense_id, card_id, fatura_period) VALUES (:eid, :cid, :fatura)");

        foreach ($_POST['name'] as $i => $n) {
            $n = trim($n);
            $a = str_replace(',', '.', trim($_POST['amount'][$i] ?? ''));
            $d = dataParaBanco(trim($_POST['date'][$i] ?? ''));
            $type = isset($_POST['type'][$i]) && $_POST['type'][$i] === 'income' ? 'income' : 'expense';
            $planned = isset($_POST['planned'][$i]) && $_POST['planned'][$i] === '1' ? 1 : 0;
            $parcelas = isset($_POST['parcelas'][$i]) ? (int)$_POST['parcelas'][$i] : 0;
            $parcelado = isset($_POST['parcelado'][$i]) && $_POST['parcelado'][$i] === '1' && $parcelas > 1;
            $cardId = isset($_POST['card_id'][$i]) && $_POST['card_id'][$i] !== '' ? (int)$_POST['card_id'][$i] : null;
            $sharedWithUid = isset($_POST['shared_with'][$i]) && $_POST['shared_with'][$i] !== '' ? (int)$_POST['shared_with'][$i] : null;

            // Buscar dados do cartão selecionado
            $diaFechamento = null;
            $diaVencimento = null;
            if ($cardId) {
                $stmtFech = $pdo->prepare("SELECT dia_fechamento, dia_vencimento FROM credit_cards WHERE id=:id AND user_id=:uid");
                $stmtFech->execute([':id'=>$cardId, ':uid'=>$current_user_id]);
                $cardRow = $stmtFech->fetch(PDO::FETCH_ASSOC);
                if ($cardRow) {
                    $diaFechamento = (int)$cardRow['dia_fechamento'];
                    $diaVencimento = (int)$cardRow['dia_vencimento'];
                }
            }

            // Se tem cartão, usa data de hoje como data da compra para cálculo da fatura
            // A due_date é calculada automaticamente (dia_vencimento no mês da fatura)
            if ($cardId && $diaFechamento && $diaVencimento) {
                $purchaseDate = $d ?: date('Y-m-d'); // data informada ou hoje

                if ($n && $a && is_numeric($a)) {
                    if ($parcelado) {
                        $baseDate = new DateTime($purchaseDate);
                        for ($p = 1; $p <= $parcelas; $p++) {
                            $nomeParcela = $n . ' (' . $p . '/' . $parcelas . ')';
                            $parcelPurchase = clone $baseDate;
                            if ($p > 1) $parcelPurchase->modify('+' . ($p - 1) . ' months');

                            $faturaPeriod = calcularFaturaPeriod($parcelPurchase->format('Y-m-d'), $diaFechamento);
                            // due_date = dia_vencimento no mês da fatura
                            $fatY = (int)substr($faturaPeriod, 0, 4);
                            $fatM = (int)substr($faturaPeriod, 5, 2);
                            $lastD = (int)(new DateTime("{$fatY}-{$fatM}-01"))->format('t');
                            $dVenc = min($diaVencimento, $lastD);
                            $dueDate = sprintf('%04d-%02d-%02d', $fatY, $fatM, $dVenc);

                            $stmt->execute([':user_id'=>$current_user_id,':shared_uid'=>$sharedWithUid,':name'=>$nomeParcela,':amount'=>$a,':due_date'=>$dueDate,':type'=>'expense',':planned'=>$planned,':period'=>$faturaPeriod]);
                            $expenseId = $pdo->lastInsertId();
                            $stmtCardLink->execute([':eid'=>$expenseId, ':cid'=>$cardId, ':fatura'=>$faturaPeriod]);
                        }
                    } else {
                        $faturaPeriod = calcularFaturaPeriod($purchaseDate, $diaFechamento);
                        $fatY = (int)substr($faturaPeriod, 0, 4);
                        $fatM = (int)substr($faturaPeriod, 5, 2);
                        $lastD = (int)(new DateTime("{$fatY}-{$fatM}-01"))->format('t');
                        $dVenc = min($diaVencimento, $lastD);
                        $dueDate = sprintf('%04d-%02d-%02d', $fatY, $fatM, $dVenc);

                        $stmt->execute([':user_id'=>$current_user_id,':shared_uid'=>$sharedWithUid,':name'=>$n,':amount'=>$a,':due_date'=>$dueDate,':type'=>'expense',':planned'=>$planned,':period'=>$faturaPeriod]);
                        $expenseId = $pdo->lastInsertId();
                        $stmtCardLink->execute([':eid'=>$expenseId, ':cid'=>$cardId, ':fatura'=>$faturaPeriod]);
                    }
                }
            } else {
                // Lançamento sem cartão — fluxo normal
                if ($n && $a && $d && is_numeric($a)) {
                    if ($parcelado) {
                        $baseDate = new DateTime($d);
                        for ($p = 1; $p <= $parcelas; $p++) {
                            $nomeParcela = $n . ' (' . $p . '/' . $parcelas . ')';
                            $parcelDate = clone $baseDate;
                            if ($p > 1) {
                                $parcelDate->modify('+' . ($p - 1) . ' months');
                                $originalDay = (int)$baseDate->format('d');
                                $lastDayOfMonth = (int)$parcelDate->format('t');
                                if ($originalDay > $lastDayOfMonth) {
                                    $parcelDate->setDate((int)$parcelDate->format('Y'), (int)$parcelDate->format('m'), $lastDayOfMonth);
                                }
                            }
                            $parcelDateStr = $parcelDate->format('Y-m-d');
                            $stmt->execute([':user_id'=>$current_user_id,':shared_uid'=>$sharedWithUid,':name'=>$nomeParcela,':amount'=>$a,':due_date'=>$parcelDateStr,':type'=>$type,':planned'=>$planned,':period'=>$parcelDate->format('Y-m')]);
                        }
                    } else {
                        $stmt->execute([':user_id'=>$current_user_id,':shared_uid'=>$sharedWithUid,':name'=>$n,':amount'=>$a,':due_date'=>$d,':type'=>$type,':planned'=>$planned,':period'=>(new DateTime($d))->format('Y-m')]);
                    }
                }
            }
        }
        header("Location: " . $redirectUrl); exit;
    }
}

// -------------------- CÁLCULOS --------------------
// $data_user_id = usuário cujos dados estamos vendo (próprio ou conta compartilhada)
// Inclui também lançamentos compartilhados com o $data_user_id
$whereUser = "(user_id=:uid OR shared_with_user_id=:uid2)";

if ($selectedPeriod !== 'all') {
    $p = $pdo->prepare("SELECT SUM(CASE WHEN type='expense' AND paid=0 THEN amount ELSE 0 END) AS unpaid_expense, SUM(CASE WHEN type='income' AND paid=0 THEN amount ELSE 0 END) AS unpaid_income FROM expenses WHERE period=:period AND {$whereUser}");
    $p->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $pt = $p->fetch(PDO::FETCH_ASSOC);
    $due_now = $pt['unpaid_expense'] ?? 0;

    $f = $pdo->prepare("SELECT SUM(amount) as v FROM expenses WHERE paid=0 AND type='expense' AND period>:period AND {$whereUser}");
    $f->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $due_future = $f->fetch(PDO::FETCH_ASSOC)['v'] ?? 0;

    $t = $pdo->prepare("SELECT SUM(amount) as v FROM expenses WHERE paid=0 AND type='expense' AND {$whereUser}");
    $t->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $total_all = $t->fetch(PDO::FETCH_ASSOC)['v'] ?? 0;
} else {
    $tot = $pdo->prepare("SELECT SUM(CASE WHEN due_date<=:today AND paid=0 AND type='expense' THEN amount ELSE 0 END) AS due_now, SUM(CASE WHEN due_date>:today AND paid=0 AND type='expense' THEN amount ELSE 0 END) AS due_future, SUM(CASE WHEN paid=0 AND type='expense' THEN amount ELSE 0 END) AS total_all FROM expenses WHERE {$whereUser}");
    $tot->execute([':today'=>$today_iso,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $tv = $tot->fetch(PDO::FETCH_ASSOC);
    $due_now = $tv['due_now'] ?? 0;
    $due_future = $tv['due_future'] ?? 0;
    $total_all = $tv['total_all'] ?? 0;
}

$g = $pdo->prepare("SELECT SUM(CASE WHEN type='income' AND paid=1 THEN amount ELSE 0 END) as rec, SUM(CASE WHEN type='expense' AND paid=1 THEN amount ELSE 0 END) as pag FROM expenses WHERE {$whereUser}");
$g->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
$gv = $g->fetch(PDO::FETCH_ASSOC);
$balanco_geral = ($gv['rec'] ?? 0) - ($gv['pag'] ?? 0);

$periodsStmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(due_date, '%Y-%m') as period FROM expenses WHERE {$whereUser} ORDER BY period DESC");
$periodsStmt->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
$periods = $periodsStmt->fetchAll(PDO::FETCH_COLUMN);

$namesStmt = $pdo->prepare("SELECT DISTINCT name FROM expenses WHERE {$whereUser} ORDER BY name ASC");
$namesStmt->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
$suggestionNames = $namesStmt->fetchAll(PDO::FETCH_COLUMN);

if ($selectedPeriod !== 'all') {
    $listStmt = $pdo->prepare("SELECT * FROM expenses WHERE period=:period AND {$whereUser} ORDER BY type ASC, due_date ASC");
    $listStmt->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $sumStmt = $pdo->prepare("SELECT SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS te, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS ti FROM expenses WHERE period=:period AND {$whereUser}");
    $sumStmt->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $sv = $sumStmt->fetch(PDO::FETCH_ASSOC);
    $saldo_mes = ($sv['ti'] ?? 0) - ($sv['te'] ?? 0);
    $total_expense_month = $sv['te'] ?? 0;
    $total_income_month = $sv['ti'] ?? 0;
} else {
    $listStmt = $pdo->prepare("SELECT * FROM expenses WHERE {$whereUser} ORDER BY type ASC, due_date DESC LIMIT 200");
    $listStmt->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $saldo_mes = 0; $total_expense_month = 0; $total_income_month = 0;
}

// IDs de despesas vinculadas a cartão (não exibir individualmente)
$cardLinkedIds = [];
$clStmt = $pdo->prepare("SELECT expense_id FROM expense_card_link");
$clStmt->execute();
$cardLinkedIds = $clStmt->fetchAll(PDO::FETCH_COLUMN);

// Filtrar: remover despesas individuais de cartão, manter o resto
$rowsSemCartao = array_filter($rows, fn($r) => !in_array($r['id'], $cardLinkedIds));

// Buscar faturas consolidadas por cartão
$faturaRows = getFaturasConsolidadas($pdo, $data_user_id, $selectedPeriod !== 'all' ? $selectedPeriod : null);

// Juntar despesas normais + faturas consolidadas
$expenseRowsBase = array_filter($rowsSemCartao, fn($r) => ($r['type'] ?? 'expense') === 'expense');
$expenseRows = array_merge(array_values($expenseRowsBase), $faturaRows);
usort($expenseRows, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

$incomeRows  = array_filter($rowsSemCartao, fn($r) => ($r['type'] ?? 'expense') === 'income');

// ---- Dados de cartões de crédito ----
$cardMetrics = getConsolidatedCardMetrics($pdo, $data_user_id);
$userCardsStmt = $pdo->prepare("SELECT id, nome, dia_vencimento, dia_fechamento FROM credit_cards WHERE user_id = :uid AND ativo = 1 ORDER BY nome ASC");
$userCardsStmt->execute([':uid' => $current_user_id]);
$userCards = $userCardsStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Dados para gráfico "Top Despesas" ----
if ($selectedPeriod !== 'all') {
    $topStmt = $pdo->prepare("SELECT name, SUM(amount) as total FROM expenses WHERE type='expense' AND period=:period AND {$whereUser} GROUP BY name ORDER BY total DESC LIMIT 7");
    $topStmt->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
} else {
    $topStmt = $pdo->prepare("SELECT name, SUM(amount) as total FROM expenses WHERE type='expense' AND {$whereUser} GROUP BY name ORDER BY total DESC LIMIT 7");
    $topStmt->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
}
$topExpenses = $topStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Dados para gráfico "Despesas pagas vs pendentes" ----
if ($selectedPeriod !== 'all') {
    $paidStmt = $pdo->prepare("SELECT SUM(CASE WHEN paid=1 THEN amount ELSE 0 END) as pago, SUM(CASE WHEN paid=0 THEN amount ELSE 0 END) as pendente FROM expenses WHERE type='expense' AND period=:period AND {$whereUser}");
    $paidStmt->execute([':period'=>$selectedPeriod,':uid'=>$data_user_id,':uid2'=>$data_user_id]);
} else {
    $paidStmt = $pdo->prepare("SELECT SUM(CASE WHEN paid=1 THEN amount ELSE 0 END) as pago, SUM(CASE WHEN paid=0 THEN amount ELSE 0 END) as pendente FROM expenses WHERE type='expense' AND {$whereUser}");
    $paidStmt->execute([':uid'=>$data_user_id,':uid2'=>$data_user_id]);
}
$paidData = $paidStmt->fetch(PDO::FETCH_ASSOC);
$paidTotal = (float)($paidData['pago'] ?? 0);
$pendingTotal = (float)($paidData['pendente'] ?? 0);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas — Dashboard</title>
<link rel="icon" href="favicon.png" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    /* Dark (padrão — visual neon) */
    --ink: #e2e8f0;
    --bg: #06080f;
    --surface: #0d1117;
    --surface2: #161b26;
    --accent: #00d4ff;
    --accent-hover: #00b8e6;
    --accent-light: rgba(0,212,255,0.1);
    --purple: #a855f7;
    --purple-light: rgba(168,85,247,0.1);
    --glow-purple: 0 0 20px rgba(168,85,247,0.15);
    --success: #00ff88;
    --success-light: rgba(0,255,136,0.1);
    --danger: #ff2d55;
    --danger-light: rgba(255,45,85,0.1);
    --warning: #ffd600;
    --warning-light: rgba(255,214,0,0.1);
    --muted: #64748b;
    --border: rgba(255,255,255,0.08);
    --glow-accent: 0 0 20px rgba(0,212,255,0.15);
    --glow-danger: 0 0 20px rgba(255,45,85,0.15);
    --glow-success: 0 0 20px rgba(0,255,136,0.15);
    --glow-warning: 0 0 20px rgba(255,214,0,0.15);
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

/* ===== SIDEBAR ===== */
.sidebar {
    width: var(--sidebar-w);
    background: #080b12;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 100;
    transition: width 0.3s;
    border-right: 1px solid rgba(0,212,255,0.1);
}

.sidebar-logo {
    padding: 14px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}

.sidebar-logo-icon {
    width: 28px;
    height: 28px;
    background: rgba(0,212,255,0.15);
    border: 1px solid rgba(0,212,255,0.3);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 15px rgba(0,212,255,0.2);
}

.sidebar-logo-icon svg { width: 15px; height: 15px; color: var(--accent); }

.sidebar-logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
}

.sidebar-section {
    padding: 12px 8px 4px;
}

.sidebar-section-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(255,255,255,0.3);
    padding: 0 8px;
    margin-bottom: 6px;
}

.period-nav {
    flex: 1;
    overflow-y: auto;
    padding: 0 8px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}

.period-nav::-webkit-scrollbar { width: 4px; }
.period-nav::-webkit-scrollbar-track { background: transparent; }
.period-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.period-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 8px;
    border-radius: 8px;
    color: rgba(255,255,255,0.6);
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.15s;
    margin-bottom: 2px;
}

.period-item:hover {
    background: rgba(255,255,255,0.07);
    color: #fff;
}

.period-item.active {
    background: rgba(0,212,255,0.12);
    color: var(--accent);
    border: 1px solid rgba(0,212,255,0.25);
    box-shadow: 0 0 12px rgba(0,212,255,0.1);
}

.period-item svg { width: 14px; height: 14px; flex-shrink: 0; opacity: 0.7; }
.period-item.active svg { opacity: 1; }

.sidebar-footer {
    padding: 10px 8px;
    border-top: 1px solid rgba(255,255,255,0.07);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 8px;
    margin-bottom: 4px;
}

.user-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    flex-shrink: 0;
}

.user-name {
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
}

.sidebar-actions { display: flex; flex-direction: column; gap: 2px; }

.sidebar-action {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 8px;
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
    text-align: left;
    font-family: 'Sora', sans-serif;
}

.sidebar-action:hover { background: rgba(255,255,255,0.07); color: #fff; }
.sidebar-action svg { width: 13px; height: 13px; }

/* ===== MAIN ===== */
.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ===== TOPBAR ===== */
.topbar {
    background: rgba(13,17,23,0.85);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left h1 {
    font-size: 18px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
    letter-spacing: -0.5px;
}

.topbar-left p {
    font-size: 12px;
    color: var(--muted);
    margin-top: 1px;
}

.topbar-right { display: flex; align-items: center; gap: 10px; }

.btn-add-entry {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-add-entry:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 20px rgba(0,212,255,0.35); }
.btn-add-entry svg { width: 16px; height: 16px; }

/* ===== CONTENT ===== */
.content { padding: 24px 28px; flex: 1; }

/* ===== STAT CARDS ===== */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover { transform: translateY(-2px); }
.stat-card.danger { border-color: rgba(255,45,85,0.2); }
.stat-card.danger:hover { box-shadow: var(--glow-danger); }
.stat-card.warning { border-color: rgba(255,214,0,0.2); }
.stat-card.warning:hover { box-shadow: var(--glow-warning); }
.stat-card.neutral { border-color: rgba(100,116,139,0.2); }
.stat-card.accent { border-color: rgba(0,212,255,0.2); }
.stat-card.accent:hover { box-shadow: var(--glow-accent); }
.stat-card.success { border-color: rgba(0,255,136,0.2); }
.stat-card.success:hover { box-shadow: var(--glow-success); }

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.stat-card.danger::before { background: linear-gradient(90deg, var(--danger), transparent); }
.stat-card.warning::before { background: linear-gradient(90deg, var(--warning), transparent); }
.stat-card.neutral::before { background: linear-gradient(90deg, var(--muted), transparent); }
.stat-card.accent::before { background: linear-gradient(90deg, var(--accent), transparent); }
.stat-card.success::before { background: linear-gradient(90deg, var(--success), transparent); }
.stat-card.purple { border-color: rgba(168,85,247,0.2); }
.stat-card.purple:hover { box-shadow: var(--glow-purple); }
.stat-card.purple::before { background: linear-gradient(90deg, var(--purple), transparent); }

.stat-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-bottom: 8px;
}

.stat-value {
    font-size: 22px;
    font-weight: 700;
    font-family: 'Space Mono', monospace;
    color: var(--ink);
    letter-spacing: -0.5px;
}

.stat-value.positive { color: var(--success); }
.stat-value.negative { color: var(--danger); }

.stat-icon {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon svg { width: 18px; height: 18px; }
.stat-card.danger .stat-icon { background: rgba(255,45,85,0.12); color: var(--danger); }
.stat-card.warning .stat-icon { background: rgba(255,214,0,0.12); color: var(--warning); }
.stat-card.neutral .stat-icon { background: var(--surface2); color: var(--muted); }
.stat-card.accent .stat-icon { background: rgba(0,212,255,0.12); color: var(--accent); }
.stat-card.success .stat-icon { background: rgba(0,255,136,0.12); color: var(--success); }
.stat-card.purple .stat-icon { background: rgba(168,85,247,0.12); color: var(--purple); }

/* ===== CHARTS GRID ===== */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.chart-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px 24px;
    border: 1px solid rgba(255,255,255,0.06);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.3s;
}

.chart-card:hover {
    transform: translateY(-2px);
}

/* Neon border glow effect */
.neon-border::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: var(--radius);
    padding: 1.5px;
    background: var(--neon-gradient);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.neon-border::after {
    content: '';
    position: absolute;
    inset: -1px;
    border-radius: var(--radius);
    background: var(--neon-gradient);
    opacity: 0.08;
    filter: blur(12px);
    pointer-events: none;
    z-index: -1;
}

.neon-cyan  { --neon-gradient: linear-gradient(135deg, #00d4ff, #0099cc, #00d4ff); }
.neon-green { --neon-gradient: linear-gradient(135deg, #00ff88, #00cc6a, #00ff88); }
.neon-red   { --neon-gradient: linear-gradient(135deg, #ff2d55, #cc2244, #ff2d55); }
.neon-purple { --neon-gradient: linear-gradient(135deg, #a855f7, #7c3aed, #a855f7); }
.neon-yellow { --neon-gradient: linear-gradient(135deg, #ffd600, #ccaa00, #ffd600); }

.neon-cyan:hover  { box-shadow: 0 0 30px rgba(0,212,255,0.15), 0 0 60px rgba(0,212,255,0.05); }
.neon-green:hover { box-shadow: 0 0 30px rgba(0,255,136,0.15), 0 0 60px rgba(0,255,136,0.05); }
.neon-red:hover   { box-shadow: 0 0 30px rgba(255,45,85,0.15), 0 0 60px rgba(255,45,85,0.05); }
.neon-purple:hover { box-shadow: 0 0 30px rgba(168,85,247,0.15), 0 0 60px rgba(168,85,247,0.05); }
.neon-yellow:hover { box-shadow: 0 0 30px rgba(255,214,0,0.15), 0 0 60px rgba(255,214,0,0.05); }

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.chart-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
}

.chart-subtitle { font-size: 12px; color: var(--muted); margin-top: 2px; }

.pie-empty {
    font-size: 11px;
    color: var(--muted);
    text-align: center;
    padding: 12px;
}

@media (max-width: 1200px) {
    .charts-grid { grid-template-columns: repeat(2, 1fr); }
    .charts-grid .chart-card:first-child { grid-column: span 2; }
}

@media (max-width: 768px) {
    .charts-grid { grid-template-columns: 1fr; }
    .charts-grid .chart-card:first-child { grid-column: span 1; }
}

/* Dark Mode Toggle Button */
.btn-theme-toggle {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: all 0.2s;
    flex-shrink: 0;
}

.btn-theme-toggle:hover {
    background: var(--surface2);
    transform: rotate(15deg) scale(1.1);
}

/* ===== LIGHT MODE ===== */
body.light {
    --ink: #0d1117;
    --bg: #f0f2f7;
    --surface: #ffffff;
    --surface2: #f8f9fc;
    --accent: #2563eb;
    --accent-hover: #1d4ed8;
    --accent-light: #eff6ff;
    --success: #059669;
    --success-light: #ecfdf5;
    --danger: #dc2626;
    --danger-light: #fef2f2;
    --warning: #d97706;
    --warning-light: #fffbeb;
    --muted: #6b7280;
    --border: #e5e7eb;
    --glow-accent: 0 4px 16px rgba(37,99,235,0.1);
    --glow-danger: 0 4px 16px rgba(220,38,38,0.1);
    --glow-success: 0 4px 16px rgba(5,150,105,0.1);
    --glow-warning: 0 4px 16px rgba(217,119,6,0.1);
}

body.light::before,
body.light::after { display: none; }

body.light .sidebar { background: #0d1117; border-right: 1px solid rgba(255,255,255,0.07); }
body.light .sidebar-logo-icon { background: var(--accent); border-color: transparent; box-shadow: none; }
body.light .sidebar-logo-icon svg { color: #fff; }
body.light .period-item.active { background: var(--accent); color: #fff; border-color: transparent; box-shadow: none; }

body.light .topbar { background: var(--surface); backdrop-filter: none; }

body.light .stat-card.danger { border-color: var(--border); }
body.light .stat-card.warning { border-color: var(--border); }
body.light .stat-card.neutral { border-color: var(--border); }
body.light .stat-card.accent { border-color: var(--border); }
body.light .stat-card.success { border-color: var(--border); }
body.light .stat-card.purple { border-color: var(--border); }
body.light .stat-card.danger::before { background: var(--danger); }
body.light .stat-card.warning::before { background: var(--warning); }
body.light .stat-card.accent::before { background: var(--accent); }
body.light .stat-card.success::before { background: var(--success); }
body.light .stat-card.purple::before { background: var(--purple, #7c3aed); }

body.light .stat-card.danger .stat-icon { background: var(--danger-light); color: var(--danger); }
body.light .stat-card.warning .stat-icon { background: var(--warning-light); color: var(--warning); }
body.light .stat-card.accent .stat-icon { background: var(--accent-light); color: var(--accent); }
body.light .stat-card.success .stat-icon { background: var(--success-light); color: var(--success); }
body.light .stat-card.purple .stat-icon { background: rgba(124,58,237,0.1); color: #7c3aed; }

body.light .chart-card { border-color: var(--border); }
body.light .neon-border::after { opacity: 0.04; }
body.light .table-card { border-color: var(--border); }
body.light .data-table tbody tr:hover { background: #fafbfc; }

body.light .badge.paid { background: var(--success-light); color: var(--success); border-color: transparent; }
body.light .badge.unpaid { background: var(--danger-light); color: var(--danger); border-color: transparent; }
body.light .badge.planned { background: var(--accent-light); color: var(--accent); border-color: transparent; }

body.light .btn-icon.toggle { background: #eff6ff; color: var(--accent); }
body.light .btn-icon.toggle:hover { background: var(--accent); color: #fff; }
body.light .btn-icon.edit { background: #fffbeb; color: var(--warning); }
body.light .btn-icon.edit:hover { background: var(--warning); color: #fff; }
body.light .btn-icon.del { background: #fef2f2; color: var(--danger); }
body.light .btn-icon.del:hover { background: var(--danger); color: #fff; }

body.light .entry-row input,
body.light .entry-row select,
body.light .edit-field input,
body.light .edit-field select { background: #fff; }

body.light .copy-prev-btn:hover { background: #eef2ff; border-color: var(--accent); color: var(--accent); }
body.light .modal-overlay { background: rgba(0,0,0,0.3); }

/* ===== PERIOD SUMMARY ===== */
.period-summary {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 16px 20px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.summary-item { text-align: center; }
.summary-item .label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.summary-item .value { font-size: 17px; font-weight: 700; font-family: 'Space Mono', monospace; margin-top: 3px; }
.summary-item .value.red { color: var(--danger); }
.summary-item .value.green { color: var(--success); }

.summary-divider { width: 1px; height: 40px; background: var(--border); }

.copy-prev-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--surface2);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    cursor: pointer;
}
.copy-prev-btn:hover { background: rgba(0,212,255,0.08); border-color: var(--accent); color: var(--accent); }
.copy-prev-btn svg { width: 14px; height: 14px; }

/* ===== TABLES ===== */
.tables-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.table-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid rgba(255,255,255,0.06);
    overflow: hidden;
}

.table-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.table-card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
}

.table-card-title .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.dot.red { background: var(--danger); }
.dot.green { background: var(--success); }

.table-card-count {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 20px;
    background: var(--surface2);
    color: var(--muted);
}

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
    padding: 11px 16px;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.data-table tbody tr:last-child td { border-bottom: none; }

.data-table tbody tr:hover { background: rgba(0,212,255,0.03); }

.item-name { font-weight: 500; }
.item-amount { font-family: 'Space Mono', monospace; font-size: 13px; font-weight: 700; }
.item-date { color: var(--muted); font-size: 12px; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.badge.paid { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.2); }
.badge.unpaid { background: rgba(255,45,85,0.1); color: var(--danger); border: 1px solid rgba(255,45,85,0.2); }
.badge.planned { background: rgba(0,212,255,0.1); color: var(--accent); border: 1px solid rgba(0,212,255,0.2); }

.action-btns { display: flex; gap: 4px; align-items: center; }

.btn-icon {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    font-size: 12px;
}

.btn-icon svg { width: 13px; height: 13px; }
.btn-icon.toggle { background: rgba(0,212,255,0.1); color: var(--accent); }
.btn-icon.toggle:hover { background: var(--accent); color: #0a0e1a; }
.btn-icon.edit { background: rgba(255,214,0,0.1); color: var(--warning); }
.btn-icon.edit:hover { background: var(--warning); color: #0a0e1a; }
.btn-icon.del { background: rgba(255,45,85,0.1); color: var(--danger); }
.btn-icon.del:hover { background: var(--danger); color: #0a0e1a; }

/* ===== EMPTY STATE ===== */
.empty-state {
    padding: 32px;
    text-align: center;
    color: var(--muted);
}

.empty-state svg { width: 32px; height: 32px; opacity: 0.3; margin-bottom: 8px; }
.empty-state p { font-size: 13px; }

/* ===== ADD ENTRY MODAL ===== */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(8px);
    z-index: 200;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.open { display: flex; }

.modal {
    background: var(--surface);
    border-radius: 16px;
    width: 100%;
    max-width: 900px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px rgba(0,0,0,0.4), 0 0 40px rgba(0,212,255,0.05);
    border: 1px solid rgba(0,212,255,0.1);
    animation: modalIn 0.2s ease;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title { font-size: 15px; font-weight: 700; }

.modal-close {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: none;
    background: var(--surface2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
    font-size: 18px;
    line-height: 1;
    color: var(--muted);
}

.modal-close:hover { background: var(--border); color: var(--ink); }

.modal-body { padding: 20px 24px; }

.entry-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto auto 1fr 1fr auto;
    gap: 8px;
    margin-bottom: 8px;
    align-items: end;
}

.entry-row label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    display: block;
    margin-bottom: 4px;
}

.entry-row input,
.entry-row select {
    width: 100%;
    padding: 9px 10px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: var(--surface2);
    transition: border-color 0.2s, box-shadow 0.2s;
}

.entry-row input:focus,
.entry-row select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,212,255,0.1);
}

.modal-footer {
    padding: 16px 24px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid var(--border);
}

.btn-add-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--ink);
}

.btn-add-row:hover { background: rgba(0,212,255,0.08); border-color: var(--accent); color: var(--accent); }

.btn-save {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-save:hover { background: var(--accent-hover); }

/* ===== EDIT CARD ===== */
.edit-panel {
    background: var(--surface);
    border-radius: var(--radius);
    border: 2px solid var(--accent);
    padding: 20px 24px;
    margin-bottom: 20px;
}

.edit-panel h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--accent); }

.edit-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 12px; }

.edit-field label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 4px;
}

.edit-field input,
.edit-field select {
    width: 100%;
    padding: 9px 10px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: var(--surface2);
    transition: border-color 0.2s;
}

.edit-field input:focus, .edit-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,212,255,0.1);
}

.edit-actions { display: flex; gap: 8px; margin-top: 16px; }

.btn-secondary {
    padding: 9px 16px;
    background: var(--surface2);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all 0.15s;
}

.btn-secondary:hover { background: var(--border); }

/* ===== DATALIST ===== */
datalist { display: none; }

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
body.light ::-webkit-scrollbar-thumb { background: #d1d5db; }
body.light ::-webkit-scrollbar-thumb:hover { background: #9ca3af; }

/* ===== RESPONSIVE ===== */
@media (max-width: 1100px) {
    .tables-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    :root { --sidebar-w: 60px; }
    .sidebar-logo-text, .sidebar-section-label, .period-item span, .user-name, .user-role, .sidebar-action span { display: none; }
    .sidebar-logo { justify-content: center; }
    .period-item { justify-content: center; }
    .sidebar-action { justify-content: center; }
    .user-info { justify-content: center; }
    .main { margin-left: 60px; }
    .content { padding: 16px; }
    .topbar { padding: 12px 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .entry-row { grid-template-columns: 1fr; }
    .edit-grid { grid-template-columns: 1fr 1fr; }
}
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
        <div class="sidebar-section-label">Períodos</div>
        <a href="?period=all" class="period-item <?= $selectedPeriod === 'all' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h7" />
            </svg>
            <span>Todos</span>
        </a>
    </div>

    <div class="period-nav">
        <?php foreach ($periods as $p): ?>
            <?php if (!$p) continue; ?>
            <a href="?period=<?= urlencode($p) ?>" class="period-item <?= $selectedPeriod === $p ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span><?= safe(periodoBr($p)) ?></span>
            </a>
        <?php endforeach; ?>
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
            <a href="cartoes.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span>Cartões</span>
            </a>
            <a href="compartilhamento.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span>Compartilhamento</span>
            </a>
            <a href="#" class="sidebar-action" onclick="document.getElementById('passwordModal').classList.add('open'); return false;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                <span>Minha conta</span>
            </a>
            <?php if ($is_admin): ?>
            <a href="usuarios.php" class="sidebar-action">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span>Usuários</span>
            </a>
            <?php endif; ?>
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

    <?php if ($viewing_shared): ?>
    <!-- BANNER CONTA COMPARTILHADA -->
    <div style="background:linear-gradient(90deg, rgba(168,85,247,0.15), rgba(0,212,255,0.1)); border-bottom:1px solid rgba(168,85,247,0.3); padding:10px 32px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:18px;">👥</span>
            <span style="font-size:13px;font-weight:600;">Visualizando conta de <strong style="color:var(--purple, #a855f7);"><?= safe($shared_owner_name) ?></strong></span>
            <span style="font-size:11px;color:var(--muted);">Lançamentos sem 👥 são somente visualização</span>
        </div>
        <a href="?action=exit_shared" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;text-decoration:none;font-size:12px;font-weight:600;transition:all 0.15s;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            Voltar para minha conta
        </a>
    </div>
    <?php endif; ?>

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <h1><?= $selectedPeriod !== 'all' ? periodoBr($selectedPeriod) : 'Todos os períodos' ?></h1>
            <p>Hoje: <?= safe($today_br) ?></p>
        </div>
        <div class="topbar-right">
            <?php if ($selectedPeriod !== 'all'): ?>
            <a href="?action=copy_prev&period=<?= $selectedPeriod ?>" class="copy-prev-btn"
               onclick="return confirm('Copiar todos os lançamentos do mês anterior?')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Importar mês anterior
            </a>
            <?php endif; ?>
            <div class="notif-wrapper" style="position:relative;">
                <button class="btn-theme-toggle" id="notifBell" title="Notificações" onclick="toggleNotifPanel()" style="width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;font-size:16px;position:relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;color:var(--ink);">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <span id="notifBadge" style="display:none;position:absolute;top:-4px;right:-4px;width:18px;height:18px;border-radius:50%;background:var(--danger);color:#fff;font-size:10px;font-weight:700;line-height:18px;text-align:center;"></span>
                </button>
                <div class="notif-panel" id="notifPanel" style="display:none;position:absolute;top:44px;right:0;width:320px;max-height:400px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.3);z-index:100;padding:0;">
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:13px;font-weight:700;">Notificações</span>
                        <button onclick="markAllNotifRead()" style="font-size:11px;color:var(--accent);background:none;border:none;cursor:pointer;font-family:'Sora',sans-serif;">Marcar todas como lidas</button>
                    </div>
                    <div id="notifList" style="padding:8px;"></div>
                </div>
            </div>
            <button class="btn-theme-toggle" id="themeToggle" title="Alternar tema" onclick="toggleTheme()">
                <span id="themeIcon">🌙</span>
            </button>
            <button class="btn-add-entry" onclick="document.getElementById('addModal').classList.add('open')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Novo lançamento
            </button>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- STAT CARDS -->
        <div class="stat-grid">
            <div class="stat-card danger">
                <div class="stat-label"><?= $selectedPeriod !== 'all' ? 'Despesas pendentes' : 'Dívidas atuais' ?></div>
                <div class="stat-value"><?= money($due_now) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
                    </svg>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label">Dívidas futuras</div>
                <div class="stat-value"><?= money($due_future) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="stat-card neutral">
                <div class="stat-label">Total não pago</div>
                <div class="stat-value"><?= money($total_all) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                    </svg>
                </div>
            </div>

            <div class="stat-card <?= ($selectedPeriod !== 'all' ? ($saldo_mes >= 0 ? 'success' : 'danger') : ($balanco_geral >= 0 ? 'success' : 'danger')) ?>">
                <div class="stat-label"><?= $selectedPeriod !== 'all' ? 'Saldo do mês' : 'Balanço geral' ?></div>
                <?php $bv = $selectedPeriod !== 'all' ? $saldo_mes : $balanco_geral; ?>
                <div class="stat-value <?= $bv >= 0 ? 'positive' : 'negative' ?>"><?= money($bv) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- CARD STAT CARDS (consolidado de cartões) -->
        <?php if ($cardMetrics['qtd_cartoes'] > 0): ?>
        <div class="stat-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 24px;">
            <div class="stat-card purple">
                <div class="stat-label">Limite cartões</div>
                <div class="stat-value"><?= money($cardMetrics['limite_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Comprometido</div>
                <div class="stat-value"><?= money($cardMetrics['usado_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" /></svg>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Disponível cartões</div>
                <div class="stat-value"><?= money($cardMetrics['disponivel_total']) ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
            </div>
            <div class="stat-card accent">
                <div class="stat-label">Cartões ativos</div>
                <div class="stat-value"><?= $cardMetrics['qtd_cartoes'] ?></div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                </div>
            </div>
            <div class="stat-card <?= $cardMetrics['percentual_geral'] > 80 ? 'danger' : ($cardMetrics['percentual_geral'] > 50 ? 'warning' : 'accent') ?>">
                <div class="stat-label">% Utilização</div>
                <div class="stat-value"><?= number_format($cardMetrics['percentual_geral'], 1, ',', '.') ?>%</div>
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" /><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" /></svg>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- CHARTS GRID -->
        <div class="charts-grid">
            <!-- 1. Histórico mensal (barras) -->
            <div class="chart-card neon-border neon-cyan" style="grid-column: span 2;">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Histórico mensal</div>
                        <div class="chart-subtitle">Receitas vs Despesas — últimos 12 meses</div>
                    </div>
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>

            <!-- 2. Evolução do Saldo (linha) -->
            <div class="chart-card neon-border neon-green">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Evolução do Saldo</div>
                        <div class="chart-subtitle">Saldo acumulado mês a mês</div>
                    </div>
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="balanceChart"></canvas>
                </div>
            </div>

            <!-- 3. Despesas por categoria (doughnut) -->
            <div class="chart-card neon-border neon-purple">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Despesas por Categoria</div>
                        <div class="chart-subtitle">Distribuição do período atual</div>
                    </div>
                </div>
                <div style="position:relative;height:200px;display:flex;align-items:center;justify-content:center;">
                    <canvas id="pieChart"></canvas>
                    <div id="pieEmpty" class="pie-empty" style="display:none;">Sem dados no período</div>
                </div>
            </div>

            <!-- 4. Top Despesas (barras horizontais) -->
            <div class="chart-card neon-border neon-red">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Top Despesas</div>
                        <div class="chart-subtitle">Maiores gastos do período</div>
                    </div>
                </div>
                <div style="position:relative;height:200px;">
                    <canvas id="topExpensesChart"></canvas>
                    <div id="topEmpty" class="pie-empty" style="display:none;">Sem despesas no período</div>
                </div>
            </div>

            <!-- 5. Pagas vs Pendentes (doughnut com centro) -->
            <div class="chart-card neon-border neon-yellow">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Pagas vs Pendentes</div>
                        <div class="chart-subtitle">Status das despesas</div>
                    </div>
                </div>
                <div style="position:relative;height:200px;display:flex;align-items:center;justify-content:center;">
                    <canvas id="paidChart"></canvas>
                    <div id="paidEmpty" class="pie-empty" style="display:none;">Sem despesas no período</div>
                </div>
            </div>
        </div>

        <?php if ($selectedPeriod !== 'all'): ?>
        <!-- PERIOD SUMMARY -->
        <div class="period-summary">
            <div class="summary-item">
                <div class="label">Despesas</div>
                <div class="value red"><?= money($total_expense_month) ?></div>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
                <div class="label">Receitas</div>
                <div class="value green"><?= money($total_income_month) ?></div>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
                <div class="label">Saldo</div>
                <div class="value <?= $saldo_mes >= 0 ? 'green' : 'red' ?>"><?= money($saldo_mes) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EDIT PANEL -->
        <?php if ($editRow): ?>
        <div class="edit-panel">
            <h3>✏️ Editando lançamento #<?= safe($editRow['id']) ?></h3>
            <form method="post">
                <input type="hidden" name="edit_id" value="<?= (int)$editRow['id'] ?>">
                <div class="edit-grid">
                    <div class="edit-field">
                        <label>Nome</label>
                        <input name="name_single" type="text" value="<?= safe($editRow['name']) ?>" required list="user-expense-names">
                    </div>
                    <div class="edit-field">
                        <label>Valor</label>
                        <input name="amount_single" type="text" value="<?= safe(str_replace('.', ',', $editRow['amount'])) ?>" required>
                    </div>
                    <div class="edit-field">
                        <label>Vencimento</label>
                        <input name="date_single" type="date" value="<?= safe($editRow['due_date']) ?>" required>
                    </div>
                    <div class="edit-field">
                        <label>Tipo</label>
                        <select name="type_single">
                            <option value="expense" <?= ($editRow['type'] ?? '') === 'expense' ? 'selected' : '' ?>>Despesa</option>
                            <option value="income" <?= ($editRow['type'] ?? '') === 'income' ? 'selected' : '' ?>>Receita</option>
                        </select>
                    </div>
                    <div class="edit-field">
                        <label>Planificado</label>
                        <select name="planned_single">
                            <option value="0" <?= empty($editRow['planned']) ? 'selected' : '' ?>>Não</option>
                            <option value="1" <?= !empty($editRow['planned']) ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="edit-field">
                        <label>Pago</label>
                        <select name="paid_single">
                            <option value="0" <?= empty($editRow['paid']) ? 'selected' : '' ?>>Não</option>
                            <option value="1" <?= !empty($editRow['paid']) ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                </div>
                <div class="edit-actions">
                    <button type="submit" class="btn-save">Salvar</button>
                    <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>?period=<?= $selectedPeriod ?>" class="btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABLES -->
        <div class="tables-grid">
            <!-- EXPENSES -->
            <div class="table-card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="dot red"></span>
                        Despesas
                    </div>
                    <span class="table-card-count"><?= count($expenseRows) ?></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Valor</th>
                            <th>Venc.</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenseRows as $r):
                            $isFatura = !empty($r['is_fatura']);
                            $isShared = !empty($r['shared_with_user_id']);
                            if ($isFatura) {
                                $canAct = false; // Faturas consolidadas não têm ação individual
                            } else {
                                $canAct = ($r['user_id'] == $current_user_id || (isset($r['shared_with_user_id']) && $r['shared_with_user_id'] == $current_user_id));
                                if ($viewing_shared && !$isShared) $canAct = false;
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="item-name"><?php if ($isFatura): ?><a href="cartoes.php?view=<?= $r['card_id'] ?>" title="Ver detalhes do cartão" style="text-decoration:none;">💳 <?= safe($r['name']) ?></a><?php elseif ($isShared): ?><span title="Lançamento compartilhado" style="margin-right:4px;">👥</span><?= safe($r['name']) ?><?php else: ?><?= safe($r['name']) ?><?php endif; ?></div>
                                <?php if (!empty($r['planned'])): ?>
                                <span class="badge planned" style="margin-top:2px;font-size:10px;">Planificado</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="item-amount" style="color:var(--danger)"><?= money($r['amount']) ?></span></td>
                            <td><span class="item-date"><?= safe(formataDataBr($r['due_date'])) ?></span></td>
                            <td>
                                <?php if ($r['paid']): ?>
                                    <span class="badge paid">✓ Pago</span>
                                <?php else: ?>
                                    <span class="badge unpaid">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canAct): ?>
                                <div class="action-btns">
                                    <a class="btn-icon toggle" href="?action=toggle&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="<?= $r['paid'] ? 'Desmarcar' : 'Marcar pago' ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon edit" href="?action=edit&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon del" href="?action=delete&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Excluir" onclick="return confirm('Excluir este lançamento?')">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </a>
                                </div>
                                <?php elseif ($isFatura): ?>
                                <div class="action-btns">
                                    <?php if (!$r['paid']): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action_pay_fatura" value="1">
                                        <input type="hidden" name="fatura_card_id" value="<?= (int)$r['card_id'] ?>">
                                        <input type="hidden" name="fatura_period" value="<?= safe($r['period']) ?>">
                                        <button type="submit" class="btn-icon toggle" title="Pagar fatura inteira" onclick="return confirm('Marcar TODOS os lançamentos desta fatura como pagos?')" style="width:auto;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:\'Sora\',sans-serif;gap:4px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="width:12px;height:12px;">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Pagar Fatura
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="cartoes.php?view=<?= (int)$r['card_id'] ?>" class="btn-icon edit" title="Ver detalhes do cartão" style="width:auto;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:500;font-family:'Sora',sans-serif;text-decoration:none;gap:4px;">
                                        Detalhes
                                    </a>
                                </div>
                                <?php else: ?>
                                <span style="font-size:11px;color:var(--muted);">Somente visualização</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($expenseRows) === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p>Nenhuma despesa neste período</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- INCOME -->
            <div class="table-card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="dot green"></span>
                        Receitas
                    </div>
                    <span class="table-card-count"><?= count($incomeRows) ?></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Valor</th>
                            <th>Venc.</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeRows as $r):
                            $isShared = !empty($r['shared_with_user_id']);
                            $canAct = ($r['user_id'] == $current_user_id || (isset($r['shared_with_user_id']) && $r['shared_with_user_id'] == $current_user_id));
                            if ($viewing_shared && !$isShared) $canAct = false;
                        ?>
                        <tr>
                            <td>
                                <div class="item-name"><?php if ($isShared): ?><span title="Lançamento compartilhado" style="margin-right:4px;">👥</span><?php endif; ?><?= safe($r['name']) ?></div>
                                <?php if (!empty($r['planned'])): ?>
                                <span class="badge planned" style="margin-top:2px;font-size:10px;">Planificado</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="item-amount" style="color:var(--success)"><?= money($r['amount']) ?></span></td>
                            <td><span class="item-date"><?= safe(formataDataBr($r['due_date'])) ?></span></td>
                            <td>
                                <?php if ($r['paid']): ?>
                                    <span class="badge paid">✓ Recebido</span>
                                <?php else: ?>
                                    <span class="badge unpaid">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canAct): ?>
                                <div class="action-btns">
                                    <a class="btn-icon toggle" href="?action=toggle&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="<?= $r['paid'] ? 'Desmarcar' : 'Marcar recebido' ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon edit" href="?action=edit&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                    <a class="btn-icon del" href="?action=delete&id=<?= (int)$r['id'] ?>&period=<?= $selectedPeriod ?>" title="Excluir" onclick="return confirm('Excluir este lançamento?')">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </a>
                                </div>
                                <?php else: ?>
                                <span style="font-size:11px;color:var(--muted);">Somente visualização</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($incomeRows) === 0): ?>
                        <tr><td colspan="5">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p>Nenhuma receita neste período</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</main>

<!-- ===== ADD ENTRY MODAL ===== -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Novo lançamento</div>
            <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">×</button>
        </div>
        <div class="modal-body">
            <form method="post" id="addForm">
                <datalist id="user-expense-names">
                    <?php foreach ($suggestionNames as $n): ?><option value="<?= safe($n) ?>"><?php endforeach; ?>
                </datalist>
                <div id="entryRows">
                    <div class="entry-row">
                        <div>
                            <label>Nome / Descrição</label>
                            <input name="name[]" type="text" placeholder="Ex: Aluguel" list="user-expense-names" autocomplete="off" required>
                        </div>
                        <div>
                            <label>Valor</label>
                            <input name="amount[]" type="text" inputmode="decimal" placeholder="0,00" required>
                        </div>
                        <div class="date-field">
                            <label>Vencimento</label>
                            <input name="date[]" type="date" value="<?= $today_iso ?>" required>
                        </div>
                        <div>
                            <label>Tipo</label>
                            <select name="type[]">
                                <option value="expense">Despesa</option>
                                <option value="income">Receita</option>
                            </select>
                        </div>
                        <div>
                            <label>Planificado</label>
                            <select name="planned[]">
                                <option value="1">Sim</option>
                                <option value="0">Não</option>
                            </select>
                        </div>
                        <div>
                            <label>Parcelado</label>
                            <select name="parcelado[]" onchange="toggleParcelas(this)">
                                <option value="0">Não</option>
                                <option value="1">Sim</option>
                            </select>
                        </div>
                        <div class="parcelas-field" style="display:none;">
                            <label>Parcelas</label>
                            <input name="parcelas[]" type="number" min="2" max="99" value="2" style="width:70px;">
                        </div>
                        <?php if ($userCards): ?>
                        <div>
                            <label>Cartão</label>
                            <select name="card_id[]" onchange="toggleCardDate(this)">
                                <option value="" data-venc="" data-fech="">Nenhum</option>
                                <?php foreach ($userCards as $uc): ?>
                                <option value="<?= $uc['id'] ?>" data-venc="<?= $uc['dia_vencimento'] ?>" data-fech="<?= $uc['dia_fechamento'] ?>"><?= safe($uc['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="card_id[]" value="">
                        <?php endif; ?>
                        <?php if (!empty($sharePartners)): ?>
                        <div>
                            <label>Compartilhar com</label>
                            <select name="shared_with[]">
                                <option value="">Nenhum</option>
                                <?php foreach ($sharePartners as $spId => $spName): ?>
                                <option value="<?= (int)$spId ?>"><?= safe($spName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="shared_with[]" value="">
                        <?php endif; ?>
                        <div style="padding-bottom:1px;">
                            <label style="visibility:hidden">x</label>
                            <button type="button" class="btn-icon del" onclick="removeEntryRow(this)" style="width:36px;height:36px;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-add-row" onclick="addEntryRow()">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Adicionar linha
            </button>
            <button type="submit" form="addForm" class="btn-save">Salvar lançamentos</button>
        </div>
    </div>
</div>

<!-- ===== MODAL ALTERAR SENHA ===== -->
<div class="modal-overlay" id="passwordModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Alterar minha senha</span>
            <button class="modal-close" onclick="document.getElementById('passwordModal').classList.remove('open')">&times;</button>
        </div>
        <form method="post" action="">
            <input type="hidden" name="action_change_password" value="1">
            <div class="modal-body">
                <?php if ($senha_msg): ?>
                <div style="padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;
                    background:<?= $senha_msg_type === 'success' ? 'rgba(0,255,136,0.1)' : 'rgba(255,45,85,0.1)' ?>;
                    color:<?= $senha_msg_type === 'success' ? 'var(--success)' : 'var(--danger)' ?>;
                    border:1px solid <?= $senha_msg_type === 'success' ? 'rgba(0,255,136,0.25)' : 'rgba(255,45,85,0.25)' ?>;">
                    <?= safe($senha_msg) ?>
                </div>
                <?php endif; ?>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--muted);margin-bottom:4px;">Senha atual</label>
                    <input type="password" name="senha_atual" required style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--muted);margin-bottom:4px;">Nova senha</label>
                    <input type="password" name="nova_senha" required minlength="6" style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
                <div style="margin-bottom:6px;">
                    <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--muted);margin-bottom:4px;">Confirmar nova senha</label>
                    <input type="password" name="confirma_senha" required minlength="6" style="width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:var(--ink);background:var(--surface2);">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-add-row" onclick="document.getElementById('passwordModal').classList.remove('open')">Cancelar</button>
                <button type="submit" class="btn-save">Alterar senha</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ===== TEMA (dark padrão, light alternativo) =====
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
    buildCharts();
}

function getChartColors() {
    const light = document.body.classList.contains('light');
    return {
        gridColor: light ? '#f3f4f6' : 'rgba(255,255,255,0.05)',
        tickColor: light ? '#6b7280' : '#64748b',
        expenseBg: light ? 'rgba(220,38,38,0.12)' : 'rgba(255,45,85,0.15)',
        expenseBorder: light ? '#dc2626' : '#ff2d55',
        incomeBg: light ? 'rgba(5,150,105,0.12)' : 'rgba(0,255,136,0.12)',
        incomeBorder: light ? '#059669' : '#00ff88',
        balanceLine: light ? '#059669' : '#00ff88',
        balanceNeg: light ? '#dc2626' : '#ff2d55',
    };
}

const chartInstances = {};

function destroyChart(key) {
    if (chartInstances[key]) { chartInstances[key].destroy(); chartInstances[key] = null; }
}

function buildCharts() {
    fetch('monthly_data.php?months_back=11')
      .then(r => r.json())
      .then(d => {
        const c = getChartColors();
        const fontOpts = { family: 'Sora', size: 10 };

        // ---- 1. BAR CHART (Receitas vs Despesas) ----
        destroyChart('bar');
        chartInstances.bar = new Chart(document.getElementById('monthlyChart').getContext('2d'), {
          type: 'bar',
          data: {
            labels: d.labels,
            datasets: [
              {
                label: 'Despesas',
                data: d.expenses,
                backgroundColor: c.expenseBg,
                borderColor: c.expenseBorder,
                borderWidth: 1.5,
                borderRadius: 6,
                barPercentage: 0.35,
                categoryPercentage: 0.7,
              },
              {
                label: 'Receitas',
                data: d.incomes,
                backgroundColor: c.incomeBg,
                borderColor: c.incomeBorder,
                borderWidth: 1.5,
                borderRadius: 6,
                barPercentage: 0.35,
                categoryPercentage: 0.7,
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'top', labels: { usePointStyle: true, padding: 14, font: { family: 'Sora', size: 11 }, color: c.tickColor } },
              tooltip: {
                mode: 'index', intersect: false,
                callbacks: { label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2}) }
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { font: fontOpts, color: c.tickColor } },
              y: { grid: { color: c.gridColor }, beginAtZero: true, ticks: { font: fontOpts, color: c.tickColor, callback: v => 'R$' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v) } }
            }
          }
        });

        // ---- 2. LINE CHART (Evolução do Saldo) ----
        const balanceData = d.incomes.map((inc, i) => inc - d.expenses[i]);
        const cumBalance = [];
        balanceData.reduce((acc, v, i) => { cumBalance[i] = acc + v; return cumBalance[i]; }, 0);

        const balanceCtx = document.getElementById('balanceChart').getContext('2d');
        const gradientGreen = balanceCtx.createLinearGradient(0, 0, 0, 200);
        gradientGreen.addColorStop(0, document.body.classList.contains('light') ? 'rgba(5,150,105,0.2)' : 'rgba(0,255,136,0.2)');
        gradientGreen.addColorStop(1, 'rgba(0,255,136,0)');

        destroyChart('balance');
        chartInstances.balance = new Chart(balanceCtx, {
          type: 'line',
          data: {
            labels: d.labels,
            datasets: [{
              label: 'Saldo',
              data: cumBalance,
              borderColor: c.balanceLine,
              backgroundColor: gradientGreen,
              borderWidth: 2,
              fill: true,
              tension: 0.4,
              pointRadius: 3,
              pointBackgroundColor: c.balanceLine,
              pointBorderWidth: 0,
              pointHoverRadius: 6,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: { label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2}) }
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { font: fontOpts, color: c.tickColor } },
              y: { grid: { color: c.gridColor }, ticks: { font: fontOpts, color: c.tickColor, callback: v => 'R$' + (Math.abs(v) >= 1000 ? (v/1000).toFixed(0) + 'k' : v) } }
            }
          }
        });

        // Build remaining charts
        buildPieChart();
        buildTopExpensesChart();
        buildPaidChart();
      });
}

// ---- 3. DOUGHNUT (Despesas por categoria) ----
function buildPieChart() {
    const rows = document.querySelectorAll('.table-card:first-child tbody tr:not(.empty-row)');
    const map = {};
    rows.forEach(tr => {
        const nameEl = tr.querySelector('.item-name');
        const amtEl  = tr.querySelector('.item-amount');
        if (!nameEl || !amtEl) return;
        const rawAmt = amtEl.textContent.replace(/[R$\s.]/g, '').replace(',', '.');
        const amt = parseFloat(rawAmt);
        if (isNaN(amt) || amt <= 0) return;
        const words = nameEl.textContent.trim().split(/\s+/);
        const cat = words.slice(0, 2).join(' ');
        map[cat] = (map[cat] || 0) + amt;
    });

    const labels = Object.keys(map);
    const data   = Object.values(map);
    const canvas  = document.getElementById('pieChart');
    const emptyEl = document.getElementById('pieEmpty');

    if (labels.length === 0) {
        canvas.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }
    canvas.style.display = '';
    emptyEl.style.display = 'none';

    const palette = [
        '#ff2d55','#ffd600','#00ff88','#00d4ff','#a855f7',
        '#ff6b35','#06b6d4','#ec4899','#84cc16','#f97316',
        '#6366f1','#14b8a6','#f59e0b','#0ea5e9','#d946ef',
    ];

    destroyChart('pie');
    chartInstances.pie = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: palette.slice(0, labels.length),
                borderWidth: 0,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        usePointStyle: true,
                        padding: 10,
                        font: { family: 'Sora', size: 10 },
                        color: getChartColors().tickColor,
                        boxWidth: 8,
                    }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return ` R$ ${ctx.parsed.toLocaleString('pt-BR', {minimumFractionDigits:2})} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ---- 4. TOP DESPESAS (barras horizontais) ----
function buildTopExpensesChart() {
    const topData = <?= json_encode($topExpenses) ?>;
    const canvas = document.getElementById('topExpensesChart');
    const emptyEl = document.getElementById('topEmpty');

    if (!topData || topData.length === 0) {
        canvas.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }
    canvas.style.display = '';
    emptyEl.style.display = 'none';

    const c = getChartColors();
    const labels = topData.map(r => r.name.length > 18 ? r.name.substring(0,18) + '…' : r.name);
    const values = topData.map(r => parseFloat(r.total));

    const neonColors = ['#ff2d55','#ff6b35','#ffd600','#f97316','#ec4899','#a855f7','#d946ef'];

    destroyChart('top');
    chartInstances.top = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: neonColors.slice(0, values.length).map(c => c + '25'),
                borderColor: neonColors.slice(0, values.length),
                borderWidth: 1.5,
                borderRadius: 4,
                barPercentage: 0.7,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: ctx => ' R$ ' + ctx.parsed.x.toLocaleString('pt-BR', {minimumFractionDigits: 2}) }
                }
            },
            scales: {
                x: { grid: { color: c.gridColor }, ticks: { font: { family: 'Sora', size: 9 }, color: c.tickColor, callback: v => 'R$' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v) } },
                y: { grid: { display: false }, ticks: { font: { family: 'Sora', size: 10 }, color: c.tickColor } }
            }
        }
    });
}

// ---- 5. PAGAS VS PENDENTES (doughnut) ----
function buildPaidChart() {
    const pago = <?= json_encode($paidTotal) ?>;
    const pendente = <?= json_encode($pendingTotal) ?>;
    const canvas = document.getElementById('paidChart');
    const emptyEl = document.getElementById('paidEmpty');

    if (pago === 0 && pendente === 0) {
        canvas.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }
    canvas.style.display = '';
    emptyEl.style.display = 'none';

    const light = document.body.classList.contains('light');

    destroyChart('paid');
    chartInstances.paid = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Pagas', 'Pendentes'],
            datasets: [{
                data: [pago, pendente],
                backgroundColor: [
                    light ? 'rgba(5,150,105,0.2)' : 'rgba(0,255,136,0.2)',
                    light ? 'rgba(220,38,38,0.2)' : 'rgba(255,45,85,0.2)',
                ],
                borderColor: [
                    light ? '#059669' : '#00ff88',
                    light ? '#dc2626' : '#ff2d55',
                ],
                borderWidth: 2,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 12,
                        font: { family: 'Sora', size: 11 },
                        color: getChartColors().tickColor,
                    }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                            const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                            return ` R$ ${ctx.parsed.toLocaleString('pt-BR', {minimumFractionDigits:2})} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

buildCharts();

// Abrir modal de senha se houve mensagem
<?php if ($senha_msg): ?>
document.getElementById('passwordModal').classList.add('open');
<?php endif; ?>

// Quando seleciona um cartão, esconde o campo de vencimento (a data é automática)
function toggleCardDate(sel) {
    const row = sel.closest('.entry-row');
    const dateField = row.querySelector('.date-field');
    const dateInput = dateField.querySelector('input[name="date[]"]');
    const dateLabel = dateField.querySelector('label');
    if (sel.value) {
        const opt = sel.options[sel.selectedIndex];
        dateLabel.textContent = 'Data da compra';
        dateInput.removeAttribute('required');
        dateField.style.opacity = '0.5';
        dateField.title = 'Vencimento automático (dia ' + opt.dataset.venc + ' da fatura)';
    } else {
        dateLabel.textContent = 'Vencimento';
        dateInput.setAttribute('required', '');
        dateField.style.opacity = '1';
        dateField.title = '';
    }
}

// Add entry row
function toggleParcelas(sel) {
    const row = sel.closest('.entry-row');
    const parcelasField = row.querySelector('.parcelas-field');
    parcelasField.style.display = sel.value === '1' ? '' : 'none';
    if (sel.value === '0') {
        parcelasField.querySelector('input').value = '2';
    }
}

function addEntryRow() {
    const container = document.getElementById('entryRows');
    const firstRow = container.querySelector('.entry-row');
    const newRow = firstRow.cloneNode(true);
    newRow.querySelectorAll('input').forEach(i => { if (i.type !== 'date') i.value = ''; });
    newRow.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    const parcelasField = newRow.querySelector('.parcelas-field');
    if (parcelasField) { parcelasField.style.display = 'none'; parcelasField.querySelector('input').value = '2'; }
    // Reset date field state (in case previous row had card selected)
    const dateField = newRow.querySelector('.date-field');
    if (dateField) { dateField.style.opacity = '1'; dateField.title = ''; dateField.querySelector('label').textContent = 'Vencimento'; dateField.querySelector('input').setAttribute('required', ''); }
    container.appendChild(newRow);
    newRow.querySelector('input[type="text"]').focus();
}

function removeEntryRow(btn) {
    const rows = document.querySelectorAll('#entryRows .entry-row');
    if (rows.length > 1) btn.closest('.entry-row').remove();
    else {
        btn.closest('.entry-row').querySelectorAll('input').forEach(i => { if (i.type !== 'date') i.value = ''; });
    }
}

document.getElementById('addForm').addEventListener('submit', function(e) {
    const names = Array.from(document.querySelectorAll('input[name="name[]"]')).map(i => i.value.trim());
    if (!names.some(n => n)) {
        e.preventDefault();
        alert('Preencha ao menos uma linha.');
    }
});

// Keyboard shortcut: N = new entry
document.addEventListener('keydown', e => {
    if (e.key === 'n' && e.target.tagName === 'BODY') {
        document.getElementById('addModal').classList.add('open');
    }
    if (e.key === 'Escape') {
        document.getElementById('addModal').classList.remove('open');
        document.getElementById('notifPanel').style.display = 'none';
    }
});

// ===== NOTIFICAÇÕES =====
function toggleNotifPanel() {
    const panel = document.getElementById('notifPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadNotifications();
    } else {
        panel.style.display = 'none';
    }
}

document.addEventListener('click', e => {
    const wrapper = document.querySelector('.notif-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('notifPanel').style.display = 'none';
    }
});

function loadNotifCount() {
    fetch('notificacoes_api.php?action=count')
        .then(r => r.json())
        .then(d => {
            const badge = document.getElementById('notifBadge');
            if (d.count > 0) {
                badge.textContent = d.count > 9 ? '9+' : d.count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(() => {});
}

function loadNotifications() {
    fetch('notificacoes_api.php?action=list&limit=15')
        .then(r => r.json())
        .then(d => {
            const list = document.getElementById('notifList');
            if (!d.notifications || d.notifications.length === 0) {
                list.innerHTML = '<p style="padding:20px;text-align:center;color:var(--muted);font-size:12px;">Nenhuma notificação</p>';
                return;
            }
            list.innerHTML = d.notifications.map(n => `
                <div style="padding:10px 12px;border-radius:8px;margin-bottom:4px;background:${n.lida ? 'transparent' : 'rgba(0,212,255,0.05)'};cursor:pointer;transition:background 0.15s;" onclick="markNotifRead(${n.id})" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='${n.lida ? 'transparent' : 'rgba(0,212,255,0.05)'}'">
                    <div style="font-size:12px;font-weight:${n.lida ? '400' : '600'};margin-bottom:2px;">${escHtml(n.mensagem)}</div>
                    <div style="font-size:10px;color:var(--muted);">${new Date(n.created_at).toLocaleDateString('pt-BR')} ${new Date(n.created_at).toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'})}</div>
                </div>
            `).join('');
        })
        .catch(() => {});
}

function markNotifRead(id) {
    fetch('notificacoes_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'mark_read', id: id})
    }).then(() => { loadNotifCount(); loadNotifications(); });
}

function markAllNotifRead() {
    fetch('notificacoes_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'mark_all_read'})
    }).then(() => { loadNotifCount(); loadNotifications(); });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Carregar contagem de notificações ao iniciar e a cada 60s
loadNotifCount();
setInterval(loadNotifCount, 60000);
</script>
</body>
</html>

