<?php
declare(strict_types=1);
/*
 * Festive Board API. One JSON file holds everything: data/db.json
 *   GET  api.php            -> whole board
 *   GET  api.php?since=N    -> {"unchanged":true} if the board is still at version N
 *   POST api.php  {op:...}  -> applies one change under a file lock, returns the whole board
 */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DATA_DIR = rtrim($DATA_DIR ?? (__DIR__ . '/data'), '/');
define('DB_FILE', $DATA_DIR . '/db.json');
define('LOCK_FILE', $DATA_DIR . '/db.lock');
const SEATS = ['DC','OV','WM','IPM','Chaplin','SW','JW','Host','Tyler',
  'SW1','SW2','SW3','SW4','SW5','SW6','SW7','SW8','SW9','SW10','SW11','SW12',
  'JW1','JW2','JW3','JW4','JW5','JW6','JW7','JW8','JW9','JW10',
  'ST1','ST2','ST3','ST4','ST5','ST6','ST7','ST8'];
const GROUPS = ['regular','infrequent','guests','pigs'];
const RANKS = ['Bro','W Bro'];
const DESSERTS = ['','Cheese','Pud','Fruit'];
const RESPONSES = ['yes','no','blank'];

function fail(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

if (($BOARD_KEY ?? '') !== '') {
  $sent = $_SERVER['HTTP_X_BOARD_KEY'] ?? '';
  if (!hash_equals($BOARD_KEY, $sent)) fail(401, 'password needed');
}

function load_db(): array {
  $raw = is_file(DB_FILE) ? file_get_contents(DB_FILE) : '';
  $db = $raw ? json_decode($raw, true) : null;
  if (!is_array($db)) $db = ['version' => 1, 'board' => ['date' => date('Y-m-d'), 'seats' => new stdClass()], 'members' => []];
  $db['members'] = array_values($db['members'] ?? []);
  $db['board']['seats'] = (array)($db['board']['seats'] ?? []);
  return $db;
}
function save_db(array $db): void {
  $db['version'] = (int)($db['version'] ?? 0) + 1;
  if (!$db['board']['seats']) $db['board']['seats'] = new stdClass();
  $tmp = DB_FILE . '.tmp';
  $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (file_put_contents($tmp, $json) === false || !rename($tmp, DB_FILE)) fail(500, 'could not write data/db.json (check folder permissions)');
}
function out(array $db): void {
  if (!$db['board']['seats']) $db['board']['seats'] = new stdClass();
  echo json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }

function clean_patch(array $p): array {
  $o = [];
  foreach ($p as $k => $v) {
    switch ($k) {
      case 'surname': $s = str($v, 60); if ($s !== '') $o[$k] = $s; break;
      case 'first':   $o[$k] = str($v, 60); break;
      case 'notes':   $o[$k] = str($v, 300); break;
      case 'rank':    if (in_array($v, RANKS, true)) $o[$k] = $v; break;
      case 'group':   if (in_array($v, GROUPS, true)) $o[$k] = $v; break;
      case 'dessert': if (in_array($v, DESSERTS, true)) $o[$k] = $v; break;
      case 'response':if (in_array($v, RESPONSES, true)) $o[$k] = $v; break;
      case 'pdl': case 'attending': case 'dining': $o[$k] = (bool)$v; break;
      case 'diet':
        $d = [];
        foreach ((array)$v as $x) { $x = strtoupper(str($x, 12)); if ($x !== '') $d[] = $x; }
        $o[$k] = array_slice(array_values(array_unique($d)), 0, 6);
        break;
    }
  }
  return $o;
}
function find_member(array $db, string $id): int {
  foreach ($db['members'] as $i => $m) if (($m['id'] ?? '') === $id) return $i;
  return -1;
}
function unseat(array &$db, string $memberId): void {
  foreach ($db['board']['seats'] as $seat => $id) if ($id === $memberId) unset($db['board']['seats'][$seat]);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $db = load_db();
  if (isset($_GET['since']) && (int)$_GET['since'] === (int)$db['version']) {
    echo json_encode(['unchanged' => true, 'version' => $db['version']]);
    exit;
  }
  out($db);
}
if ($method !== 'POST') fail(405, 'GET or POST only');

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || !isset($body['op'])) fail(400, 'bad request');

$lock = fopen(LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX)) fail(500, 'could not lock the data file');
$db = load_db();

switch ($body['op']) {
  case 'member.add':
    $m = (array)($body['member'] ?? []);
    $id = (string)($m['id'] ?? '');
    if (!preg_match('/^m[a-z0-9]{3,20}$/', $id) || find_member($db, $id) >= 0) fail(400, 'bad or duplicate id');
    $p = clean_patch($m);
    if (empty($p['surname'])) fail(400, 'surname needed');
    $db['members'][] = array_merge([
      'id' => $id, 'surname' => '', 'first' => '', 'rank' => 'Bro', 'group' => 'regular', 'pdl' => false,
      'response' => 'blank', 'attending' => false, 'dining' => false, 'dessert' => '', 'diet' => [], 'notes' => '',
      'ord' => count($db['members'])], $p);
    break;

  case 'member.update':
    $i = find_member($db, (string)($body['id'] ?? ''));
    if ($i < 0) fail(404, 'no such member');
    $p = clean_patch((array)($body['patch'] ?? []));
    $db['members'][$i] = array_merge($db['members'][$i], $p);
    if (array_key_exists('dining', $p) && !$p['dining']) unseat($db, $db['members'][$i]['id']);
    break;

  case 'member.delete':
    $id = (string)($body['id'] ?? '');
    $i = find_member($db, $id);
    if ($i >= 0) { array_splice($db['members'], $i, 1); unseat($db, $id); }
    break;

  case 'board.update':
    if (isset($body['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$body['date'])) $db['board']['date'] = $body['date'];
    foreach ((array)($body['seats'] ?? []) as $seat => $id) {
      if (!in_array($seat, SEATS, true)) continue;
      if ($id === null || $id === '') { unset($db['board']['seats'][$seat]); continue; }
      $i = find_member($db, (string)$id);
      if ($i < 0 || empty($db['members'][$i]['dining'])) continue;   // only diners can be seated
      unseat($db, (string)$id);                                       // nobody sits twice
      $db['board']['seats'][$seat] = (string)$id;
    }
    break;

  default: fail(400, 'unknown op');
}
save_db($db);
flock($lock, LOCK_UN);
out($db);
