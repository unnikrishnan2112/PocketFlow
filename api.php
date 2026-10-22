<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function problem(string $message, int $status = 422): never { respond(['ok'=>false, 'message'=>$message], $status); }
// All API endpoints, including read-only reporting, require authentication.
$userId = pfUserId();
if ($userId < 1) problem('Please sign in to continue.', 401);
function input(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function validMoney(string $raw, bool $allowZero = false): string {
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,2})?$/', $raw)) problem('Enter a valid amount (up to 2 decimal places).');
    $value = number_format((float)$raw, 2, '.', '');
    if (!$allowZero && (float)$value <= 0) problem('Amount must be greater than zero.');
    return $value;
}
function validDate(string $raw): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) problem('Please select a valid date.');
    return $raw;
}
function validId(string $raw): int {
    if (!ctype_digit($raw) || (int)$raw < 1) problem('Please choose a valid item.');
    return (int)$raw;
}
function validText(string $value, string $name, int $limit): string {
    if ($value === '' || mb_strlen($value) > $limit) problem("$name must be between 1 and $limit characters.");
    return $value;
}
function getLockedSource(PDO $db, int $userId, int $id): array {
    $stmt = $db->prepare('SELECT id,name,balance FROM sources WHERE id=? AND user_id=? FOR UPDATE');
    $stmt->execute([$id,$userId]);
    $source = $stmt->fetch();
    if (!$source) problem('This source does not belong to your account or no longer exists.', 404);
    return $source;
}
function insertEntry(PDO $db, int $userId, int $source, string $kind, string $title, string $category, string $amount, string $date, string $note='', ?string $group=null): void {
    $stmt = $db->prepare('INSERT INTO transactions (user_id,source_id,kind,title,category,amount,occurred_on,note,transfer_group) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$userId,$source,$kind,$title,$category,$amount,$date,$note ?: null,$group]);
}
function adjust(PDO $db, int $userId, int $source, string $amount, bool $add): void {
    if ($add) {
        $stmt = $db->prepare('UPDATE sources SET balance = balance + ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$amount,$source,$userId]);
    } else {
        $stmt = $db->prepare('UPDATE sources SET balance = balance - ? WHERE id = ? AND user_id = ? AND balance >= ?');
        $stmt->execute([$amount,$source,$userId,$amount]);
        if ($stmt->rowCount() !== 1) problem('Not enough balance in this income source.');
    }
    if ($stmt->rowCount() !== 1) problem('Income source not found.', 404);
}
function monthBounds(): array { return [date('Y-m-01'),date('Y-m-01',strtotime('first day of next month'))]; }

function state(PDO $db, int $userId): never {
    $stmt=$db->prepare("SELECT s.id,s.name,s.icon,s.color,s.balance,s.created_at,
        COALESCE(SUM(CASE WHEN t.kind='income' THEN t.amount ELSE 0 END),0) AS income_total,
        COALESCE(SUM(CASE WHEN t.kind='expense' THEN t.amount ELSE 0 END),0) AS expense_total,
        COUNT(t.id) AS transaction_count
        FROM sources s LEFT JOIN transactions t ON t.source_id=s.id AND t.user_id=s.user_id
        WHERE s.user_id=?
        GROUP BY s.id,s.name,s.icon,s.color,s.balance,s.created_at ORDER BY s.created_at ASC,s.id ASC");
    $stmt->execute([$userId]); $sources=$stmt->fetchAll();

    [$from,$to]=monthBounds();
    $stmt=$db->prepare("SELECT
        COALESCE(SUM(CASE WHEN kind='income' THEN amount ELSE 0 END),0) AS all_income,
        COALESCE(SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END),0) AS all_expense,
        COALESCE(SUM(CASE WHEN kind='income' AND occurred_on>=? AND occurred_on<? THEN amount ELSE 0 END),0) AS month_income,
        COALESCE(SUM(CASE WHEN kind='expense' AND occurred_on>=? AND occurred_on<? THEN amount ELSE 0 END),0) AS month_expense
        FROM transactions WHERE user_id=?");
    $stmt->execute([$from,$to,$from,$to,$userId]); $totals=$stmt->fetch();
    $totals['balance']=array_reduce($sources,fn($sum,$s)=>$sum+(float)$s['balance'],0);

    $start=date('Y-m-01',strtotime('-5 months'));
    $stmt=$db->prepare("SELECT DATE_FORMAT(occurred_on,'%Y-%m') AS month,
        SUM(CASE WHEN kind='income' THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END) AS expense
        FROM transactions WHERE user_id=? AND occurred_on>=? AND occurred_on<?
        GROUP BY DATE_FORMAT(occurred_on,'%Y-%m') ORDER BY month ASC");
    $stmt->execute([$userId,$start,$to]); $monthly=$stmt->fetchAll();

    $stmt=$db->prepare("SELECT category,SUM(amount) AS amount FROM transactions
        WHERE user_id=? AND kind='expense' AND occurred_on>=? AND occurred_on<?
        GROUP BY category ORDER BY amount DESC LIMIT 7");
    $stmt->execute([$userId,$from,$to]); $categories=$stmt->fetchAll();

    $stmt=$db->prepare('SELECT t.id,t.kind,t.title,t.category,t.amount,t.occurred_on,t.note,t.transfer_group,t.source_id,s.name AS source_name,s.icon AS source_icon,s.color AS source_color FROM transactions t JOIN sources s ON s.id=t.source_id AND s.user_id=t.user_id WHERE t.user_id=? ORDER BY t.occurred_on DESC,t.id DESC LIMIT 7');
    $stmt->execute([$userId]); $recent=$stmt->fetchAll();
    respond(['ok'=>true,'sources'=>$sources,'totals'=>$totals,'monthly'=>$monthly,'categories'=>$categories,'recent'=>$recent]);
}
function transactions(PDO $db, int $userId): never {
    $where=['t.user_id=?']; $args=[$userId];
    $source=trim((string)($_GET['source_id'] ?? ''));
    $kind=trim((string)($_GET['kind'] ?? 'all'));
    $month=trim((string)($_GET['month'] ?? ''));
    $q=trim((string)($_GET['q'] ?? ''));
    if ($source !== '') { $where[]='t.source_id=?'; $args[]=validId($source); }
    if ($kind === 'income') $where[]="t.kind='income'";
    elseif ($kind === 'expense') $where[]="t.kind='expense'";
    elseif ($kind === 'transfer') $where[]="t.kind IN ('transfer_in','transfer_out')";
    elseif ($kind !== 'all') problem('Invalid filter.');
    if ($month !== '') {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) problem('Invalid month filter.');
        $first=$month.'-01'; $next=(new DateTimeImmutable($first))->modify('+1 month')->format('Y-m-d');
        $where[]='t.occurred_on>=? AND t.occurred_on<?'; $args[]=$first; $args[]=$next;
    }
    if ($q !== '') {
        if (mb_strlen($q)>100) problem('Search is too long.');
        $where[]='(t.title LIKE ? OR t.category LIKE ? OR s.name LIKE ? OR t.note LIKE ?)';
        $like='%'.$q.'%'; array_push($args,$like,$like,$like,$like);
    }
    $filter=' WHERE '.implode(' AND ',$where);
    $from=' FROM transactions t JOIN sources s ON s.id=t.source_id AND s.user_id=t.user_id';
    $stmt=$db->prepare('SELECT COUNT(*)'.$from.$filter); $stmt->execute($args); $total=(int)$stmt->fetchColumn();
    $page=max(1,min(100000,(int)($_GET['page']??1))); $limit=18; $offset=($page-1)*$limit;
    $stmt=$db->prepare('SELECT t.id,t.kind,t.title,t.category,t.amount,t.occurred_on,t.note,t.transfer_group,t.source_id,s.name AS source_name,s.icon AS source_icon,s.color AS source_color'.$from.$filter.' ORDER BY t.occurred_on DESC,t.id DESC LIMIT ? OFFSET ?');
    foreach($args as $i=>$value) $stmt->bindValue($i+1,$value);
    $stmt->bindValue(count($args)+1,$limit,PDO::PARAM_INT);
    $stmt->bindValue(count($args)+2,$offset,PDO::PARAM_INT);
    $stmt->execute();
    respond(['ok'=>true,'items'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'has_more'=>($offset+$limit)<$total]);
}

try {
    $db=db();
    $action=(string)($_POST['action'] ?? $_GET['action'] ?? 'state');
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        if ($action==='state') state($db,$userId);
        if ($action==='transactions') transactions($db,$userId);
        problem('Unknown action.',404);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') problem('Method not allowed.',405);
    if (!pfVerifyCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) problem('Security token expired. Refresh the page.',403);

    if ($action==='source') {
        $name=validText(input('name'),'Source name',80);
        $icon=input('icon'); $color=input('color');
        $allowedIcons=['wallet2','briefcase','laptop','shop','gift','piggy-bank','bank','cash-stack','stars','three-dots'];
        $allowedColors=['#6257ED','#0C9F88','#F2A23A','#EF6A86','#4689E8','#9B6BDD'];
        if (!in_array($icon,$allowedIcons,true)) $icon='wallet2';
        if (!in_array($color,$allowedColors,true)) $color='#6257ED';
        $opening=validMoney(input('opening')!==''?input('opening'):'0',true);
        $db->beginTransaction();
        $stmt=$db->prepare('INSERT INTO sources (user_id,name,icon,color,balance) VALUES (?,?,?,?,?)');
        $stmt->execute([$userId,$name,$icon,$color,$opening]);
        $id=(int)$db->lastInsertId();
        if ((float)$opening>0) insertEntry($db,$userId,$id,'income','Opening balance','Opening balance',$opening,date('Y-m-d'));
        $db->commit(); respond(['ok'=>true,'message'=>'Income source created.']);
    }
    if ($action==='income' || $action==='expense') {
        $source=validId(input('source_id')); $amount=validMoney(input('amount'));
        $title=validText(input('title'),'Description',120);
        $category=validText(input('category'),'Category',40);
        $date=validDate(input('date'));
        $note=input('note'); if (mb_strlen($note)>500) problem('Note is too long.');
        $db->beginTransaction();
        getLockedSource($db,$userId,$source);
        adjust($db,$userId,$source,$amount,$action==='income');
        insertEntry($db,$userId,$source,$action,$title,$category,$amount,$date,$note);
        $db->commit(); respond(['ok'=>true,'message'=>ucfirst($action).' saved successfully.']);
    }
    if ($action==='transfer') {
        $from=validId(input('from_id')); $to=validId(input('to_id'));
        if ($from===$to) problem('Choose two different sources.');
        $amount=validMoney(input('amount')); $date=validDate(input('date'));
        $note=input('note'); if (mb_strlen($note)>500) problem('Note is too long.');
        $db->beginTransaction();
        foreach([min($from,$to),max($from,$to)] as $id) getLockedSource($db,$userId,$id);
        $stmt=$db->prepare('SELECT id,name FROM sources WHERE user_id=? AND id IN (?,?)');
        $stmt->execute([$userId,$from,$to]);
        $names=[]; foreach($stmt->fetchAll() as $s) $names[$s['id']]=$s['name'];
        if (count($names)!==2) problem('Source not found.',404);
        adjust($db,$userId,$from,$amount,false);
        adjust($db,$userId,$to,$amount,true);
        $group=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
        insertEntry($db,$userId,$from,'transfer_out','To '.$names[$to],'Transfer',$amount,$date,$note,$group);
        insertEntry($db,$userId,$to,'transfer_in','From '.$names[$from],'Transfer',$amount,$date,$note,$group);
        $db->commit(); respond(['ok'=>true,'message'=>'Transfer completed.']);
    }
    if ($action==='delete') {
        $id=validId(input('id'));
        $db->beginTransaction();
        $stmt=$db->prepare('SELECT * FROM transactions WHERE id=? AND user_id=? FOR UPDATE');
        $stmt->execute([$id,$userId]);
        $entry=$stmt->fetch(); if (!$entry) problem('Transaction not found.',404);
        if ($entry['transfer_group']) {
            $stmt=$db->prepare('SELECT * FROM transactions WHERE user_id=? AND transfer_group=? ORDER BY source_id ASC FOR UPDATE');
            $stmt->execute([$userId,$entry['transfer_group']]); $pair=$stmt->fetchAll();
            if (count($pair)!==2) problem('Transfer records are incomplete.');
            foreach($pair as $item) getLockedSource($db,$userId,(int)$item['source_id']);
            foreach($pair as $item) if ($item['kind']==='transfer_in') adjust($db,$userId,(int)$item['source_id'],$item['amount'],false);
            foreach($pair as $item) if ($item['kind']==='transfer_out') adjust($db,$userId,(int)$item['source_id'],$item['amount'],true);
            $stmt=$db->prepare('DELETE FROM transactions WHERE user_id=? AND transfer_group=?');
            $stmt->execute([$userId,$entry['transfer_group']]);
        } else {
            getLockedSource($db,$userId,(int)$entry['source_id']);
            adjust($db,$userId,(int)$entry['source_id'],$entry['amount'],$entry['kind']==='expense');
            $stmt=$db->prepare('DELETE FROM transactions WHERE id=? AND user_id=?');
            $stmt->execute([$id,$userId]);
        }
        $db->commit(); respond(['ok'=>true,'message'=>'Transaction deleted and balance corrected.']);
    }
    if ($action==='delete_source') {
        $id=validId(input('id'));
        $db->beginTransaction();
        $source=getLockedSource($db,$userId,$id);
        $stmt=$db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND source_id=?');
        $stmt->execute([$userId,$id]);
        if ((float)$source['balance']!==0.0 || (int)$stmt->fetchColumn()>0) problem('A source can be removed only when its balance is zero and it has no transactions.');
        $stmt=$db->prepare('DELETE FROM sources WHERE id=? AND user_id=?');
        $stmt->execute([$id,$userId]);
        $db->commit(); respond(['ok'=>true,'message'=>'Empty source removed.']);
    }
    problem('Unknown action.',404);
} catch(PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('PocketFlow DB error: '.$e->getMessage());
    problem('Database error. Check setup, database.sql and config.php.',500);
} catch(Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('PocketFlow error: '.$e->getMessage());
    problem('Something went wrong. Please try again.',500);
}
