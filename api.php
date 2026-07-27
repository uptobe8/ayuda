<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

const DEFAULT_ADMIN_HASH = '$2y$12$77HFR9XS2.Lnzi4hOfV1S.eHtBJsAF5HZDqXFZEi9vy2G4Rp/uPlm';

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['ok' => false, 'error' => 'JSON no válido'], 400);
    return $data;
}

function clean_string(mixed $value, int $max = 255): string {
    $value = trim((string)$value);
    if (mb_strlen($value) > $max) $value = mb_substr($value, 0, $max);
    return $value;
}

function require_admin(): void {
    if (empty($_SESSION['admin'])) json_response(['ok' => false, 'error' => 'No autorizado'], 401);
}

function client_key(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . '|coord-ayuda-2026');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $storage = __DIR__ . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0770, true) && !is_dir($storage)) {
        json_response(['ok' => false, 'error' => 'No se pudo crear el almacenamiento'], 500);
    }

    $dbPath = getenv('AYUDA_DB_PATH') ?: ($storage . '/coord_ayuda.sqlite');
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec('PRAGMA busy_timeout=8000;');
        initialize_db($pdo);
        return $pdo;
    } catch (Throwable $e) {
        error_log('DB error: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Base de datos no disponible'], 500);
    }
}

function initialize_db(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS signups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        location TEXT NOT NULL,
        participation_type TEXT NOT NULL,
        need_or_task TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT '',
        availability TEXT NOT NULL DEFAULT '',
        vehicle TEXT NOT NULL DEFAULT '',
        can_transport TEXT NOT NULL DEFAULT '',
        destination TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'Pendiente',
        coordinator TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signups_need ON signups(need_or_task)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signups_status ON signups(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signups_created ON signups(created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS needs (
        id INTEGER PRIMARY KEY,
        category TEXT NOT NULL,
        need TEXT NOT NULL UNIQUE,
        priority TEXT NOT NULL,
        unit TEXT NOT NULL,
        target REAL NULL,
        notes TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY,
        task TEXT NOT NULL UNIQUE,
        description TEXT NOT NULL,
        zone TEXT NOT NULL DEFAULT '',
        event_date TEXT NOT NULL DEFAULT '',
        slot TEXT NOT NULL DEFAULT '',
        required_people INTEGER NULL,
        owner TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'Pendiente',
        notes TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        kind TEXT NOT NULL,
        client_key TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        hits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(kind, client_key)
    )");

    $needs = [
        ['Hidratación','Agua embotellada','Urgente','Botellas'],
        ['Protección / EPI','Mascarillas','Urgente','Unidades'],
        ['Protección / EPI','Gafas de protección','Urgente','Unidades'],
        ['Protección / EPI','Guantes','Urgente','Pares'],
        ['Protección / EPI','Chaqueta de seguridad ignífuga','Si disponible','Unidades'],
        ['Alimentación','Garbanzos cocidos','Necesario','Kilos'],
        ['Alimentación','Judías verdes cocidas','Necesario','Kilos'],
        ['Alimentación','Hummus','Necesario','Unidades'],
        ['Alimentación','Tortillas','Necesario','Unidades'],
        ['Alimentación','Atún','Necesario','Latas'],
        ['Alimentación','Pan (varios tipos)','Necesario','Barras / bolsas'],
        ['Herramientas / mantenimiento','Aceite de motosierra','Necesario','Litros'],
        ['Herramientas / mantenimiento','Aceite mezcla 2T / desbrozadora','Necesario','Litros'],
        ['Herramientas / mantenimiento','Hilo de desbrozadora','Opcional','Unidades'],
        ['Herramientas / apoyo','Pulverizadora de mochila','Si disponible','Unidades'],
        ['Petición voluntariado','Tabaco (solo adultos)','Opcional','Unidades'],
        ['Petición voluntariado','Cerveza (solo adultos)','Opcional','Unidades'],
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO needs (id,category,need,priority,unit) VALUES (?,?,?,?,?)');
    foreach ($needs as $i => $n) $stmt->execute([$i + 1, $n[0], $n[1], $n[2], $n[3]]);

    $tasks = [
        ['Recogida de donaciones / compras','Recoger materiales y alimentos en puntos acordados'],
        ['Preparación de comida','Preparar alternativas a bocadillos: ensaladas, hummus, tortillas, atún, etc.'],
        ['Transporte de material','Mover materiales/comida al punto de destino'],
        ['Clasificación / inventario','Recibir, ordenar y anotar lo que entra y sale'],
        ['Reparto','Distribuir suministros a los equipos/voluntariado'],
        ['Coordinación','Confirmar necesidades, personas, vehículos y destinos'],
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO tasks (id,task,description) VALUES (?,?,?)');
    foreach ($tasks as $i => $t) $stmt->execute([$i + 1, $t[0], $t[1]]);
}

function rate_limit(PDO $pdo, string $kind, int $maxHits, int $windowSeconds): bool {
    $key = client_key();
    $now = time();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT window_start,hits FROM rate_limits WHERE kind=? AND client_key=?');
        $stmt->execute([$kind, $key]);
        $row = $stmt->fetch();
        if (!$row || ($now - (int)$row['window_start']) >= $windowSeconds) {
            $stmt = $pdo->prepare('INSERT INTO rate_limits(kind,client_key,window_start,hits) VALUES(?,?,?,1) ON CONFLICT(kind,client_key) DO UPDATE SET window_start=excluded.window_start,hits=1');
            $stmt->execute([$kind, $key, $now]);
            $pdo->commit();
            return true;
        }
        if ((int)$row['hits'] >= $maxHits) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('UPDATE rate_limits SET hits=hits+1 WHERE kind=? AND client_key=?');
        $stmt->execute([$kind, $key]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return true;
    }
}

function public_stats(PDO $pdo): array {
    $sql = "SELECT
        COUNT(*) AS people,
        SUM(CASE WHEN status <> 'Cancelado' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN can_transport='Sí' OR participation_type='Transportar' THEN 1 ELSE 0 END) AS transport
        FROM signups";
    $r = $pdo->query($sql)->fetch() ?: [];
    return [
        'people' => (int)($r['people'] ?? 0),
        'active' => (int)($r['active'] ?? 0),
        'transport' => (int)($r['transport'] ?? 0),
    ];
}

function export_csv(PDO $pdo): never {
    require_admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="coordinacion-ayuda-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['ID','Fecha','Nombre','Teléfono','Población','Tipo de ayuda','Tarea o aportación','Cantidad','Unidad','Disponibilidad','Vehículo','Puede transportar','Destino','Estado','Coordinador','Observaciones'], ';');
    $stmt = $pdo->query('SELECT * FROM signups ORDER BY id DESC');
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['id'],$r['created_at'],$r['name'],$r['phone'],$r['location'],$r['participation_type'],$r['need_or_task'],$r['quantity'],$r['unit'],$r['availability'],$r['vehicle'],$r['can_transport'],$r['destination'],$r['status'],$r['coordinator'],$r['notes']], ';');
    }
    fclose($out);
    exit;
}

$action = $_GET['action'] ?? 'health';
$pdo = db();

if ($action === 'health') {
    json_response(['ok' => true, 'database' => 'sqlite', 'time' => gmdate('c')]);
}

if ($action === 'public-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'stats' => public_stats($pdo)]);
}

if ($action === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit($pdo, 'signup', 20, 600)) json_response(['ok' => false, 'error' => 'Demasiados envíos desde este dispositivo. Inténtalo más tarde.'], 429);
    $d = read_json();
    if (!empty($d['website'])) json_response(['ok' => true]);

    $name = clean_string($d['name'] ?? '', 120);
    $phone = clean_string($d['phone'] ?? '', 40);
    $location = clean_string($d['location'] ?? '', 100);
    $participation = clean_string($d['participationType'] ?? '', 100);
    $need = clean_string($d['needOrTask'] ?? '', 160);
    if ($name === '' || $phone === '' || $location === '' || $participation === '' || $need === '') {
        json_response(['ok' => false, 'error' => 'Faltan datos obligatorios'], 422);
    }

    $quantity = is_numeric($d['quantity'] ?? null) ? max(0, (float)$d['quantity']) : 0;
    $stmt = $pdo->prepare('INSERT INTO signups (name,phone,location,participation_type,need_or_task,quantity,unit,availability,vehicle,can_transport,destination,status,coordinator,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $name,
        $phone,
        $location,
        $participation,
        $need,
        $quantity,
        clean_string($d['unit'] ?? '', 60),
        clean_string($d['availability'] ?? '', 80),
        clean_string($d['vehicle'] ?? '', 80),
        clean_string($d['canTransport'] ?? '', 10),
        clean_string($d['destination'] ?? '', 180),
        'Pendiente',
        '',
        clean_string($d['notes'] ?? '', 1200),
    ]);
    json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'stats' => public_stats($pdo)], 201);
}

if ($action === 'admin-login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit($pdo, 'admin-login', 8, 900)) json_response(['ok' => false, 'error' => 'Demasiados intentos. Espera 15 minutos.'], 429);
    $d = read_json();
    $hash = getenv('AYUDA_ADMIN_PASSWORD_HASH') ?: DEFAULT_ADMIN_HASH;
    if (!password_verify((string)($d['password'] ?? ''), $hash)) {
        usleep(350000);
        json_response(['ok' => false, 'error' => 'Contraseña incorrecta'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    json_response(['ok' => true]);
}

if ($action === 'admin-logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_response(['ok' => true]);
}

if ($action === 'admin-session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'admin' => !empty($_SESSION['admin'])]);
}

if ($action === 'needs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    $sql = "SELECT n.*,
        COALESCE(SUM(CASE WHEN s.status <> 'Cancelado' THEN s.quantity ELSE 0 END),0) committed,
        COALESCE(SUM(CASE WHEN s.status = 'Entregado' THEN s.quantity ELSE 0 END),0) delivered
        FROM needs n LEFT JOIN signups s ON s.need_or_task=n.need
        GROUP BY n.id ORDER BY n.id";
    json_response(['ok' => true, 'needs' => $pdo->query($sql)->fetchAll()]);
}

if ($action === 'need-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    $d = read_json();
    $id = (int)($d['id'] ?? 0);
    $target = ($d['target'] ?? '') === '' ? null : max(0, (float)$d['target']);
    $stmt = $pdo->prepare('UPDATE needs SET target=? WHERE id=?');
    $stmt->execute([$target, $id]);
    json_response(['ok' => true]);
}

if ($action === 'tasks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    $sql = "SELECT t.*, COUNT(s.id) signed FROM tasks t LEFT JOIN signups s ON s.need_or_task=t.task AND s.status <> 'Cancelado' GROUP BY t.id ORDER BY t.id";
    json_response(['ok' => true, 'tasks' => $pdo->query($sql)->fetchAll()]);
}

if ($action === 'task-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    $d = read_json();
    $id = (int)($d['id'] ?? 0);
    $allowedStatus = ['Pendiente','Asignada','En curso','Completada','Cancelada'];
    $status = clean_string($d['status'] ?? 'Pendiente', 40);
    if (!in_array($status, $allowedStatus, true)) $status = 'Pendiente';
    $required = ($d['requiredPeople'] ?? '') === '' ? null : max(0, (int)$d['requiredPeople']);
    $stmt = $pdo->prepare('UPDATE tasks SET zone=?, required_people=?, status=? WHERE id=?');
    $stmt->execute([clean_string($d['zone'] ?? '', 160), $required, $status, $id]);
    json_response(['ok' => true]);
}

if ($action === 'summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    $s = $pdo->query("SELECT COUNT(*) total, SUM(CASE WHEN status <> 'Cancelado' THEN 1 ELSE 0 END) active, SUM(CASE WHEN status='Entregado' THEN 1 ELSE 0 END) delivered, SUM(CASE WHEN can_transport='Sí' OR participation_type='Transportar' THEN 1 ELSE 0 END) transport FROM signups")->fetch();
    $inProgress = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='En curso'")->fetchColumn();
    json_response(['ok' => true, 'summary' => [
        'total' => (int)($s['total'] ?? 0),
        'active' => (int)($s['active'] ?? 0),
        'delivered' => (int)($s['delivered'] ?? 0),
        'inProgress' => $inProgress,
        'transport' => (int)($s['transport'] ?? 0),
    ]]);
}

if ($action === 'export-csv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    export_csv($pdo);
}

json_response(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
