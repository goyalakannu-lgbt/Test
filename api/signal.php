<?php
/**
 * WebRTC Signaling Server (PHP / File-based)
 * Handles: register, heartbeat, list-peers, send-signal, poll-signals
 * 
 * Compatible with PHP 7.4 through PHP 8.4+
 */

// Suppress raw display errors to prevent breaking JSON headers; log errors instead
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Config ──────────────────────────────────────────────────────────────────
define('PEER_TTL',      15);   // seconds before a peer is considered offline
define('SIGNAL_TTL',    60);   // seconds before unread signals are purged
define('MAX_SIGNALS',   100);  // max queued signals per user
define('POLL_TIMEOUT',  20);   // long-poll max wait in seconds
define('POLL_INTERVAL', 0.3);  // seconds between poll checks

// ── Helpers ─────────────────────────────────────────────────────────────────
function base_dir(): string {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    // Try system temp directory first
    $tmp = sys_get_temp_dir() . '/webrtc_signaling';
    $can_use_tmp = false;
    if (@is_dir($tmp) || @mkdir($tmp, 0777, true)) {
        if (@is_writable($tmp)) {
            $test = $tmp . '/.test_write_' . uniqid();
            if (@file_put_contents($test, '1') !== false) {
                @unlink($test);
                $can_use_tmp = true;
            }
        }
    }

    if ($can_use_tmp) {
        $dir = $tmp;
    } else {
        // Fallback to local .data directory if /tmp is not accessible or restricted
        $local = __DIR__ . '/.data';
        if (!@is_dir($local)) {
            @mkdir($local, 0777, true);
        }
        if (!@file_exists($local . '/.htaccess')) {
            @file_put_contents($local . '/.htaccess', "Deny from all\n");
        }
        $dir = $local;
    }
    return $dir;
}

function peer_file(string $id): string {
    $d = base_dir() . '/peers';
    if (!@is_dir($d)) @mkdir($d, 0777, true);
    return $d . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '.json';
}

function signal_file(string $id): string {
    $d = base_dir() . '/signals';
    if (!@is_dir($d)) @mkdir($d, 0777, true);
    return $d . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '.json';
}

function lock_file(string $path) {
    $lf = @fopen($path . '.lock', 'c');
    if ($lf && is_resource($lf)) {
        @flock($lf, LOCK_EX);
    }
    return $lf;
}

function unlock_file($lf): void {
    if ($lf && is_resource($lf)) {
        @flock($lf, LOCK_UN);
        @fclose($lf);
    }
}

function read_json(string $path) {
    if (!@file_exists($path)) return null;
    $raw = @file_get_contents($path);
    return $raw ? json_decode($raw, true) : null;
}

function write_json(string $path, $data): void {
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function clean_stale_peers(): void {
    $dir = base_dir() . '/peers';
    if (!@is_dir($dir)) return;
    $files = @glob($dir . '/*.json');
    if (!$files) return;
    foreach ($files as $f) {
        $peer = read_json($f);
        if ($peer && (time() - ($peer['heartbeat'] ?? 0)) > PEER_TTL) {
            @unlink($f);
            @unlink($f . '.lock');
        }
    }
}

function get_online_peers(): array {
    clean_stale_peers();
    $dir = base_dir() . '/peers';
    if (!@is_dir($dir)) return [];
    $files = @glob($dir . '/*.json');
    if (!$files) return [];
    $peers = [];
    foreach ($files as $f) {
        $peer = read_json($f);
        if ($peer && !empty($peer['id'])) {
            $peers[] = $peer;
        }
    }
    return $peers;
}

function ok($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── Main Execution ───────────────────────────────────────────────────────────
try {
    $raw_input = file_get_contents('php://input');
    $input = $raw_input ? json_decode($raw_input, true) : [];
    if (!is_array($input)) {
        $input = [];
    }

    $action = $_GET['action'] ?? $input['action'] ?? '';
    $my_id  = trim($input['id'] ?? $_GET['id'] ?? '');
    $my_id  = preg_replace('/[^a-zA-Z0-9_-]/', '', $my_id);

    if (!$my_id && $action !== 'list') {
        err('Missing id');
    }

    // Register / heartbeat — call every ~8s
    if ($action === 'heartbeat') {
        $pf = peer_file($my_id);
        $lf = lock_file($pf);
        $peer = read_json($pf) ?? [];
        $peer['id']        = $my_id;
        $peer['name']      = substr($input['name'] ?? $peer['name'] ?? 'User', 0, 40);
        $peer['heartbeat'] = time();
        write_json($pf, $peer);
        unlock_file($lf);
        ok(['peers' => get_online_peers()]);
    }

    // Deregister (graceful leave)
    if ($action === 'leave') {
        $pf = peer_file($my_id);
        @unlink($pf);
        @unlink($pf . '.lock');
        // push a "bye" signal to any peer we were in a call with
        $to = trim($input['to'] ?? '');
        $to = preg_replace('/[^a-zA-Z0-9_-]/', '', $to);
        if ($to) {
            $sf = signal_file($to);
            $lf = lock_file($sf);
            $queue = read_json($sf) ?? [];
            $queue[] = ['from' => $my_id, 'type' => 'bye', 'ts' => time()];
            write_json($sf, array_slice($queue, -MAX_SIGNALS));
            unlock_file($lf);
        }
        ok();
    }

    // List online peers (excluding self)
    if ($action === 'list') {
        $peers = array_values(array_filter(
            get_online_peers(),
            function($p) use ($my_id) {
                return ($p['id'] ?? '') !== $my_id;
            }
        ));
        ok(['peers' => $peers]);
    }

    // Send a WebRTC signal (offer / answer / candidate / bye / call-request / call-reject)
    if ($action === 'signal') {
        $to      = trim($input['to'] ?? '');
        $to      = preg_replace('/[^a-zA-Z0-9_-]/', '', $to);
        $type    = trim($input['type'] ?? '');
        $payload = $input['payload'] ?? null;

        if (!$to || !$type) {
            err('Missing to / type');
        }

        // Verify target is online
        $pf = peer_file($to);
        $target = read_json($pf);
        if (!$target || (time() - ($target['heartbeat'] ?? 0)) > PEER_TTL) {
            err('Peer offline', 410);
        }

        $sf = signal_file($to);
        $lf = lock_file($sf);
        $queue = read_json($sf) ?? [];
        $queue[] = [
            'from'    => $my_id,
            'type'    => $type,
            'payload' => $payload,
            'ts'      => time(),
        ];
        // Purge old signals
        $now = time();
        $queue = array_filter($queue, function($s) use ($now) {
            return ($now - ($s['ts'] ?? 0)) < SIGNAL_TTL;
        });
        write_json($sf, array_values(array_slice($queue, -MAX_SIGNALS)));
        unlock_file($lf);

        ok();
    }

    // Long-poll for incoming signals
    if ($action === 'poll') {
        if (function_exists('set_time_limit')) {
            @set_time_limit(35);
        }

        $sf    = signal_file($my_id);
        $start = microtime(true);

        while (true) {
            if (connection_aborted()) {
                exit;
            }

            clearstatcache();
            $lf    = lock_file($sf);
            $queue = read_json($sf) ?? [];

            if (!empty($queue)) {
                // Clear queue while holding the lock to prevent race conditions
                write_json($sf, []);
                unlock_file($lf);
                ok(['signals' => $queue]);
            }
            unlock_file($lf);

            if ((microtime(true) - $start) >= POLL_TIMEOUT) {
                ok(['signals' => []]);
            }

            usleep((int)(POLL_INTERVAL * 1000000));
        }
    }

    err('Unknown action');

} catch (Throwable $e) {
    err('Server error: ' . $e->getMessage(), 500);
}
