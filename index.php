<?php
// index.php
declare(strict_types=1);
header_remove("X-Powered-By");

$cfg = require __DIR__.'/config/config.php';
$db  = new PDO('sqlite:'.__DIR__.'/db.sqlite', '', '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function json_out($data, int $code=200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function current_period_keys(array $cfg): array {
  $y = (int)gmdate('Y');
  $m = (int)gmdate('n');
  // school year spans e.g. 2025–2026 → "2526"
  $sy_start = ($m >= 8) ? $y : $y-1;
  $sy_code  = substr((string)$sy_start,2,2).substr((string)($sy_start+1),2,2);

  $tn = 'T1';
  foreach ($cfg['trimesters'] as $key=>$range) {
    [$sm,$sd] = $range['start']; [$em,$ed] = $range['end'];
    $start = gmmktime(0,0,0,$sm,$sd, ($sm>=8? $sy_start : $sy_start+1));
    $end   = gmmktime(23,59,59,$em,$ed, ($em>=8? $sy_start : $sy_start+1));
    $now   = time();
    if ($now >= $start && $now <= $end) { $tn = $key; break; }
  }
  return [$sy_code.$tn, $sy_code.'YEAR'];
}

function ensure_log_folder(string $base, string $period): string {
  $dir = rtrim($base,'/').'/'.$period;
  if (!is_dir($dir)) mkdir($dir, 0775, true);
  return $dir;
}

/**
 * Send WhatsApp via CallMeBot.
 * Accepts the *sub-array* $cfg['callmebot'] or null. No-ops safely if not configured.
 */
function callmebot_notify(?array $cmcfg, string $text): void {
  if (!$cmcfg) return;
  $phone = $cmcfg['phone']  ?? '';
  $apikey= $cmcfg['apikey'] ?? '';
  if ($phone === '' || $apikey === '') return;

  $p = urlencode($phone);
  $t = urlencode($text);
  $k = urlencode($apikey);
  $url = "https://api.callmebot.com/whatsapp.php?phone={$p}&text={$t}&apikey={$k}";
  @file_get_contents($url);
}

$api = $_GET['api'] ?? null;
if ($api) {

  /* ===========================================================
     PUBLIC: ACTIVITIES — quota-aware (only CONFIRMED consumption)
  =========================================================== */
  if ($api === 'activities') {
    [$trimesterKey, ] = current_period_keys($cfg);

    $sql = "
      SELECT
        a.id, a.name, a.cycle, a.duration_hours, a.group_size, a.summary, a.ri_id,
        r.name AS ri_name,

        COALESCE((
          SELECT COUNT(*) FROM slot s
          WHERE s.activity_id = a.id AND s.status = 'OPEN'
        ), 0) AS open_slots,

        COALESCE((
          SELECT SUM(q.hours) FROM quota_ledger q
          WHERE q.ri_id = a.ri_id
            AND q.period_key = :tkey
            AND q.direction = 'CONSUME'
        ), 0) AS consumed_hours,

        r.quota_hours_trimester AS quota_hours_trimester

      FROM activity a
      JOIN ri r ON r.id = a.ri_id
      WHERE a.is_published = 1
      ORDER BY a.cycle, a.name
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':tkey' => $trimesterKey]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
      $remaining = max(($row['quota_hours_trimester'] ?? 0) - ($row['consumed_hours'] ?? 0), 0);
      $quota_slots = (int) floor(($row['duration_hours'] > 0) ? ($remaining / $row['duration_hours']) : 0);
      $row['slots_available'] = min((int)$row['open_slots'], $quota_slots);
      unset($row['open_slots'], $row['consumed_hours'], $row['quota_hours_trimester']);
    }

    json_out($rows);
  }

  /* ===========================================================
     PUBLIC: SLOTS
  =========================================================== */
  if ($api === 'slots') {
    $aid = (int)($_GET['activity_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, starts_at, ends_at, status FROM slot WHERE activity_id=? ORDER BY starts_at");
    $stmt->execute([$aid]);
    json_out($stmt->fetchAll());
  }

  /* ===========================================================
     PUBLIC: BOOK SLOT (single-button; add RESERVE if needed)
  =========================================================== */
  if ($api === 'book' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    foreach (['slot_id','teacher_name','teacher_email','teacher_cycle'] as $k) {
      if (empty($in[$k])) json_out(['ok'=>false,'error'=>"Missing $k"],400);
    }
    $slot_id = (int)$in['slot_id'];

    $db->beginTransaction();
    try {
      // Load slot + activity + RI
      $slot = $db->prepare("SELECT s.*, a.name AS activity_name, a.duration_hours, r.name AS ri_name
                            FROM slot s
                            JOIN activity a ON a.id=s.activity_id
                            JOIN ri r ON r.id=s.ri_id
                            WHERE s.id=?");
      $slot->execute([$slot_id]);
      $s = $slot->fetch();
      if (!$s) throw new RuntimeException('Slot not found');
      if (!in_array($s['status'], ['OPEN','PENDING'], true)) {
        throw new RuntimeException('Slot not available');
      }

      // If OPEN, atomically flip to PENDING (first writer wins) and write RESERVE holds
      if ($s['status'] === 'OPEN') {
        $upd = $db->prepare("UPDATE slot SET status='PENDING' WHERE id=? AND status='OPEN'");
        $upd->execute([$slot_id]);
        if ($upd->rowCount() === 1) {
          [$trimesterKey, $yearKey] = current_period_keys($cfg);
          $now = now_utc();
          $ins = $db->prepare("INSERT INTO quota_ledger (ri_id, booking_id, hours, period_key, direction, created_at)
                               VALUES (?,?,?,?,?,?)");
          $ins->execute([$s['ri_id'], null, $s['duration_hours'], $trimesterKey, 'RESERVE', $now]);
          $ins->execute([$s['ri_id'], null, $s['duration_hours'], $yearKey,      'RESERVE', $now]);
        }
      }

      // Create PENDING booking (DB unique index can enforce one active per slot)
      $now = now_utc();
      $insB = $db->prepare("INSERT INTO booking (slot_id, teacher_name, teacher_email, teacher_cycle, created_at, status)
                            VALUES (?,?,?,?,?, 'PENDING')");
      $insB->execute([$slot_id, trim($in['teacher_name']), trim($in['teacher_email']), trim($in['teacher_cycle']), $now]);
      $booking_id = (int)$db->lastInsertId();

      // Log snapshot
      [$trimesterKey, ] = current_period_keys($cfg);
      $dir = ensure_log_folder(__DIR__.'/logs', $trimesterKey);
      file_put_contents("$dir/booking_{$booking_id}.json", json_encode([
        'booking_id'=>$booking_id,
        'slot_id'=>$slot_id,
        'activity'=>$s['activity_name'],
        'ri'=>$s['ri_name'],
        'teacher_name'=>$in['teacher_name'],
        'teacher_email'=>$in['teacher_email'],
        'teacher_cycle'=>$in['teacher_cycle'],
        'created_at'=>$now,
        'status'=>'PENDING'
      ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

      // Notify (non-blocking). Safe even if callmebot config missing.
      $msg = "New PENDING booking:\nActivity: {$s['activity_name']}\nRI: {$s['ri_name']}\nTeacher: {$in['teacher_name']} ({$in['teacher_cycle']})\nEmail: {$in['teacher_email']}\nStarts: {$s['starts_at']}\nBooking ID: #$booking_id";
      callmebot_notify($cfg['callmebot'] ?? null, $msg);

      $db->commit();
      json_out(['ok'=>true,'booking_id'=>$booking_id]);
    } catch (PDOException $e) {
      $db->rollBack();
      if (strpos($e->getMessage(), 'booking_one_active_per_slot') !== false) {
        json_out(['ok'=>false,'error'=>'This slot was just taken. Please pick another date.'], 409);
      }
      json_out(['ok'=>false,'error'=>$e->getMessage()], 409);
    } catch (Throwable $e) {
      $db->rollBack();
      json_out(['ok'=>false,'error'=>$e->getMessage()], 409);
    }
  }

  /* ===========================================================
     ADMIN AUTH GATE (for admin-only endpoints)
  =========================================================== */
  $adminOnly = ['admin_state','activity_update','approve','reject'];
  if (in_array($api, $adminOnly, true)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = null;
    if ($authHeader && stripos($authHeader, 'Bearer ') === 0) { $token = substr($authHeader, 7); }
    if ($token === null) { $token = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? null; }
    if ($token === null) { $token = $_GET['secret'] ?? null; } // convenient for local tests
    if ($token !== ($cfg['admin_secret'] ?? '')) {
      json_out(['ok'=>false,'error'=>'Unauthorized'], 401);
    }
  }

  /* =======================
     ADMIN: lists
  ========================*/

  // list activities (basic)
  if ($api === 'admin_activities') {
    $rows = $db->query("SELECT a.*, r.name AS ri_name
                        FROM activity a JOIN ri r ON r.id=a.ri_id
                        ORDER BY a.id DESC")->fetchAll();
    json_out($rows);
  }

  // list all RIs (for selects)
  if ($api === 'admin_ris') {
    $rows = $db->query("SELECT id, name FROM ri ORDER BY name")->fetchAll();
    json_out($rows);
  }

  // get one activity
  if ($api === 'admin_activity_get') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM activity WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_out(['ok'=>false,'error'=>'Not found'],404);
    json_out($row);
  }

  /* =======================
     ADMIN: activity save/delete
  ========================*/
  if ($api === 'admin_activity_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    $fields = [
      'ri_id' => (int)($in['ri_id'] ?? 0),
      'cycle' => trim($in['cycle'] ?? ''),
      'name'  => trim($in['name'] ?? ''),
      'duration_hours' => (float)($in['duration_hours'] ?? 1),
      'group_size'     => (int)($in['group_size'] ?? 25),
      'is_published'   => (int)($in['is_published'] ?? 1),
      'summary'        => trim($in['summary'] ?? '')
    ];

    if ($id) {
      $sql="UPDATE activity SET ri_id=:ri_id, cycle=:cycle, name=:name,
           duration_hours=:duration_hours, group_size=:group_size,
           is_published=:is_published, summary=:summary WHERE id=:id";
      $st=$db->prepare($sql);
      $st->execute($fields+['id'=>$id]);
      json_out(['ok'=>true,'id'=>$id]);
    } else {
      $sql="INSERT INTO activity (ri_id,cycle,name,duration_hours,group_size,is_published,summary)
            VALUES (:ri_id,:cycle,:name,:duration_hours,:group_size,:is_published,:summary)";
      $st=$db->prepare($sql);
      $st->execute($fields);
      json_out(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    }
  }

  if ($api === 'admin_activity_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    $st = $db->prepare("DELETE FROM activity WHERE id=?");
    $st->execute([$id]);
    json_out(['ok'=>true]);
  }

  /* =======================
     ADMIN: slots CRUD
  ========================*/
  if ($api === 'admin_slots') {
    $aid = (int)($_GET['activity_id'] ?? 0);
    $st = $db->prepare("SELECT id, starts_at, ends_at, status FROM slot WHERE activity_id=? ORDER BY starts_at");
    $st->execute([$aid]);
    json_out($st->fetchAll());
  }

  if ($api === 'admin_slot_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id  = (int)($in['id'] ?? 0);
    if ($id) {
      $st = $db->prepare("UPDATE slot SET starts_at=?, ends_at=? WHERE id=?");
      $st->execute([ $in['starts_at'], $in['ends_at'], $id ]);
      json_out(['ok'=>true,'id'=>$id]);
    } else {
      // creating requires activity_id
      $aid = (int)($in['activity_id'] ?? 0);
      // infer ri_id from the activity
      $ri = $db->prepare("SELECT ri_id FROM activity WHERE id=?"); $ri->execute([$aid]);
      $row = $ri->fetch(); if(!$row) json_out(['ok'=>false,'error'=>'Activity not found'],404);
      $st = $db->prepare("INSERT INTO slot (activity_id, ri_id, starts_at, ends_at, status) VALUES (?,?,?,?, 'OPEN')");
      $st->execute([$aid, (int)$row['ri_id'], $in['starts_at'], $in['ends_at']]);
      json_out(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    }
  }

  if ($api === 'admin_slot_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    $st = $db->prepare("DELETE FROM slot WHERE id=?");
    $st->execute([$id]);
    json_out(['ok'=>true]);
  }

  /* =======================
     ADMIN: quotas view/update
  ========================*/
  if ($api === 'admin_quotas') {
    [$trimesterKey, $yearKey] = current_period_keys($cfg);
    $rows = $db->query("
      SELECT r.id, r.name, r.quota_hours_trimester, r.quota_hours_year,
        COALESCE( (SELECT SUM(hours) FROM quota_ledger q
                   WHERE q.ri_id=r.id AND q.period_key='{$trimesterKey}'
                         AND q.direction='CONSUME'), 0 ) AS consumed_trimester,
        COALESCE( (SELECT SUM(hours) FROM quota_ledger q
                   WHERE q.ri_id=r.id AND q.period_key='{$yearKey}'
                         AND q.direction='CONSUME'), 0 ) AS consumed_year
      FROM ri r ORDER BY r.name
    ")->fetchAll();
    foreach ($rows as &$x) {
      $x['remaining_trimester'] = max(0, (float)$x['quota_hours_trimester'] - (float)$x['consumed_trimester']);
    }
    json_out($rows);
  }

  if ($api === 'admin_quotas_update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $st = $db->prepare("UPDATE ri SET quota_hours_trimester=?, quota_hours_year=? WHERE id=?");
    $st->execute([ (float)$in['quota_hours_trimester'], (float)$in['quota_hours_year'], (int)$in['ri_id'] ]);
    json_out(['ok'=>true]);
  }

  /* =======================
     ADMIN: bookings by status
  ========================*/
  if ($api === 'admin_bookings') {
    $status = $_GET['status'] ?? 'PENDING';
    $st = $db->prepare("
      SELECT b.*, s.starts_at, s.ends_at, a.name AS activity_name, r.name AS ri_name
      FROM booking b
      JOIN slot s ON s.id=b.slot_id
      JOIN activity a ON a.id=s.activity_id
      JOIN ri r ON r.id=s.ri_id
      WHERE b.status=?
      ORDER BY s.starts_at ASC
    ");
    $st->execute([$status]);
    json_out($st->fetchAll());
  }



  /* ===========================================================
     ADMIN: STATE (pending bookings + activities list)
  =========================================================== */
  if ($api === 'admin_state') {
    $pending = $db->query("
      SELECT b.id, b.created_at, b.teacher_name, b.teacher_email, b.teacher_cycle,
             a.name AS activity_name, r.name AS ri_name, s.starts_at
      FROM booking b
      JOIN slot s ON s.id=b.slot_id
      JOIN activity a ON a.id=s.activity_id
      JOIN ri r ON r.id=s.ri_id
      WHERE b.status='PENDING' AND s.status='PENDING'
      ORDER BY b.id DESC
    ")->fetchAll();

    $activities = $db->query("
      SELECT a.id, a.name, a.cycle, a.duration_hours, a.group_size, a.is_published,
             a.summary, a.ri_id, r.name AS ri_name
      FROM activity a
      JOIN ri r ON r.id=a.ri_id
      ORDER BY a.cycle, a.name
    ")->fetchAll();

    json_out(['pending_bookings'=>$pending, 'activities'=>$activities]);
  }

  /* ===========================================================
     ADMIN: ACTIVITY UPDATE (inline editing)
  =========================================================== */
  if ($api === 'activity_update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);
    if ($id<=0) json_out(['ok'=>false,'error'=>'Missing id'],400);

    $name = trim((string)($in['name'] ?? ''));
    $cycle = trim((string)($in['cycle'] ?? ''));
    $duration = (float)($in['duration_hours'] ?? 0);
    $group = (int)($in['group_size'] ?? 0);
    $published = (int)($in['is_published'] ?? 0);

    $st = $db->prepare("UPDATE activity SET name=?, cycle=?, duration_hours=?, group_size=?, is_published=? WHERE id=?");
    $st->execute([$name, $cycle, $duration, $group, $published, $id]);

    json_out(['ok'=>true]);
  }

  /* ===========================================================
     ADMIN: APPROVE BOOKING (CONFIRMED)
  =========================================================== */
  if ($api === 'approve' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $bid = (int)($in['booking_id'] ?? 0);

    $db->beginTransaction();
    try {
      $stm = $db->prepare("SELECT b.*, s.id as sid, s.ri_id, s.status as slot_status, a.duration_hours, a.name as activity_name, r.name as ri_name, s.starts_at
                           FROM booking b
                           JOIN slot s ON s.id=b.slot_id
                           JOIN activity a ON a.id=s.activity_id
                           JOIN ri r ON r.id=s.ri_id
                           WHERE b.id=?");
      $stm->execute([$bid]);
      $b = $stm->fetch();
      if (!$b || $b['status']!=='PENDING' || $b['slot_status']!=='PENDING') throw new RuntimeException('Invalid state');

      $db->prepare("UPDATE booking SET status='CONFIRMED' WHERE id=?")->execute([$bid]);
      $db->prepare("UPDATE slot SET status='BOOKED' WHERE id=?")->execute([$b['sid']]);

      [$trimesterKey, $yearKey] = current_period_keys($cfg);
      $now = now_utc();
      $ins = $db->prepare("INSERT INTO quota_ledger (ri_id, booking_id, hours, period_key, direction, created_at)
                           VALUES (?,?,?,?,?,?)");
      $ins->execute([$b['ri_id'], $bid, $b['duration_hours'], $trimesterKey, 'CONSUME', $now]);
      $ins->execute([$b['ri_id'], $bid, $b['duration_hours'], $yearKey,      'CONSUME', $now]);

      $msg = "Booking CONFIRMED:\nActivity: {$b['activity_name']}\nRI: {$b['ri_name']}\nTeacher: {$b['teacher_name']} ({$b['teacher_cycle']})\nStarts: {$b['starts_at']}\nBooking ID: #$bid";
      callmebot_notify($cfg['callmebot'] ?? null, $msg);

      $db->commit();
      json_out(['ok'=>true]);
    } catch(Throwable $e) {
      $db->rollBack();
      json_out(['ok'=>false,'error'=>$e->getMessage()], 409);
    }
  }

  /* ===========================================================
     ADMIN: REJECT BOOKING (release)
  =========================================================== */
  if ($api === 'reject' && $_SERVER['REQUEST_METHOD']==='POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $bid = (int)($in['booking_id'] ?? 0);

    $db->beginTransaction();
    try {
      $stm = $db->prepare("SELECT b.*, s.id as sid, s.ri_id, s.status as slot_status, a.duration_hours, a.name as activity_name, r.name as ri_name, s.starts_at
                           FROM booking b
                           JOIN slot s ON s.id=b.slot_id
                           JOIN activity a ON a.id=s.activity_id
                           JOIN ri r ON r.id=s.ri_id
                           WHERE b.id=?");
      $stm->execute([$bid]);
      $b = $stm->fetch();
      if (!$b || $b['status']!=='PENDING' || $b['slot_status']!=='PENDING') throw new RuntimeException('Invalid state');

      $db->prepare("UPDATE booking SET status='REJECTED' WHERE id=?")->execute([$bid]);
      $db->prepare("UPDATE slot SET status='OPEN' WHERE id=?")->execute([$b['sid']]);

      [$trimesterKey, $yearKey] = current_period_keys($cfg);
      $now = now_utc();
      $ins = $db->prepare("INSERT INTO quota_ledger (ri_id, booking_id, hours, period_key, direction, created_at)
                           VALUES (?,?,?,?,?,?)");
      $ins->execute([$b['ri_id'], $bid, $b['duration_hours'], $trimesterKey, 'RELEASE', $now]);
      $ins->execute([$b['ri_id'], $bid, $b['duration_hours'], $yearKey,      'RELEASE', $now]);

      $msg = "Booking REJECTED (slot released):\nActivity: {$b['activity_name']}\nRI: {$b['ri_name']}\nStarts: {$b['starts_at']}\nBooking ID: #$bid";
      callmebot_notify($cfg['callmebot'] ?? null, $msg);

      $db->commit();
      json_out(['ok'=>true]);
    } catch(Throwable $e) {
      $db->rollBack();
      json_out(['ok'=>false,'error'=>$e->getMessage()], 409);
    }
  }

  json_out(['ok'=>false,'error'=>'Unknown endpoint'],404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ByteMe — RI Booking</title>
  <link rel="stylesheet" href="static/app.css" />
</head>
<body>
  <header class="topbar">
    <div class="brand">ByteMe</div>
    <div class="tagline">Coding-Aktivitéiten mat engem RI buchen</div>
  </header>

  <main class="container">
    <div id="activities" class="card"></div>
  </main>

  <script src="static/app.js?v=2"></script>
</body>
</html>
